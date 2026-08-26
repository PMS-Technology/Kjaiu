@extends('layouts.portal')

@section('title', $product->name)
@section('eyebrow', 'KJAIU / PRODUCT DETAIL')
@section('description', ($product->group?->parent?->name ? $product->group->parent->name.' / ' : '').$product->group?->name)
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.products.index') }}">← 返回目录</a>
@endsection

@section('content')
    <section class="product-detail-grid">
        <article class="panel product-detail-story">
            <div class="product-detail-mark">
                <span class="product-glyph">{{ strtoupper(mb_substr($product->type, 0, 3)) }}</span>
                <span class="type-chip">{{ $product->type }}</span>
            </div>
            <h2>{{ $product->name }}</h2>
            <div class="product-description">{!! nl2br(e($product->description ?: '稳定、清晰、按需交付的服务产品。')) !!}</div>
            <div class="product-availability">
                <span>交付方式</span><strong>{{ $product->auto_setup ? '付款后自动开通' : '付款后进入交付队列' }}</strong>
                <span>库存状态</span><strong>{{ $product->stock_control ? (($product->quantity ?? 0).' 件可用') : '可订购' }}</strong>
            </div>
        </article>

        <article class="panel product-purchase-panel">
            <header class="panel-head"><div><p class="panel-kicker">CONFIGURE ORDER</p><h2>选择付款周期</h2></div></header>
            <form class="portal-form" method="POST" action="{{ route('portal.products.cart.store', $product) }}">
                @csrf
                <div class="cycle-option-grid">
                    @foreach ($prices as $price)
                        <label class="cycle-option">
                            <input type="radio" name="billing_cycle" value="{{ $price['billing_cycle'] }}" @checked(old('billing_cycle', $prices->first()['billing_cycle']) === $price['billing_cycle']) required>
                            <span>
                                <small>{{ $cycles[$price['billing_cycle']] ?? $price['billing_cycle'] }}</small>
                                <strong>¥{{ number_format((float) $price['price'], 2) }}</strong>
                                <em>设置费 ¥{{ number_format((float) $price['setup_fee'], 2) }}</em>
                            </span>
                        </label>
                    @endforeach
                </div>
                <label class="field">
                    <span>数量</span>
                    <input type="number" name="quantity" value="{{ old('quantity', 1) }}" min="1" max="100" required>
                </label>
                <button class="button button-primary button-wide" type="submit"><span>加入购物车</span><span>↗</span></button>
            </form>
        </article>
    </section>
@endsection
