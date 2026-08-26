@extends('layouts.portal')

@section('title', '我的账单')
@section('eyebrow', 'KJAIU / BILLING DESK')
@section('description', '核对账单明细、到期时间和真实付款状态。')

@section('content')
    <section class="panel resource-panel">
        <header class="resource-toolbar resource-toolbar-wrap">
            <form class="portal-filter" method="GET">
                <label class="field"><span>账单状态</span><select name="status"><option value="">全部状态</option>@foreach(['Unpaid'=>'待支付','Paid'=>'已支付','Cancelled'=>'已取消','Refunded'=>'已退款'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label>
                <button class="button button-primary" type="submit">筛选</button>
            </form>
            <span class="result-count">{{ $invoices->total() }} 笔账单</span>
        </header>
        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>账单编号</th><th>金额</th><th>状态</th><th>付款方式</th><th>到期日</th><th>创建时间</th><th></th></tr></thead>
                <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td data-label="账单编号"><a class="mono-link" href="{{ route('portal.invoices.show', $invoice) }}">#{{ $invoice->number }}</a></td>
                        <td data-label="金额" class="money-cell">¥{{ number_format((float) $invoice->total, 2) }}</td>
                        <td data-label="状态"><span class="status status-{{ strtolower($invoice->status) }}">{{ ['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status }}</span></td>
                        <td data-label="付款方式">{{ $invoice->payment_method ?: '未选择' }}</td>
                        <td data-label="到期日"><span class="{{ $invoice->status === 'Unpaid' && $invoice->due_at?->isPast() ? 'danger-text' : 'muted' }}">{{ $invoice->due_at?->format('Y-m-d H:i') ?? '—' }}</span></td>
                        <td data-label="创建时间"><span class="muted">{{ $invoice->created_at->format('Y-m-d H:i') }}</span></td>
                        <td data-label="操作" class="align-right"><a class="row-action" href="{{ route('portal.invoices.show', $invoice) }}">查看 →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>INV</span><h3>没有匹配的账单</h3><p>调整筛选条件，或在购物车完成一次结算。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($invoices->hasPages())<div class="pagination-wrap">{{ $invoices->links() }}</div>@endif
    </section>
@endsection
