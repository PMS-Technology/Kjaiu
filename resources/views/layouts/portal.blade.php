<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '客户门户') · Kjaiu</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal-shell">
    <header class="portal-header">
        <div class="portal-header-inner">
            <a class="brand portal-brand" href="{{ route('portal.dashboard') }}" aria-label="Kjaiu 客户门户">
                <span class="brand-mark">K<span></span></span>
                <span class="brand-copy"><strong>Kjaiu</strong><small>CLIENT PORTAL</small></span>
            </a>

            <div class="portal-account">
                <div class="portal-account-copy">
                    <span>{{ auth()->user()->name }}</span>
                    <small>余额 ¥{{ number_format((float) auth()->user()->credit, 2) }}</small>
                </div>
                @if (auth()->user()->isAdministrator())
                    <a class="portal-admin-link" href="{{ route('admin.dashboard') }}">进入管理后台 ↗</a>
                @endif
                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button class="portal-logout" type="submit">退出</button>
                </form>
            </div>
        </div>

        <nav class="portal-nav" aria-label="客户门户导航">
            <div class="portal-nav-inner">
                <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'is-active' : '' }}">概览</a>
                <a href="{{ route('portal.products.index') }}" class="{{ request()->routeIs('portal.products.*') ? 'is-active' : '' }}">产品</a>
                <a href="{{ route('portal.cart.index') }}" class="{{ request()->routeIs('portal.cart.*') ? 'is-active' : '' }}">购物车 <span>{{ auth()->user()->cartItems()->count() }}</span></a>
                <a href="{{ route('portal.orders.index') }}" class="{{ request()->routeIs('portal.orders.*') ? 'is-active' : '' }}">订单</a>
                <a href="{{ route('portal.invoices.index') }}" class="{{ request()->routeIs('portal.invoices.*') ? 'is-active' : '' }}">账单</a>
                <a href="{{ route('portal.services.index') }}" class="{{ request()->routeIs('portal.services.*') ? 'is-active' : '' }}">服务</a>
                <a href="{{ route('portal.profile.show') }}" class="{{ request()->routeIs('portal.profile.*') ? 'is-active' : '' }}">账户</a>
            </div>
        </nav>
    </header>

    <main class="portal-main">
        <header class="portal-page-head">
            <div>
                <p class="eyebrow">@yield('eyebrow', 'KJAIU / CLIENT PORTAL')</p>
                <h1>@yield('title', '客户门户')</h1>
                <p class="page-description">@yield('description')</p>
            </div>
            <div class="page-actions">@yield('actions')</div>
        </header>

        @if (session('success'))
            <div class="notice notice-success" role="status">
                <span class="notice-icon">✓</span>
                <span>{{ session('success') }}</span>
                <button type="button" data-dismiss-parent aria-label="关闭">×</button>
            </div>
        @endif

        @if (session('pending'))
            <div class="notice notice-pending" role="status">
                <span class="notice-icon">i</span>
                <span>{{ session('pending') }}</span>
                <button type="button" data-dismiss-parent aria-label="关闭">×</button>
            </div>
        @endif

        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                <span class="notice-icon">!</span>
                <span>{{ $errors->first() }}</span>
                <button type="button" data-dismiss-parent aria-label="关闭">×</button>
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="portal-footer">
        <span>KJAIU / CLIENT SERVICES</span>
        <span>{{ now()->format('Y') }} · {{ config('kjaiu.company_name', 'Kjaiu') }}</span>
    </footer>
</body>
</html>
