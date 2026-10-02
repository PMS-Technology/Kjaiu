<?php

namespace App\Http\Controllers\Admin;

use App\Models\Contract;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 站务 extras that do not fit the other controllers: 菜单管理 (`menus/*`),
 * 合同管理 (`contract/*`) and 高级选项 (`advanced_options/*`).
 */
class MenuController extends AdminController
{
    // -----------------------------------------------------------------
    // 菜单管理
    // -----------------------------------------------------------------

    /**
     * `POST menus/getMenuList` / `POST menus/getMenu` — the menu tree.
     */
    public function getMenuList(Request $request)
    {
        $rows = DB::table('menu')->orderBy('id')->get();

        return $this->ok([
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    /**
     * `POST menus/getMenu`
     */
    public function getMenu(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('menu')->where('id', $id)->first() : null;

        return $this->ok($row ? (array) $row : null);
    }

    /**
     * `POST menus/getMenuType` / `POST menus/getNavType` — menu type options.
     */
    public function getMenuType(Request $request)
    {
        return $this->ok([
            ['value' => 1, 'name' => '自定义链接'],
            ['value' => 2, 'name' => '商品分类'],
            ['value' => 3, 'name' => '单页'],
            ['value' => 4, 'name' => '系统菜单'],
        ]);
    }

    /**
     * `POST menus/getTypeAllMenu` — everything the builder can attach.
     */
    public function getTypeAllMenu(Request $request)
    {
        return $this->ok([
            'menu' => DB::table('menu')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'nav' => DB::table('nav')->orderBy('order')->get()->map(fn ($r) => (array) $r)->all(),
            'nav_group' => DB::table('nav_group')->orderBy('order')->get()->map(fn ($r) => (array) $r)->all(),
            'product_groups' => DB::table('product_groups')->orderBy('order')->get(['id', 'name'])->toArray(),
        ]);
    }

    /**
     * `POST menus/getProductList` — products attachable to a menu entry.
     */
    public function getProductList(Request $request)
    {
        return $this->ok(AdminMeta::productList());
    }

    /**
     * `POST menus/getLang` — language options for a menu label.
     */
    public function getLang(Request $request)
    {
        return $this->ok(AdminMeta::LANGUAGES);
    }

    /**
     * `POST menus/getSystemNav` / `POST menus/getOtherMenu` / `getDefaultSenior`
     */
    public function getSystemNav(Request $request)
    {
        return $this->ok([
            'nav' => DB::table('nav')->where('plugin', '')->orWhereNull('plugin')->orderBy('order')->get()->map(fn ($r) => (array) $r)->all(),
            'menu' => DB::table('menu')->get()->map(fn ($r) => (array) $r)->all(),
        ]);
    }

    public function getOtherMenu(Request $request)
    {
        return $this->getSystemNav($request);
    }

    public function getDefaultSenior(Request $request)
    {
        return $this->ok(['senior' => 0, 'nav' => DB::table('nav')->orderBy('order')->get()->map(fn ($r) => (array) $r)->all()]);
    }

    /**
     * `POST menus/addMenu` / `POST menus/editMenu`
     */
    public function addMenu(Request $request)
    {
        return $this->writeMenu($request, (int) $request->input('id', 0));
    }

    public function editMenu(Request $request)
    {
        return $this->writeMenu($request, (int) $request->input('id', 0));
    }

    /**
     * `POST menus/delMenu` / `POST menus/delTwoMenu`
     */
    public function delMenu(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('nav')->whereIn('pid', $ids)->delete();
        DB::table('nav')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    public function delTwoMenu(Request $request)
    {
        return $this->delMenu($request);
    }

    /**
     * `POST menus/addCustomPage` / `POST menus/addProductPage` — shortcuts that
     * create a `shd_nav` entry pointing at a generated page.
     */
    public function addCustomPage(Request $request)
    {
        return $this->writeMenu($request, 0, 3);
    }

    public function addProductPage(Request $request)
    {
        return $this->writeMenu($request, 0, 2);
    }

    /**
     * `POST menus/setNavList` / `POST menus/setWebNavList` — persist a
     * reordered menu tree in one call.
     */
    public function setNavList(Request $request)
    {
        $rows = $request->input('data', $request->input('list', $request->input('nav', [])));

        if (! is_array($rows)) {
            return $this->validationFail('参数错误');
        }

        $this->applyNavTree($rows);

        $this->log('保存导航菜单');

        return $this->ok(null, '保存成功');
    }

    public function setWebNavList(Request $request)
    {
        return $this->setNavList($request);
    }

    /**
     * `POST menus/editMenuActive` — toggle whether a menu entry is shown.
     */
    public function editMenuActive(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('nav')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('菜单不存在');
        }

        DB::table('nav')->where('id', $id)->update([
            'menu_type' => (int) $row->menu_type === 1 ? 0 : 1,
        ]);

        return $this->ok(['id' => $id], '操作成功');
    }

    /**
     * `POST menus/saveLinks` / `POST menus/deleteLinks` — the 友情链接 block
     * inside the menu builder.
     */
    public function saveLinks(Request $request)
    {
        return app(SiteController::class)->friendlyLinkSave($request);
    }

    public function deleteLinks(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('friendly_links')->whereIn('id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    /**
     * `POST menus/createWebPage` — metadata for the 创建单页 dialog.
     */
    public function createWebPage(Request $request)
    {
        return $this->ok([
            'menu' => DB::table('menu')->get()->map(fn ($r) => (array) $r)->all(),
            'type' => [1 => '自定义链接', 2 => '商品分类', 3 => '单页'],
            'lang' => AdminMeta::LANGUAGES,
        ]);
    }

    // -----------------------------------------------------------------
    // 合同管理
    // -----------------------------------------------------------------

    /**
     * `GET contract/setting` / `POST contract/setting`
     */
    public function contractSetting(Request $request)
    {
        $keys = ['contract_open', 'contract_force', 'contract_auto', 'contract_pdf', 'contract_notice'];

        if ($request->isMethod('post')) {
            SettingService::putMany(array_intersect_key($request->all(), array_flip($keys)));
            $this->log('保存合同设置');

            return $this->ok(null, '保存成功');
        }

        $out = [];

        foreach ($keys as $key) {
            $out[$key] = SettingService::value($key, '');
        }

        return $this->ok($out);
    }

    /**
     * `GET contract/tpl` — the contract templates.
     */
    public function contractTpl(Request $request)
    {
        $rows = DB::table('contract')->where('base', 1)->orderBy('id')->get();

        return $this->ok($rows->map(fn ($r) => (array) $r)->all());
    }

    /**
     * `POST contract/detail/<id?>` / `GET contract/detail/<id?>` — create or
     * edit a template.
     */
    public function contractDetail(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));

        if ($request->isMethod('post')) {
            return $this->writeContract($request, $id);
        }

        $row = $id > 0 ? DB::table('contract')->where('id', $id)->first() : null;

        return $this->ok([
            'data' => $row ? (array) $row : null,
            'products' => AdminMeta::productList(),
        ]);
    }

    /**
     * `POST contract/detail/<id?>` (POST form of the same route).
     */
    public function contractDetailPost(Request $request, $id = null)
    {
        return $this->writeContract($request, (int) ($id ?? $request->input('id', 0)));
    }

    /**
     * `DELETE contract/tpl/<id>`
     */
    public function contractDeleteTpl(Request $request, $id)
    {
        $id = (int) $id;

        if (DB::table('contract')->where('id', $id)->where('base', 0)->exists()) {
            return $this->fail('该模板下还有已签署合同，不能删除');
        }

        DB::table('contract')->where('id', $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET contract/contract` — signed contracts (the 合同审核 queue).
     */
    public function contractList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        // `shd_contract` holds the templates; each signed copy is a row in
        // `shd_contract_pdf` pointing at the client's host.
        $query = DB::table('contract_pdf')
            ->leftJoin('host as h', 'h.id', '=', 'contract_pdf.host_id')
            ->leftJoin('clients as u', 'u.id', '=', 'h.uid')
            ->select('contract_pdf.id', 'contract_pdf.pdf_num', 'contract_pdf.contract_id', 'contract_pdf.uid', 'contract_pdf.host_id', 'contract_pdf.status', 'contract_pdf.pdf_address', 'contract_pdf.information', 'contract_pdf.ip', 'contract_pdf.create_time', 'contract_pdf.express_company', 'contract_pdf.express_order', 'contract_pdf.remark', 'contract_pdf.is_post', 'contract_pdf.sign_addr', 'contract_pdf.cancel_post', 'u.username', 'u.companyname', 'h.domain as host_domain');

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('contract_pdf.status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('contract_pdf.id')->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST contract/check` — approve or reject a signed contract.
     */
    public function contractCheck(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('contract')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('合同不存在');
        }

        $status = (int) $request->input('status', 1);

        DB::table('contract')->where('id', $id)->update([
            'status' => $status,
            'remark' => (string) $request->input('remark', $row->remark),
            'update_time' => time(),
        ]);

        $this->log('审核合同 #'.$id);

        return $this->ok(['id' => $id], '操作成功');
    }

    /**
     * `GET contract/contract_page` / `POST contract/contract_page/<id>` —
     * the express-mailing step.
     */
    public function contractPage(Request $request, $id = null)
    {
        if ($request->isMethod('post')) {
            $id = (int) ($id ?? $request->input('id', 0));

            DB::table('contract')->where('id', $id)->update([
                'is_post' => (int) $request->input('is_post', 0),
                'update_time' => time(),
            ]);

            DB::table('express')->insert([
                'contract_id' => $id,
                'company' => (string) $request->input('express_company', ''),
                'order_num' => (string) $request->input('express_order', ''),
                'create_time' => time(),
            ]);

            return $this->ok(['id' => $id], '保存成功');
        }

        $row = $id !== null ? DB::table('contract')->where('id', (int) $id)->first() : null;

        return $this->ok($row ? (array) $row : null);
    }

    /**
     * `GET contract/download/<id>` — the contract PDF.
     */
    public function contractDownload(Request $request, $id)
    {
        $row = DB::table('contract_pdf')->where('contract_id', (int) $id)->orderByDesc('id')->first();

        if ($row === null) {
            return $this->fail('合同文件不存在');
        }

        $path = public_path(ltrim((string) ($row->path ?? ''), '/'));

        if (! is_file($path)) {
            return $this->fail('合同文件不存在');
        }

        return response()->download($path);
    }

    /**
     * `POST contract/cancel` / `POST contract/cancel_post/<id>`
     */
    public function contractCancel(Request $request, $id = null)
    {
        $id = (int) ($id ?? $request->input('id', 0));

        DB::table('contract')->where('id', $id)->update([
            'status' => 0,
            'remark' => (string) $request->input('remark', ''),
            'update_time' => time(),
        ]);

        $this->log('作废合同 #'.$id);

        return $this->ok(['id' => $id], '操作成功');
    }

    public function contractCancelPost(Request $request, $id = null)
    {
        return $this->contractCancel($request, $id);
    }

    /**
     * `POST contract/delete`
     */
    public function contractDelete(Request $request)
    {
        $ids = $request->input('id', $request->input('ids', []));
        $ids = is_array($ids) ? array_filter(array_map('intval', $ids)) : array_filter([(int) $ids]);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        DB::table('contract')->whereIn('id', $ids)->delete();
        DB::table('contract_pdf')->whereIn('contract_id', $ids)->delete();

        return $this->ok(['ids' => array_values($ids)], '删除成功');
    }

    // -----------------------------------------------------------------
    // 高级选项 (客户的 高级筛选条件)
    // -----------------------------------------------------------------

    /**
     * 高级选项 — the client-side search-condition builder.
     *
     * This SPA screen stores its condition/result set as a JSON blob in
     * `shd_configuration` rather than in its own table, since it is UI state
     * shared by every administrator.
     */
    private const ADVANCED_KEY = 'advanced_options';

    /**
     * `GET advanced_options/page` / `GET advanced_options`
     */
    public function advancedOptionsPage(Request $request)
    {
        return $this->ok([
            'data' => $this->advancedStore(),
            'condition' => $this->conditionOptions(),
            'operator' => $this->operators(),
        ]);
    }

    /**
     * `GET|POST advanced_options/create`
     */
    public function advancedOptionsCreate(Request $request)
    {
        if (! $request->isMethod('post')) {
            return $this->advancedOptionsPage($request);
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('名称不能为空');
        }

        $store = $this->advancedStore();
        $id = $this->nextAdvancedId($store);

        $store[] = [
            'id' => $id,
            'name' => $name,
            'type' => (string) $request->input('type', 'client'),
            'condition' => [],
            'result' => [],
            'create_time' => time(),
        ];

        $this->saveAdvancedStore($store);

        return $this->ok(['id' => $id], '创建成功');
    }

    /**
     * `POST advanced_options` — save the whole set.
     */
    public function advancedOptionsSave(Request $request)
    {
        $rows = $request->input('data', $request->input('list', []));

        if (! is_array($rows)) {
            return $this->validationFail('参数错误');
        }

        $store = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $store[] = [
                'id' => (int) ($row['id'] ?? $index + 1),
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? 'client'),
                'condition' => $this->normaliseAdvancedChildren($row['condition'] ?? []),
                'result' => $this->normaliseAdvancedChildren($row['result'] ?? []),
                'create_time' => (int) ($row['create_time'] ?? time()),
            ];
        }

        $this->saveAdvancedStore($store);
        $this->log('保存高级选项');

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST advanced_options/addcondition` / `POST advanced_options/addresult`
     */
    public function advancedOptionsAddCondition(Request $request)
    {
        return $this->addAdvancedChild($request, 'condition');
    }

    public function advancedOptionsAddResult(Request $request)
    {
        return $this->addAdvancedChild($request, 'result');
    }

    /**
     * `DELETE advanced_options/deletecondition` / `.../deleteresult`
     */
    public function advancedOptionsDeleteCondition(Request $request)
    {
        return $this->deleteAdvancedChild($request, 'condition');
    }

    public function advancedOptionsDeleteResult(Request $request)
    {
        return $this->deleteAdvancedChild($request, 'result');
    }

    /**
     * `GET advanced_options/<id>` / `GET advanced_options/<id>/edit`
     */
    public function advancedOptionsRead(Request $request, $id)
    {
        $row = $this->findAdvanced((int) $id);

        if ($row === null) {
            return $this->notFound('高级选项不存在');
        }

        return $this->ok([
            'data' => $row,
            'condition' => $row['condition'],
            'result' => $row['result'],
            'options' => $this->conditionOptions(),
            'operator' => $this->operators(),
        ]);
    }

    public function advancedOptionsEdit(Request $request, $id)
    {
        return $this->advancedOptionsRead($request, $id);
    }

    /**
     * `PUT advanced_options/<id>`
     */
    public function advancedOptionsUpdate(Request $request, $id)
    {
        $id = (int) $id;
        $store = $this->advancedStore();
        $found = false;

        foreach ($store as $index => $row) {
            if ((int) $row['id'] !== $id) {
                continue;
            }

            if ($request->filled('name')) {
                $store[$index]['name'] = (string) $request->input('name');
            }

            if ($request->filled('type')) {
                $store[$index]['type'] = (string) $request->input('type');
            }

            if ($request->has('condition')) {
                $store[$index]['condition'] = $this->normaliseAdvancedChildren((array) $request->input('condition'));
            }

            if ($request->has('result')) {
                $store[$index]['result'] = $this->normaliseAdvancedChildren((array) $request->input('result'));
            }

            $found = true;
        }

        if (! $found) {
            return $this->notFound('高级选项不存在');
        }

        $this->saveAdvancedStore($store);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * `DELETE advanced_options/<id>`
     */
    public function advancedOptionsDelete(Request $request, $id)
    {
        $id = (int) $id;
        $store = array_values(array_filter($this->advancedStore(), fn ($row) => (int) $row['id'] !== $id));

        $this->saveAdvancedStore($store);

        return $this->ok(null, '删除成功');
    }

    /**
     * @return array<int,array>
     */
    private function advancedStore(): array
    {
        $raw = SettingService::value(self::ADVANCED_KEY, '[]');
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        // Normalise the child arrays so the SPA always receives lists.
        return array_values(array_map(function ($row) {
            $row = (array) $row;
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['condition'] = $this->normaliseAdvancedChildren($row['condition'] ?? []);
            $row['result'] = $this->normaliseAdvancedChildren($row['result'] ?? []);

            return $row;
        }, $decoded));
    }

    private function saveAdvancedStore(array $store): void
    {
        SettingService::putMany([
            self::ADVANCED_KEY => json_encode(array_values($store), JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function findAdvanced(int $id): ?array
    {
        foreach ($this->advancedStore() as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    private function nextAdvancedId(array $store): int
    {
        $max = 0;

        foreach ($store as $row) {
            $max = max($max, (int) ($row['id'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @return array<int,array>
     */
    private function normaliseAdvancedChildren(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $out[] = [
                'id' => (int) ($row['id'] ?? count($out) + 1),
                'field' => (string) ($row['field'] ?? $row['condition'] ?? ''),
                'operator' => (string) ($row['operator'] ?? '='),
                'value' => is_array($row['value'] ?? null)
                    ? implode(',', $row['value'])
                    : (string) ($row['value'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Append one condition/result row to an advanced option.
     */
    private function addAdvancedChild(Request $request, string $kind)
    {
        $oid = (int) $request->input('oid', $request->input('id', 0));
        $store = $this->advancedStore();
        $found = false;
        $newId = 1;

        foreach ($store as $index => $row) {
            if ((int) $row['id'] !== $oid) {
                continue;
            }

            $existing = $store[$index][$kind];
            $newId = count($existing) + 1;

            $existing[] = [
                'id' => $newId,
                'field' => (string) $request->input('field', $request->input('condition', '')),
                'operator' => (string) $request->input('operator', '='),
                'value' => is_array($request->input('value'))
                    ? implode(',', $request->input('value'))
                    : (string) $request->input('value', ''),
            ];

            $store[$index][$kind] = $existing;
            $found = true;
        }

        if (! $found) {
            return $this->notFound('高级选项不存在');
        }

        $this->saveAdvancedStore($store);

        return $this->ok(['id' => $newId], '添加成功');
    }

    /**
     * Remove one condition/result row.
     */
    private function deleteAdvancedChild(Request $request, string $kind)
    {
        $oid = (int) $request->input('oid', 0);
        $childId = (int) $request->input('id', 0);
        $store = $this->advancedStore();

        foreach ($store as $index => $row) {
            if ((int) $row['id'] !== $oid) {
                continue;
            }

            $store[$index][$kind] = array_values(array_filter(
                $row[$kind],
                fn ($child) => (int) $child['id'] !== $childId,
            ));
        }

        $this->saveAdvancedStore($store);

        return $this->ok(null, '删除成功');
    }

    /**
     * The fields an advanced condition may test.
     */
    private function conditionOptions(): array
    {
        return [
            ['value' => 'username', 'name' => '姓名'],
            ['value' => 'email', 'name' => '邮箱'],
            ['value' => 'phonenumber', 'name' => '手机号'],
            ['value' => 'companyname', 'name' => '公司名称'],
            ['value' => 'status', 'name' => '状态'],
            ['value' => 'groupid', 'name' => '客户分组'],
            ['value' => 'credit', 'name' => '余额'],
            ['value' => 'create_time', 'name' => '注册时间'],
        ];
    }

    private function operators(): array
    {
        return [
            ['value' => '=', 'name' => '等于'],
            ['value' => '!=', 'name' => '不等于'],
            ['value' => 'like', 'name' => '包含'],
            ['value' => '>', 'name' => '大于'],
            ['value' => '<', 'name' => '小于'],
            ['value' => 'in', 'name' => '属于'],
        ];
    }
}
