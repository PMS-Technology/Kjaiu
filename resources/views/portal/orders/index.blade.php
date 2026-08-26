@extends('layouts.portal')

@section('title', '我的订单')
@section('eyebrow', 'KJAIU / ORDER HISTORY')
@section('description', '查看结算时保留的商品、价格和付款周期快照。')

@section('content')
    <section class="panel resource-panel">
        <header class="panel-head">
            <div><p class="panel-kicker">{{ $orders->total() }} ORDERS</p><h2>订单记录</h2></div>
            <a class="text-link" href="{{ route('portal.products.index') }}">选购产品 →</a>
        </header>
        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>订单</th><th>项目</th><th>金额</th><th>状态</th><th>账单</th><th>创建时间</th><th></th></tr></thead>
                <tbody>
                @forelse ($orders as $order)
                    <tr>
                        <td data-label="订单"><a class="mono-link" href="{{ route('portal.orders.show', $order) }}">#{{ $order->id }}</a></td>
                        <td data-label="项目">{{ $order->items_count }} 项</td>
                        <td data-label="金额" class="money-cell">¥{{ number_format((float) $order->total, 2) }}</td>
                        <td data-label="状态"><span class="status status-{{ strtolower($order->status) }}">{{ ['Pending'=>'待支付','Paid'=>'已支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$order->status] ?? $order->status }}</span></td>
                        <td data-label="账单">@if($order->invoice)<a class="mono-link" href="{{ route('portal.invoices.show', $order->invoice) }}">{{ $order->invoice->number }}</a>@else<span class="muted">—</span>@endif</td>
                        <td data-label="创建时间"><span class="muted">{{ $order->created_at->format('Y-m-d H:i') }}</span></td>
                        <td data-label="操作" class="align-right"><a class="row-action" href="{{ route('portal.orders.show', $order) }}">详情 →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>ORD</span><h3>还没有订单</h3><p>购物车结算后，订单快照会保存在这里。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($orders->hasPages())<div class="pagination-wrap">{{ $orders->links() }}</div>@endif
    </section>
@endsection
