<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Services\Admin\AdminMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 客户关怀 — automated follow-up rules.
 *
 * A care rule pairs a trigger (a client has a service expiring, has not logged
 * in for N days, has used N% of a quota …) with an action (email / SMS /
 * system message) and an optional product scope. Rows live in
 * `shd_client_care`, with the product scope in `shd_client_care_product_links`
 * and the trigger definitions in `shd_client_care_trigger`.
 */
class ClientCareController extends AdminController
{
    /**
     * `GET client_care/search_condition` — the condition dictionary.
     */
    public function searchCondition(Request $request)
    {
        $triggers = DB::table('client_care_trigger')->orderBy('id')->get();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'trigger' => $triggers->map(fn ($t) => [
                'id' => (int) $t->id,
                'name' => $this->triggerLabel((int) $t->type),
                'value' => (int) $t->id,
            ])->all(),
            'trigger_list' => $triggers->map(fn ($t) => (array) $t)->all(),
            'method' => $this->methods(),
        ] + $this->envelope());
    }

    /**
     * `GET client_care/care_list` — the rule list.
     */
    public function careList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('client_care');

        if ($name = trim((string) $request->input('name', $request->input('keywords', '')))) {
            $query->where('client_care.name', 'like', "%{$name}%");
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('client_care.status', (int) $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('client_care.id')->forPage($page, $limit)->get();

        $triggers = DB::table('client_care_trigger')->pluck('type', 'id');

        $list = $rows->map(function ($row) use ($triggers) {
            $entry = (array) $row;
            $entry['trigger_name'] = $this->triggerLabel((int) ($triggers[$row->trigger] ?? 0));
            $entry['method_zh'] = $this->methods()[(int) $row->method] ?? '';
            $entry['products'] = DB::table('client_care_product_links')
                ->join('products', 'products.id', '=', 'client_care_product_links.product_id')
                ->where('client_care_product_links.care_id', $row->id)
                ->pluck('products.name')
                ->all();

            return $entry;
        })->all();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'care_list' => $list,
            'list' => $list,
            'total' => $total,
        ] + $this->envelope());
    }

    /**
     * `GET client_care/create_care` — the 新增关怀 rule form metadata.
     */
    public function createPage(Request $request)
    {
        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'products' => AdminMeta::productList(),
            'trigger' => DB::table('client_care_trigger')->get()->map(fn ($t) => (array) $t)->all(),
            'method' => $this->methods(),
            'email_template' => DB::table('email_templates')->get(['id', 'name', 'type'])->toArray(),
            'message_tmeplate' => DB::table('message_template')->get(['id', 'title'])->toArray(),
            'client_groups' => AdminMeta::clientGroups(),
        ] + $this->envelope());
    }

    /**
     * `GET client_care/edit_care/<id>`
     */
    public function editPage(Request $request, $id)
    {
        $row = DB::table('client_care')->where('id', (int) $id)->first();

        if ($row === null) {
            return $this->notFound('关怀规则不存在');
        }

        $payload = $this->createPage($request)->getData(true);
        $payload['data'] = (array) $row;
        $payload['care'] = (array) $row;
        $payload['selected'] = DB::table('client_care_product_links')->where('care_id', $row->id)->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        return $this->respond($payload);
    }

    /**
     * `POST client_care/create_care_post`
     */
    public function create(Request $request)
    {
        return $this->writeCare($request, 0);
    }

    /**
     * `POST client_care/edit_care_post`
     */
    public function update(Request $request)
    {
        return $this->writeCare($request, (int) $request->input('id', 0));
    }

    /**
     * `GET client_care/delete_care/<id>`
     */
    public function delete(Request $request, $id)
    {
        $id = (int) $id;

        DB::transaction(function () use ($id) {
            DB::table('client_care')->where('id', $id)->delete();
            DB::table('client_care_product_links')->where('care_id', $id)->delete();
        });

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET client_care/test` — dry-run a rule and report who it would match.
     */
    public function test(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $rule = $id > 0 ? DB::table('client_care')->where('id', $id)->first() : null;

        $days = (int) ($rule->days ?? $request->input('days', 7));
        $trigger = (int) ($rule->trigger_id ?? $request->input('trigger_id', 0));

        $productIds = DB::table('client_care_product_links')
            ->where('care_id', $id)
            ->pluck('product_id')
            ->all();

        $query = Client::query()->where('status', 1);

        if ($productIds !== []) {
            $uids = DB::table('host')->whereIn('productid', $productIds)->pluck('uid')->unique()->all();
            $query->whereIn('id', $uids ?: [-1]);
        }

        // The built-in trigger ids: 1 = expiring service, 2 = long inactive,
        // 3 = unpaid invoice. Anything else can only be previewed as everyone.
        if ($trigger === 1 || $trigger === 2) {
            $uids = DB::table('host')
                ->whereIn('domainstatus', ['Active', 'Suspended'])
                ->whereBetween('nextduedate', [time(), time() + $days * 86400])
                ->pluck('uid')
                ->unique()
                ->all();

            $query->whereIn('id', $uids ?: [-1]);
        } elseif ($trigger === 2) {
            $query->where('lastlogin', '<', time() - $days * 86400);
        } elseif ($trigger === 3) {
            $uids = DB::table('invoices')
                ->whereIn('status', ['Unpaid', 'Overdue'])
                ->pluck('uid')
                ->unique()
                ->all();

            $query->whereIn('id', $uids ?: [-1]);
        }

        $total = (clone $query)->count();
        $sample = (clone $query)->orderByDesc('id')->limit(20)->get(['id', 'username', 'email', 'phonenumber']);

        return $this->ok([
            'count' => $total,
            'list' => $sample->map(fn (Client $c) => [
                'id' => (int) $c->id,
                'username' => $c->username,
                'email' => $c->email,
                'phonenumber' => $c->phonenumber,
            ])->all(),
        ], '请求成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Create/update one care rule plus its product scope.
     */
    private function writeCare(Request $request, int $id)
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->validationFail('规则名称不能为空');
        }

        $data = [
            'name' => $name,
            'trigger' => (int) $request->input('trigger_id', $request->input('trigger', 0)),
            'time' => (int) $request->input('days', $request->input('time', 0)),
            'method' => (int) $request->input('method', 0),
            'email_template_id' => (int) $request->input('email_template', $request->input('email_template_id', 0)),
            'message_template_id' => (int) $request->input('message_tmeplate', $request->input('message_template_id', 0)),
            'status' => (int) $request->input('status', 1),
            'range_type' => (int) $request->input('range_type', 0),
            'update_time' => time(),
        ];

        if ($id > 0) {
            DB::table('client_care')->where('id', $id)->update($data);
        } else {
            $data['create_time'] = time();
            $id = (int) DB::table('client_care')->insertGetId($data);
        }

        $pids = $request->input('pids', $request->input('products', []));
        $pids = is_array($pids) ? $pids : explode(',', (string) $pids);

        if ($pids !== []) {
            DB::table('client_care_product_links')->where('care_id', $id)->delete();

            foreach (array_unique(array_filter(array_map('intval', $pids))) as $pid) {
                DB::table('client_care_product_links')->insert(['care_id' => $id, 'product_id' => $pid]);
            }
        }

        $this->log('保存客户关怀规则：'.$name, $id);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * Human label for a `shd_client_care_trigger.type` code.
     */
    private function triggerLabel(int $type): string
    {
        return [
            1 => '产品到期前',
            2 => '产品到期后',
            3 => '长期未登录',
            4 => '有未支付账单',
            5 => '购买后',
            6 => '注册后',
        ][$type] ?? ('条件'.$type);
    }

    /**
     * The delivery-channel dictionary (`method`).
     */
    private function methods(): array
    {
        return [
            0 => '邮件',
            1 => '短信',
            2 => '站内信',
            3 => '邮件+短信',
        ];
    }
}
