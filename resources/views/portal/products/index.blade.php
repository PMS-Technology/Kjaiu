@extends('layouts.portal')

@section('title', '产品目录')
@section('eyebrow', 'KJAIU / ACTIVE CATALOG')
@section('description', '浏览当前可购买产品，按实际需要选择付款周期。')
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.cart.index') }}">查看购物车 <span>→</span></a>
@endsection

@section('content')
    <div class="portal-catalog">
        @forelse ($groups as $group)
            <section class="catalog-group">
                <header class="catalog-group-head">
                    <div><p class="panel-kicker">{{ $group->parent?->name }} / {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</p><h2>{{ $group->name }}</h2></div>
                    <div><strong>{{ $group->headline }}</strong><span>{{ $group->tagline }}</span></div>
                </header>
                <div class="product-card-grid">
                    @foreach ($group->products as $product)
                        @php
                            $allPrices = collect([['billing_cycle' => $product->billing_cycle, 'price' => $product->price]])->merge($product->prices->map(fn($price) => ['billing_cycle' => $price->billing_cycle, 'price' => $price->price]))->unique('billing_cycle');
                            $lowestPrice = $allPrices->min(fn($price) => (float) $price['price']);
                        @endphp
                        <article class="portal-product-card">
                            <div class="product-card-top">
                                <span class="product-glyph">{{ strtoupper(mb_substr($product->type, 0, 3)) }}</span>
                                <span class="type-chip">{{ $product->type }}</span>
                            </div>
                            <h3>{{ $product->name }}</h3>
                            <p>{{ \Illuminate\Support\Str::limit($product->description ?: '稳定、清晰、按需交付的服务产品。', 120) }}</p>
                            <div class="product-cycle-list">
                                @foreach ($allPrices as $price)
                                    <span>{{ $cycles[$price['billing_cycle']] ?? $price['billing_cycle'] }} · ¥{{ number_format((float) $price['price'], 2) }}</span>
                                @endforeach
                            </div>
                            <footer>
                                <div><span>低至</span><strong><small>¥</small>{{ number_format($lowestPrice, 2) }}</strong></div>
                                <a class="button button-primary" href="{{ route('portal.products.show', $product) }}">查看详情 →</a>
                            </footer>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="panel empty-state"><span>CAT</span><h3>暂无在售产品</h3><p>产品上架后会在这里按分组展示。</p></section>
        @endforelse
    </div>
@endsection
