@extends('layouts.portal')

@section('title', '账单 '.$invoice->number)
@section('eyebrow', 'KJAIU / INVOICE DETAIL')
@section('description', '付款以账户余额扣款记录或人工到账确认结果为准。')
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.invoices.index') }}">← 返回账单</a>
    @if ($invoice->order)<a class="button button-secondary" href="{{ route('portal.orders.show', $invoice->order) }}">查看订单 →</a>@endif
@endsection

@section('content')
    <section class="invoice-hero portal-invoice-hero">
        <div class="invoice-brand-block">
            <span class="brand-mark brand-mark-large">K<span></span></span>
            <div><p>INVOICE NUMBER</p><h2>{{ $invoice->number }}</h2><span>开具于 {{ $invoice->created_at->format('Y-m-d H:i') }}</span></div>
        </div>
        <div class="invoice-total-block">
            <p>INVOICE TOTAL</p>
            <strong><small>¥</small>{{ number_format((float) $invoice->total, 2) }}</strong>
            <span class="status status-{{ strtolower($invoice->status) }}">{{ ['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status }}</span>
        </div>
    </section>

    <section class="invoice-meta-grid">
        <article><span>账单状态</span><strong>{{ ['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status }}</strong></article>
        <article><span>到期时间</span><strong>{{ $invoice->due_at?->format('Y-m-d H:i') ?? '—' }}</strong></article>
        <article><span>付款方式</span><strong>{{ $invoice->payment_method ?: '未选择' }}</strong></article>
        <article><span>支付时间</span><strong>{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '尚未支付' }}</strong></article>
    </section>

    @if ($invoice->status === 'Unpaid' && $invoice->payment_method && strcasecmp($invoice->payment_method, 'Credit') !== 0)
        <div class="notice notice-pending" role="status">
            <span class="notice-icon">i</span>
            <span>账单 {{ $invoice->number }} 尚未完成付款。请联系 {{ config('kjaiu.company_email') }} 并提供账单号获取 {{ $invoice->payment_method }} 付款指引；到账确认前状态保持待支付。</span>
        </div>
    @endif

    <section class="dashboard-grid invoice-detail-grid">
        <article class="panel invoice-lines-panel">
            <header class="panel-head"><div><p class="panel-kicker">LINE ITEMS</p><h2>账单明细</h2></div></header>
            <div class="table-scroll">
                <table class="data-table invoice-lines">
                    <thead><tr><th>说明</th><th>类型</th><th>周期</th><th class="align-right">金额</th></tr></thead>
                    <tbody>
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td data-label="说明"><strong>{{ $item->description }}</strong></td>
                            <td data-label="类型"><span class="type-chip">{{ $item->type }}</span></td>
                            <td data-label="周期">{{ $item->billing_cycle ?: '—' }}</td>
                            <td data-label="金额" class="money-cell align-right">¥{{ number_format((float) $item->amount, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="align-right">小计</td><td class="money-cell align-right">¥{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
                        @if ((float) $invoice->credit > 0)<tr><td colspan="3" class="align-right">已用余额</td><td class="money-cell align-right">¥{{ number_format((float) $invoice->credit, 2) }}</td></tr>@endif
                        <tr class="grand-total"><td colspan="3" class="align-right">账单总额</td><td class="money-cell align-right">¥{{ number_format((float) $invoice->total, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </article>

        <aside class="invoice-side-stack">
            @if ($invoice->status === 'Unpaid')
                <article class="panel">
                    <header class="panel-head"><div><p class="panel-kicker">PAYMENT</p><h2>选择支付方式</h2></div></header>
                    <form class="portal-form" method="POST" action="{{ route('portal.invoices.payment', $invoice) }}" data-confirm="确认使用所选方式处理这笔账单吗？">
                        @csrf
                        <label class="field">
                            <span>支付方式</span>
                            <select name="payment" required>
                                <option value="Credit">账户余额（¥{{ number_format((float) auth()->user()->credit, 2) }}）</option>
                                @foreach ($gateways as $gateway)<option value="{{ $gateway->name }}">{{ $gateway->title }}</option>@endforeach
                            </select>
                        </label>
                        <p class="gateway-disclaimer">余额支付会立即扣款。选择外部方式后，请联系 {{ config('kjaiu.company_email') }} 并提供账单号 {{ $invoice->number }} 获取付款指引；到账确认前状态保持待支付。</p>
                        <button class="button button-primary button-wide" type="submit"><span>确认支付方式</span><span>↗</span></button>
                    </form>
                    @if ($canCancelRenewal)
                        <form class="portal-form renewal-cancel-form" method="POST" action="{{ route('portal.invoices.renewal.cancel', $invoice) }}" data-confirm="确认取消这张续费账单吗？取消后可为当前到期周期重新选择续费周期。">
                            @csrf
                            <p class="gateway-disclaimer">需要改选续费周期？先取消当前未支付续费账单，再返回服务页面重新创建。</p>
                            <button class="button button-secondary button-wide" type="submit"><span>取消续费账单</span><span>×</span></button>
                        </form>
                    @endif
                </article>
            @endif

            <article class="panel">
                <header class="panel-head"><div><p class="panel-kicker">PAYMENT RECORDS</p><h2>付款记录</h2></div></header>
                <div class="portal-payment-list">
                    @forelse ($invoice->transactions as $transaction)
                        <div class="payment-entry"><div><span>{{ $transaction->gateway }}</span><strong>{{ $transaction->transaction_number }}</strong><small>{{ $transaction->paid_at?->format('Y-m-d H:i') }}</small></div><b>¥{{ number_format((float) max($transaction->amount_in, $transaction->amount_out), 2) }}</b></div>
                    @empty
                        <div class="empty-state compact"><p>尚无已确认付款记录</p></div>
                    @endforelse
                </div>
            </article>
        </aside>
    </section>
@endsection
