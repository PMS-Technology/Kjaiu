<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Configuration;
use App\Models\Host;
use App\Models\ModuleQueue;
use App\Models\Product;
use App\Models\ProductConfigOption;
use App\Models\ProductConfigOptionSub;
use App\Models\Server;
use App\Models\ServerGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provisioning driver layer.
 *
 * On the original platform a provisioning module is a directory of plain PHP
 * functions under `public/plugins/servers/<module>/<module>.php` exposing
 * `bthosts_CreateAccount($params)`, `bthosts_SuspendAccount($params)` and so
 * on. Every client-area module action (power on, reboot, reinstall, console,
 * ...) is dispatched through those functions, and upstream-reseller products
 * are forwarded to the supplier installation instead.
 *
 * This service keeps that contract: it loads the driver, builds the parameter
 * bag the drivers expect, normalises the driver's return value into the
 * platform envelope and records asynchronous calls in `shd_module_queue`.
 */
class ModuleService
{
    /** Driver function suffix => the client-area action name that triggers it. */
    public const ACTION_FUNCTIONS = [
        'create' => 'CreateAccount',
        'suspend' => 'SuspendAccount',
        'unsuspend' => 'UnsuspendAccount',
        'terminate' => 'TerminateAccount',
        'renew' => 'Renew',
        'status' => 'Status',
        'crack_pass' => 'CrackPassword',
        'on' => 'PowerOn',
        'off' => 'PowerOff',
        'reboot' => 'Reboot',
        'hard_off' => 'HardPowerOff',
        'hard_reboot' => 'HardReboot',
        'bmc' => 'Bmc',
        'kvm' => 'Kvm',
        'ikvm' => 'Ikvm',
        'vnc' => 'Vnc',
        'rescue' => 'Rescue',
        'reinstall' => 'Reinstall',
        'get_reinstall' => 'GetReinstall',
        'client_area' => 'ClientArea',
        'allow_function' => 'AllowFunction',
        'chart' => 'Chart',
        'charts' => 'Chart',
        'button' => 'Button',
        'reset_license' => 'ResetLicense',
        'cancel_task' => 'CancelTask',
        'traffic' => 'Traffic',
        'flow_packet' => 'FlowPacket',
        'custom' => 'Custom',
    ];

    /**
     * Client-area actions that run asynchronously when the module declares a
     * queue-capable action, mirroring the original `module_queue` behaviour.
     */
    public const QUEUEABLE = [
        'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount',
        'Renew', 'Reinstall', 'Rescue', 'CrackPassword', 'HardReboot',
    ];

    /** Actions that change `shd_host.domainstatus` on success. */
    protected const STATUS_EFFECTS = [
        'CreateAccount' => Host::STATUS_ACTIVE,
        'SuspendAccount' => Host::STATUS_SUSPENDED,
        'UnsuspendAccount' => Host::STATUS_ACTIVE,
        'TerminateAccount' => Host::STATUS_TERMINATED,
    ];

    /** @var array<string, bool> modules already loaded in this process */
    protected static array $loaded = [];

    /**
     * Absolute path of the driver file for a module.
     */
    public function driverPath(string $module): string
    {
        $module = preg_replace('/[^A-Za-z0-9_\-]/', '', $module) ?: '';

        return base_path('public/plugins/servers/' . $module . '/' . $module . '.php');
    }

    /**
     * Whether the installation ships a local driver for this module.
     */
    public function hasDriver(string $module): bool
    {
        return $module !== '' && is_file($this->driverPath($module));
    }

    /**
     * Load the driver file once per request and return the prefix its
     * functions use (`bthosts` for `bthosts_CreateAccount`).
     */
    public function loadDriver(string $module): ?string
    {
        $path = $this->driverPath($module);

        if (! $this->hasDriver($module)) {
            return null;
        }

        // Drivers are written as a set of global functions, so the file is
        // included rather than autoloaded.
        if (! isset(self::$loaded[$module])) {
            try {
                require_once $path;
            } catch (\Throwable $e) {
                Log::error('Provisioning driver failed to load', [
                    'module' => $module,
                    'error' => $e->getMessage(),
                ]);

                self::$loaded[$module] = false;

                return null;
            }

            self::$loaded[$module] = true;
        }

        return self::$loaded[$module] ? $module : null;
    }

    /**
     * Driver functions the module implements, keyed by their normalised name.
     *
     * @return array<string, string> normalised name => real function name
     */
    public function functionIndex(string $module): array
    {
        $path = $this->driverPath($module);

        if (! $this->hasDriver($module)) {
            return [];
        }

        $source = @file_get_contents($path);

        if ($source === false) {
            return [];
        }

        preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/', $source, $matches);

        $index = [];

        foreach ($matches[1] ?? [] as $function) {
            $index[$this->normalise($function)] = $function;
        }

        return $index;
    }

    /**
     * Resolve the real driver function name for a module action.
     */
    public function resolveFunction(string $module, string $action): ?string
    {
        $index = $this->functionIndex($module);

        if ($index === []) {
            return null;
        }

        $suffix = self::ACTION_FUNCTIONS[$action] ?? $this->studly($action);

        // Driver functions are prefixed with the module name, so match on the
        // `_<Suffix>` tail of every candidate.
        foreach ($index as $normalised => $real) {
            $parts = explode('_', ltrim($real, '_'));

            if (count($parts) > 1 && $this->normalise(end($parts)) === $this->normalise($suffix)) {
                return $real;
            }
        }

        if (isset($index[$this->normalise($module . '_' . $suffix)])) {
            return $index[$this->normalise($module . '_' . $suffix)];
        }

        Log::debug('Provisioning action not implemented by module', [
            'module' => $module,
            'action' => $action,
            'expected' => $suffix,
        ]);

        return null;
    }

    /**
     * Callable functions the module offers, in the shape the client area uses:
     * `{ type: 'default'|'custom', function, name, select }`.
     *
     * `_AllowFunction()` is consulted first; modules that do not implement it
     * fall back to the standard set, mirroring the original's `default` list.
     *
     * @return array<int, array{type:string, function:string, name:string, select:array, desc:string}>
     */
    public function availableFunctions(string $module): array
    {
        if ($module === '') {
            return [];
        }

        $declared = $this->allowFunction($module);

        if ($declared !== []) {
            return $declared;
        }

        $index = $this->functionIndex($module);
        $functions = [];

        foreach ($this->standardActions() as $action => $label) {
            $resolved = $this->resolveFunction($module, $action);

            if ($resolved === null) {
                continue;
            }

            $functions[] = [
                'type' => 'default',
                'function' => $action,
                'name' => $label,
                'desc' => '',
                'select' => [],
            ];
        }

        return $functions;
    }

    /**
     * Read `_AllowFunction()` from the driver.
     */
    protected function allowFunction(string $module): array
    {
        $this->loadDriver($module);
        $function = $this->resolveFunction($module, 'allow_function');

        if ($function === null || ! function_exists($function)) {
            return [];
        }

        try {
            $result = $function();
        } catch (\Throwable $e) {
            Log::warning('Provisioning module _AllowFunction() failed', [
                'module' => $module,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($result)) {
            return [];
        }

        $functions = [];

        foreach ($result as $key => $definition) {
            if (is_string($definition)) {
                $functions[] = [
                    'type' => 'default',
                    'function' => (string) $key,
                    'name' => $definition,
                    'desc' => '',
                    'select' => [],
                ];

                continue;
            }

            if (! is_array($definition)) {
                continue;
            }

            $functions[] = [
                'type' => (string) ($definition['type'] ?? 'default'),
                'function' => (string) ($definition['func'] ?? $definition['function'] ?? $key),
                'name' => (string) ($definition['name'] ?? $key),
                'desc' => (string) ($definition['desc'] ?? ''),
                'select' => (array) ($definition['select'] ?? []),
            ];
        }

        return $functions;
    }

    /**
     * The default action set (`_AllowFunction` fallback), in button order.
     */
    public function standardActions(): array
    {
        return [
            'on' => '开机',
            'off' => '关机',
            'reboot' => '重启',
            'hard_off' => '硬关机',
            'hard_reboot' => '硬重启',
            'bmc' => '重置BMC',
            'kvm' => 'KVM',
            'ikvm' => 'IKVM',
            'vnc' => 'VNC',
            'rescue' => '救援系统',
            'crack_pass' => '重置密码',
            'reinstall' => '重装系统',
            'status' => '获取状态',
        ];
    }

    /**
     * Client-area action => driver action name, used by the controller.
     */
    public function actionFunction(string $action): string
    {
        return self::ACTION_FUNCTIONS[$action] ?? $this->studly($action);
    }

    /**
     * Whether an action is listed in the `second_verify_action` setting.
     *
     * The original stores camelCase names (`hardOff`, `crackPass`), so both
     * spellings normalise to the same token.
     */
    public function requiresSecondVerify(string $action): bool
    {
        if ((int) Configuration::value('second_verify', 1) !== 1) {
            return false;
        }

        $raw = (string) Configuration::value('second_verify_action', '');

        if (trim($raw) === '') {
            return false;
        }

        $required = array_map(
            fn ($value) => $this->normalise(trim((string) $value)),
            array_filter(explode(',', $raw), fn ($value) => trim((string) $value) !== '')
        );

        return in_array($this->normalise($this->actionFunction($action)), $required, true)
            || in_array($this->normalise($action), $required, true);
    }

    /**
     * Begin a module call. Returns the envelope plus, when the action needs
     * 二次验证 and no code was supplied, the `needs_second_verify` flag.
     */
    public function dispatch(Host $host, string $action, bool $queue = false, array $extra = []): array
    {
        $driverAction = $this->actionFunction($action);

        if ($queue || $this->shouldQueue($driverAction)) {
            $row = ModuleQueue::create([
                'service_type' => ModuleQueue::SERVICE_HOST,
                'service_id' => $host->id,
                'module_name' => $this->moduleNameFor($host),
                'module_action' => $driverAction,
                'last_attempt' => 0,
                'last_attempt_error' => '',
                'num_retries' => 0,
                'completed' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ]);

            return [
                'status' => true,
                'msg' => '操作已加入队列',
                'data' => ['queue_id' => $row->id, 'queue' => true],
            ];
        }

        return $this->call($this->moduleNameFor($host), $action, $host, $extra);
    }

    /**
     * Execute one module action against the local driver or the supplier.
     *
     * @return array{status:bool, msg:string, data:mixed}
     */
    public function call(string $module, string $action, Host $host, array $extra = []): array
    {
        $driverAction = $this->actionFunction($action);

        if ($this->isUpstream($host)) {
            return $this->callUpstream($driverAction, $host, $extra);
        }

        if (! $this->hasDriver($module)) {
            return [
                'status' => false,
                'msg' => '该产品未配置服务器模块（' . ($module !== '' ? $module : '未设置') . '）',
                'data' => null,
            ];
        }

        $this->loadDriver($module);
        $function = $this->resolveFunction($module, $action);

        if ($function === null || ! function_exists($function)) {
            return [
                'status' => false,
                'msg' => '模块功能不存在：' . $driverAction,
                'data' => null,
            ];
        }

        $params = $this->buildParams($host, $extra);

        try {
            $result = $function($params);
        } catch (\Throwable $e) {
            Log::error('Provisioning driver threw', [
                'module' => $module,
                'function' => $function,
                'host' => $host->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => false, 'msg' => '模块执行异常：' . $e->getMessage(), 'data' => null];
        }

        $envelope = $this->normaliseResult($result);

        if ($envelope['status'] && isset(self::STATUS_EFFECTS[$driverAction])) {
            $host->markAs(self::STATUS_EFFECTS[$driverAction]);
        }

        return $envelope;
    }

    /**
     * Run a module action for several hosts, returning one envelope each.
     *
     * @param  array<int, int|string>  $hostIds
     * @return array<int|string, array{status:bool, msg:string, data:mixed}>
     */
    public function callBatch(array $hostIds, string $action, ?Client $client = null): array
    {
        $results = [];

        foreach ($hostIds as $hostId) {
            $host = Host::query()->find((int) $hostId);

            if ($host === null || ($client !== null && (int) $host->uid !== (int) $client->id)) {
                $results[$hostId] = ['status' => false, 'msg' => '产品不存在', 'data' => null];

                continue;
            }

            $results[$hostId] = $this->call($this->moduleNameFor($host), $action, $host);
        }

        return $results;
    }

    /**
     * Forward the action to the upstream supplier client.
     */
    protected function callUpstream(string $driverAction, Host $host, array $extra = []): array
    {
        $client = $this->supplierClient($host);

        if ($client === null) {
            return ['status' => false, 'msg' => '未找到可用的供应商接口', 'data' => null];
        }

        try {
            $result = match ($driverAction) {
                'CreateAccount' => $client->createAccount($host),
                'SuspendAccount' => $client->suspendAccount($host),
                'UnsuspendAccount' => $client->unsuspendAccount($host),
                'TerminateAccount' => $client->terminateAccount($host),
                'Renew' => $client->renewHost($host),
                'Status' => $client->hostStatus($host),
                'CrackPassword' => $client->changePassword($host, (string) ($extra['password'] ?? '')),
                'Reinstall' => $client->reinstall($host, (array) ($extra['options'] ?? [])),
                default => $client->request('post', '/v1/hosts/' . $host->id . '/module/' . $driverAction, $extra),
            };
        } catch (\Throwable $e) {
            Log::error('Supplier module call failed', [
                'host' => $host->id,
                'action' => $driverAction,
                'error' => $e->getMessage(),
            ]);

            return ['status' => false, 'msg' => '供应商接口调用失败：' . $e->getMessage(), 'data' => null];
        }

        $envelope = $this->normaliseResult($result);

        if ($envelope['status'] && isset(self::STATUS_EFFECTS[$driverAction])) {
            $host->markAs(self::STATUS_EFFECTS[$driverAction]);
        }

        return $envelope;
    }

    /**
     * The `App\Integrations\Upstream\SupplierClient` for an upstream-reseller
     * product, or null when the host is locally provisioned.
     */
    public function supplierClient(Host $host): ?object
    {
        $api = $this->supplierApi($host);

        if ($api === null) {
            return null;
        }

        // Only the `zjmf_api` / `v10` supplier types speak the /v1 protocol;
        // manual and resource-pool suppliers resolve to null.
        if (class_exists(\App\Integrations\Upstream\SupplierClientFactory::class)) {
            return \App\Integrations\Upstream\SupplierClientFactory::forApi($api);
        }

        if (! class_exists(\App\Integrations\Upstream\SupplierClient::class)) {
            return null;
        }

        try {
            return new \App\Integrations\Upstream\SupplierClient($api);
        } catch (\Throwable $e) {
            Log::error('Supplier client could not be constructed', [
                'api' => $api->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * `shd_zjmf_finance_api` row backing an upstream product.
     */
    public function supplierApi(Host $host): ?\App\Models\FinanceApi
    {
        $product = $host->product;

        if ($product === null) {
            return null;
        }

        if ((int) $product->zjmf_api_id > 0) {
            return \App\Models\FinanceApi::query()->find((int) $product->zjmf_api_id);
        }

        // Products imported per supplier carry the supplier as their
        // `server_group` when `api_type` is zjmf_api/resource.
        $group = ServerGroup::query()->find((int) $product->server_group);

        if ($group !== null) {
            $server = Server::query()->where('gid', $group->id)->first();

            if ($server !== null && (int) $server->server_type > 0) {
                return \App\Models\FinanceApi::query()->find((int) $server->server_type);
            }
        }

        return null;
    }

    public function isUpstream(Host $host): bool
    {
        $product = $host->product;

        return $product !== null && $product->isUpstream() && $this->supplierApi($host) !== null;
    }

    /**
     * Module identifier for a host: the product's `server_type`, falling back
     * to the server group / server row.
     */
    public function moduleNameFor(Host $host): string
    {
        $product = $host->product;

        if ($product !== null && trim((string) $product->server_type) !== '') {
            return (string) $product->server_type;
        }

        $server = $this->resolveServer($host);

        if ($server !== null && trim((string) $server->server_type) !== '') {
            return (string) $server->server_type;
        }

        $group = $product !== null ? ServerGroup::query()->find((int) $product->server_group) : null;

        return $group === null ? '' : (string) $group->type;
    }

    /**
     * The provisioning server a host is assigned to.
     */
    public function resolveServer(Host $host): ?Server
    {
        if ((int) $host->serverid > 0) {
            $server = Server::query()->find((int) $host->serverid);

            if ($server !== null) {
                return $server;
            }
        }

        $product = $host->product;

        if ($product === null || (int) $product->server_group <= 0) {
            return null;
        }

        return Server::query()
            ->where('gid', (int) $product->server_group)
            ->where('active', 1)
            ->where('disabled', 0)
            ->orderBy('id')
            ->first();
    }

    /**
     * Parameter bag handed to the provisioning driver.
     */
    public function buildParams(Host $host, array $extra = []): array
    {
        $product = $host->product;
        $server = $this->resolveServer($host);
        $client = $host->client;

        $options = [];

        for ($i = 1; $i <= 24; $i++) {
            $column = 'config_option' . $i;
            $options[$column] = $product === null ? '' : (string) $product->getAttribute($column);
        }

        $params = array_merge([
            'hostid' => (int) $host->id,
            'serviceid' => (int) $host->id,
            'clientsdetails' => [
                'userid' => (int) $host->uid,
                'id' => (int) $host->uid,
                'firstname' => $client?->username,
                'lastname' => $client?->username,
                'email' => $client?->email,
                'companyname' => $client?->companyname,
                'phonenumber' => $client?->phonenumber,
                'address1' => $client?->address1,
                'city' => $client?->city,
                'country' => $client?->country,
                'postcode' => $client?->postcode,
                'credit' => $client === null ? 0 : (float) $client->credit,
            ],
            'productid' => (int) $host->productid,
            'productname' => $product?->name,
            'producttype' => $product?->type,
            'domain' => (string) $host->domain,
            'username' => (string) $host->username,
            'password' => $this->decryptPassword((string) $host->password),
            'serviceusername' => (string) $host->username,
            'servicepassword' => $this->decryptPassword((string) $host->password),
            'dedicatedip' => (string) $host->dedicatedip,
            'assignedips' => (string) $host->assignedips,
            'ns1' => (string) $host->ns1,
            'ns2' => (string) $host->ns2,
            'port' => (int) $host->port,
            'billingcycle' => (string) $host->billingcycle,
            'amount' => (float) $host->amount,
            'firstpaymentamount' => (float) $host->firstpaymentamount,
            'regdate' => (int) $host->regdate,
            'nextduedate' => (int) $host->nextduedate,
            'domainstatus' => (string) $host->domainstatus,
            'server' => [
                'serverid' => (int) ($server->id ?? 0),
                'name' => $server?->name,
                'hostname' => $server?->hostname,
                'ipaddress' => $server?->ip_address,
                'username' => $server?->username,
                'password' => $this->decryptPassword((string) $server?->password),
                'accesshash' => (string) $server?->accesshash,
                'port' => (int) ($server->port ?: 0),
                'secure' => (int) ($server->secure ?? 0),
                'statusaddress' => $server?->status_address,
            ],
            'configoptions' => (array) ($extra['configoptions'] ?? []),
            'customfields' => (array) ($extra['customfields'] ?? []),
            'model' => $this->overrides($host),
        ], $options, $extra);

        unset($params['options']);

        return $params;
    }

    /**
     * Configurable options stored against a host, keyed by option id.
     *
     * `shd_host_config_options` holds one row per selected sub-option;
     * quantity options reuse the same row with a `qty`.
     */
    public function hostConfigOptions(Host $host): array
    {
        $rows = DB::table('host_config_options')->where('relid', $host->id)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->configid][] = [
                'optionid' => (int) $row->optionid,
                'qty' => (int) $row->qty,
            ];
        }

        return $grouped;
    }

    /**
     * Extract the driver's own overrides (reinstall port/format flags, OS
     * lists, console URLs) from the last client-area call.
     */
    protected function overrides(Host $host): array
    {
        $overrides = [];

        if (trim((string) $host->reinstall_info) !== '') {
            $decoded = json_decode((string) $host->reinstall_info, true);
            $overrides['reinstall_info'] = is_array($decoded) ? $decoded : (string) $host->reinstall_info;
        }

        return array_merge($overrides, [
            'show_last_act_message' => (int) $host->show_last_act_message,
            'os' => (string) $host->os,
            'os_url' => (string) $host->os_url,
        ]);
    }

    /**
     * Normalise whatever a driver returned.
     *
     * Drivers answer with either `['status' => true, 'msg' => '...']`,
     * `['status' => 'on', 'data' => [...]]` (console/status actions) or a bare
     * string/array.
     *
     * @return array{status:bool, msg:string, data:mixed}
     */
    public function normaliseResult(mixed $result): array
    {
        if (is_bool($result)) {
            return ['status' => $result, 'msg' => $result ? '操作成功' : '操作失败', 'data' => null];
        }

        if (is_string($result)) {
            $decoded = json_decode($result, true);

            if (is_array($decoded)) {
                return $this->normaliseResult($decoded);
            }

            return ['status' => $result !== '', 'msg' => $result !== '' ? $result : '操作失败', 'data' => null];
        }

        if (! is_array($result)) {
            return ['status' => false, 'msg' => '模块返回数据异常', 'data' => $result];
        }

        if ($result === []) {
            return ['status' => true, 'msg' => '操作成功', 'data' => null];
        }

        $rawStatus = $result['status'] ?? null;
        $msg = (string) ($result['msg'] ?? $result['message'] ?? '');

        if (is_array($rawStatus)) {
            return ['status' => true, 'msg' => $msg !== '' ? $msg : '操作成功', 'data' => $rawStatus];
        }

        if (is_string($rawStatus)) {
            // Power state ({status: 'on', des: '运行中'}) and progress payloads.
            $data = $result['data'] ?? [
                'status' => $rawStatus,
                'des' => (string) ($result['des'] ?? $msg),
            ];

            return ['status' => true, 'msg' => $msg !== '' ? $msg : '操作成功', 'data' => $data];
        }

        if (is_bool($rawStatus)) {
            return [
                'status' => $rawStatus,
                'msg' => $msg !== '' ? $msg : ($rawStatus ? '操作成功' : '操作失败'),
                'data' => $result['data'] ?? null,
            ];
        }

        return ['status' => true, 'msg' => $msg !== '' ? $msg : '操作成功', 'data' => $result];
    }

    /**
     * Whether the module declares the action queue-only.
     */
    protected function shouldQueue(string $driverAction): bool
    {
        if (! in_array($driverAction, self::QUEUEABLE, true)) {
            return false;
        }

        return (int) Configuration::value('module_queue_enable', 0) === 1;
    }

    /**
     * Execute pending rows from `shd_module_queue`.
     *
     * @return array{processed:int, completed:int, failed:int}
     */
    public function processQueue(int $limit = 20): array
    {
        $rows = ModuleQueue::query()
            ->where('completed', 0)
            ->where('service_type', ModuleQueue::SERVICE_HOST)
            ->where('num_retries', '<', 5)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $completed = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $host = Host::query()->find((int) $row->service_id);

            if ($host === null) {
                $row->completed = 1;
                $row->last_attempt_error = '产品不存在';
                $row->update_time = time();
                $row->save();

                continue;
            }

            $action = $this->actionForFunction((string) $row->module_action);
            $result = $this->call((string) $row->module_name, $action, $host);

            $row->last_attempt = time();
            $row->update_time = time();

            if ($result['status']) {
                $row->completed = 1;
                $row->last_attempt_error = '';
                $row->save();
                $completed++;

                continue;
            }

            $row->num_retries = (int) $row->num_retries + 1;
            $row->last_attempt_error = mb_substr($result['msg'], 0, 1000);
            $row->save();
            $failed++;
        }

        return ['processed' => $rows->count(), 'completed' => $completed, 'failed' => $failed];
    }

    /**
     * Reverse lookup: a driver action name back to a client-area action.
     */
    public function actionForFunction(string $driverAction): string
    {
        $needle = $this->normalise($driverAction);

        foreach (self::ACTION_FUNCTIONS as $action => $suffix) {
            if ($this->normalise($suffix) === $needle) {
                return $action;
            }
        }

        return $driverAction;
    }

    /**
     * The encryption helper the original uses for stored passwords.
     */
    protected function decryptPassword(?string $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (class_exists(\App\Support\Crypto::class)) {
            try {
                $decrypted = \App\Support\Crypto::decrypt($value);

                if (is_string($decrypted) && $decrypted !== '') {
                    return $decrypted;
                }
            } catch (\Throwable) {
                // Values stored before encryption was introduced stay usable.
            }
        }

        return $value;
    }

    protected function normalise(string $value): string
    {
        return strtolower(str_replace(['_', '-'], '', $value));
    }

    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}
