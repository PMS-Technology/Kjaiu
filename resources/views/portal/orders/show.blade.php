@extends('layouts.portal')

@section('title', '订单 #'.$order->id)
@section('eyebrow', 'KJAIU / ORDER SNAPSHOT')
@section('description', '以下名称、周期和金额均为结算当时的订单快照。')
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.orders.index') }}">← 返回订单</a>
    @if ($order->invoice)<a class="button button-primary" href="{{ route('portal.invoices.show', $order->invoice) }}">查看账单 →</a>@endif
@endsection

@section('content')
    <section class="detail-summary-grid">
        <article><span>订单状态</span><strong><span class="status status-{{ strtolower($order->status) }}">{{ ['Pending'=>'待支付','Paid'=>'已支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$order->status] ?? $order->status }}</span></strong></article>
        <article><span>创建时间</span><strong>{{ $order->created_at->format('Y-m-d H:i') }}</strong></article>
        <article><span>订单总额</span><strong>¥{{ number_format((float) $order->total, 2) }}</strong></article>
        <article><span>关联账单</span><strong>{{ $order->invoice?->number ?? '—' }}</strong></article>
    </section>

    <section class="panel">
        <header class="panel-head"><div><p class="panel-kicker">ORDER ITEMS</p><h2>商品快照</h2></div></header>
        <div class="table-scroll">
            <table class="data-table invoice-lines">
                <thead><tr><th>商品名称</th><th>付款周期</th><th>数量</th><th>单价</th><th>设置费</th><th class="align-right">小计</th></tr></thead>
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td data-label="商品名称"><strong>{{ $item->product_name }}</strong></td>
                        <td data-label="付款周期">{{ $item->billing_cycle }}</td>
                        <td data-label="数量">{{ $item->quantity }}</td>
                        <td data-label="单价" class="money-cell">¥{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td data-label="设置费" class="money-cell">¥{{ number_format((float) $item->setup_fee, 2) }}</td>
                        <td data-label="小计" class="money-cell align-right">¥{{ number_format((float) $item->amount, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="5" class="align-right">小计</td><td class="money-cell align-right">¥{{ number_format((float) $order->subtotal, 2) }}</td></tr>
                    @if ((float) $order->discount > 0)<tr><td colspan="5" class="align-right">优惠</td><td class="money-cell align-right">-¥{{ number_format((float) $order->discount, 2) }}</td></tr>@endif
                    <tr class="grand-total"><td colspan="5" class="align-right">订单总额</td><td class="money-cell align-right">¥{{ number_format((float) $order->total, 2) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </section>
@endsection
