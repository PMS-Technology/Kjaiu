<?php

namespace App\Http\Controllers\Admin;

use App\Models\Host;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\ModuleRegistry;
use App\Services\ModuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 魔方DCIM / 魔方云 — the panel's own server-inventory views.
 *
 * These pages manage the DCIM side of the platform: the bare-metal server
 * inventory (`shd_dcim_servers`), the traffic packages sold against them
 * (`shd_dcim_flow_packet`) and the power/console actions. Where a host is
 * provisioned through a module the action is dispatched there, so the same
 * code path works for locally managed hardware and for upstream DCIM.
 */
class DcimController extends AdminController
{
    /** Power/console actions and the module action they map to. */
    private const POWER_ACTIONS = [
        'dcim/on' => 'on',
        'dcim/off' => 'off',
        'dcim/reboot' => 'reboot',
        'dcim/bmc' => 'bmc',
        'dcim/kvm' => 'kvm',
        'dcim/ikvm' => 'ikvm',
        'dcim/novnc' => 'vnc',
        'dcim/reinstall' => 'reinstall',
        'dcim/rescue' => 'rescue',
        'dcim/crack_pass' => 'crack_pass',
    ];

    /**
     * `GET dcim/server` — the DCIM inventory.
     *
     * Each `shd_dcim_servers` row extends one module server instance from
     * `shd_servers` with the billing/OS metadata DCIM needs.
     */
    public function serverList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 'dcim_servers.serverid', 'dcim_servers.reinstall_times', 'dcim_servers.buy_times', 'dcim_servers.reinstall_price', 'dcim_servers.auth', 'dcim_servers.api_status', 'dcim_servers.area', 'dcim_servers.os', 'dcim_servers.bill_type', 'dcim_servers.flow_remind', 'dcim_servers.ip_customid', 'dcim_servers.is_certifi', 's.name', 's.ip_address', 's.hostname', 's.type', 's.max_accounts', 's.disabled');

        $name = trim((string) $request->input('name', $request->input('keywords', '')));

        if ($name !== '') {
            $query->where(function ($q) use ($name) {
                $q->where('s.name', 'like', "%{$name}%")
                    ->orWhere('s.ip_address', 'like', "%{$name}%")
                    ->orWhere('s.hostname', 'like', "%{$name}%");
            });
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('dcim_servers.api_status', (int) $status);
        }

        if ($request->filled('area')) {
            $query->where('dcim_servers.area', 'like', '%'.$request->input('area').'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('dcim_servers.id')->forPage($page, $limit)->get();

        $list = $rows->map(fn ($row) => $this->dcimServerRow($row))->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST|PUT dcim/server` — add or edit a DCIM server.
     *
     * The address and module credentials live on `shd_servers`; this endpoint
     * writes both sides in one call.
     */
    public function serverSave(Request $request)
    {
        $id = (int) $request->input('id', 0);

        $serverId = (int) $request->input('serverid', 0);
        $module = (string) $request->input('module', $request->input('type', ''));
        $ip = trim((string) $request->input('ip', $request->input('ip_address', '')));

        DB::transaction(function () use ($request, $id, &$serverId, $module, $ip) {
            if ($serverId <= 0) {
                // A brand-new entry may bring its own server instance.
                $name = trim((string) $request->input('name', ''));

                if ($name === '' && $ip === '') {
                    throw new \InvalidArgumentException('服务器名称不能为空');
                }

                $serverId = (int) DB::table('servers')->insertGetId([
                    'gid' => (int) $request->input('gid', 0),
                    'name' => $name !== '' ? $name : $ip,
                    'ip_address' => $ip,
                    'hostname' => (string) $request->input('hostname', ''),
                    'max_accounts' => (int) $request->input('max_accounts', 0),
                    'username' => (string) $request->input('username', ''),
                    'password' => (string) $request->input('password', ''),
                    'port' => (int) $request->input('port', 0),
                    'secure' => (int) $request->input('secure', 0),
                    'active' => 1,
                    'disabled' => 0,
                    'type' => $module,
                    'server_type' => 'dcim',
                    'link_status' => 0,
                ]);
            } else {
                $update = [];

                if ($ip !== '') {
                    $update['ip_address'] = $ip;
                }

                foreach (['name', 'hostname', 'username', 'password'] as $field) {
                    if ($request->filled($field)) {
                        $update[$field] = (string) $request->input($field);
                    }
                }

                foreach (['max_accounts', 'port', 'secure', 'disabled'] as $field) {
                    if ($request->has($field)) {
                        $update[$field] = (int) $request->input($field);
                    }
                }

                if ($module !== '') {
                    $update['type'] = $module;
                }

                if ($update !== []) {
                    DB::table('servers')->where('id', $serverId)->update($update);
                }
            }

            $data = [
                'serverid' => $serverId,
                'reinstall_times' => (int) $request->input('reinstall_times', 0),
                'buy_times' => (int) $request->input('buy_times', 0),
                'reinstall_price' => $this->money((float) $request->input('reinstall_price', 0)),
                'auth' => (string) $request->input('auth', ''),
                'api_status' => (int) $request->input('api_status', $request->input('status', 0)),
                'area' => (string) $request->input('area', ''),
                'os' => is_array($request->input('os'))
                    ? implode(',', $request->input('os'))
                    : (string) $request->input('os', ''),
                'bill_type' => (string) $request->input('bill_type', ''),
                'flow_remind' => (string) $request->input('flow_remind', ''),
                'ip_customid' => (int) $request->input('ip_customid', 0),
                'is_certifi' => (string) $request->input('is_certifi', ''),
            ];

            if ($id > 0) {
                DB::table('dcim_servers')->where('id', $id)->update($data);
            } else {
                $id = (int) DB::table('dcim_servers')->insertGetId($data);
            }
        });

        $this->log('保存DCIM服务器 #'.$id, $id);

        return $this->ok(['id' => $id, 'serverid' => $serverId], '保存成功');
    }

    /**
     * `DELETE dcim/server` — remove DCIM entries.
     */
    public function serverDelete(Request $request)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        $serverIds = DB::table('dcim_servers')->whereIn('id', $ids)->pluck('serverid')->all();

        if (DB::table('host')->whereIn('serverid', $serverIds ?: [-1])->whereIn('domainstatus', Host::LIVE_STATUSES)->exists()) {
            return $this->fail('所选服务器上还有已开通的产品，不能删除');
        }

        DB::table('dcim_servers')->whereIn('id', $ids)->delete();

        $this->log('删除DCIM服务器：'.implode(',', $ids));

        return $this->ok(['ids' => $ids], '删除成功');
    }

    /**
     * `GET dcim/server/<id>` — one server's detail.
     */
    public function serverDetail(Request $request, $id)
    {
        $row = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 'dcim_servers.serverid', 'dcim_servers.reinstall_times', 'dcim_servers.buy_times', 'dcim_servers.reinstall_price', 'dcim_servers.auth', 'dcim_servers.api_status', 'dcim_servers.area', 'dcim_servers.os', 'dcim_servers.bill_type', 'dcim_servers.flow_remind', 'dcim_servers.ip_customid', 'dcim_servers.is_certifi', 's.name', 's.ip_address', 's.hostname', 's.type', 's.max_accounts', 's.username', 's.port', 's.secure')
            ->where('dcim_servers.id', (int) $id)
            ->first();

        if ($row === null) {
            return $this->notFound('服务器不存在');
        }

        $data = $this->dcimServerRow($row);
        // The edit form needs the login pair; the list deliberately omits it.
        $data['username'] = (string) ($row->username ?? '');
        $data['port'] = (int) ($row->port ?? 0);
        $data['secure'] = (int) ($row->secure ?? 0);
        $data['hosts'] = DB::table('host')->where('serverid', $row->serverid)->count();

        return $this->ok(['data' => $data, 'server' => $data]);
    }

    /**
     * `GET dcim/server/<id>/status` — refresh one server's power state.
     */
    public function refreshStatus(Request $request, $id)
    {
        $server = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 'dcim_servers.serverid', 'dcim_servers.api_status', 's.ip_address')
            ->where('dcim_servers.id', (int) $id)
            ->first();

        if ($server === null) {
            return $this->notFound('服务器不存在');
        }

        $status = $this->probePowerStatus((string) $server->ip_address);

        DB::table('dcim_servers')->where('id', (int) $id)->update([
            'api_status' => $status === 'on' ? 1 : 0,
        ]);

        return $this->ok(['id' => (int) $id, 'api_status' => $status === 'on' ? 1 : 0, 'power_status' => $status], '刷新成功');
    }

    /**
     * `GET dcim/server/status` — refresh the whole inventory.
     */
    public function refreshAllStatus(Request $request)
    {
        $servers = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 's.ip_address')
            ->get();

        $updated = 0;

        foreach ($servers as $server) {
            DB::table('dcim_servers')->where('id', $server->id)->update([
                'api_status' => $this->probePowerStatus((string) $server->ip_address) === 'on' ? 1 : 0,
            ]);

            $updated++;
        }

        $this->log('刷新DCIM服务器状态 '.$updated.' 台');

        return $this->ok(['count' => $updated], '刷新成功');
    }

    /**
     * `POST dcim/refresh_power_status` — the per-host variant used by the DCIM
     * inner page.
     */
    public function refreshPowerStatus(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        $dcimId = (int) DB::table('dcim_servers')->where('serverid', $host->serverid)->value('id');

        if ($dcimId <= 0) {
            return $this->fail('该产品未关联DCIM服务器');
        }

        return $this->refreshStatus($request, $dcimId);
    }

    /**
     * `POST dcim/assign` — bind a server to a host.
     */
    public function assignServer(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', $request->input('hostid', 0)));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $dcimId = (int) $request->input('dcimid', $request->input('id2', 0));
        $server = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 'dcim_servers.serverid', 's.ip_address', 's.name')
            ->where('dcim_servers.id', $dcimId)
            ->first();

        if ($server === null) {
            return $this->notFound('服务器不存在');
        }

        $host->serverid = (int) $server->serverid;
        $host->dcimid = (int) $server->id;

        if ((string) $server->ip_address !== '') {
            $host->dedicatedip = (string) $server->ip_address;
        }

        $host->update_time = time();
        $host->save();

        $this->log('分配DCIM服务器 #'.$server->id.' 给产品 #'.$host->id, (int) $host->id);

        return $this->ok(['id' => (int) $host->id, 'dcimid' => (int) $server->id, 'serverid' => (int) $server->serverid], '分配成功');
    }

    /**
     * `DELETE dcim/delete {id}` — release a host's server binding.
     */
    public function delete(Request $request)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        Host::query()->whereIn('id', $ids)->update([
            'dcimid' => 0,
            'serverid' => 0,
            'update_time' => time(),
        ]);

        $this->log('解除DCIM关联：'.implode(',', $ids));

        return $this->ok(['ids' => $ids], '操作成功');
    }

    /**
     * `GET dcim/detail {id}` — the DCIM inner page for a host.
     */
    public function detail(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('获取失败');
        }

        $server = $host->dcimid
            ? DB::table('dcim_servers')
                ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
                ->select('dcim_servers.id', 'dcim_servers.serverid', 'dcim_servers.reinstall_times', 'dcim_servers.buy_times', 'dcim_servers.reinstall_price', 'dcim_servers.auth', 'dcim_servers.api_status', 'dcim_servers.area', 'dcim_servers.os', 'dcim_servers.bill_type', 'dcim_servers.flow_remind', 'dcim_servers.ip_customid', 'dcim_servers.is_certifi', 's.name', 's.ip_address', 's.hostname', 's.type')
                ->where('dcim_servers.id', $host->dcimid)
                ->first()
            : null;

        return $this->ok([
            'host' => $host->toArray(),
            'server' => $server ? $this->dcimServerRow($server) : null,
            'traffic' => $this->trafficSummary((int) $host->id),
            'power_status' => (int) ($server->api_status ?? 0) === 1 ? 'on' : 'off',
            'os' => (string) $host->os,
        ]);
    }

    /**
     * `GET dcim/sales {id}` — the sellable server list for a product.
     */
    public function sales(Request $request)
    {
        $productId = (int) $request->input('id', $request->input('pid', 0));

        if ($productId > 0 && ! DB::table('products')->where('id', $productId)->exists()) {
            return $this->fail('该产品未选择接口');
        }

        $query = DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->select('dcim_servers.id', 'dcim_servers.serverid', 'dcim_servers.reinstall_times', 'dcim_servers.buy_times', 'dcim_servers.reinstall_price', 'dcim_servers.auth', 'dcim_servers.api_status', 'dcim_servers.area', 'dcim_servers.os', 'dcim_servers.bill_type', 'dcim_servers.flow_remind', 'dcim_servers.ip_customid', 'dcim_servers.is_certifi', 's.name', 's.ip_address', 's.hostname', 's.type')
            ->where('dcim_servers.api_status', 1);

        if ($request->filled('area')) {
            $query->where('dcim_servers.area', (string) $request->input('area'));
        }

        if ($request->boolean('only_free')) {
            $taken = DB::table('host')
                ->where('dcimid', '>', 0)
                ->whereIn('domainstatus', Host::LIVE_STATUSES)
                ->pluck('dcimid')
                ->all();

            $query->whereNotIn('dcim_servers.id', $taken ?: [-1]);
        }

        return $this->ok($query->orderBy('dcim_servers.id')->get()->map(fn ($row) => $this->dcimServerRow($row))->all());
    }

    /**
     * `GET dcim/traffic_usage {id}` — traffic consumed by a host.
     */
    public function trafficUsage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        return $this->ok($this->trafficSummary((int) $host->id));
    }

    /**
     * `POST dcim/traffic` — record a traffic overflow purchase.
     */
    public function traffic(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));

        DB::table('dcim_buy_record')->insert([
            'hostid' => (int) $host->id,
            'uid' => (int) $host->uid,
            'relid' => (int) $request->input('packet_id', 0),
            'name' => (string) $request->input('name', '流量包'),
            'price' => $amount,
            'capacity' => (int) $request->input('traffic', 0),
            'status' => 1,
            'type' => 1,
            'create_time' => time(),
        ]);

        $this->log('产品 #'.$host->id.' 购买流量包', (int) $host->id);

        return $this->ok(null, '购买成功');
    }

    /**
     * `GET dcim/download {id}` — the DCIM client download for a host.
     */
    public function download(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        $url = (string) (DB::table('dcim_servers')
            ->leftJoin('servers as s', 's.id', '=', 'dcim_servers.serverid')
            ->where('dcim_servers.id', $host->dcimid)
            ->value('s.status_address') ?? '');

        if ($url === '') {
            return $this->fail('该服务器未配置DCIM客户端下载地址');
        }

        return $this->ok(['url' => $url]);
    }

    /**
     * `POST dcim/on|off|reboot|bmc|kvm|ikvm|novnc|reinstall|rescue|crack_pass`
     *
     * All power and console actions share one handler; the module layer decides
     * what the action means for the host's driver.
     */
    public function powerAction(Request $request)
    {
        $path = trim($request->path(), '/');
        $action = self::POWER_ACTIONS[$path] ?? null;

        if ($action === null) {
            return $this->validationFail('不支持的操作');
        }

        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $extra = $request->except(['id']);

        try {
            $result = (new ModuleService())->dispatch($host, $action, false, is_array($extra) ? $extra : []);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage() ?: '操作失败');
        }

        $this->log('DCIM操作 '.$action.' 于产品 #'.$host->id, (int) $host->id);

        return $this->respond($result + $this->envelope());
    }

    /**
     * `GET dcim/resintall_status {id}` — reinstall progress.
     */
    public function reinstallStatus(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        return $this->ok([
            'status' => (int) $host->flag,
            'reinstall_info' => (string) $host->reinstall_info,
            'os' => (string) $host->os,
            'os_url' => (string) $host->os_url,
        ]);
    }

    /**
     * `POST dcim/cancel_task {id}` — abort a running reinstall.
     */
    public function cancelTask(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $host->flag = 0;
        $host->reinstall_info = '';
        $host->update_time = time();
        $host->save();

        $this->log('取消重装任务 产品 #'.$host->id, (int) $host->id);

        return $this->ok(null, '操作成功');
    }

    /**
     * `POST dcim/unsuspend_reinstall {id}` — resume a suspended reinstall.
     */
    public function unsuspendReinstall(Request $request)
    {
        return $this->cancelTask($request);
    }

    /**
     * `GET dcim/novnc {id}` — the console page metadata.
     */
    public function novncPage(Request $request)
    {
        $host = Host::query()->find((int) $request->input('id', 0));

        if ($host === null) {
            return $this->fail('ID错误');
        }

        return $this->ok([
            'id' => (int) $host->id,
            'url' => (string) (DB::table('servers')->where('id', $host->serverid)->value('status_address') ?? ''),
            'token' => md5($host->id.':'.$host->uid.':'.time()),
        ]);
    }

    /**
     * `GET dcim/flowpacket` — the traffic package catalogue.
     */
    public function flowPacketList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('dcim_flow_packet');
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->paginated($rows->map(fn ($r) => (array) $r)->all(), $total, $page, $limit);
    }

    /**
     * `GET dcim/flowpacket_page` / `GET dcim/flowpacket_page/<id>`
     */
    public function flowPacketPage(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));
        $row = $id > 0 ? DB::table('dcim_flow_packet')->where('id', $id)->first() : null;

        return $this->ok(['data' => $row ? (array) $row : null]);
    }

    /**
     * `POST|PUT dcim/flowpacket`
     */
    public function flowPacketSave(Request $request)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('名称不能为空');
        }

        $data = [
            'name' => $name,
            'capacity' => (int) $request->input('capacity', $request->input('flow', 0)),
            'price' => $this->money((float) $request->input('price', 0)),
            'stock' => (int) $request->input('stock', 0),
            'status' => (int) $request->input('status', 1),
            'allow_products' => is_array($request->input('allow_products'))
                ? implode(',', array_filter(array_map('intval', $request->input('allow_products'))))
                : (string) $request->input('allow_products', ''),
            'update_time' => time(),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('dcim_flow_packet')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        $data['create_time'] = time();
        $data['sale_times'] = 0;

        return $this->ok(['id' => (int) DB::table('dcim_flow_packet')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE dcim/flowpacket`
     */
    public function flowPacketDelete(Request $request)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('dcim_flow_packet')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => $ids], '删除成功');
    }

    /**
     * `GET dcim/buy_record` — traffic purchase history.
     */
    public function buyRecordList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('dcim_buy_record')
            ->leftJoin('clients as c', 'c.id', '=', 'dcim_buy_record.uid')
            ->leftJoin('host as h', 'h.id', '=', 'dcim_buy_record.hostid')
            ->select('dcim_buy_record.id', 'dcim_buy_record.uid', 'dcim_buy_record.relid', 'dcim_buy_record.name', 'dcim_buy_record.price', 'dcim_buy_record.status', 'dcim_buy_record.create_time', 'dcim_buy_record.pay_time', 'dcim_buy_record.capacity', 'dcim_buy_record.show_status', 'dcim_buy_record.invoiceid', 'dcim_buy_record.type', 'dcim_buy_record.hostid', 'c.username', 'h.domain');

        if ($uid = $request->input('uid')) {
            $query->where('dcim_buy_record.uid', (int) $uid);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('dcim_buy_record.id')->forPage($page, $limit)->get();

        return $this->paginated($rows->map(fn ($r) => (array) $r)->all(), $total, $page, $limit);
    }

    /**
     * `DELETE dcim/buy_record`
     */
    public function buyRecordDelete(Request $request)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('dcim_buy_record')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => $ids], '删除成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Flatten a joined `shd_dcim_servers` + `shd_servers` row.
     */
    private function dcimServerRow(object $row): array
    {
        $entry = (array) $row;
        $entry['status'] = (int) ($entry['api_status'] ?? 0);
        $entry['power_status'] = (int) ($entry['api_status'] ?? 0) === 1 ? 'on' : 'off';
        $entry['os_list'] = array_values(array_filter(array_map('trim', explode(',', (string) ($entry['os'] ?? '')))));
        $entry['module'] = (string) ($entry['type'] ?? '');
        $entry['module_name'] = $entry['module'] !== ''
            ? ModuleRegistry::label($entry['module'])
            : '';
        $entry['host_count'] = DB::table('host')->where('serverid', $entry['serverid'] ?? 0)->count();

        return $entry;
    }

    /**
     * The traffic figures shown on the DCIM inner page.
     */
    private function trafficSummary(int $hostId): array
    {
        $host = Host::query()->find($hostId);

        if ($host === null) {
            return ['usage' => 0, 'limit' => 0, 'percent' => 0];
        }

        $usage = (float) $host->bwusage;
        $limit = (int) $host->bwlimit;

        return [
            'usage' => $this->money($usage),
            'limit' => $limit,
            'percent' => $limit > 0 ? $this->money($usage / $limit * 100) : 0,
            'records' => DB::table('dcim_buy_record')->where('hostid', $hostId)->count(),
        ];
    }

    /**
     * Probe an out-of-band address. Without a vendor driver the reachability
     * of the management port is the only signal available, which is enough for
     * the inventory's status column.
     */
    private function probePowerStatus(string $ipmi): string
    {
        if ($ipmi === '') {
            return 'unknown';
        }

        $host = preg_replace('#^https?://#', '', $ipmi) ?: $ipmi;
        $connection = @fsockopen($host, 623, $errno, $errstr, 2);

        if (is_resource($connection)) {
            fclose($connection);

            return 'on';
        }

        $connection = @fsockopen($host, 443, $errno, $errstr, 2);

        if (is_resource($connection)) {
            fclose($connection);

            return 'on';
        }

        return 'off';
    }

    /**
     * @return array<int,int>
     */
    private function ids(Request $request): array
    {
        $ids = $request->input('id', $request->input('ids', []));

        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
