<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 推介计划 (affiliate) — the platform-wide commission ladder, per-client
 * affiliate accounts, per-product rules and the affiliate records.
 *
 * Storage:
 *   shd_affiliates            one row per client affiliate account
 *   shd_affiliates_user       which client referred which
 *   shd_affiliate_ladder      turnover → extra commission tiers
 *   shd_affiliates_product_setting  per-product commission override
 */
class AffiliateController extends AdminController
{
    /**
     * `GET aff` / `GET affiliates` — the affiliate overview.
     */
    public function index(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('affiliates')
            ->leftJoin('clients as c', 'c.id', '=', 'affiliates.uid')
            ->select('affiliates.id', 'affiliates.date', 'affiliates.uid', 'affiliates.visitors', 'affiliates.registcount', 'affiliates.payamount', 'affiliates.onetime', 'affiliates.audited_balance', 'affiliates.balance', 'affiliates.withdrawn', 'affiliates.created_time', 'affiliates.updated_time', 'affiliates.withdraw_ing', 'affiliates.url_identy', 'affiliates.sum', 'c.username', 'c.companyname', 'c.email');

        if ($username = trim((string) $request->input('username', $request->input('keywords', '')))) {
            $query->where(function ($q) use ($username) {
                $q->where('c.username', 'like', "%{$username}%")
                    ->orWhere('c.email', 'like', "%{$username}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('affiliates.id')->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['balance'] = $this->money((float) $row->balance);
            $entry['withdrawn'] = $this->money((float) $row->withdrawn);
            $entry['withdraw_ing'] = $this->money((float) $row->withdraw_ing);
            $entry['audited_balance'] = $this->money((float) $row->audited_balance);

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'total' => $total,
            'data' => $list,
            'list' => $list,
            'setting' => SettingService::group('affiliate'),
            'bates' => (float) (SettingService::value('affiliate_bates', 0) ?? 0),
        ]);
    }

    /**
     * `GET affladder` — the commission ladder.
     */
    public function ladderList(Request $request)
    {
        $rows = DB::table('affiliate_ladder')->orderBy('turnover')->get();

        return $this->okFlat('请求成功', [
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $rows->count(),
        ]);
    }

    /**
     * `GET aff/edit_affladderpage`
     */
    public function ladderPage(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = $id > 0 ? DB::table('affiliate_ladder')->where('id', $id)->first() : null;

        if ($id > 0 && $row === null) {
            return $this->fail('请求错误');
        }

        return $this->ok(['ladder' => $row ? (array) $row : null]);
    }

    /**
     * `POST aff/add_affladder` / `POST aff/edit_affladder`
     */
    public function ladderSave(Request $request)
    {
        $turnover = $this->money((float) $request->input('turnover', 0));

        if ($turnover <= 0) {
            return $this->validationFail('营业额必须大于0');
        }

        $data = [
            'turnover' => $turnover,
            'bates' => (float) $request->input('bates', 0),
            'is_flag' => (int) $request->input('is_flag', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($id > 0) {
            DB::table('affiliate_ladder')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '编辑成功');
        }

        return $this->ok(['id' => (int) DB::table('affiliate_ladder')->insertGetId($data)], '添加成功');
    }

    /**
     * `GET aff/del_affladder`
     */
    public function ladderDelete(Request $request)
    {
        DB::table('affiliate_ladder')->where('id', (int) $request->input('id', 0))->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET aff/get_timetype` — the cycle dictionary used by the record filters.
     */
    public function timeType(Request $request)
    {
        return $this->ok(AdminMeta::BILLING_CYCLES);
    }

    /**
     * `GET aff/useraffi_page` — the per-client affiliate editor.
     */
    public function userPage(Request $request)
    {
        $uid = (int) $request->input('uid', $request->input('id', 0));

        $client = Client::query()->find($uid);
        $affiliate = DB::table('affiliates')->where('uid', $uid)->first();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $affiliate ? (array) $affiliate : null,
            'datauser' => $client ? (array) $client->toArray() : null,
            'setting' => SettingService::group('affiliate'),
        ] + $this->envelope());
    }

    /**
     * `GET aff/useraffi_list` — affiliate accounts with their referrer.
     */
    public function userList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('affiliates_user')
            ->leftJoin('clients as c', 'c.id', '=', 'affiliates_user.uid')
            ->select('affiliates_user.id', 'affiliates_user.uid', 'affiliates_user.affid', 'affiliates_user.create_time', 'c.username', 'c.email');

        if ($username = trim((string) $request->input('username', ''))) {
            $query->where('c.username', 'like', "%{$username}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('affiliates_user.id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'total' => $total,
        ] + $this->envelope());
    }

    /**
     * `GET aff/useraffi_record` — the commission ledger of one affiliate.
     */
    public function userRecord(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('affiliates')->leftJoin('clients as c', 'c.id', '=', 'affiliates.uid')
            ->select('affiliates.id', 'affiliates.date', 'affiliates.uid', 'affiliates.visitors', 'affiliates.registcount', 'affiliates.payamount', 'affiliates.onetime', 'affiliates.audited_balance', 'affiliates.balance', 'affiliates.withdrawn', 'affiliates.created_time', 'affiliates.updated_time', 'affiliates.withdraw_ing', 'affiliates.url_identy', 'affiliates.sum', 'c.username');

        if ($uid = $request->input('uid')) {
            $query->where('affiliates.uid', (int) $uid);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('affiliates.id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => 200,
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'msg' => '请求成功',
            'total' => $total,
        ] + $this->envelope());
    }

    /**
     * `GET aff/useraffibuy_record` — purchases made through an affiliate link.
     */
    public function userBuyRecord(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $uid = (int) $request->input('uid', 0);

        // Purchases attributed to a referrer live in `shd_affiliates_user`.
        $referred = DB::table('affiliates_user')->when($uid > 0, fn ($q) => $q->where('affid', $uid))->pluck('uid')->all();

        $query = DB::table('orders')
            ->leftJoin('clients as c', 'c.id', '=', 'orders.uid')
            ->select('orders.id', 'orders.uid', 'orders.ordernum', 'orders.status', 'orders.pay_time', 'orders.create_time', 'orders.update_time', 'orders.amount', 'orders.payment', 'orders.promo_code', 'orders.promo_type', 'orders.promo_value', 'orders.invoiceid', 'orders.delete_time', 'orders.notes', 'c.username')
            ->whereNull('orders.delete_time')
            ->whereIn('orders.uid', $referred ?: [-1]);

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('orders.id')->forPage($page, $limit)->get();

        return $this->respond([
            'status' => 200,
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'msg' => '请求成功',
            'total' => $total,
        ] + $this->envelope());
    }

    /**
     * `POST aff/useraffi_post` — save a client's affiliate settings.
     */
    public function userSave(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $keys = [
            'affiliate_enabled', 'affiliate_is_reorder', 'affiliate_reorder',
            'affiliate_is_renew', 'affiliate_renew', 'affiliate_bates',
            'affiliate_type', 'affiliate_renew_type', 'affiliate_reorder_type',
        ];

        $data = array_intersect_key($request->all(), array_flip($keys));

        $exists = DB::table('affiliates_user_setting')->where('uid', $uid)->exists();

        if ($exists) {
            DB::table('affiliates_user_setting')->where('uid', $uid)->update($data);
        } else {
            DB::table('affiliates_user_setting')->insert($data + ['uid' => $uid, 'create_time' => time()]);
        }

        // Make sure the client has an affiliate account row.
        if (! DB::table('affiliates')->where('uid', $uid)->exists()) {
            DB::table('affiliates')->insert([
                'uid' => $uid,
                'date' => time(),
                'balance' => 0,
                'withdrawn' => 0,
                'withdraw_ing' => 0,
                'audited_balance' => 0,
                'created_time' => time(),
                'updated_time' => time(),
            ]);
        }

        $this->log('保存客户'.$client->username.'的推介设置', $uid);

        return $this->ok(['uid' => $uid], '保存成功');
    }

    /**
     * `POST aff/useraffi_balance` — adjust an affiliate's balance.
     */
    public function userBalance(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $amount = $this->money((float) $request->input('amount', $request->input('balance', 0)));

        $affiliate = DB::table('affiliates')->where('uid', $uid)->first();

        if ($affiliate === null) {
            return $this->notFound('推广账户不存在');
        }

        if (abs($amount) < 0.0001) {
            return $this->validationFail('金额不能为0');
        }

        DB::table('affiliates')->where('uid', $uid)->update([
            'balance' => $this->money((float) $affiliate->balance + $amount),
            'updated_time' => time(),
        ]);

        $this->log('调整推广余额 '.$amount.' 客户 #'.$uid, $uid);

        return $this->ok(['balance' => $this->money((float) $affiliate->balance + $amount)], '操作成功');
    }

    /**
     * `GET aff/productaffi_page` — per-product affiliate rules.
     */
    public function productPage(Request $request)
    {
        $pid = (int) $request->input('pid', $request->input('id', 0));

        $row = $pid > 0 ? DB::table('affiliates_product_setting')->where('pid', $pid)->first() : null;

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $row ? (array) $row : null,
            'products' => AdminMeta::productList(),
        ] + $this->envelope());
    }

    /**
     * `POST aff/productaffi_post`
     */
    public function productSave(Request $request)
    {
        $pid = (int) $request->input('pid', 0);

        if ($pid <= 0) {
            return $this->validationFail('请选择商品');
        }

        $keys = [
            'affiliate_enabled', 'affiliate_is_reorder', 'affiliate_reorder',
            'affiliate_is_renew', 'affiliate_renew', 'affiliate_bates',
            'affiliate_type', 'affiliate_renew_type', 'affiliate_reorder_type',
        ];

        $data = array_intersect_key($request->all(), array_flip($keys));

        $exists = DB::table('affiliates_product_setting')->where('pid', $pid)->exists();

        if ($exists) {
            DB::table('affiliates_product_setting')->where('pid', $pid)->update($data);
        } else {
            DB::table('affiliates_product_setting')->insert($data + ['pid' => $pid, 'create_time' => time()]);
        }

        $this->log('保存商品'.$pid.'的推介设置', $pid);

        return $this->ok(['pid' => $pid], '保存成功');
    }

    /**
     * `GET aff/test` — a self-check of the affiliate configuration.
     */
    public function test(Request $request)
    {
        return $this->ok([
            'affiliate_open' => (int) (SettingService::value('affiliate_open', '0') ?? 0),
            'affiliate_bates' => (float) (SettingService::value('affiliate_bates', 0) ?? 0),
            'affiliate_count' => DB::table('affiliates')->count(),
            'ladder_count' => DB::table('affiliate_ladder')->count(),
            'pending_withdraw' => DB::table('affiliates_withdraw')->where('status', 0)->count(),
        ], '成功');
    }
}
