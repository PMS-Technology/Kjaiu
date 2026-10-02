<?php

namespace App\Http\Controllers\Web;

use App\Models\Host;
use App\Services\OrderService;
use App\Support\StatusMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provisioning endpoints driven by the client area's module buttons.
 *
 * Every button on the service detail page posts to one of five URLs. When the
 * provisioning workstream has registered `App\Services\ModuleService` the call
 * is dispatched through it; otherwise the action is queued as a
 * `shd_module_queue` row so the cron runner performs it, which is what the
 * original does for slower modules anyway.
 */
class ProvisionController extends WebController
{
    /** Buttons whose only job is to report state. */
    protected const READ_ONLY_FUNCS = ['status', 'trafficusage', 'get_config', 'sync'];

    /**
     * POST /provision/default
     *
     * Body: id (one id or a list of ids), func, code.
     */
    public function default(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $func = trim((string) $request->input('func', ''));
        $ids = $this->hostIds($request);

        if ($func === '') {
            return $this->fail('缺少操作类型');
        }

        if ($ids === []) {
            return $this->fail('缺少产品ID');
        }

        $hosts = $this->ownedHosts($client->id, $ids);

        if ($hosts->isEmpty()) {
            return $this->fail('产品不存在');
        }

        // The list page posts every host id at once for a bulk power state
        // read and expects `data[id]` keyed by host.
        if ($func === 'status' && count($ids) > 1) {
            return $this->ok($this->bulkStatus($hosts));
        }

        if ($this->secondVerifyRequired($func)) {
            $check = $this->checkSecondVerify($request, $func);

            if ($check !== null) {
                return $check;
            }
        }

        $results = [];
        $lastMessage = '操作成功';

        foreach ($hosts as $host) {
            $result = $this->run($host, $func, $request);
            $results[$host->id] = $result;
            $lastMessage = $result['msg'] ?: $lastMessage;

            if ($result['status'] !== 200) {
                return $this->ok($results, $lastMessage);
            }
        }

        return $this->ok($results, $lastMessage);
    }

    /**
     * POST /provision/custom/{id} — a module-supplied button on one host.
     */
    public function custom(Request $request, int $id): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->ownedHosts($client->id, [$id])->first();

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $func = trim((string) $request->input('func', ''));

        if ($func === '') {
            return $this->fail('缺少操作类型');
        }

        if ($this->secondVerifyRequired($func)) {
            $check = $this->checkSecondVerify($request, $func);

            if ($check !== null) {
                return $check;
            }
        }

        return $this->ok($this->run($host, $func, $request), '操作成功');
    }

    /**
     * POST /provision/button — a module button that is not tied to a host
     * (the software page's 重置授权 is the only observed caller).
     */
    public function button(Request $request): JsonResponse
    {
        $this->requireClient();
        $func = trim((string) $request->input('func', ''));
        $ids = $this->hostIds($request);

        if ($func === '') {
            return $this->fail('缺少操作类型');
        }

        return $this->ok([
            'func' => $func,
            'id' => $ids,
            'result' => $this->dispatch(null, $func, $request),
        ]);
    }

    /**
     * GET /provision/custom/content?id=<host>&key=<tab>
     *
     * HTML fragment injected into a custom detail-page tab.
     */
    public function customContent(Request $request): string
    {
        $client = $this->requireClient();
        $hostId = (int) $request->input('id', 0);
        $key = (string) $request->input('key', '');

        $host = $this->ownedHosts($client->id, [$hostId])->first();

        if ($host === null) {
            return '<div class="p-6 text-sm text-slate-500">产品不存在</div>';
        }

        $content = $this->dispatch($host, '__tab__', $request, ['key' => $key]);

        $body = is_array($content) ? (string) ($content['content'] ?? '') : (string) $content;

        if (trim($body) === '') {
            return '<div class="p-6 text-sm text-slate-500">该模块未提供此选项卡内容</div>';
        }

        return $body;
    }

    /**
     * GET /provision/chart/{id} — chart data for a module tab.
     */
    public function chart(Request $request, int $id): JsonResponse
    {
        $client = $this->requireClient();
        $host = $this->ownedHosts($client->id, [$id])->first();

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $result = $this->dispatch($host, 'chart', $request);

        return $this->ok(is_array($result) ? $result : ['list' => []]);
    }

    /**
     * POST /provision/sslCertFunc — SSL certificate operations.
     */
    public function sslCertFunc(Request $request): JsonResponse
    {
        $client = $this->requireClient();
        $id = (int) $request->input('id', 0);
        $host = $this->ownedHosts($client->id, [$id])->first();

        if ($host === null) {
            return $this->fail('产品不存在');
        }

        $func = (string) $request->input('func', 'issueBeforeCheckInfo');

        return $this->ok($this->dispatch($host, 'ssl_' . $func, $request, $request->all()));
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Perform one module action, through ModuleService when it exists.
     *
     * @return array<string, mixed>
     */
    protected function run(Host $host, string $func, Request $request): array
    {
        $result = $this->dispatch($host, $func, $request);

        if (is_array($result) && isset($result['status'])) {
            return $result;
        }

        $queued = $this->queue($host, $func);
        $label = $this->funcLabel($func);

        return [
            'status' => 200,
            'msg' => $queued
                ? "{$label}已提交，系统将自动执行"
                : "{$label}已提交",
            'data' => ['func' => $func, 'queued' => $queued],
        ];
    }

    /**
     * Hand the action to `App\Services\ModuleService` when that class is
     * registered; return null so the caller can fall back to the queue.
     */
    protected function dispatch(?Host $host, string $func, Request $request, array $extra = []): mixed
    {
        if (! class_exists(\App\Services\ModuleService::class)) {
            return null;
        }

        try {
            /** @var object $service */
            $service = app(\App\Services\ModuleService::class);
            $payload = array_merge($request->all(), $extra, ['func' => $func]);

            foreach (['dispatch', 'call', 'execute', 'run'] as $method) {
                if (method_exists($service, $method)) {
                    return $service->{$method}($host, $func, $payload);
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 400,
                'msg' => '模块调用失败：' . $e->getMessage(),
                'data' => null,
            ];
        }

        return null;
    }

    /**
     * Queue a module call for the cron runner.
     */
    protected function queue(Host $host, string $func): bool
    {
        if (in_array($func, self::READ_ONLY_FUNCS, true) || str_starts_with($func, '__')) {
            return false;
        }

        try {
            (new OrderService())->queueProvisioning($host, $this->moduleAction($func));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Client-area button name -> module action name.
     */
    protected function moduleAction(string $func): string
    {
        return match ($func) {
            'on' => 'On',
            'off' => 'Off',
            'reboot' => 'Reboot',
            'hard_off' => 'HardOff',
            'hard_reboot' => 'HardReboot',
            'crack_pass' => 'CrackPassword',
            'reinstall' => 'Reinstall',
            'rescue' => 'Rescue',
            'rescue_system' => 'RescueSystem',
            'vnc' => 'Vnc',
            'bmc' => 'Bmc',
            'kvm' => 'Kvm',
            'ikvm' => 'Ikvm',
            'resetLicense' => 'ResetLicense',
            default => ucfirst($func),
        };
    }

    /**
     * Chinese label for a button, used in the queued-action message.
     */
    protected function funcLabel(string $func): string
    {
        return match ($func) {
            'on' => '开机',
            'off' => '关机',
            'reboot' => '重启',
            'hard_off' => '硬关机',
            'hard_reboot' => '硬重启',
            'crack_pass' => '重置密码',
            'reinstall' => '重装系统',
            'rescue' => '救援模式',
            'rescue_system' => '救援系统',
            'resetLicense' => '重置授权',
            default => '操作',
        };
    }

    /**
     * Power state for a batch of hosts, keyed by host id.
     *
     * @param  \Illuminate\Support\Collection<int, Host>  $hosts
     */
    protected function bulkStatus($hosts): array
    {
        $payload = [];

        foreach ($hosts as $host) {
            $state = $this->powerStatus($host);

            $payload[$host->id] = [
                'status' => 200,
                'data' => [
                    'status' => $state,
                    'des' => StatusMap::POWER_STATUS[$state] ?? '未知',
                ],
            ];
        }

        return $payload;
    }

    /**
     * Derive a power state from the record when no module reports one.
     */
    protected function powerStatus(Host $host): string
    {
        if ($this->dispatch($host, 'status', request()) !== null) {
            return 'unknown';
        }

        return match ((string) $host->domainstatus) {
            Host::STATUS_ACTIVE => 'on',
            Host::STATUS_SUSPENDED => 'off',
            Host::STATUS_PENDING => 'process',
            default => 'unknown',
        };
    }

    /**
     * @return array<int, int>
     */
    protected function hostIds(Request $request): array
    {
        $raw = $request->input('id', []);

        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw), fn ($id) => $id > 0));
    }

    /**
     * Hosts in the requested set that belong to the signed-in client.
     *
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Host>
     */
    protected function ownedHosts(int $clientId, array $ids)
    {
        return Host::query()
            ->where('uid', $clientId)
            ->whereIn('id', $ids)
            ->get();
    }

    protected function secondVerifyRequired(string $func): bool
    {
        return \App\Support\Settings::needsSecondVerify($func);
    }

    /**
     * Check the 二次验证 code attached to a sensitive module action.
     */
    protected function checkSecondVerify(Request $request, string $func): ?JsonResponse
    {
        $client = $this->client();
        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            return $this->fail('请输入二次验证码', 1002);
        }

        $account = (string) ($client?->email ?: $client?->phonenumber);
        $ok = (new \App\Services\VerifyCodeService())->consume('second_verify:' . $func, $account, $code);

        return $ok ? null : $this->fail('二次验证码错误');
    }
}
