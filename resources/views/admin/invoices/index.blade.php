@extends('layouts.admin')

@section('title', '账单中心')
@section('eyebrow', 'KJAIU / INVOICE DESK')
@section('description', '追踪应收、付款状态与每一张账单的业务来源。')

@section('actions')
    <button class="button button-primary" type="button" data-dialog-open="invoice-dialog">手工开账 <span>＋</span></button>
@endsection

@section('content')
    @php
        $pageItems = $invoices->getCollection();
        $pageUnpaid = $pageItems->where('status', 'Unpaid')->sum('total');
        $pagePaid = $pageItems->where('status', 'Paid')->sum('total');
    @endphp
    <section class="summary-strip">
        <div><span>账单总数</span><strong>{{ number_format($invoices->total()) }}</strong></div>
        <i></i>
        <div><span>本页已收</span><strong>¥{{ number_format((float) $pagePaid, 2) }}</strong></div>
        <i></i>
        <div><span>本页待收</span><strong>¥{{ number_format((float) $pageUnpaid, 2) }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar resource-toolbar-wrap">
            <form class="search-form search-form-grow" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索账单编号、客户或邮箱">
                <select name="status" aria-label="账单状态">
                    <option value="">全部状态</option>
                    <option value="Unpaid" @selected($status === 'Unpaid')>待支付</option>
                    <option value="Paid" @selected($status === 'Paid')>已支付</option>
                    <option value="Cancelled" @selected($status === 'Cancelled')>已取消</option>
                    <option value="Refunded" @selected($status === 'Refunded')>已退款</option>
                </select>
                <button type="submit">筛选</button>
            </form>
            <span class="result-count">{{ $invoices->total() }} 张账单</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>账单编号</th><th>客户</th><th>金额</th><th>状态</th><th>到期时间</th><th>创建时间</th><th class="align-right">操作</th></tr></thead>
                <tbody>
                @forelse ($invoices as $invoice)
                    @php($overdue = $invoice->status === 'Unpaid' && $invoice->due_at?->isPast())
                    <tr>
                        <td data-label="账单编号"><a class="mono-link" href="{{ route('admin.invoices.show', $invoice) }}">{{ $invoice->number }}</a><small class="cell-sub">ID {{ $invoice->id }}{{ $invoice->order_id ? ' · 订单 '.$invoice->order_id : ' · 手工账单' }}</small></td>
                        <td data-label="客户"><strong>{{ $invoice->user?->name ?? '未知客户' }}</strong><small class="cell-sub">{{ $invoice->user?->email }}</small></td>
                        <td data-label="金额" class="money-cell">¥{{ number_format((float) $invoice->total, 2) }}</td>
                        <td data-label="状态"><span class="status status-{{ $overdue ? 'overdue' : strtolower($invoice->status) }}">{{ $overdue ? '已逾期' : (['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status) }}</span></td>
                        <td data-label="到期时间"><span class="{{ $overdue ? 'danger-text' : 'muted' }}">{{ $invoice->due_at?->format('Y-m-d H:i') ?? '—' }}</span></td>
                        <td data-label="创建时间"><span class="muted">{{ $invoice->created_at->format('Y-m-d H:i') }}</span></td>
                        <td data-label="操作" class="align-right"><a class="row-action" href="{{ route('admin.invoices.show', $invoice) }}">查看 →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>INV</span><h3>没有匹配的账单</h3><p>调整搜索条件，或手工开具一张新账单。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $invoices->links() }}</div>
    </section>

    <dialog class="modal" id="invoice-dialog" @if($errors->any() && old('_form') === 'invoice') open @endif>
        <form method="POST" action="{{ route('admin.invoices.store') }}">
            @csrf
            <input type="hidden" name="_form" value="invoice">
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <header class="modal-head"><div><p class="panel-kicker">MANUAL INVOICE</p><h2>手工开具账单</h2></div><button type="button" data-dialog-close aria-label="关闭">×</button></header>
            <div class="modal-body form-grid">
                <label class="field field-full"><span>付款客户</span><select name="user_id" required><option value="">选择客户</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('user_id') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->email }}</option>@endforeach</select></label>
                <label class="field field-full"><span>账单项目</span><textarea name="description" rows="3" required maxlength="2000" placeholder="说明这笔应收款的业务内容">{{ old('description') }}</textarea></label>
                <label class="field"><span>应收金额</span><div class="money-input"><b>¥</b><input type="number" name="amount" value="{{ old('amount') }}" min="0.01" step="0.01" required placeholder="0.00"></div></label>
                <label class="field"><span>付款期限</span><input type="datetime-local" name="due_at" value="{{ old('due_at', now()->addDays(7)->format('Y-m-d\TH:i')) }}" required></label>
                <label class="field field-full"><span>内部备注 <small>选填，客户 API 不展示</small></span><textarea name="notes" rows="2" maxlength="5000">{{ old('notes') }}</textarea></label>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">确认开账</button></footer>
        </form>
    </dialog>
@endsection
