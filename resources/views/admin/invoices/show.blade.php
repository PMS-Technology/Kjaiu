@extends('layouts.admin')

@section('title', '账单 '.$invoice->number)
@section('eyebrow', 'KJAIU / INVOICE '.$invoice->id)
@section('description', '核对项目、支付流水与账单生命周期。')

@section('actions')
    <a class="button button-secondary" href="{{ route('admin.invoices.index') }}">← 返回列表</a>
    @if($invoice->status === 'Unpaid')
        <button class="button button-primary" type="button" data-dialog-open="pay-dialog">确认入账 <span>↙</span></button>
    @endif
@endsection

@section('content')
    @php($overdue = $invoice->status === 'Unpaid' && $invoice->due_at?->isPast())
    <section class="invoice-hero">
        <div class="invoice-brand-block">
            <span class="brand-mark brand-mark-large">K<span></span></span>
            <div><p>ISSUED BY</p><h2>{{ config('kjaiu.company_name') }}</h2><span>{{ config('kjaiu.company_email') }}</span></div>
        </div>
        <div class="invoice-total-block">
            <p>AMOUNT DUE</p>
            <strong><small>¥</small>{{ number_format((float) $invoice->total, 2) }}</strong>
            <span class="status status-{{ $overdue ? 'overdue' : strtolower($invoice->status) }}">{{ $overdue ? '已逾期' : (['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status) }}</span>
        </div>
    </section>

    <section class="invoice-meta-grid">
        <article><span>账单编号</span><strong>{{ $invoice->number }}</strong><small>内部 ID #{{ $invoice->id }}</small></article>
        <article><span>付款客户</span><strong>{{ $invoice->user->name }}</strong><small>{{ $invoice->user->email }}</small></article>
        <article><span>开具时间</span><strong>{{ $invoice->created_at->format('Y-m-d') }}</strong><small>{{ $invoice->created_at->format('H:i:s T') }}</small></article>
        <article><span>付款期限</span><strong class="{{ $overdue ? 'danger-text' : '' }}">{{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</strong><small>{{ $invoice->due_at?->format('H:i:s') ?? '未设置' }}</small></article>
    </section>

    <section class="dashboard-grid invoice-detail-grid">
        <article class="panel invoice-lines-panel">
            <header class="panel-head"><div><p class="panel-kicker">LINE ITEMS</p><h2>账单项目</h2></div><span class="panel-meta">{{ $invoice->items->count() }} 项</span></header>
            <div class="table-scroll">
                <table class="data-table invoice-lines">
                    <thead><tr><th>项目说明</th><th>类型</th><th>关联</th><th class="align-right">金额</th></tr></thead>
                    <tbody>
                    @foreach($invoice->items as $item)
                        <tr>
                            <td data-label="项目说明"><strong>{{ $item->description }}</strong>@if($item->service)<small class="cell-sub">服务：{{ $item->service->name }}</small>@endif</td>
                            <td data-label="类型"><span class="type-chip">{{ strtoupper($item->type) }}</span></td>
                            <td data-label="关联"><span class="muted">{{ $item->rel_id ? '#'.$item->rel_id : '—' }}</span></td>
                            <td data-label="金额" class="align-right money-cell">¥{{ number_format((float) $item->amount, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3">小计</td><td class="align-right">¥{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
                        @if((float)$invoice->credit !== 0.0)<tr><td colspan="3">余额抵扣</td><td class="align-right">- ¥{{ number_format((float) $invoice->credit, 2) }}</td></tr>@endif
                        <tr class="grand-total"><td colspan="3">应付合计</td><td class="align-right">¥{{ number_format((float) $invoice->total, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            @if($invoice->notes)<div class="invoice-note"><span>INTERNAL NOTE</span><p>{{ $invoice->notes }}</p></div>@endif
        </article>

        <aside class="invoice-side-stack">
            <article class="panel timeline-panel">
                <header class="panel-head"><div><p class="panel-kicker">LIFECYCLE</p><h2>账单轨迹</h2></div></header>
                <div class="invoice-timeline">
                    <div class="timeline-item is-complete"><i></i><div><strong>账单已创建</strong><span>{{ $invoice->created_at->format('Y-m-d H:i') }}</span></div></div>
                    @if($invoice->status === 'Paid')
                        <div class="timeline-item is-complete"><i></i><div><strong>付款已确认</strong><span>{{ $invoice->paid_at?->format('Y-m-d H:i') }} · {{ $invoice->payment_method }}</span></div></div>
                        <div class="timeline-item is-complete"><i></i><div><strong>业务结算完成</strong><span>服务与余额已同步更新</span></div></div>
                    @elseif($invoice->status === 'Cancelled')
                        <div class="timeline-item is-cancelled"><i></i><div><strong>账单已取消</strong><span>该账单不再接受付款</span></div></div>
                    @else
                        <div class="timeline-item {{ $overdue ? 'is-alert' : '' }}"><i></i><div><strong>{{ $overdue ? '账单已逾期' : '等待客户付款' }}</strong><span>截止 {{ $invoice->due_at?->format('Y-m-d H:i') }}</span></div></div>
                    @endif
                </div>
            </article>

            <article class="panel payment-panel">
                <header class="panel-head"><div><p class="panel-kicker">PAYMENTS</p><h2>支付流水</h2></div></header>
                @forelse($invoice->transactions as $transaction)
                    <div class="payment-entry">
                        <div><span>{{ $transaction->gateway }}</span><strong>{{ $transaction->transaction_number }}</strong><small>{{ $transaction->paid_at?->format('Y-m-d H:i:s') }}</small></div>
                        <b>¥{{ number_format((float)((float)$transaction->amount_in > 0 ? $transaction->amount_in : $transaction->amount_out), 2) }}</b>
                    </div>
                @empty
                    <div class="empty-state compact"><span>¥</span><p>尚无支付流水</p></div>
                @endforelse
            </article>

            @if($invoice->status === 'Unpaid')
                <article class="panel"><header class="panel-head"><div><p class="panel-kicker">STATUS</p><h2>修改账单状态</h2></div></header><form method="POST" action="{{ route('admin.invoices.status', $invoice) }}" data-confirm="确认修改账单状态？已支付会触发真实入账与服务开通，已取消会释放预占库存。">@csrf @method('PATCH')<div class="modal-body form-grid"><label class="field"><span>目标状态</span><select name="status" required><option value="Paid">已支付</option><option value="Cancelled">已取消</option></select></label><label class="field"><span>收款方式 <small>取消时忽略</small></span><select name="gateway"><option value="">请选择</option>@foreach($gateways as $gateway)<option value="{{ $gateway->name }}">{{ $gateway->title }}</option>@endforeach<option value="Cash">现金</option></select></label><label class="field field-full"><span>外部流水号</span><input name="transaction_number" maxlength="191"></label></div><footer class="modal-foot"><button class="button button-primary">修改状态</button></footer></form></article>
                <form class="danger-zone" method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}" data-confirm="确认取消这张账单？订单预占库存将被释放，且该账单不能继续付款。">
                    @csrf
                    <input type="hidden" name="_form" value="cancel">
                    <div><strong>取消账单</strong><span>订单账单将同时释放预占库存。</span></div>
                    <button type="submit">取消并释放</button>
                </form>
            @endif
        </aside>
    </section>

    <dialog class="modal" id="pay-dialog" @if($errors->any() && old('_form') === 'pay') open @endif>
        <form method="POST" action="{{ route('admin.invoices.pay', $invoice) }}" data-confirm="请确认款项已真实到账。提交后将更新余额或开通关联服务，此操作不可直接撤销。">
            @csrf
            <input type="hidden" name="_form" value="pay">
            <header class="modal-head"><div><p class="panel-kicker">RECORD PAYMENT</p><h2>确认款项入账</h2></div><button type="button" data-dialog-close aria-label="关闭">×</button></header>
            <div class="modal-body">
                <div class="confirmation-amount"><span>本次入账金额</span><strong>¥{{ number_format((float) $invoice->total, 2) }}</strong><p>请仅在银行或支付平台已确认收款后执行。</p></div>
                <div class="form-grid">
                    <label class="field"><span>支付渠道</span><select name="gateway" required><option value="">选择渠道</option>@foreach($gateways as $gateway)<option value="{{ $gateway->name }}" @selected(old('gateway') === $gateway->name)>{{ $gateway->title }}</option>@endforeach<option value="Cash" @selected(old('gateway') === 'Cash')>现金</option></select></label>
                    <label class="field"><span>外部流水号 <small>选填</small></span><input name="transaction_number" value="{{ old('transaction_number') }}" maxlength="191" placeholder="支付平台或银行流水号"></label>
                </div>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">确认已到账</button></footer>
        </form>
    </dialog>
@endsection
