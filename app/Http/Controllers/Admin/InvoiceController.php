<?php

namespace App\Http\Controllers\Admin;

use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentGateway;
use App\Models\PromoCode;
use App\Services\Admin\AdminMeta;
use App\Services\Admin\SettingService;
use App\Services\InvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 财务 — invoices (账单管理/账单详情), transaction records (交易流水) and credit
 * lines (信用额管理).
 *
 * The report endpoints put their payload at the top level rather than under
 * `data` (`invoice/index` → `data` + `page` + `price`/`totalprice`);
 * `accounts` → `data` + `page` + `count`), so several actions here use
 * `okFlat()`.
 */
class InvoiceController extends AdminController
{
    /**
     * `GET invoice/index` — the 账单管理 table.
     */
    public function index(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'create_time', 'paid_time', 'due_time', 'total', 'status'], 'id');

        $query = $this->invoiceQuery($request);

        $total = (clone $query)->count();
        $sum = (float) (clone $query)->sum('total');

        $rows = $query->orderBy($orderBy, $sort)->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'data' => $this->decorateInvoices($rows),
            'list' => $this->decorateInvoices($rows),
            'page' => [
                'total' => $total,
                'current' => $page,
                'limit' => $limit,
                'last_page' => (int) ceil($total / max(1, $limit)),
            ],
            'price' => $this->money($sum),
            'totalprice' => $this->money($sum),
            'tabsSearch' => $this->tabs(),
        ]);
    }

    /**
     * `GET invoice/search_page` — the filter widgets.
     */
    public function searchPage(Request $request)
    {
        return $this->ok([
            'type' => $this->invoiceTypes(),
            'payment' => AdminMeta::gateways(),
            'sale' => AdminMeta::admins(),
            'tabsSearch' => $this->tabs(),
            'status' => AdminMeta::INVOICE_STATUS,
        ]);
    }

    /**
     * `GET invoice/paid|unpaid|cancelled` — mark one or many invoices.
     *
     * The same action serves both a single row (`?id=`) and a bulk selection
     * (`data[]`), exactly like the original.
     */
    public function markPaid(Request $request)
    {
        return $this->markStatus($request, 'Paid');
    }

    public function markUnpaid(Request $request)
    {
        return $this->markStatus($request, 'Unpaid');
    }

    public function markCancelled(Request $request)
    {
        return $this->markStatus($request, 'Cancelled');
    }

    /**
     * `GET invoice/duplicate {id}` — copy an invoice into a new unpaid one.
     */
    public function duplicate(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $copy = DB::transaction(function () use ($invoice) {
            $new = $invoice->replicate();
            $new->invoice_num = app(InvoiceService::class)->nextNumber();
            $new->status = 'Unpaid';
            $new->paid_time = 0;
            $new->create_time = time();
            $new->update_time = time();
            $new->payment = '';
            $new->payment_status = '';
            $new->save();

            foreach (InvoiceItem::query()->where('invoice_id', $invoice->id)->whereNull('delete_time')->get() as $item) {
                $row = $item->replicate();
                $row->invoice_id = $new->id;
                $row->delete_time = 0;
                $row->save();
            }

            return $new;
        });

        $this->log('复制账单 #'.$invoice->id.' → #'.$copy->id, (int) $copy->id);

        return $this->ok(['id' => (int) $copy->id], '复制成功');
    }

    /**
     * `GET invoice/summary/<id>` — the 账单详情 header.
     */
    public function summary(Request $request, $id)
    {
        $invoice = Invoice::query()->find((int) $id);

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $client = Client::query()->find($invoice->uid);
        $items = InvoiceItem::query()->where('invoice_id', $invoice->id)->whereNull('delete_time')->get();
        $paid = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->sum('amount_in');

        return $this->ok([
            'invoice' => (array) $invoice->toArray(),
            'client' => $client ? (array) $client->toArray() : null,
            'items' => $items->map(fn (InvoiceItem $i) => (array) $i->toArray())->all(),
            'accounts' => DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->get()->map(fn ($a) => (array) $a)->all(),
            'paid' => $this->money($paid),
            'surplus' => $this->money((float) $invoice->total - $paid),
            'status' => (string) $invoice->status,
            'status_zh' => AdminMeta::invoiceStatusLabel((string) $invoice->status),
            'payment' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `GET invoice/<id>` — the full 账单详情 payload.
     */
    public function detail(Request $request, $id)
    {
        return $this->summary($request, $id);
    }

    /**
     * `DELETE invoice/delete {id}` — the schema soft-deletes through
     * `delete_time` / `is_delete`.
     */
    public function delete(Request $request)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('请选择要删除的账单');
        }

        $blocked = Invoice::query()->whereIn('id', $ids)->where('status', 'Paid')->pluck('id')->all();
        $deletable = array_values(array_diff($ids, $blocked));

        if ($deletable === []) {
            return $this->fail('已支付的账单不能删除');
        }

        Invoice::query()->whereIn('id', $deletable)->update([
            'delete_time' => time(),
            'is_delete' => 1,
        ]);

        $this->log('删除账单：'.implode(',', $deletable));

        return $this->ok(
            ['deleted' => $deletable, 'blocked' => $blocked],
            $blocked === [] ? '删除成功' : '部分已支付账单未能删除',
        );
    }

    /**
     * `POST invoice/email {id}` — re-send the invoice notification.
     */
    public function sendEmail(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $client = Client::query()->find($invoice->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        try {
            \Illuminate\Support\Facades\Mail::raw(
                '您的账单 '.$invoice->invoice_num.' 金额 '.$invoice->total.' 元，请及时支付。',
                function ($message) use ($client) {
                    $message->to((string) $client->email)->subject('账单通知');
                },
            );
        } catch (\Throwable $e) {
            return $this->fail('发送失败：'.$e->getMessage());
        }

        $this->log('发送账单邮件 #'.$invoice->id, (int) $invoice->id);

        return $this->ok(null, '发送成功');
    }

    /**
     * `GET invoice/addpay_page/<id>` — 添加付款 dialog metadata.
     */
    public function addPayPage(Request $request, $id)
    {
        $invoice = Invoice::query()->find((int) $id);

        if ($invoice === null) {
            return $this->fail('ID错误');
        }

        $paid = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->sum('amount_in');

        return $this->ok([
            'invoice' => (array) $invoice->toArray(),
            'surplus' => $this->money((float) $invoice->total - $paid),
            'paid' => $this->money($paid),
            'payment' => AdminMeta::gateways(),
            'email_template' => DB::table('email_templates')->get(['id', 'name', 'type'])->toArray(),
        ]);
    }

    /**
     * `POST invoice/addpay` — record a manual payment against an invoice.
     */
    public function addPay(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));

        if ($amount <= 0) {
            return $this->validationFail('付款金额必须大于0');
        }

        $payTime = $this->timestamp($request->input('pay_time')) ?? time();
        $gateway = (string) $request->input('gateway', '');

        DB::transaction(function () use ($invoice, $amount, $payTime, $gateway, $request) {
            DB::table('accounts')->insert([
                'uid' => $invoice->uid,
                'currency' => (string) (DB::table('currencies')->where('id', 1)->value('code') ?? 'CNY'),
                'gateway' => $gateway,
                'create_time' => time(),
                'update_time' => time(),
                'pay_time' => $payTime,
                'description' => '账单付款 #'.$invoice->id,
                'amount_in' => $amount,
                'fees' => 0,
                'amount_out' => 0,
                'rate' => 1,
                'trans_id' => (string) $request->input('trans_id', ''),
                'invoice_id' => $invoice->id,
                'refund' => 0,
                'delete_time' => null,
            ]);

            $paid = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->sum('amount_in');

            // Settle the invoice once the payments cover the total.
            if ($paid + 0.0001 >= (float) $invoice->total && (string) $invoice->status !== 'Paid') {
                app(InvoiceService::class)->markPaid($invoice, $gateway, $amount);
            }
        });

        $this->log('账单付款 #'.$invoice->id.' 金额 '.$amount, (int) $invoice->id);

        return $this->ok(['amount' => $amount], '付款成功');
    }

    /**
     * `GET invoice/add_pay_invoice_page/<id>` — the extra-payment rows.
     */
    public function addPayInvoicePage(Request $request, $id)
    {
        $invoice = Invoice::query()->find((int) $id);

        if ($invoice === null) {
            return $this->fail('ID错误');
        }

        $rows = DB::table('accounts')
            ->where('invoice_id', $invoice->id)
            ->whereNull('delete_time')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => (array) $a)
            ->all();

        return $this->ok([
            'list' => $rows,
            'payment' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST invoice/add_pay_invoice` — add another payment row.
     */
    public function addPayInvoice(Request $request)
    {
        return $this->addPay($request);
    }

    /**
     * `POST invoice/delete_pay_invoice {id}` — remove a payment row.
     */
    public function deletePayInvoice(Request $request)
    {
        $id = (int) $request->input('id', $request->input('account_id', 0));

        $row = DB::table('accounts')->where('id', $id)->first();

        if ($row === null) {
            return $this->notFound('付款记录不存在');
        }

        DB::table('accounts')->where('id', $id)->update(['delete_time' => time()]);

        // Removing a payment can un-settle the invoice.
        if ($row->invoice_id) {
            $invoice = Invoice::query()->find($row->invoice_id);

            if ($invoice !== null && (string) $invoice->status === 'Paid') {
                $paid = (float) DB::table('accounts')
                    ->where('invoice_id', $invoice->id)
                    ->whereNull('delete_time')
                    ->sum('amount_in');

                if ($paid + 0.0001 < (float) $invoice->total) {
                    $invoice->status = 'Unpaid';
                    $invoice->paid_time = 0;
                    $invoice->update_time = time();
                    $invoice->save();
                }
            }
        }

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET invoice/option_page/<id>` — 编辑账单 dialog metadata.
     */
    public function optionPage(Request $request, $id)
    {
        $invoice = Invoice::query()->find((int) $id);

        if ($invoice === null) {
            return $this->fail('ID错误');
        }

        return $this->ok([
            'invoice' => (array) $invoice->toArray(),
            'status' => AdminMeta::INVOICE_STATUS,
            'payment' => AdminMeta::gateways(),
            'type' => $this->invoiceTypes(),
        ]);
    }

    /**
     * `POST invoice/option` — save the invoice header.
     */
    public function option(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $data = [];

        foreach (['notes', 'status', 'payment', 'type'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($request->has('create_time')) {
            $value = $this->timestamp($request->input('create_time'));

            if ($value) {
                $data['create_time'] = $value;
            }
        }

        if ($request->has('due_time')) {
            $value = $this->timestamp($request->input('due_time'));

            if ($value) {
                $data['due_time'] = $value;
            }
        }

        $data['update_time'] = time();

        $invoice->fill($data)->save();

        $this->log('编辑账单 #'.$invoice->id, (int) $invoice->id);

        return $this->ok(['id' => (int) $invoice->id], '保存成功');
    }

    /**
     * `POST invoice/apply_credit_limit` — settle an invoice from the credit
     * line instead of the balance.
     */
    public function applyCreditLimit(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $client = Client::query()->find($invoice->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        if (! $client->is_open_credit_limit) {
            return $this->fail('该客户未开启信用额');
        }

        $available = (float) $client->credit_limit - (float) $client->credit_limit_balance;

        if ($available + 0.0001 < (float) $invoice->total) {
            return $this->fail('信用额不足');
        }

        DB::transaction(function () use ($invoice, $client) {
            $client->credit_limit_balance = $this->money((float) $client->credit_limit_balance + (float) $invoice->total);
            $client->save();

            $invoice->use_credit_limit = 1;
            $invoice->payment = 'CreditLimit';
            $invoice->status = 'Paid';
            $invoice->paid_time = time();
            $invoice->update_time = time();
            $invoice->save();

            DB::table('credit_limit')->insert([
                'uid' => $client->id,
                'create_time' => time(),
                'description' => '信用额支付账单 #'.$invoice->id,
                'type' => 'payment',
                'notes' => '',
                'handle_id' => $this->adminId(),
                'ip' => (string) request()->ip(),
            ]);
        });

        $this->log('信用额支付账单 #'.$invoice->id, (int) $invoice->id);

        return $this->ok(null, '支付成功');
    }

    /**
     * `GET invoice/refund_page {id}` — 退款 dialog metadata.
     */
    public function refundPage(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->fail('ID错误');
        }

        $paid = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->sum('amount_in');
        $refunded = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->where('refund', 1)->sum('amount_out');

        return $this->ok([
            'invoice' => (array) $invoice->toArray(),
            'paid' => $this->money($paid),
            'refunded' => $this->money($refunded),
            'diff_amount' => $this->money($paid - $refunded),
            'type' => ['credit' => '退回余额', 'gateway' => '原路退回'],
            'gateway' => AdminMeta::gateways(),
        ]);
    }

    /**
     * `POST invoice/refund` — refund an invoice.
     */
    public function refund(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));
        $paid = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->whereNull('delete_time')->sum('amount_in');

        if ($amount <= 0) {
            return $this->validationFail('退款金额必须大于0');
        }

        if ($amount > $paid + 0.0001) {
            return $this->validationFail('退款金额不能超过已付金额');
        }

        $type = (string) $request->input('type', 'credit');

        DB::transaction(function () use ($invoice, $amount, $type) {
            $client = Client::query()->find($invoice->uid);

            if ($type === 'credit' && $client !== null) {
                $client->addCredit($amount, '账单退款 #'.$invoice->id, (int) $invoice->id);
            }

            DB::table('accounts')->insert([
                'uid' => $invoice->uid,
                'currency' => (string) (DB::table('currencies')->where('id', 1)->value('code') ?? 'CNY'),
                'gateway' => (string) request()->input('payment', ''),
                'create_time' => time(),
                'update_time' => time(),
                'pay_time' => time(),
                'description' => '账单退款 #'.$invoice->id,
                'amount_in' => 0,
                'fees' => 0,
                'amount_out' => $amount,
                'rate' => 1,
                'trans_id' => '',
                'invoice_id' => $invoice->id,
                'refund' => 1,
            ]);

            $refunded = (float) DB::table('accounts')->where('invoice_id', $invoice->id)->where('refund', 1)->sum('amount_out');

            if ($refunded + 0.0001 >= $paid && (string) $invoice->status === 'Paid') {
                $invoice->status = 'Refunded';
                $invoice->update_time = time();
                $invoice->save();
            }
        });

        $this->log('账单退款 #'.$invoice->id.' 金额 '.$amount, (int) $invoice->id);

        return $this->ok(['amount' => $amount], '退款成功');
    }

    /**
     * `GET invoice/notes_page {id}` — 备注 dialog.
     */
    public function notesPage(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->fail('ID错误');
        }

        return $this->ok([
            'id' => (int) $invoice->id,
            'notes' => $invoice->notes,
        ]);
    }

    /**
     * `POST invoice/notes` — save 备注.
     */
    public function notes(Request $request)
    {
        $invoice = Invoice::query()->find((int) $request->input('id', 0));

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        $invoice->notes = (string) $request->input('notes', '');
        $invoice->update_time = time();
        $invoice->save();

        return $this->ok(null, '保存成功');
    }

    /**
     * `DELETE invoice/delete_item {id}` — remove a line item and re-total.
     */
    public function deleteItem(Request $request)
    {
        $item = InvoiceItem::query()->find((int) $request->input('id', 0));

        if ($item === null) {
            return $this->notFound('账单项目不存在');
        }

        DB::transaction(function () use ($item) {
            $item->delete_time = time();
            $item->save();

            $this->recalculate($item->invoice_id);
        });

        return $this->ok(null, '删除成功');
    }

    /**
     * `POST invoice/edit_item` — edit a line item and re-total.
     */
    public function editItem(Request $request)
    {
        $item = InvoiceItem::query()->find((int) $request->input('id', 0));

        if ($item === null) {
            return $this->notFound('账单项目不存在');
        }

        DB::transaction(function () use ($item, $request) {
            if ($request->has('description')) {
                $item->description = (string) $request->input('description');
            }

            if ($request->has('amount')) {
                $item->amount = $this->money((float) $request->input('amount'));
            }

            if ($request->has('notes')) {
                $item->notes = (string) $request->input('notes');
            }

            $item->save();

            $this->recalculate($item->invoice_id);
        });

        $this->log('编辑账单项目 #'.$item->id, (int) $item->invoice_id);

        return $this->ok(['id' => (int) $item->id], '保存成功');
    }

    /**
     * `DELETE invoice/delete_account/<id>` — remove a payment row.
     */
    public function deleteAccount(Request $request, $id)
    {
        DB::table('accounts')->where('id', (int) $id)->update(['delete_time' => time()]);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET invoice/log_list` — the operation log of one invoice.
     */
    public function logList(Request $request)
    {
        $invoiceId = (int) $request->input('id', $request->input('invoice_id', 0));
        [$page, $limit] = $this->pageParams($request);

        $query = DB::table('activity_log');

        if ($invoiceId > 0) {
            $query->where('type_data_id', $invoiceId)->where('type', 2);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(fn ($r) => (array) $r)->all();

        return $this->okFlat('请求成功', [
            'data' => $rows,
            'list' => $rows,
            'count' => $total,
        ]);
    }

    /**
     * `GET invoice/renew` — the 续费订单 table.
     */
    public function renewList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = Invoice::query()->where('type', 'renew');

        if ($username = trim((string) $request->input('username', ''))) {
            $uids = Client::query()->where('username', 'like', "%{$username}%")->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        if ($payment = $request->input('payment')) {
            $payment === 'ALL' ?: $query->where('payment', $payment);
        }

        if ($request->filled('amount')) {
            $query->where('total', (float) $request->input('amount'));
        }

        $time = $request->input('searchTime');

        if (is_array($time)) {
            [$start, $end] = $this->timeRange($time);

            if ($start) {
                $query->where('paid_time', '>=', $start);
            }

            if ($end) {
                $query->where('paid_time', '<=', $end);
            }
        }

        $total = (clone $query)->count();
        $sum = (float) (clone $query)->sum('total');
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'list' => $this->decorateInvoices($rows),
            'count' => $total,
            'price_total' => $this->money($sum),
            'price_total_page' => $this->money((float) $rows->sum('total')),
        ]);
    }

    /**
     * `POST invoices_createnew` — generate the renewal invoice(s) for a host.
     *
     * Thin wrapper over the service layer so the 续费订单 page can create a
     * bill without going through the full order flow.
     */
    public function createRenew(Request $request)
    {
        $hostId = (int) $request->input('hostid', $request->input('id', 0));

        $host = \App\Models\Host::query()->find($hostId);

        if ($host === null) {
            return $this->notFound('产品不存在');
        }

        $client = Client::query()->find($host->uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $cycle = (string) ($request->input('billingcycle') ?: $host->billingcycle ?: 'monthly');
        $amount = $request->filled('amount')
            ? $this->money((float) $request->input('amount'))
            : $this->money((float) (app(\App\Services\PricingService::class)->cyclePrice($host->product, $cycle) ?? $host->amount));

        if ($amount <= 0) {
            return $this->validationFail('金额必须大于0');
        }

        $invoice = app(InvoiceService::class)->create(
            $client,
            [[
                'type' => 'renew',
                'rel_id' => (int) $host->id,
                'description' => '续费 - '.($host->product?->name ?? $host->domain),
                'amount' => $amount,
            ]],
            $this->timestamp($request->input('due_time')) ?? time() + 86400 * 7,
            'renew',
        );

        $this->log('生成续费账单 #'.$invoice->id, (int) $invoice->id);

        return $this->ok(['id' => (int) $invoice->id, 'amount' => $amount], '创建成功');
    }

    /**
     * `GET invoice/type/list` — bill-type dictionary.
     */
    public function typeList(Request $request)
    {
        return $this->ok($this->invoiceTypes());
    }

    // -----------------------------------------------------------------
    // 交易流水
    // -----------------------------------------------------------------

    /**
     * `GET accounts` — 交易流水 with the per-currency summary row.
     */
    public function accounts(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        [$orderBy, $sort] = $this->sortParams($request, ['id', 'create_time', 'amount_in', 'amount_out'], 'id');

        $query = DB::table('accounts')->whereNull('accounts.delete_time');

        $this->applyAccountFilters($query, $request);

        $total = (clone $query)->count();
        $rows = $query->orderBy('accounts.'.$orderBy, $sort)->forPage($page, $limit)->get();

        $list = $rows->map(function ($row) {
            $entry = (array) $row;
            $entry['username'] = (string) (Client::query()->whereKey($row->uid)->value('username') ?? '');
            $entry['amount_in'] = $this->money((float) $row->amount_in);
            $entry['amount_out'] = $this->money((float) $row->amount_out);
            $entry['type_zh'] = $this->accountTypeLabel((int) ($row->refund ?? 0), (float) $row->amount_in);
            $entry['sale_id'] = (int) (Client::query()->whereKey($row->uid)->value('sale_id') ?? 0);
            $entry['user_nickname'] = (string) (\App\Models\User::query()->whereKey($entry['sale_id'])->value('user_nickname') ?? '');

            return $entry;
        })->all();

        // Summary row: 总收入 / 总支出 / 总结余, per currency.
        $summary = [];
        $code = (string) (Currency::query()->orderBy('id')->value('code') ?? 'CNY');

        $amountIn = (float) (clone $query)->sum('amount_in');
        $amountOut = (float) (clone $query)->sum('amount_out');
        $fees = (float) (clone $query)->sum('fees');
        $prefix = (string) (Currency::query()->orderBy('id')->value('prefix') ?? '¥');

        $summary[$code] = [
            'amount_in' => $prefix.$this->money($amountIn).'元',
            'amount_out' => $prefix.$this->money($amountOut).'元',
            'fees' => $prefix.$this->money($fees).'元',
            'surplus' => $prefix.$this->money($amountIn - $amountOut).'元',
        ];

        return $this->okFlat('请求成功', [
            'data' => $list,
            'page' => [
                'total' => $total,
                'current' => $page,
                'limit' => $limit,
            ],
            'count' => $summary,
            'amount_in_totals' => $prefix.$this->money($amountIn).'元',
            'currency_id' => $code,
        ]);
    }

    /**
     * `GET accounts/create` — the 添加交易 form metadata.
     */
    public function accountCreate(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->ok([
            'client' => $uid > 0 ? (array) (Client::query()->find($uid)?->toArray() ?? []) : null,
            'gateway' => AdminMeta::gateways(),
            'currencies' => AdminMeta::currencies(),
            'type' => ['in' => '收入', 'out' => '支出'],
        ]);
    }

    /**
     * `GET accounts/createinvoice` — inline invoice creation from the
     * transaction form.
     */
    public function accountCreateInvoice(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));

        if ($amount <= 0) {
            return $this->validationFail('金额必须大于0');
        }

        $invoice = app(InvoiceService::class)->create(
            $client,
            [[
                'type' => (string) $request->input('type', 'other'),
                'rel_id' => 0,
                'description' => (string) ($request->input('description') ?: '后台创建账单'),
                'amount' => $amount,
            ]],
            $this->timestamp($request->input('due_time')) ?? time() + 86400 * 7,
        );

        return $this->ok(['id' => (int) $invoice->id], '创建成功');
    }

    /**
     * `POST accounts` — record a transaction.
     */
    public function accountSave(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->validationFail('请选择客户');
        }

        $amountIn = $this->money((float) $request->input('amount_in', 0));
        $amountOut = $this->money((float) $request->input('amount_out', 0));

        if ($amountIn <= 0 && $amountOut <= 0) {
            return $this->validationFail('金额必须大于0');
        }

        $id = DB::transaction(function () use ($request, $client, $amountIn, $amountOut) {
            $id = (int) DB::table('accounts')->insertGetId([
                'uid' => $client->id,
                'currency' => (string) $request->input('currency', 'CNY'),
                'gateway' => (string) $request->input('gateway', ''),
                'create_time' => time(),
                'update_time' => time(),
                'pay_time' => $this->timestamp($request->input('pay_time')) ?? time(),
                'description' => (string) $request->input('description', ''),
                'amount_in' => $amountIn,
                'fees' => $this->money((float) $request->input('fees', 0)),
                'amount_out' => $amountOut,
                'rate' => (float) $request->input('rate', 1),
                'trans_id' => (string) $request->input('trans_id', ''),
                'invoice_id' => (int) $request->input('invoice_id', 0),
                'refund' => 0,
            ]);

            // Keep the customer's balance in step with manual adjustments.
            $delta = $amountIn - $amountOut;

            if (abs($delta) > 0.0001) {
                $client->addCredit($delta, (string) $request->input('description', '后台交易记录'));
            }

            return $id;
        });

        $this->log('添加交易记录 #'.$id.' 客户'.$client->username, (int) $client->id);

        return $this->ok(['id' => $id], '添加成功');
    }

    /**
     * `GET accounts/<id>`
     */
    public function accountRead(Request $request, $id)
    {
        $row = DB::table('accounts')->where('id', (int) $id)->first();

        return $row === null ? $this->notFound('交易记录不存在') : $this->ok((array) $row);
    }

    /**
     * `PUT accounts/<id>`
     */
    public function accountUpdate(Request $request, $id)
    {
        $row = DB::table('accounts')->where('id', (int) $id)->first();

        if ($row === null) {
            return $this->notFound('交易记录不存在');
        }

        $data = [];

        foreach (['description', 'gateway', 'trans_id'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->input($field);
            }
        }

        if ($request->has('amount_in')) {
            $data['amount_in'] = $this->money((float) $request->input('amount_in'));
        }

        if ($request->has('amount_out')) {
            $data['amount_out'] = $this->money((float) $request->input('amount_out'));
        }

        $data['update_time'] = time();

        DB::table('accounts')->where('id', (int) $id)->update($data);

        return $this->ok(['id' => (int) $id], '保存成功');
    }

    /**
     * `DELETE accounts/<id>`
     */
    public function accountDelete(Request $request, $id)
    {
        DB::table('accounts')->where('id', (int) $id)->update(['delete_time' => time()]);

        return $this->ok(null, '删除成功');
    }

    /**
     * `GET accounts_search` — transaction search variant.
     */
    public function accountSearch(Request $request)
    {
        return $this->accounts($request);
    }

    // -----------------------------------------------------------------
    // 信用额
    // -----------------------------------------------------------------

    /**
     * `GET credit_limit {uid}` — one client's credit line.
     */
    public function creditLimit(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        return $this->respond([
            'user' => [
                'id' => (int) $client->id,
                'username' => $client->username,
                'credit' => $this->money((float) $client->credit),
                'credit_limit' => $this->money((float) $client->credit_limit),
                'credit_limit_balance' => $this->money((float) $client->credit_limit_balance),
                'is_open_credit_limit' => (int) $client->is_open_credit_limit,
                'bill_generation_date' => (int) $client->bill_generation_date,
                'bill_repayment_period' => (int) $client->bill_repayment_period,
                'repayment_date' => (int) $client->repayment_date,
            ],
            'credit_limit_config' => SettingService::group('credit_limit'),
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
        ] + $this->envelope());
    }

    /**
     * `POST credit_limit` — open a credit line.
     */
    public function creditLimitCreate(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $amount = $this->money((float) $request->input('credit_limit', 0));

        if ($amount <= 0) {
            return $this->validationFail('信用额必须大于0');
        }

        $generationDate = (int) $request->input('bill_generation_date', 1);
        $period = (int) $request->input('bill_repayment_period', 30);

        $client->credit_limit = $amount;
        $client->credit_limit_balance = 0;
        $client->is_open_credit_limit = 1;
        $client->bill_generation_date = $generationDate;
        $client->bill_repayment_period = $period;
        $client->credit_limit_create_time = time();
        $client->repayment_date = $this->nextRepaymentDate($generationDate, $period);
        $client->update_time = time();
        $client->save();

        DB::table('credit_limit')->insert([
            'uid' => $client->id,
            'create_time' => time(),
            'description' => '开通信用额：'.$amount,
            'type' => 'create',
            'notes' => '',
            'handle_id' => $this->adminId(),
            'ip' => (string) request()->ip(),
        ]);

        $this->log('开通客户'.$client->username.'信用额 '.$amount, $uid);

        return $this->ok(['uid' => $uid], '开通成功');
    }

    /**
     * `PUT credit_limit` — change the limit.
     */
    public function creditLimitUpdate(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        $old = (float) $client->credit_limit;
        $amount = $this->money((float) $request->input('credit_limit', $old));

        if ($amount < (float) $client->credit_limit_balance) {
            return $this->validationFail('信用额不能小于已使用金额');
        }

        $client->credit_limit = $amount;
        $client->is_open_credit_limit = $amount > 0 ? 1 : 0;

        if ($request->has('bill_generation_date')) {
            $client->bill_generation_date = (int) $request->input('bill_generation_date');
        }

        if ($request->has('bill_repayment_period')) {
            $client->bill_repayment_period = (int) $request->input('bill_repayment_period');
        }

        $client->update_time = time();
        $client->save();

        DB::table('credit_limit')->insert([
            'uid' => $client->id,
            'create_time' => time(),
            'description' => '调整信用额：'.$old.' → '.$amount,
            'type' => 'update',
            'notes' => '',
            'handle_id' => $this->adminId(),
            'ip' => (string) request()->ip(),
        ]);

        return $this->ok(['uid' => $uid], '修改成功');
    }

    /**
     * `DELETE credit_limit {uid}` — close the credit line.
     */
    public function creditLimitDelete(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('用户不存在');
        }

        if ((float) $client->credit_limit_balance > 0) {
            return $this->fail('该客户还有未结清的信用额账单，不能关闭');
        }

        $client->credit_limit = 0;
        $client->credit_limit_balance = 0;
        $client->is_open_credit_limit = 0;
        $client->update_time = time();
        $client->save();

        DB::table('credit_limit')->insert([
            'uid' => $client->id,
            'create_time' => time(),
            'description' => '关闭信用额',
            'type' => 'delete',
            'notes' => '',
            'handle_id' => $this->adminId(),
            'ip' => (string) request()->ip(),
        ]);

        return $this->ok(['uid' => $uid], '关闭成功');
    }

    /**
     * `GET credit_limit/list` — 信用额管理 → 支付 tab.
     */
    public function creditLimitList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request, 10);

        $query = Invoice::query()->where('use_credit_limit', 1);

        if ($status = $request->input('status')) {
            $status === 'ALL' ?: $query->where('status', $status);
        }

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_num', 'like', '%'.$request->input('invoice_id').'%');
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'count' => $total,
            'invoices' => $this->decorateInvoices($rows),
            'invoice_status' => AdminMeta::INVOICE_STATUS,
            'credit_limit_invoice_status' => AdminMeta::INVOICE_STATUS,
        ]);
    }

    /**
     * `GET credit_limit/user_invoice` — 还款 tab.
     */
    public function creditLimitUserInvoice(Request $request)
    {
        [$page, $limit] = $this->pageParams($request, 10);

        $query = Invoice::query();

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        if ($username = trim((string) $request->input('username', ''))) {
            $uids = Client::query()->where('username', 'like', "%{$username}%")->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        if (($status = $request->input('payment_status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        return $this->okFlat('请求成功', [
            'count' => $total,
            'invoices' => $this->decorateInvoices($rows),
            'invoice_status' => AdminMeta::INVOICE_STATUS,
            'credit_limit_invoice_status' => AdminMeta::INVOICE_STATUS,
        ]);
    }

    /**
     * `GET credit_limit/user_invoice_detail {id}` — one invoice's items.
     */
    public function creditLimitUserInvoiceDetail(Request $request)
    {
        $id = (int) $request->input('id', 0);

        if ($id <= 0) {
            return $this->fail('ID_ERROR');
        }

        $invoice = Invoice::query()->find($id);

        if ($invoice === null) {
            return $this->notFound('账单不存在');
        }

        return $this->ok([
            'invoice' => (array) $invoice->toArray(),
            'items' => InvoiceItem::query()->where('invoice_id', $id)->whereNull('delete_time')->get()->map(fn ($i) => (array) $i->toArray())->all(),
        ]);
    }

    /**
     * `GET credit_limit/log {uid}` — the change log.
     */
    public function creditLimitLog(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);
        $uid = (int) $request->input('uid', 0);

        $query = DB::table('credit_limit')->when($uid > 0, fn ($q) => $q->where('uid', $uid));
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get()->map(function ($row) {
            $entry = (array) $row;
            $entry['admin'] = (string) (DB::table('user')->where('id', $row->handle_id)->value('user_login') ?? '');

            return $entry;
        })->all();

        return $this->okFlat('请求成功', [
            'data' => $rows,
            'list' => $rows,
            'count' => $total,
        ]);
    }

    /**
     * `GET credit_limit/client_list` — 客户 tab / 客户资源池.
     */
    public function creditLimitClientList(Request $request)
    {
        [$page, $limit] = $this->pageParams($request);

        $query = Client::query()->where('is_open_credit_limit', 1);

        if ($username = trim((string) $request->input('username', ''))) {
            $query->where('username', 'like', "%{$username}%");
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('id')->forPage($page, $limit)->get();

        $list = $rows->map(function (Client $client) {
            return [
                'id' => (int) $client->id,
                'uid' => (int) $client->id,
                'username' => $client->username,
                'credit' => $this->money((float) $client->credit),
                'credit_limit' => $this->money((float) $client->credit_limit),
                'credit_limit_balance' => $this->money((float) $client->credit_limit_balance),
                'repayment_date' => (int) $client->repayment_date,
                'bill_generation_date' => (int) $client->bill_generation_date,
                'bill_repayment_period' => (int) $client->bill_repayment_period,
            ];
        })->all();

        return $this->okFlat('请求成功', [
            'total' => $total,
            'list' => $list,
            'data' => $list,
            'credit_limit_invoice_status' => AdminMeta::INVOICE_STATUS,
        ]);
    }

    /**
     * `GET credit_limit/config` — 信用额设置.
     */
    public function creditLimitConfig(Request $request)
    {
        $config = SettingService::group('credit_limit');

        return $this->respond(array_merge(
            ['status' => ApiResponse::OK, 'msg' => '请求成功'],
            $config,
            $this->envelope(),
        ));
    }

    /**
     * `POST credit_limit/config` — save 信用额设置.
     */
    public function creditLimitConfigSave(Request $request)
    {
        SettingService::saveGroup('credit_limit', $request->all());

        $this->log('保存信用额设置');

        return $this->ok(null, '保存成功');
    }

    /**
     * `GET credit {uid}` — the client-detail 信用管理 tab.
     */
    public function creditList(Request $request)
    {
        $uid = (int) $request->input('uid', 0);

        return $this->respond(array_merge([
            'data' => DB::table('credit')
                ->when($uid > 0, fn ($q) => $q->where('uid', $uid))
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(fn ($c) => (array) $c)
                ->all(),
            'count' => DB::table('credit')->when($uid > 0, fn ($q) => $q->where('uid', $uid))->count(),
            'user' => $uid > 0 ? (array) (Client::query()->find($uid)?->toArray() ?? []) : null,
            'status' => ApiResponse::OK,
            'msg' => '请求成功',
        ], $this->envelope()));
    }

    /**
     * `POST credit/create` — a manual balance adjustment.
     */
    public function creditCreate(Request $request)
    {
        $uid = (int) $request->input('uid', 0);
        $client = Client::query()->find($uid);

        if ($client === null) {
            return $this->notFound('客户不存在');
        }

        $amount = $this->money((float) $request->input('amount', 0));

        if (abs($amount) < 0.0001) {
            return $this->validationFail('金额不能为0');
        }

        $credit = $client->addCredit($amount, (string) ($request->input('description') ?: '管理员调整余额'), 0);

        $this->log('调整客户'.$client->username.'余额 '.$amount, $uid);

        return $this->ok(['id' => (int) $credit->id, 'balance' => $this->money((float) $client->credit)], '操作成功');
    }

    /**
     * `DELETE credit/<id>`
     */
    public function creditDelete(Request $request, $id)
    {
        DB::table('credit')->where('id', (int) $id)->delete();

        return $this->ok(null, '删除成功');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Base query for the 账单管理 filters.
     */
    private function invoiceQuery(Request $request)
    {
        $query = Invoice::query()->whereNull('delete_time')->where('is_delete', 0);

        if ($uid = $request->input('uid')) {
            $query->where('uid', (int) $uid);
        }

        if ($username = trim((string) $request->input('username', ''))) {
            $uids = Client::query()
                ->where(function ($q) use ($username) {
                    $q->where('username', 'like', "%{$username}%")
                        ->orWhere('email', 'like', "%{$username}%")
                        ->orWhere('companyname', 'like', "%{$username}%");
                })
                ->pluck('id')
                ->all();

            $query->whereIn('uid', $uids ?: [-1]);
        }

        if ($invoiceNum = trim((string) $request->input('invoice_id', ''))) {
            $query->where('invoice_num', 'like', "%{$invoiceNum}%");
        }

        if (($status = $request->input('status')) !== null && $status !== '' && $status !== 'ALL') {
            $query->where('status', $status);
        }

        if (($payment = $request->input('payment')) !== null && $payment !== '' && $payment !== 'ALL') {
            $query->where('payment', $payment);
        }

        if (($type = $request->input('type')) !== null && $type !== '' && $type !== 'ALL') {
            $query->where('type', $type);
        }

        if ($saleId = $request->input('sale_id')) {
            $uids = Client::query()->where('sale_id', (int) $saleId)->pluck('id')->all();
            $query->whereIn('uid', $uids ?: [-1]);
        }

        foreach (['create_time_bak' => 'create_time', 'due_time_bak' => 'due_time', 'paid_time_bak' => 'paid_time'] as $input => $column) {
            $value = $request->input($input);

            if (! is_array($value)) {
                continue;
            }

            [$start, $end] = $this->timeRange($value);

            if ($start) {
                $query->where($column, '>=', $start);
            }

            if ($end) {
                $query->where($column, '<=', $end);
            }
        }

        return $query;
    }

    /**
     * `GET invoice/paid|unpaid|cancelled` implementation.
     */
    private function markStatus(Request $request, string $status)
    {
        $ids = $this->ids($request);

        if ($ids === []) {
            return $this->validationFail('ID错误');
        }

        $invoices = Invoice::query()->whereIn('id', $ids)->get();

        if ($invoices->isEmpty()) {
            return $this->notFound('账单不存在');
        }

        foreach ($invoices as $invoice) {
            if ($status === 'Paid') {
                // Recording a payment is the only way to reach Paid, so the
                // original's shortcut writes a zero-fee transaction row too.
                app(InvoiceService::class)->markPaid($invoice, (string) $invoice->payment, (float) $invoice->total);
            } elseif ($status === 'Cancelled') {
                if ((string) $invoice->status === 'Paid') {
                    return $this->fail('已支付的账单不能取消');
                }

                $invoice->status = 'Cancelled';
                $invoice->update_time = time();
                $invoice->save();
            } else {
                $invoice->status = 'Unpaid';
                $invoice->paid_time = 0;
                $invoice->payment = '';
                $invoice->update_time = time();
                $invoice->save();
            }
        }

        $this->log('账单状态变更：'.implode(',', $ids).' → '.$status);

        return $this->ok(['ids' => $ids, 'status' => $status], '操作成功');
    }

    /**
     * Decorate invoice rows with their client and labels.
     */
    private function decorateInvoices($rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $uids = $rows->pluck('uid')->unique()->all();
        $clients = Client::query()->whereIn('id', $uids)->get()->keyBy('id');
        $staff = \App\Models\User::query()->pluck('user_nickname', 'id');

        return $rows->map(function (Invoice $invoice) use ($clients, $staff) {
            $client = $clients[$invoice->uid] ?? null;

            return [
                'id' => (int) $invoice->id,
                'uid' => (int) $invoice->uid,
                'invoice_num' => $invoice->invoice_num,
                'username' => $client?->username,
                'companyname' => $client?->companyname,
                'create_time' => (int) $invoice->create_time,
                'due_time' => (int) $invoice->due_time,
                'paid_time' => (int) $invoice->paid_time,
                'subtotal' => $this->money((float) $invoice->subtotal),
                'total' => $this->money((float) $invoice->total),
                'tax' => $this->money((float) $invoice->tax),
                'payment' => $invoice->payment,
                'status' => (string) $invoice->status,
                'status_zh' => AdminMeta::invoiceStatusLabel((string) $invoice->status),
                'type' => (string) $invoice->type,
                'type_zh' => $this->invoiceTypes()[(string) $invoice->type] ?? (string) $invoice->type,
                'notes' => $invoice->notes,
                'use_credit_limit' => (int) $invoice->use_credit_limit,
                'sale_id' => (int) ($client->sale_id ?? 0),
                'user_nickname' => (string) ($staff[$client->sale_id ?? 0] ?? ''),
            ];
        })->all();
    }

    /**
     * Recompute an invoice's totals after its items changed.
     */
    private function recalculate(int $invoiceId): void
    {
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null) {
            return;
        }

        $subtotal = (float) InvoiceItem::query()
            ->where('invoice_id', $invoiceId)
            ->whereNull('delete_time')
            ->sum('amount');

        $invoice->subtotal = $this->money($subtotal);
        $invoice->total = $this->money($subtotal + (float) $invoice->tax + (float) $invoice->tax2);
        $invoice->update_time = time();
        $invoice->save();
    }

    /**
     * Apply the 交易流水 filters.
     */
    private function applyAccountFilters($query, Request $request): void
    {
        if ($uid = $request->input('uid', $request->input('client_id'))) {
            $query->where('accounts.uid', (int) $uid);
        }

        if ($description = trim((string) $request->input('description', ''))) {
            $query->where('accounts.description', 'like', "%{$description}%");
        }

        if ($request->filled('amount')) {
            $query->where(function ($q) use ($request) {
                $amount = (float) $request->input('amount');
                $q->where('accounts.amount_in', $amount)->orWhere('accounts.amount_out', $amount);
            });
        }

        if ($transId = trim((string) $request->input('trans_id', ''))) {
            $query->where('accounts.trans_id', 'like', "%{$transId}%");
        }

        if ($gateway = $request->input('gateway')) {
            $gateway === 'ALL' ?: $query->where('accounts.gateway', $gateway);
        }

        if ($saleId = $request->input('sale_id')) {
            $uids = Client::query()->where('sale_id', (int) $saleId)->pluck('id')->all();
            $query->whereIn('accounts.uid', $uids ?: [-1]);
        }

        if ($start = $this->timestamp($request->input('start_time'))) {
            $query->where('accounts.create_time', '>=', $start);
        }

        if ($end = $this->timestamp($request->input('end_time'))) {
            $query->where('accounts.create_time', '<=', $end);
        }

        $type = $request->input('type');

        if ($type === 'in') {
            $query->where('accounts.amount_in', '>', 0);
        } elseif ($type === 'out') {
            $query->where('accounts.amount_out', '>', 0);
        } elseif ($type === 'refund') {
            $query->where('accounts.refund', 1);
        }
    }

    private function accountTypeLabel(int $refund, float $amountIn): string
    {
        if ($refund === 1) {
            return '退款';
        }

        return $amountIn > 0 ? '收入' : '支出';
    }

    /**
     * Read invoice ids from any of the shapes a row action posts.
     *
     * @return array<int,int>
     */
    private function ids(Request $request): array
    {
        $ids = $request->input('id', $request->input('ids', $request->input('data', [])));

        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (! is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Bill types as `code => label`.
     */
    private function invoiceTypes(): array
    {
        $fromDb = DB::table('invoices')
            ->select('type')
            ->distinct()
            ->pluck('type')
            ->filter()
            ->all();

        $known = [
            'hosting' => '产品',
            'renew' => '续费',
            'recharge' => '充值',
            'upgrade' => '升级',
            'other' => '其他',
            'credit_limit' => '信用额',
            'withdraw' => '提现',
        ];

        foreach ($fromDb as $type) {
            $known[$type] ??= $type;
        }

        return $known;
    }

    /**
     * The status tabs above the invoice table.
     */
    private function tabs(): array
    {
        $tabs = [['label' => '全部', 'value' => 'ALL']];

        foreach (AdminMeta::INVOICE_STATUS as $value => $meta) {
            $tabs[] = ['label' => $meta['name'], 'value' => $value];
        }

        return $tabs;
    }

    /**
     * 出账日 + 最后还款日 → the next repayment timestamp the schema stores.
     */
    private function nextRepaymentDate(int $generationDate, int $period): int
    {
        $day = max(1, min(28, $generationDate));
        $now = time();
        $generation = mktime(0, 0, 0, (int) date('n', $now), $day, (int) date('Y', $now));

        if ($generation < $now) {
            $generation = mktime(0, 0, 0, (int) date('n', $now) + 1, $day, (int) date('Y', $now));
        }

        return $generation + max(0, $period) * 86400;
    }
}
