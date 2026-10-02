<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Small settings-adjacent endpoints that do not belong to a single big tab:
 * 支付接口 (gateway enable/configure), 插件, and the misc config readers the
 * SPA calls from the page shell.
 */
class ConfigController extends AdminController
{
    /**
     * `GET common/info_notice` — the announcement banner shown on the
     * dashboard.
     */
    public function infoNotice(Request $request)
    {
        $rows = DB::table('info_notice')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        return $this->ok([
            'list' => $rows,
            'data' => $rows,
            'notice' => $rows,
        ]);
    }

    /**
     * `POST info_notice` — publish a notice.
     */
    public function infoNoticeSave(Request $request)
    {
        $info = trim((string) $request->input('info', ''));

        if ($info === '') {
            return $this->validationFail('内容不能为空');
        }

        $id = (int) DB::table('info_notice')->insertGetId([
            'relid' => (int) $request->input('relid', 0),
            'type' => (string) $request->input('type', 'admin'),
            'info' => $info,
            'admin' => $this->adminName(),
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `DELETE info_notice/<id>`
     */
    public function infoNoticeDelete(Request $request, $id)
    {
        DB::table('info_notice')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET config_general/lang/list` — installed languages (also served by the
     * auth controller's `common` payload).
     */
    public function languageList(Request $request)
    {
        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
            'lang' => AdminMeta::LANGUAGES,
        ] + $this->envelope());
    }

    /**
     * `GET config_general/gateway/list` — the 支付接口 tab rows, merged from
     * `shd_plugin` and `shd_payment_gateways`.
     */
    public function gatewayList(Request $request)
    {
        $plugins = DB::table('plugin')
            ->where('type', 1)
            ->orderBy('order')
            ->get();

        $enabled = DB::table('payment_gateways')->pluck('value', 'gateway');

        $rows = $plugins->map(function ($plugin) use ($enabled) {
            $config = json_decode((string) $plugin->config, true);

            return [
                'id' => (int) $plugin->id,
                'name' => $plugin->name,
                'title' => $plugin->title,
                'description' => $plugin->description,
                'author' => $plugin->author,
                'version' => $plugin->version,
                'status' => (int) $plugin->status,
                'module' => $plugin->module,
                'enabled' => $enabled->has($plugin->name),
                'config' => is_array($config) ? $config : [],
            ];
        })->all();

        return $this->okFlat('请求成功', [
            'data' => $rows,
            'list' => $rows,
            'total' => count($rows),
        ]);
    }

    /**
     * `GET config_general/source_api` — 资源API settings (identical payload to
     * `config_general/apiconfig`, reached from the other menu entry).
     */
    public function sourceApi(Request $request)
    {
        return $this->ok(SettingService::group('apiconfig'));
    }
}
