<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 提现审核 — 推广收益提现 (`shd_affiliates_withdraw`) and 余额提现
 * (`shd_withdraw`), plus the payout methods clients withdraw to.
 *
 * Both tables share the same review lifecycle:
 *   Pending → Active (approved) | Cancelled (rejected, with a reason).
 */
class WithdrawController extends AdminController
{
    /**
     * `GET withdraw/withdraw` / `GET withdrawdeposits` — the 提现 table.
     */
    public function index(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('withdraw')
            ->leftJoin('clients as c', 'c.id', '=', 'withdraw.uid')
            ->leftJoin('withdraw_method as m', 'm.id', '=', 'withdraw.account_id')
            ->select('withdraw.id', 'withdraw.uid', 'withdraw.amount', 'withdraw.relid', 'withdraw.admin', 'withdraw.status', 'withdraw.cancelled_reason', 'withdraw.type', 'withdraw.account_id', 'withdraw.create_time', 'withdraw.update_time', 'c.username', 'c.companyname', 'm.type as method_type', 'm.account_num', 'm.account_name');

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('withdraw.status', $status);
        }

        if (($type = $request->input('type')) !== null && $type !== '' && $type !== 'ALL') {
            $query->where('withdraw.type', $type);
        }

        $username = trim((string) $request->input('username', ''));

        if ($username !== '') {
            $query->where(function ($q) use ($username) {
                $q->where('c.username', 'like', "%{$username}%")
                    ->orWhere('c.companyname', 'like', "%{$username}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('withdraw.id')->forPage($page, $limit)->get();

        $admins = DB::table('user')->pluck('user_login', 'id');

        $list = $rows->map(function ($row) use ($admins) {
            $entry = (array) $row;
            $entry['amount'] = $this->money((float) $row->amount);
            $entry['username'] = (string) ($row->companyname ?: $row->username ?: '');
            $entry['user_nickname'] = (string) ($admins[$row->admin] ?? '');
            $entry['status_zh'] = AdminMeta::WITHDRAW_STATUS[$row->status] ?? (string) $row->status;
            $entry['type_zh'] = AdminMeta::WITHDRAW_TYPE[$row->type] ?? (string) $row->type;
            $entry['reason'] = $row->cancelled_reason;

            return $entry;
        })->all();

        // `status` is already taken by the response envelope, so the
        // dictionary travels under its own key.
        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
            'status_map' => AdminMeta::WITHDRAW_STATUS,
            'type_map' => AdminMeta::WITHDRAW_TYPE,
        ]);
    }

    /**
     * `POST withdraw/withdraw` — approve or reject a withdrawal.
     */
    public function operate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('withdraw')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('提现申请不存在');
        }

        if ((string) $row->status !== 'Pending') {
            return $this->fail('该申请已处理');
        }

        $status = (string) $request->input('status', 'Active');
        $reason = (string) $request->input('reason', $request->input('cancelled_reason', ''));

        if (! in_array($status, ['Active', 'Cancelled'], true)) {
            return $this->validationFail('状态值错误');
        }

        if ($status === 'Cancelled' && $reason === '') {
            return $this->validationFail('请填写拒绝原因');
        }

        DB::transaction(function () use ($row, $status, $reason) {
            DB::table('withdraw')->where('id', $row->id)->update([
                'status' => $status,
                'cancelled_reason' => $reason,
                'admin' => $this->adminId(),
                'update_time' => time(),
            ]);

            // A rejection returns the money to the affiliate balance; an
            // approval only settles the "in progress" figure.
            if ($row->type === 'income') {
                $affiliate = DB::table('affiliates')->where('uid', $row->uid)->first();

                if ($affiliate !== null) {
                    $update = ['withdraw_ing' => max(0, (float) $affiliate->withdraw_ing - (float) $row->amount)];

                    if ($status === 'Active') {
                        $update['withdrawn'] = (float) $affiliate->withdrawn + (float) $row->amount;
                    } else {
                        $update['balance'] = (float) $affiliate->balance + (float) $row->amount;
                    }

                    DB::table('affiliates')->where('uid', $row->uid)->update($update + ['updated_time' => time()]);
                }
            } elseif ($row->type === 'credit') {
                // A rejected balance withdrawal releases the held credit.
                if ($status === 'Cancelled') {
                    $client = Client::query()->find($row->uid);

                    if ($client !== null) {
                        $client->addCredit((float) $row->amount, '提现被拒绝退回', (int) $row->id);
                    }
                }
            }
        });

        $label = AdminMeta::WITHDRAW_STATUS[$status] ?? $status;
        $this->log('审核提现 #'.$row->id.' → '.$label, (int) $row->uid);

        return $this->ok(['id' => $id, 'status' => $status], '操作成功');
    }

    /**
     * `GET|POST aff/affiwithdraw_record` — 推广收益 withdrawal requests.
     */
    public function affiliateRecords(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        // `shd_affiliates_withdraw.status` is an int: 0 pending, 1 approved,
        // 2 rejected; `type` points at the payout method row.
        $statusInt = null;
        $status = $request->input('status');

        if ($status !== null && $status !== '' && $status !== 'ALL') {
            $statusInt = ['Pending' => 0, 'Active' => 1, 'Cancelled' => 2][$status] ?? null;
        }

        $query = DB::table('affiliates_withdraw')
            ->leftJoin('withdraw_method as m', 'm.id', '=', 'affiliates_withdraw.type')
            ->leftJoin('clients as c', 'c.id', '=', 'affiliates_withdraw.uid')
            ->select('affiliates_withdraw.id', 'affiliates_withdraw.uid', 'affiliates_withdraw.num', 'affiliates_withdraw.type', 'affiliates_withdraw.admin_id', 'affiliates_withdraw.create_time', 'affiliates_withdraw.update_time', 'affiliates_withdraw.status', 'affiliates_withdraw.reason', 'm.alipay', 'm.account_num', 'm.account_bank', 'm.account_name', 'm.username as method_username', 'c.username');

        if ($statusInt !== null) {
            $query->where('affiliates_withdraw.status', $statusInt);
        }

        if ($username = trim((string) $request->input('username', ''))) {
            $query->where('c.username', 'like', "%{$username}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('affiliates_withdraw.id')->forPage($page, $limit)->get();

        $admins = DB::table('user')->pluck('user_login', 'id');
        $statusMap = [0 => 'Pending', 1 => 'Active', 2 => 'Cancelled'];

        $list = $rows->map(function ($row) use ($admins, $statusMap) {
            $entry = (array) $row;
            $entry['num'] = $this->money((float) $row->num);
            $entry['username'] = (string) ($row->username ?? '');
            $entry['person'] = (string) ($row->person ?? $row->username ?? '');
            $entry['account_num'] = (string) ($row->account_num ?? '');
            $entry['user_login'] = (string) ($admins[$row->admin_id] ?? '');
            $entry['status'] = $statusMap[(int) $row->status] ?? 'Pending';
            $entry['status_zh'] = AdminMeta::WITHDRAW_STATUS[$entry['status']] ?? '';
            $entry['cancelled_reason'] = (string) ($row->reason ?? '');
            $entry['type_zh'] = ($row->type ?? null) ? '指定账户' : '余额';

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'list' => $list,
            'data' => $list,
            'total' => $total,
            'count' => $total,
        ]);
    }

    /**
     * `POST aff/affiwithdrawsh` — approve/reject an affiliate withdrawal.
     */
    public function affiliateOperate(Request $request)
    {
        $id = (int) $request->input('id', 0);
        $row = DB::table('affiliates_withdraw')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('提现申请不存在');
        }

        if ((int) $row->status !== 0) {
            return $this->fail('该申请已处理');
        }

        $status = (string) $request->input('status', 'Active');
        $reason = (string) $request->input('reason', $request->input('cancelled_reason', ''));

        if ($status === 'Cancelled' && $reason === '') {
            return $this->validationFail('请填写拒绝原因');
        }

        $statusInt = $status === 'Active' ? 1 : 2;

        DB::transaction(function () use ($row, $statusInt, $reason) {
            DB::table('affiliates_withdraw')->where('id', $row->id)->update([
                'status' => $statusInt,
                'reason' => $reason,
                'admin_id' => $this->adminId(),
                'update_time' => time(),
            ]);

            $affiliate = DB::table('affiliates')->where('uid', $row->uid)->first();

            if ($affiliate === null) {
                return;
            }

            $update = ['withdraw_ing' => max(0, (float) $affiliate->withdraw_ing - (float) $row->num)];

            if ($statusInt === 1) {
                $update['withdrawn'] = (float) $affiliate->withdrawn + (float) $row->num;
            } else {
                $update['balance'] = (float) $affiliate->balance + (float) $row->num;
            }

            DB::table('affiliates')->where('uid', $row->uid)->update($update + ['updated_time' => time()]);
        });

        $label = $statusInt === 1 ? '已通过' : '已拒绝';
        $this->log('审核推广收益提现 #'.$row->id.' → '.$label, (int) $row->uid);

        return $this->ok(['id' => $id], '操作成功');
    }

    /**
     * `GET aff/gateway_list` / `GET aff/withdraw_method` — payout methods.
     */
    public function gatewayList(Request $request)
    {
        $rows = DB::table('withdraw_method')->orderByDesc('default')->get();

        return $this->respond([
            'status' => 200,
            'msg' => '请求成功',
            'data' => $rows->map(fn ($r) => (array) $r)->all(),
            'list' => $rows->map(fn ($r) => (array) $r)->all(),
            'type' => ['bank' => '银行卡', 'alipay' => '支付宝'],
        ] + $this->envelope());
    }

    /**
     * `POST aff/withdraw_method` — add or edit a payout method.
     */
    public function gatewaySave(Request $request)
    {
        $type = (string) $request->input('type', 'bank');

        if (! in_array($type, ['bank', 'alipay'], true)) {
            return $this->validationFail('收款方式类型错误');
        }

        $data = [
            'type' => $type,
            'account_bank' => (string) $request->input('account_bank', ''),
            'account_name' => (string) $request->input('account_name', ''),
            'account_num' => (string) $request->input('account_num', ''),
            'account_address' => (string) $request->input('account_address', ''),
            'username' => (string) $request->input('username', ''),
            'alipay' => (string) $request->input('alipay', ''),
            'default' => (int) $request->input('default', 0),
        ];

        $id = (int) $request->input('id', 0);

        if ($data['default'] === 1) {
            DB::table('withdraw_method')->update(['default' => 0]);
        }

        if ($id > 0) {
            DB::table('withdraw_method')->where('id', $id)->update($data);

            return $this->ok(['id' => $id], '保存成功');
        }

        return $this->ok(['id' => (int) DB::table('withdraw_method')->insertGetId($data)], '添加成功');
    }

    /**
     * `DELETE aff/withdraw_method/<id>`
     */
    public function gatewayDelete(Request $request, $id)
    {
        DB::table('withdraw_method')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    /**
     * The affiliate settings block the withdrawal page reads.
     */
    public function affiliateConfig(Request $request)
    {
        return $this->ok(SettingService::group('affiliate'));
    }
}
