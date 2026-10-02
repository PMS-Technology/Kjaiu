<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AdminMeta;
use App\Services\Admin\ModuleRegistry;
use App\Services\Admin\SettingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 全局可配置项 — config-option groups, the options inside them and the
 * per-cycle sub-option pricing rows.
 *
 * Storage layout (mirrors the original):
 *   shd_product_config_groups        group (name, description, global)
 *   shd_product_config_links         group ↔ product
 *   shd_product_config_options       option (type, order, hidden, upgrade, …)
 *   shd_product_config_options_sub   sub-option (one row per selectable value)
 *   shd_pricing (type=config)        per-cycle price of a sub-option
 */
class ConfigOptionController extends AdminController
{
    /** `option_type` codes as the SPA renders them. */
    private const OPTION_TYPES = [
        1 => '单选',
        2 => '多选',
        3 => '输入框',
        4 => '下拉',
        5 => 'slider',
        6 => 'checkbox',
        7 => 'linked',
    ];

    /**
     * `GET options/groups_list`
     */
    public function groupsList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        // Table aliases are avoided here: the framework applies the `shd_`
        // prefix after the alias, which produces an invalid table name.
        $groups = DB::table('product_config_groups');

        if ($keywords = trim((string) $request->input('keywords', ''))) {
            $groups->where('name', 'like', "%{$keywords}%");
        }

        $total = (clone $groups)->count();
        $rows = $groups->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['products'] = DB::table('product_config_links')
                ->join('products', 'products.id', '=', 'product_config_links.pid')
                ->where('product_config_links.gid', $row->id)
                ->pluck('products.name')
                ->all();
            // The table column is `products`; show a count where the row has
            // no names so the badge still renders.
            $entry['product_count'] = count($entry['products']);

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'data' => $list,
            'list' => $list,
            'total' => $total,
            'count' => DB::table('product_config_groups')->count(),
            'type' => AdminMeta::OPTION_PRODUCT_TYPES,
        ]);
    }

    /**
     * `GET options/search_page` — the filter widgets for the group list.
     */
    public function searchPage(Request $request)
    {
        return $this->ok([
            'type' => AdminMeta::OPTION_PRODUCT_TYPES,
            'products' => AdminMeta::productList(),
            'search' => [
                'name' => '组名',
                'description' => '描述',
                'products' => '产品',
            ],
        ]);
    }

    /**
     * `GET options/duplicate_groups` — the copy dialog metadata.
     */
    public function duplicateGroupsPage(Request $request)
    {
        return $this->ok([
            'groups' => DB::table('product_config_groups')->orderBy('id')->get(['id', 'name'])->toArray(),
        ]);
    }

    /**
     * `POST options/duplicate_groups_post` — deep-copy a group.
     */
    public function duplicateGroups(Request $request)
    {
        $gid = (int) $request->input('gid', 0);
        $newName = trim((string) $request->input('newname', ''));

        $group = DB::table('product_config_groups')->where('id', $gid)->first();

        if ($group === null) {
            return $this->notFound('配置项组不存在');
        }

        if ($newName === '') {
            return $this->validationFail('新的组名不能为空');
        }

        $newGid = DB::transaction(function () use ($group, $newName) {
            $newGid = (int) DB::table('product_config_groups')->insertGetId([
                'name' => $newName,
                'description' => $group->description,
                'global' => 0,
                'upstream_id' => $group->upstream_id,
            ]);

            $this->copyOptions($gid, $newGid);

            return $newGid;
        });

        $this->log('复制配置项组：'.$group->name.' → '.$newName, $newGid);

        return $this->ok(['id' => $newGid], '复制成功');
    }

    /**
     * `GET options/delete_groups/<id>`
     */
    public function deleteGroup(Request $request, $id)
    {
        $id = (int) $id;

        $used = DB::table('product_config_links')->where('gid', $id)->exists();

        if ($used) {
            return $this->fail('该配置项组已被商品使用，不能删除');
        }

        DB::transaction(function () use ($id) {
            $optionIds = DB::table('product_config_options')->where('gid', $id)->pluck('id')->all();

            if ($optionIds !== []) {
                $subIds = DB::table('product_config_options_sub')->whereIn('config_id', $optionIds)->pluck('id')->all();

                if ($subIds !== []) {
                    DB::table('pricing')->where('type', 'config')->whereIn('relid', $subIds)->delete();
                }

                DB::table('product_config_options_sub')->whereIn('config_id', $optionIds)->delete();
            }

            DB::table('product_config_options')->where('gid', $id)->delete();
            DB::table('product_config_groups')->where('id', $id)->delete();
        });

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET options/edit_groups/<id>?type=` — one group with its options.
     */
    public function editGroup(Request $request, $id)
    {
        $group = DB::table('product_config_groups')->where('id', (int) $id)->first();

        if ($group === null) {
            return $this->notFound('配置项组不存在');
        }

        $options = DB::table('product_config_options')
            ->where('gid', $group->id)
            ->orderBy('order')
            ->get();

        return $this->ok([
            'group' => (array) $group,
            'groupFormData' => (array) $group,
            'options' => $options->map(fn ($o) => $this->optionPayload($o))->all(),
            'type' => AdminMeta::OPTION_PRODUCT_TYPES,
            'option_type' => self::OPTION_TYPES,
            'products' => AdminMeta::productList(),
            'p_id' => DB::table('product_config_links')->where('gid', $group->id)->pluck('pid')->all(),
        ]);
    }

    /**
     * `POST options/create_groups_post` — create or update a group and its
     * product links.
     */
    public function createGroup(Request $request)
    {
        return $this->writeGroup($request, (int) $request->input('id', 0));
    }

    /**
     * `POST options/edit_groups_post`
     */
    public function updateGroup(Request $request)
    {
        return $this->writeGroup($request, (int) $request->input('id', 0));
    }

    /**
     * `GET options/add_options_page` — the option editor's initial state.
     */
    public function addOptionPage(Request $request)
    {
        $gid = (int) $request->input('gid', 0);

        return $this->ok([
            'gid' => $gid,
            'option_type' => self::OPTION_TYPES,
            'group' => DB::table('product_config_groups')->where('id', $gid)->first(),
            'option' => null,
            'sub' => [],
            'currencies' => AdminMeta::currencies(),
            'linkage' => $this->linkageOptions(),
        ]);
    }

    /**
     * `POST options/add_options`
     */
    public function addOption(Request $request)
    {
        return $this->writeOption($request, 0);
    }

    /**
     * `GET options/edit_config/<id>` — the single-option editor.
     */
    public function editOptionPage(Request $request, $id)
    {
        $option = DB::table('product_config_options')->where('id', (int) $id)->first();

        if ($option === null) {
            return $this->notFound('配置项不存在');
        }

        return $this->ok([
            'option' => $this->optionPayload($option),
            'gid' => (int) $option->gid,
            'option_type' => self::OPTION_TYPES,
            'currencies' => AdminMeta::currencies(),
            'linkage' => $this->linkageOptions(),
            'group' => DB::table('product_config_groups')->where('id', $option->gid)->first(),
        ]);
    }

    /**
     * `POST options/edit_config_post`
     */
    public function updateOption(Request $request)
    {
        return $this->writeOption($request, (int) $request->input('id', 0));
    }

    /**
     * `GET options/delete_options/<id>`
     */
    public function deleteOption(Request $request, $id)
    {
        $id = (int) $id;

        DB::transaction(function () use ($id) {
            $subIds = DB::table('product_config_options_sub')->where('config_id', $id)->pluck('id')->all();

            if ($subIds !== []) {
                DB::table('pricing')->where('type', 'config')->whereIn('relid', $subIds)->delete();
            }

            DB::table('product_config_options_sub')->where('config_id', $id)->delete();
            DB::table('product_config_options')->where('id', $id)->delete();
        });

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET|POST options/config_options_check_os` — the OS list an option
     * exposes. The original proxies the module's OS list; we return what the
     * linked server module reports, falling back to the built-in catalogue.
     */
    public function checkOs(Request $request)
    {
        $module = (string) $request->input('type', $request->input('module', ''));

        $osList = [];
        $functions = [];

        if ($module !== '' && ModuleRegistry::exists($module)) {
            // A driver that exposes the OS catalogue does so through
            // `OsList`; anything else simply reports no OS list, which is how
            // the original behaves for modules without OS support.
            $functions = ModuleRegistry::functions($module);

            if (in_array('get_os', $functions, true) || in_array('OsList', $functions, true)) {
                $osList = [];
            }
        }

        // The built-in catalogue every driver understands.
        $catalogue = [
            ['id' => 'centos7', 'name' => 'CentOS 7'],
            ['id' => 'centos8', 'name' => 'CentOS 8'],
            ['id' => 'ubuntu20', 'name' => 'Ubuntu 20.04'],
            ['id' => 'ubuntu22', 'name' => 'Ubuntu 22.04'],
            ['id' => 'debian11', 'name' => 'Debian 11'],
            ['id' => 'debian12', 'name' => 'Debian 12'],
            ['id' => 'windows2019', 'name' => 'Windows Server 2019'],
            ['id' => 'windows2022', 'name' => 'Windows Server 2022'],
        ];

        if ($osList === []) {
            $osList = $catalogue;
        }

        return $this->ok([
            'module' => $module,
            'os' => $osList,
            'functions' => $functions,
        ]);
    }

    /**
     * `POST options/saveLinkAgeLevel` — the two-level config-option linkage.
     *
     * `linkage_pid` points at the parent option, `linkage_top_pid` at the
     * grandparent; `linkage_level` carries the selected sub-option ids so a
     * child only shows for the matching parent value.
     */
    public function saveLinkAgeLevel(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->validationFail('ID错误');
        }

        $data = [
            'linkage_pid' => (int) $request->input('linkage_pid', 0),
            'linkage_top_pid' => (int) $request->input('linkage_top_pid', 0),
            'linkage_level' => $this->normaliseLevel($request->input('linkage_level')),
        ];

        if (DB::table('product_config_options')->where('id', $id)->exists()) {
            DB::table('product_config_options')->where('id', $id)->update($data);
        } else {
            DB::table('product_config_options_sub')->where('id', $id)->update($data);
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `POST options/saveLinkAgeOrder` — drag-sort the linked options.
     */
    public function saveLinkAgeOrder(Request $request)
    {
        $ids = $request->input('ids', $request->input('data', []));

        if (! is_array($ids)) {
            return $this->validationFail('参数错误');
        }

        foreach (array_values($ids) as $index => $id) {
            $id = is_array($id) ? (int) ($id['id'] ?? 0) : (int) $id;

            if ($id > 0) {
                DB::table('product_config_options')->where('id', $id)->update(['order' => $index]);
                DB::table('product_config_options_sub')->where('id', $id)->update(['sort_order' => $index]);
            }
        }

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET options/getNextLinkAgeList` — the next level of linked options.
     */
    public function getNextLinkAgeList(Request $request)
    {
        $pid = (int) $request->input('pid', 0);
        $topPid = (int) $request->input('top_pid', 0);
        $level = $request->input('level');

        $query = DB::table('product_config_options')->orderBy('order');

        if ($topPid > 0) {
            $query->where('linkage_top_pid', $topPid)->orWhere('linkage_pid', $pid);
        } elseif ($pid > 0) {
            $query->where('linkage_pid', $pid);
        }

        $rows = $query->get();

        return $this->ok($rows->map(function ($option) use ($level) {
            $payload = $this->optionPayload($option);

            if ($level !== null && $level !== '') {
                $payload['sub'] = array_values(array_filter($payload['sub'], function ($sub) use ($level) {
                    return $sub['linkage_level'] === '' || str_contains($sub['linkage_level'], (string) $level);
                }));
            }

            return $payload;
        })->all());
    }

    /**
     * `GET adminGetLinkAgeList {pid, gid}` — the linked options of a product,
     * used by the order form and the host editor.
     */
    public function adminGetLinkAgeList(Request $request)
    {
        $pid = (int) $request->input('pid', 0);
        $gid = (int) $request->input('gid', 0);

        $groupIds = $gid > 0
            ? [$gid]
            : DB::table('product_config_links')->where('pid', $pid)->pluck('gid')->all();

        if ($groupIds === []) {
            return $this->respond([
                'status' => ApiResponse::OK,
                'msg' => 'success',
                'data' => [],
                'is_aff' => (string) (SettingService::value('affiliate_open', '0') ?? '0'),
            ]);
        }

        $options = DB::table('product_config_options')
            ->whereIn('gid', $groupIds)
            ->where('hidden', 0)
            ->orderBy('order')
            ->get();

        return $this->respond([
            'status' => ApiResponse::OK,
            'msg' => 'success',
            'data' => $options->map(fn ($o) => $this->optionPayload($o))->all(),
            'is_aff' => (string) (SettingService::value('affiliate_open', '0') ?? '0'),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function writeGroup(Request $request, int $id)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('组名不能为空');
        }

        $data = [
            'name' => $name,
            'description' => (string) $request->input('description', ''),
            'global' => (int) $request->input('global', 0),
        ];

        if ($id > 0) {
            DB::table('product_config_groups')->where('id', $id)->update($data);
        } else {
            $id = (int) DB::table('product_config_groups')->insertGetId($data);
        }

        // 指定产品 — replace the group's product links.
        if ($request->has('p_id') || $request->has('pids')) {
            $pids = $request->input('p_id', $request->input('pids', []));
            $pids = is_array($pids) ? array_filter(array_map('intval', $pids)) : array_filter(array_map('intval', explode(',', (string) $pids)));

            DB::table('product_config_links')->where('gid', $id)->delete();

            foreach (array_unique($pids) as $pid) {
                DB::table('product_config_links')->insert(['gid' => $id, 'pid' => $pid]);
            }
        }

        $this->log(($id > 0 ? '保存' : '添加').'配置项组：'.$name, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * Create/update one option plus its sub-options and per-cycle pricing.
     */
    private function writeOption(Request $request, int $id)
    {
        $name = trim((string) $request->input('option_name', $request->input('name', '')));

        if ($name === '') {
            return $this->validationFail('配置项名称不能为空');
        }

        $gid = (int) $request->input('gid', 0);

        if ($gid <= 0) {
            return $this->validationFail('请选择配置项组');
        }

        $data = [
            'gid' => $gid,
            'option_name' => $name,
            'option_type' => (int) $request->input('option_type', 1),
            'qty_minimum' => (int) $request->input('qty_minimum', 0),
            'qty_maximum' => (int) $request->input('qty_maximum', 0),
            'qty_stage' => (int) $request->input('qty_stage', 0),
            'order' => (int) $request->input('order', 0),
            'hidden' => (int) $request->input('hidden', 0),
            'upgrade' => (int) $request->input('upgrade', 0),
            'auto' => (int) $request->input('auto', 0),
            'is_discount' => (int) $request->input('is_discount', 0),
            'is_rebate' => (int) $request->input('is_rebate', 0),
            'notes' => (string) $request->input('notes', ''),
            'unit' => (string) $request->input('unit', ''),
            'senior' => (int) $request->input('senior', 0),
            'linkage_pid' => (int) $request->input('linkage_pid', 0),
            'linkage_top_pid' => (int) $request->input('linkage_top_pid', 0),
            'linkage_level' => $this->normaliseLevel($request->input('linkage_level')),
        ];

        if ($id > 0) {
            DB::table('product_config_options')->where('id', $id)->update($data);
        } else {
            $id = (int) DB::table('product_config_options')->insertGetId($data);
        }

        $subs = $request->input('sub', $request->input('option_sub', []));

        if (is_array($subs)) {
            $this->saveSubOptions($id, $subs);
        }

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * Persist the sub-option rows and their `shd_pricing` matrix.
     */
    private function saveSubOptions(int $optionId, array $subs): void
    {
        $keep = [];

        foreach ($subs as $sub) {
            if (! is_array($sub)) {
                continue;
            }

            $subName = trim((string) ($sub['option_name'] ?? ''));

            if ($subName === '') {
                continue;
            }

            $payload = [
                'config_id' => $optionId,
                'option_name' => $subName,
                'qty_minimum' => (int) ($sub['qty_minimum'] ?? 0),
                'qty_maximum' => (int) ($sub['qty_maximum'] ?? 0),
                'sort_order' => (int) ($sub['sort_order'] ?? 0),
                'hidden' => (int) ($sub['hidden'] ?? 0),
                'linkage_pid' => (int) ($sub['linkage_pid'] ?? 0),
                'linkage_top_pid' => (int) ($sub['linkage_top_pid'] ?? 0),
                'linkage_level' => $this->normaliseLevel($sub['linkage_level'] ?? ''),
            ];

            $subId = (int) ($sub['id'] ?? 0);

            if ($subId > 0 && DB::table('product_config_options_sub')->where('id', $subId)->exists()) {
                DB::table('product_config_options_sub')->where('id', $subId)->update($payload);
            } else {
                $subId = (int) DB::table('product_config_options_sub')->insertGetId($payload);
            }

            $keep[] = $subId;

            if (isset($sub['pricing']) && is_array($sub['pricing'])) {
                $this->saveSubPricing($subId, $sub['pricing']);
            }
        }

        // Anything the editor dropped goes away with its pricing rows.
        $stale = DB::table('product_config_options_sub')
            ->where('config_id', $optionId)
            ->whereNotIn('id', $keep ?: [0])
            ->pluck('id')
            ->all();

        if ($stale !== []) {
            DB::table('pricing')->where('type', 'config')->whereIn('relid', $stale)->delete();
            DB::table('product_config_options_sub')->whereIn('id', $stale)->delete();
        }
    }

    /**
     * @param  array<int|string,array>  $rows
     */
    private function saveSubPricing(int $subId, array $rows): void
    {
        foreach ($rows as $currencyId => $row) {
            if (! is_array($row)) {
                continue;
            }

            $currency = (int) ($row['currency'] ?? (is_numeric($currencyId) ? $currencyId : 0));

            if ($currency <= 0) {
                continue;
            }

            $exists = DB::table('pricing')
                ->where('type', 'config')
                ->where('relid', $subId)
                ->where('currency', $currency)
                ->exists();

            $update = [];

            foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                if (array_key_exists($cycle, $row) && $row[$cycle] !== null && $row[$cycle] !== '') {
                    $update[$cycle] = $this->money((float) $row[$cycle]);
                }
            }

            foreach (AdminMeta::SETUP_COLUMNS as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
                    $update[$column] = $this->money((float) $row[$column]);
                }
            }

            if ($update === []) {
                continue;
            }

            if ($exists) {
                DB::table('pricing')
                    ->where('type', 'config')
                    ->where('relid', $subId)
                    ->where('currency', $currency)
                    ->update($update);
            } else {
                $insert = array_merge([
                    'type' => 'config',
                    'relid' => $subId,
                    'currency' => $currency,
                ], $update);

                foreach (AdminMeta::CYCLE_COLUMNS as $cycle) {
                    $insert[$cycle] ??= -1;
                }

                foreach (AdminMeta::SETUP_COLUMNS as $column) {
                    $insert[$column] ??= 0;
                }

                DB::table('pricing')->insert($insert);
            }
        }
    }

    /**
     * Option payload with its sub-options and per-cycle pricing resolved.
     */
    private function optionPayload($option): array
    {
        $row = (array) $option;

        $subs = DB::table('product_config_options_sub')
            ->where('config_id', $option->id)
            ->orderBy('sort_order')
            ->get();

        $row['sub'] = $subs->map(function ($sub) {
            $entry = (array) $sub;
            $entry['pricing'] = DB::table('pricing')
                ->where('type', 'config')
                ->where('relid', $sub->id)
                ->get()
                ->map(fn ($p) => (array) $p)
                ->all();

            return $entry;
        })->all();

        $row['option_type_zh'] = self::OPTION_TYPES[(int) $option->option_type] ?? '';
        $row['title'] = $row['option_name'];

        return $row;
    }

    /**
     * The linkage targets offered in the editor.
     */
    private function linkageOptions(): array
    {
        return DB::table('product_config_options')
            ->orderBy('order')
            ->get(['id', 'option_name', 'gid', 'linkage_pid', 'linkage_top_pid'])
            ->map(fn ($o) => (array) $o)
            ->all();
    }

    /**
     * `linkage_level` is stored as a comma list of sub-option ids; accept
     * either that or an array from the SPA.
     */
    private function normaliseLevel(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));

        return implode(',', $parts);
    }

    /**
     * Deep-copy the options (with sub-options and pricing) of one group.
     */
    private function copyOptions(int $fromGid, int $toGid): void
    {
        foreach (DB::table('product_config_options')->where('gid', $fromGid)->get() as $option) {
            $data = (array) $option;
            unset($data['id']);
            $data['gid'] = $toGid;
            $data['copy_id'] = $option->id;

            $newOptionId = (int) DB::table('product_config_options')->insertGetId($data);

            foreach (DB::table('product_config_options_sub')->where('config_id', $option->id)->get() as $sub) {
                $subData = (array) $sub;
                unset($subData['id']);
                $subData['config_id'] = $newOptionId;
                $subData['copy_id'] = $sub->id;

                $newSubId = (int) DB::table('product_config_options_sub')->insertGetId($subData);

                foreach (DB::table('pricing')->where('type', 'config')->where('relid', $sub->id)->get() as $pricing) {
                    $pricingData = (array) $pricing;
                    unset($pricingData['id']);
                    $pricingData['relid'] = $newSubId;
                    DB::table('pricing')->insert($pricingData);
                }
            }
        }
    }
}
