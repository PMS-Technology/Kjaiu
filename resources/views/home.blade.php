@extends('layouts.public')

@section('title', config('app.name', 'Kjaiu').' · 可信云基础设施')

@section('content')
<section class="public-hero">
    <div class="hero-grid-lines" aria-hidden="true"></div>
    <div class="hero-orbit hero-orbit-one" aria-hidden="true"></div>
    <div class="hero-orbit hero-orbit-two" aria-hidden="true"></div>
    <div class="public-hero-copy">
        <p class="public-kicker"><i></i> INFRASTRUCTURE, WITHOUT THE NOISE</p>
        <h1>云服务不该复杂，<br><em>只该可靠。</em></h1>
        <p class="hero-lead">从选购、结算到交付与续费，{{ config('app.name', 'Kjaiu') }} 把云资源和资金轨迹放进同一个清晰、可信的服务界面。</p>
        <div class="hero-buttons">
            <a class="hero-primary" href="{{ auth()->check() ? route('portal.products.index') : route('login') }}">浏览云产品 <span>↗</span></a>
            <a class="hero-secondary" href="#capabilities">了解平台能力 <span>↓</span></a>
        </div>
        <div class="hero-proof">
            <div><strong>{{ str_pad((string) $productCount, 2, '0', STR_PAD_LEFT) }}</strong><span>在售产品</span></div>
            <div><strong>{{ str_pad((string) $categoryCount, 2, '0', STR_PAD_LEFT) }}</strong><span>服务类别</span></div>
            <div><strong>24/7</strong><span>自动账务</span></div>
        </div>
    </div>
    <div class="public-hero-visual" aria-label="云基础设施运行状态">
        <div class="cloud-console">
            <header><span><i></i> KJAIU EDGE / LIVE</span><b>运行正常</b></header>
            <div class="cloud-map">
                <span class="map-label map-label-a">CLIENT</span>
                <span class="map-label map-label-b">EDGE</span>
                <span class="map-label map-label-c">COMPUTE</span>
                <div class="map-node node-a"></div><div class="map-node node-b"></div><div class="map-node node-c"></div>
                <svg viewBox="0 0 440 270" role="img" aria-label="客户、边缘和计算节点连接示意"><path d="M70 194C130 112 179 209 229 132S336 48 382 90"/><path d="M70 194C172 237 278 240 382 90"/></svg>
            </div>
            <footer><div><span>DEPLOYMENT</span><strong>&lt; 60s</strong></div><div><span>LEDGER</span><strong>TRACEABLE</strong></div><div><span>STATUS</span><strong>HEALTHY</strong></div></footer>
        </div>
        <div class="visual-note"><span>01</span><p>资源交付<br>状态实时可见</p></div>
    </div>
</section>

<section class="public-marquee" aria-label="平台能力概览"><div><span>COMPUTE</span><i></i><span>NETWORK</span><i></i><span>BILLING</span><i></i><span>AUTOMATION</span><i></i><span>OBSERVABILITY</span></div></section>

<section class="public-products" id="products">
    <header class="public-section-head"><div><p class="public-kicker">PRODUCT MATRIX / 01</p><h2>从合适的资源开始。</h2></div><p>清楚的周期、明确的价格、统一的交付入口。没有隐藏步骤，也没有割裂的账务信息。</p></header>
    <div class="public-product-grid">
        @forelse($products as $index => $product)
            <article class="public-product-card {{ $index === 0 ? 'is-featured' : '' }}">
                <header><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><b>{{ strtoupper($product->type) }}</b></header>
                <div class="product-card-body"><p>{{ $product->group?->parent?->name }} / {{ $product->group?->name }}</p><h3>{{ $product->name }}</h3><div class="product-description">{{ \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 86) ?: '稳定资源、清晰账务与统一服务交付。' }}</div></div>
                <footer><div><small>起步价格</small><strong><i>¥</i>{{ number_format((float) $product->homepage_price, 2) }}<em>/ {{ $cycles[$product->homepage_billing_cycle] ?? $product->homepage_billing_cycle }}</em></strong></div><a href="{{ auth()->check() ? route('portal.products.show', $product) : route('login') }}" aria-label="查看 {{ $product->name }}">↗</a></footer>
            </article>
        @empty
            <article class="public-product-empty"><span>CATALOG / STANDBY</span><h3>产品目录正在准备</h3><p>管理员上架商品后，将自动显示在这里。</p><a href="{{ route('login') }}">进入客户中心 ↗</a></article>
        @endforelse
    </div>
</section>

<section class="public-capabilities" id="capabilities">
    <div class="capability-intro"><p class="public-kicker">WHY KJAIU / 02</p><h2>少一点黑箱，<br>多一点确定。</h2><p>平台不只提供资源，更让订单、账单、资金和服务状态保持一致。</p></div>
    <div class="capability-list">
        <article><span>01</span><div><h3>分钟级交付</h3><p>付款状态确认后自动进入服务交付链路，减少等待和重复沟通。</p></div><b>FAST</b></article>
        <article><span>02</span><div><h3>统一账务视图</h3><p>余额、账单、流水和订单互相关联，每次变化都有可追踪记录。</p></div><b>CLEAR</b></article>
        <article><span>03</span><div><h3>全周期服务</h3><p>从首次购买到续费管理，都在同一个客户门户中完成。</p></div><b>STEADY</b></article>
    </div>
</section>

<section class="public-operations" id="operations">
    <div class="operation-signal"><i></i><i></i><i></i><span>ALL SYSTEMS OPERATIONAL</span></div>
    <div><p class="public-kicker">SERVICE STANDARD / 03</p><h2>基础设施在幕后，<br>控制权在你手中。</h2></div>
    <div class="operation-metrics"><article><span>账务</span><strong>幂等</strong><p>避免重复结算</p></article><article><span>交付</span><strong>可追踪</strong><p>状态清晰可见</p></article><article><span>连接</span><strong>TLS</strong><p>上游链路可校验</p></article></div>
</section>

<section class="public-cta">
    <p class="public-kicker">READY WHEN YOU ARE</p><h2>下一台云资源，<br><em>从清晰开始。</em></h2><a href="{{ auth()->check() ? route('portal.products.index') : route('login') }}">进入产品中心 <span>↗</span></a>
</section>
@endsection
