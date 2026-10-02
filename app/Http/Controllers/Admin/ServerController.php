<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AdminMeta;
use App\Services\Admin\ModuleRegistry;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 通用接口 (servers), 接口分组 (server groups) and the plugin/gateway list.
 *
 * A "server" is one configured instance of a provisioning module
 * (`shd_servers`); a "server group" (`shd_server_groups`) pools several of
 * them so a product can be assigned to whichever one has capacity.
 */
class ServerController extends AdminController
{
    /**
     * `GET servers_list {limit,page,search,gid}` — the 接口列表 tab.
     */
    public function list(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('servers')
            ->leftJoin('server_groups as g', 'g.id', '=', 'servers.gid')
            ->select('servers.id', 'servers.gid', 'servers.name', 'servers.ip_address', 'servers.assigned_ips', 'servers.hostname', 'servers.monthly_cost', 'servers.noc', 'servers.status_address', 'servers.name_server1', 'servers.name_server1_ip', 'servers.name_server2', 'servers.name_server2_ip', 'servers.name_server3', 'servers.name_server3_ip', 'servers.name_server4', 'servers.name_server4_ip', 'servers.name_server5', 'servers.name_server5_ip', 'servers.max_accounts', 'servers.username', 'servers.password', 'servers.accesshash', 'servers.secure', 'servers.port', 'servers.active', 'servers.disabled', 'servers.server_type', 'servers.link_status', 'servers.type', 'g.name as gname')
            ->orderByDesc('servers.id');

        $search = trim((string) $request->input('search', $request->input('keywords', '')));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('servers.name', 'like', "%{$search}%")
                    ->orWhere('servers.hostname', 'like', "%{$search}%")
                    ->orWhere('servers.ip_address', 'like', "%{$search}%")
                    ->orWhere('servers.type', 'like', "%{$search}%");
            });
        }

        if ($gid = $request->input('gid')) {
            $query->where('servers.gid', (int) $gid);
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['open_num'] = $row->max_accounts > 0
                ? (int) DB::table('host')->where('serverid', $row->id)->count().'/'.$row->max_accounts
                : '不限';
            $entry['module_name'] = ModuleRegistry::label((string) $row->type);

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'data' => $list,
            'list' => $list,
            'total' => $total,
            'count' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * `GET groups_list {limit,page}` — the 接口分组 tab.
     */
    public function groupList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('server_groups')->orderByDesc('id');
        $total = (clone $query)->count();
        $rows = $query->forPage($page, $limit)->get();

        $list = $rows->map(function ($group) {
            $entry = (array) $group;
            $entry['server_count'] = DB::table('server_groups_rel')->where('group_id', $group->id)->count();

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'data' => $list,
            'list' => $list,
            'total' => $total,
            'count' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * `GET servers_add` — the 添加接口 form metadata.
     */
    public function addPage(Request $request)
    {
        return $this->ok([
            'modules' => ModuleRegistry::modules(),
            'groups' => DB::table('server_groups')->orderBy('id')->get(['id', 'name', 'type', 'system_type'])->toArray(),
        ]);
    }

    /**
     * `POST servers_add_post {data, config}` — create a server.
     *
     * The module's own connection fields arrive as a nested `config` object,
     * which the original serialises into `username`/`password` on the row.
     */
    public function create(Request $request)
    {
        $data = $request->input('data', $request->all());
        $config = $request->input('config', []);

        if (! is_array($data)) {
            $data = $request->all();
        }

        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? '');

        if ($name === '') {
            return $this->validationFail('接口名称不能为空');
        }

        if ($type === '') {
            return $this->validationFail('请选择服务器模块');
        }

        // `username`/`accesshash` double as the module credential store.
        $credentials = $this->splitConfig(is_array($config) ? $config : []);

        $id = (int) DB::table('servers')->insertGetId([
            'gid' => (int) ($data['gid'] ?? 0),
            'name' => $name,
            'ip_address' => (string) ($data['ip_address'] ?? ''),
            'hostname' => (string) ($data['hostname'] ?? ''),
            'max_accounts' => (int) ($data['max_accounts'] ?? 0),
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'accesshash' => (string) ($data['accesshash'] ?? ''),
            'port' => (int) ($data['port'] ?? 0),
            'secure' => (int) ($data['secure'] ?? 0),
            'active' => 1,
            'disabled' => 0,
            'type' => $type,
            'server_type' => (string) ($data['server_type'] ?? ''),
            'link_status' => 0,
        ]);

        // When the server was created inside a group, wire the membership.
        if ((int) ($data['gid'] ?? 0) > 0) {
            $this->linkToGroup((int) $data['gid'], $id);
        }

        $this->log('添加接口：'.$name, $id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `GET edit_servers/<id>` — the edit form, with the module config fields
     * resolved from stored credentials.
     */
    public function editPage(Request $request, $id)
    {
        $server = DB::table('servers')->where('id', (int) $id)->first();

        if ($server === null) {
            return $this->notFound('接口不存在');
        }

        $config = json_decode((string) $server->password, true);
        $config = is_array($config) ? $config : [];

        $fields = ModuleRegistry::configFields((string) $server->type);

        foreach ($fields as $index => $field) {
            $fields[$index]['value'] = $config[$field['name']] ?? ($field['default'] ?? '');
        }

        $row = (array) $server;
        $row['password'] = '';
        $row['config'] = $config;

        return $this->ok([
            'data' => $row,
            'server' => $row,
            'config' => $fields,
            'modules' => ModuleRegistry::modules(),
            'groups' => DB::table('server_groups')->orderBy('id')->get(['id', 'name'])->toArray(),
        ]);
    }

    /**
     * `POST edit_servers_post {data}`
     */
    public function update(Request $request)
    {
        $data = $request->input('data', $request->all());
        $config = $request->input('config', []);

        if (! is_array($data)) {
            $data = $request->all();
        }

        $id = (int) ($data['id'] ?? 0);
        $server = DB::table('servers')->where('id', $id)->first();

        if ($server === null) {
            return $this->notFound('接口不存在');
        }

        $update = [];

        foreach (['name', 'ip_address', 'hostname', 'accesshash', 'server_type', 'assigned_ips', 'noc', 'status_address'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = (string) $data[$field];
            }
        }

        foreach (['gid', 'max_accounts', 'port', 'secure', 'active', 'disabled'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = (int) $data[$field];
            }
        }

        if (array_key_exists('type', $data) && $server->type === '') {
            $update['type'] = (string) $data['type'];
        }

        if (is_array($config) && $config !== []) {
            $credentials = $this->splitConfig($config, (string) $server->username);
            $update['username'] = $credentials['username'];
            $update['password'] = $credentials['password'];
        }

        if ($update !== []) {
            DB::table('servers')->where('id', $id)->update($update);
        }

        // Keep the group membership in step with the selected group.
        if (array_key_exists('gid', $data)) {
            $this->linkToGroup((int) $data['gid'], $id);
        }

        $this->log('编辑接口 #'.$id, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `GET delete_servers/<id>`
     */
    public function delete(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('host')->where('serverid', $id)->whereIn('domainstatus', ['Pending', 'Active', 'Suspended'])->exists()) {
            return $this->fail('该接口下还有产品/服务，不能删除');
        }

        DB::table('servers')->where('id', $id)->delete();
        DB::table('server_groups_rel')->where('server_id', $id)->delete();

        $this->log('删除接口 #'.$id);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET|POST get_modules_group {type}` — the module's config field list and
     * the groups a server of that type can join.
     */
    public function moduleGroup(Request $request)
    {
        $type = (string) $request->input('type', $request->input('data', ''));

        return $this->ok([
            'modules' => ModuleRegistry::modules(),
            'config' => ModuleRegistry::configFields($type),
            'groups' => DB::table('server_groups')
                ->when($type !== '', fn ($q) => $q->where(function ($w) use ($type) {
                    $w->where('type', $type)->orWhere('type', '');
                }))
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'system_type'])
                ->toArray(),
        ]);
    }

    /**
     * `GET server_test_link/<id>` — test connectivity to the server's module.
     */
    public function testConnection(Request $request, $id)
    {
        $server = DB::table('servers')->where('id', (int) $id)->first();

        if ($server === null) {
            return $this->notFound('接口不存在');
        }

        $module = (string) $server->type;

        if ($module === '' || ! ModuleRegistry::exists($module)) {
            DB::table('servers')->where('id', $id)->update(['link_status' => 0]);

            // Without a local driver there is nothing to dial; the address is
            // still validated so a typo is caught here rather than at
            // provisioning time.
            $host = (string) ($server->hostname ?: $server->ip_address);

            if ($host === '') {
                return $this->fail('接口地址未配置');
            }

            $port = (int) $server->port;

            if ($port <= 0) {
                return $this->fail('未安装该模块的对接驱动，无法测试连接');
            }

            $ok = $this->probe($host, $port);

            DB::table('servers')->where('id', $id)->update(['link_status' => $ok ? 1 : 0]);

            return $ok
                ? $this->ok(['link_status' => 1], '链接成功')
                : $this->fail('链接失败');
        }

        // A real driver is present: ask it to run its own connection test.
        try {
            $result = (new \App\Services\ModuleService())->call($module, 'test_connection', new \App\Models\Host(), [
                'server' => (array) $server,
            ]);
        } catch (\Throwable $e) {
            DB::table('servers')->where('id', $id)->update(['link_status' => 0]);

            return $this->fail($e->getMessage() ?: '链接失败');
        }

        $ok = ($result['status'] ?? 0) === ApiResponse::OK;
        DB::table('servers')->where('id', $id)->update(['link_status' => $ok ? 1 : 0]);

        return $this->respond($result + $this->envelope());
    }

    /**
     * `GET create_groups` — 添加接口分组 metadata.
     */
    public function createGroupPage(Request $request)
    {
        return $this->ok([
            'servers' => DB::table('servers')
                ->where('active', 1)
                ->get(['id', 'name', 'type', 'ip_address'])
                ->map(fn ($s) => (array) $s)
                ->all(),
            'modules' => ModuleRegistry::modules(),
            'mode' => [0 => '顺序分配', 1 => '负载均衡', 2 => '随机分配'],
        ]);
    }

    /**
     * `POST create_groups_post`
     */
    public function createGroup(Request $request)
    {
        return $this->writeGroup($request, 0);
    }

    /**
     * `GET edit_server_groups/<id>`
     */
    public function editGroup(Request $request, $id)
    {
        $group = DB::table('server_groups')->where('id', (int) $id)->first();

        if ($group === null) {
            return $this->notFound('接口分组不存在');
        }

        return $this->ok([
            'data' => (array) $group,
            'group' => (array) $group,
            'servers' => DB::table('servers')->orderBy('id')->get(['id', 'name', 'type', 'ip_address'])->toArray(),
            'selected' => DB::table('server_groups_rel')->where('group_id', $group->id)->pluck('server_id')->all(),
            'mode' => [0 => '顺序分配', 1 => '负载均衡', 2 => '随机分配'],
        ]);
    }

    /**
     * `POST edit_server_groups_post`
     */
    public function updateGroup(Request $request)
    {
        return $this->writeGroup($request, (int) $request->input('id', 0));
    }

    /**
     * `GET delete_server_groups/<id>`
     */
    public function deleteGroup(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('products')->where('server_group', $id)->exists()) {
            return $this->fail('该分组已被商品使用，不能删除');
        }

        DB::table('server_groups')->where('id', $id)->delete();
        DB::table('server_groups_rel')->where('group_id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET plugins` — the 插件/支付接口 table.
     */
    public function pluginList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('plugin');

        if ($type = $request->input('type')) {
            $query->where('type', (int) $type);
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('order')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'title' => $row->title,
            'description' => $row->description,
            'author' => $row->author,
            'version' => $row->version,
            'status' => (int) $row->status,
            'module' => $row->module,
            'type' => (int) $row->type,
            'has_admin' => (int) $row->has_admin,
            'help_url' => $row->help_url,
            'config' => json_decode((string) $row->config, true) ?: [],
        ])->all();

        return $this->okFlat('请求成功', [
            'data' => $list,
            'list' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `GET pl_index/<module>` — one plugin's detail.
     */
    public function pluginIndex(Request $request, $module)
    {
        $row = DB::table('plugin')->where('name', $module)->first();

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        return $this->ok([
            'plugin' => (array) $row,
            'config' => $this->pluginConfig($row),
        ]);
    }

    /**
     * `POST pl_install` — mark a plugin installed / enabled.
     */
    public function pluginInstall(Request $request)
    {
        return $this->togglePlugin($request, true);
    }

    /**
     * `POST pl_uninstall`
     */
    public function pluginUninstall(Request $request)
    {
        return $this->togglePlugin($request, false);
    }

    /**
     * `POST pl_toggle` — flip the enabled flag and refresh the gateway cache.
     */
    public function pluginToggle(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('plugin')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        $status = $request->has('status')
            ? (int) $request->input('status')
            : ((int) $row->status === 1 ? 0 : 1);

        DB::table('plugin')->where('id', $id)->update(['status' => $status]);
        $this->syncGateway($row->name, $status);

        $this->log(($status === 1 ? '启用' : '禁用').'插件：'.$row->title, $id);

        return $this->ok(['status' => $status], '操作成功');
    }

    /**
     * `POST pl_copy` — copy one gateway's configuration to another.
     */
    public function pluginCopy(Request $request)
    {
        $targetId = (int) $request->input('id', $request->input('newGatewayId', 0));
        $sourceId = (int) $request->input('copy_id', $request->input('copyId', 0));

        $target = DB::table('plugin')->where('id', $targetId)->first();
        $source = DB::table('plugin')->where('id', $sourceId)->first();

        if ($target === null || $source === null) {
            return $this->notFound('插件不存在');
        }

        DB::table('plugin')->where('id', $targetId)->update([
            'config' => $source->config,
        ]);

        $this->log('复制插件配置：'.$source->title.' → '.$target->title, $targetId);

        return $this->ok(null, '复制成功');
    }

    /**
     * `GET pl_setting/<type>/<id>` — a gateway's config form.
     */
    public function pluginSetting(Request $request, $type = null, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));
        $row = $id > 0 ? DB::table('plugin')->where('id', $id)->first() : null;

        if ($row === null) {
            $row = DB::table('plugin')->where('name', (string) $type)->first();
        }

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        return $this->ok([
            'plugin' => (array) $row,
            'config' => $this->pluginConfig($row),
        ]);
    }

    /**
     * `GET oauth` — the third-party login plugin list.
     */
    public function oauthList(Request $request)
    {
        $rows = DB::table('plugin')->where('module', 'oauth')->orWhere('type', 2)->orderBy('order')->get();

        return $this->okFlat('请求成功', [
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $rows->count(),
        ]);
    }

    /**
     * `GET oauth/config` — the OAuth plugin configuration form.
     */
    public function oauthConfig(Request $request)
    {
        $name = (string) $request->input('name', '');
        $row = $name !== ''
            ? DB::table('plugin')->where('name', $name)->first()
            : DB::table('plugin')->where('module', 'oauth')->first();

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        $stored = json_decode((string) $row->config, true);

        return $this->ok([
            'plugin' => (array) $row,
            'config' => [
                ['name' => 'appid', 'type' => 'text', 'title' => 'AppID', 'value' => $stored['appid'] ?? ''],
                ['name' => 'appsecret', 'type' => 'password', 'title' => 'AppSecret', 'value' => $stored['appsecret'] ?? ''],
                ['name' => 'callback', 'type' => 'text', 'title' => '回调地址', 'value' => $stored['callback'] ?? url('/oauth/callback/'.$row->name)],
            ],
        ]);
    }

    /**
     * `POST pl_sort/<module>` — reorder the plugin list.
     */
    public function pluginSort(Request $request, $module = null)
    {
        $ids = $request->input('ids', $request->input('data', []));

        if (! is_array($ids)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($ids) as $index => $id) {
            $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

            if ($id > 0) {
                DB::table('plugin')->where('id', $id)->update(['order' => $index]);
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST pl_setting_post` — save a gateway's configuration.
     */
    public function pluginSettingPost(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('plugin')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        $config = $request->input('config', $request->input('data', []));

        if (is_array($config)) {
            DB::table('plugin')->where('id', $id)->update([
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->log('保存插件配置：'.$row->title, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Create/update a server group, including its member servers.
     */
    private function writeGroup(Request $request, int $id)
    {
        $name = trim((string) $request->input('group_name', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('接口分组名不能为空');
        }

        $data = [
            'name' => $name,
            'type' => (string) $request->input('type', ''),
            'system_type' => (string) $request->input('system_type', 'normal'),
            'mode' => (int) $request->input('mode', 0),
            'capacity' => (int) $request->input('capacity', 0),
        ];

        if ($id > 0) {
            DB::table('server_groups')->where('id', $id)->update($data);
        } else {
            $id = (int) DB::table('server_groups')->insertGetId($data);
        }

        $servers = $request->input('sid', $request->input('servers', []));
        $servers = is_array($servers) ? $servers : explode(',', (string) $servers);

        if ($servers !== []) {
            DB::table('server_groups_rel')->where('group_id', $id)->delete();

            foreach (array_unique(array_filter(array_map('intval', $servers))) as $sid) {
                DB::table('server_groups_rel')->insert(['group_id' => $id, 'server_id' => $sid]);
                DB::table('servers')->where('id', $sid)->update(['gid' => $id]);
            }
        }

        $this->log('保存接口分组：'.$name, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * Membership helper used when a server is created or re-assigned.
     */
    private function linkToGroup(int $gid, int $serverId): void
    {
        DB::table('server_groups_rel')->where('server_id', $serverId)->delete();

        if ($gid > 0) {
            DB::table('server_groups_rel')->insert(['group_id' => $gid, 'server_id' => $serverId]);
        }
    }

    /**
     * Split a module config object into the `username`/`password` slots the
     * `shd_servers` schema provides for credential storage.
     *
     * @return array{username:string,password:string}
     */
    private function splitConfig(array $config, string $existingUsername = ''): array
    {
        $username = (string) ($config['username'] ?? $existingUsername);
        $password = $config['password'] ?? '';

        // Everything that is not the login pair is kept in the password blob
        // as JSON, which is how the original round-trips module settings.
        $rest = $config;
        unset($rest['username'], $rest['password']);

        if ($username === '' && isset($config['hostname'])) {
            $username = (string) $config['hostname'];
        }

        // Non-credential settings round-trip as JSON in `password`, which is
        // the slot the original uses for module-specific configuration.
        if (is_string($password) && $password !== '') {
            $rest['__password__'] = $password;
        }

        return [
            'username' => $username,
            'password' => (string) json_encode($rest, JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Best-effort TCP probe used when no driver is installed.
     */
    private function probe(string $host, int $port): bool
    {
        $host = preg_replace('#^https?://#', '', $host) ?: $host;

        $connection = @fsockopen($host, $port, $errno, $errstr, 3);

        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return false;
    }

    /**
     * Enabled/disabled gateway rows mirror the plugin table.
     */
    private function togglePlugin(Request $request, bool $enable)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('plugin')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('插件不存在');
        }

        DB::table('plugin')->where('id', $id)->update(['status' => $enable ? 1 : 0]);
        $this->syncGateway($row->name, $enable ? 1 : 0);

        $this->log(($enable ? '安装' : '卸载').'插件：'.$row->title, $id);

        return $this->ok(['status' => $enable ? 1 : 0], '操作成功');
    }

    /**
     * Keep `shd_payment_gateways` in step with the plugin switch so the
     * client-area gateway list stays accurate.
     */
    private function syncGateway(string $name, int $status): void
    {
        // `shd_payment_gateways` holds one row per enabled gateway, where
        // `value` is the display title and `setting` the JSON config blob.
        $exists = DB::table('payment_gateways')->where('gateway', $name)->exists();

        if ($status === 0) {
            DB::table('payment_gateways')->where('gateway', $name)->delete();

            return;
        }

        if ($exists) {
            return;
        }

        $title = (string) (DB::table('plugin')->where('name', $name)->value('title') ?? $name);

        DB::table('payment_gateways')->insert([
            'gateway' => $name,
            'setting' => (string) json_encode([], JSON_UNESCAPED_UNICODE),
            'value' => $title,
            'order' => 0,
        ]);
    }

    /**
     * The config fields a gateway plugin declares.
     */
    private function pluginConfig(object $row): array
    {
        $stored = json_decode((string) $row->config, true);
        $stored = is_array($stored) ? $stored : [];

        return [
            ['name' => 'merchant_id', 'type' => 'text', 'title' => '商户号', 'value' => $stored['merchant_id'] ?? ''],
            ['name' => 'key', 'type' => 'text', 'title' => '密钥', 'value' => $stored['key'] ?? ''],
            ['name' => 'appid', 'type' => 'text', 'title' => 'APPID', 'value' => $stored['appid'] ?? ''],
            ['name' => 'appsecret', 'type' => 'password', 'title' => 'APPSECRET', 'value' => $stored['appsecret'] ?? ''],
            ['name' => 'notify_url', 'type' => 'text', 'title' => '异步通知地址', 'value' => $stored['notify_url'] ?? url('/pay/notify/'.$row->name)],
            ['name' => 'return_url', 'type' => 'text', 'title' => '同步跳转地址', 'value' => $stored['return_url'] ?? url('/pay/return/'.$row->name)],
        ];
    }
}
