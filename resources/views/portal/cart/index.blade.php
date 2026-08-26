@extends('layouts.portal')

@section('title', '购物车')
@section('eyebrow', 'KJAIU / CART REVIEW')
@section('description', '确认产品、周期和数量后，一次结算购物车内全部项目。')
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.products.index') }}">继续选购 <span>→</span></a>
@endsection

@section('content')
    @if ($lines->isEmpty())
        <section class="panel empty-state">
            <span>CART</span><h3>购物车还是空的</h3><p>从当前在售目录中选择合适的产品和付款周期。</p>
            <a class="button button-primary" href="{{ route('portal.products.index') }}">浏览产品</a>
        </section>
    @else
        <section class="cart-layout">
            <article class="panel">
                <header class="panel-head"><div><p class="panel-kicker">{{ $lines->count() }} CART LINES</p><h2>购物车项目</h2></div></header>
                <div class="portal-cart-list">
                    @foreach ($lines as $line)
                        <div class="portal-cart-row">
                            <div class="product-cell">
                                <span class="product-glyph">{{ strtoupper(mb_substr($line['item']->product?->type ?? '?', 0, 3)) }}</span>
                                <span><strong>{{ $line['item']->product?->name ?? '已删除商品' }}</strong><small>{{ $line['item']->billing_cycle }} · 单价含设置费 {{ $line['unit_total'] === null ? '无法计价' : '¥'.number_format((float) $line['unit_total'], 2) }}</small></span>
                            </div>
                            <form class="cart-quantity-form" method="POST" action="{{ route('portal.cart.update', $line['item']) }}">
                                @csrf @method('PATCH')
                                <label><span>数量</span><input type="number" name="quantity" value="{{ $line['item']->quantity }}" min="1" max="100" required @disabled(! $line['available'])></label>
                                <button class="row-action" type="submit" @disabled(! $line['available'])>更新</button>
                            </form>
                            <div class="cart-line-total"><span>小计</span><strong>{{ $line['line_total'] === null ? '无法计价' : '¥'.number_format((float) $line['line_total'], 2) }}</strong></div>
                            <form method="POST" action="{{ route('portal.cart.destroy', $line['item']) }}" data-confirm="确定移出这个购物车项目吗？">
                                @csrf @method('DELETE')
                                <button class="row-action danger-text" type="submit">移除</button>
                            </form>
                            @unless ($line['available'])
                                <p class="cart-line-warning">{{ $line['issue'] }}</p>
                            @endunless
                        </div>
                    @endforeach
                </div>
            </article>

            <aside class="panel checkout-panel">
                <header class="panel-head"><div><p class="panel-kicker">CHECKOUT</p><h2>结算摘要</h2></div></header>
                <form class="portal-form" method="POST" action="{{ route('portal.cart.checkout') }}" data-confirm="确认结算购物车内全部项目吗？">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                    <div class="checkout-total"><span>应付总额</span><strong>@if($total === null)无法计算@else<small>¥</small>{{ number_format((float) $total, 2) }}@endif</strong><p>{{ $lines->sum(fn($line) => $line['item']->quantity) }} 件产品 · {{ $lines->count() }} 个项目</p></div>
                    @if ($checkoutBlocked)
                        <p class="gateway-disclaimer cart-checkout-warning">购物车包含不可结算项目。请先移除标红项目，确认剩余商品后再结算。</p>
                    @endif
                    <label class="field">
                        <span>支付方式</span>
                        <select name="payment" required @disabled($checkoutBlocked)>
                            <option value="Credit" @selected(old('payment') === 'Credit')>账户余额（¥{{ number_format((float) auth()->user()->credit, 2) }}）</option>
                            @foreach ($gateways as $gateway)
                                <option value="{{ $gateway->name }}" @selected(old('payment') === $gateway->name)>{{ $gateway->title }}</option>
                            @endforeach
                        </select>
                    </label>
                    <p class="gateway-disclaimer">选择外部渠道只会创建待支付账单。创建后请按账单页提示联系 {{ config('kjaiu.company_email') }} 获取付款指引；到账确认前订单不会被视为已支付。</p>
                    <button class="button button-primary button-wide" type="submit" @disabled($checkoutBlocked)><span>{{ $checkoutBlocked ? '请先移除不可用项目' : '结算全部项目' }}</span><span>↗</span></button>
                </form>
            </aside>
        </section>
    @endif
@endsection
