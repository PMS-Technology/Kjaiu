<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '工作台') · Kjaiu</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-shell">
    <input class="nav-toggle" type="checkbox" id="nav-toggle" aria-label="切换导航">
    <aside class="sidebar">
        <a class="brand" href="{{ route('admin.dashboard') }}" aria-label="Kjaiu 首页">
            <span class="brand-mark">K<span></span></span>
            <span class="brand-copy"><strong>Kjaiu</strong><small>FINANCE OS</small></span>
        </a>

        <nav class="primary-nav" aria-label="主导航">
            <p class="nav-label">运营中心</p>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/></svg>
                <span>总览</span>
            </a>
            <a href="{{ route('admin.customers.index') }}" class="{{ request()->routeIs('admin.customers.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM8 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8 0c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4ZM8 15c-.31 0-.65.02-1 .05C4.65 15.26 0 16.45 0 19v1h5v-3c0-.7.27-1.34.75-1.91A13.4 13.4 0 0 1 8 15Z"/></svg>
                <span>用户</span>
            </a>
            <a href="{{ route('admin.products.index') }}" class="{{ request()->routeIs('admin.products.*') || request()->routeIs('admin.product-groups.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 5-9 5-9-5 9-5Zm-7.74 8.2L12 14.5l7.74-4.3L21 11v6l-9 5-9-5v-6l1.26-.8Z"/></svg>
                <span>商品</span>
            </a>
            <a href="{{ route('admin.services.index') }}" class="{{ request()->routeIs('admin.services.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h18v6H3V4Zm2 2v2h2V6H5Zm-2 6h18v8H3v-8Zm2 2v4h2v-4H5Zm4 0v2h8v-2H9Z"/></svg>
                <span>服务</span>
            </a>
            <a href="{{ route('admin.suppliers.index') }}" class="{{ request()->routeIs('admin.suppliers.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16v6H4V3Zm2 2v2h3V5H6Zm-2 6h7v10H4V11Zm9 0h7v10h-7V11Zm2 2v2h3v-2h-3Z"/></svg>
                <span>上游供应商</span>
            </a>
            <a href="{{ route('admin.supplier-operations.index') }}" class="{{ request()->routeIs('admin.supplier-operations.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3h16v4H4V3Zm0 7h10v4H4v-4Zm0 7h7v4H4v-4Zm13-8 5 5-5 5v-3h-4v-4h4V9Z"/></svg>
                <span>上游操作</span>
            </a>

            <p class="nav-label nav-label-spaced">财务中心</p>
            <a href="{{ route('admin.invoices.index') }}" class="{{ request()->routeIs('admin.invoices.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 2h11l3 3v17l-3-2-3 2-3-2-3 2-3-2V3a1 1 0 0 1 1-1Zm2 5v2h9V7H7Zm0 4v2h9v-2H7Zm0 4v2h6v-2H7Z"/></svg>
                <span>账单</span>
            </a>
            <a href="{{ route('admin.finance.index') }}" class="{{ request()->routeIs('admin.finance.*') ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3V5Zm2 2v10h14V7H5Zm7 1a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM6 8h2v2H6V8Zm10 6h2v2h-2v-2Z"/></svg>
                <span>资金流水</span>
            </a>

            <p class="nav-label nav-label-spaced">账户</p>
            <a href="{{ route('portal.dashboard') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4 2 12l8 8v-5h8v-6h-8V4Zm10 2h2v12h-2V6Z"/></svg>
                <span>返回客户门户</span>
            </a>
        </nav>

        <div class="sidebar-foot">
            <div class="user-chip">
                <span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                <span><strong>{{ auth()->user()->name }}</strong><small>系统管理员</small></span>
            </div>
            <form action="{{ route('logout') }}" method="POST">
                @csrf
                <button class="logout-button" type="submit" aria-label="退出登录" title="退出登录">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H4v16h6v-2H6V6h4V4Zm5.59 3.59L17 9l-2 2H9v2h6l2 2-1.41 1.41L11.17 12l4.42-4.41ZM20 4h-6v2h4v12h-4v2h6V4Z"/></svg>
                </button>
            </form>
        </div>
    </aside>

    <label class="nav-backdrop" for="nav-toggle" aria-hidden="true"></label>

    <main class="main-stage">
        <header class="mobile-bar">
            <a class="brand brand-mobile" href="{{ route('admin.dashboard') }}"><span class="brand-mark">K<span></span></span><strong>Kjaiu</strong></a>
            <label class="menu-button" for="nav-toggle"><span></span><span></span><span></span></label>
        </header>

        <div class="page-wrap">
            <header class="page-head">
                <div>
                    <p class="eyebrow">@yield('eyebrow', 'KJAIU / OPERATIONS')</p>
                    <h1>@yield('title', '工作台')</h1>
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

            @if ($errors->any())
                <div class="notice notice-error" role="alert">
                    <span class="notice-icon">!</span>
                    <span>{{ $errors->first() }}</span>
                    <button type="button" data-dismiss-parent aria-label="关闭">×</button>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</body>
</html>
