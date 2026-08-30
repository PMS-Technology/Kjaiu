<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ config('app.name', 'Kjaiu') }} 提供透明、稳定、可追踪的云服务与数字基础设施。">
    <title>@yield('title', config('app.name', 'Kjaiu').' 云服务')</title>
    @if(config('kjaiu.site.favicon_url'))<link rel="icon" href="{{ config('kjaiu.site.favicon_url') }}">@endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public-site">
    <a class="public-skip-link" href="#main-content">跳到主要内容</a>
    <header class="public-header">
        <a class="public-brand" href="{{ route('home') }}" aria-label="{{ config('app.name') }} 首页">
            @if(config('kjaiu.site.logo_url'))
                <img src="{{ config('kjaiu.site.logo_url') }}" alt="">
            @else
                <span class="public-brand-mark">K<i></i></span>
            @endif
            <span><strong>{{ config('app.name', 'Kjaiu') }}</strong><small>CLOUD INFRASTRUCTURE</small></span>
        </a>
        <nav class="public-nav" aria-label="官网导航">
            <a href="#products">云产品</a>
            <a href="#capabilities">平台能力</a>
            <a href="#operations">服务保障</a>
        </nav>
        <div class="public-actions">
            @auth
                <a class="public-console" href="{{ route('portal.dashboard') }}">客户中心 <span>↗</span></a>
                @if(auth()->user()->isAdministrator())<a class="public-login" href="{{ route('admin.dashboard') }}">管理后台</a>@endif
            @else
                <a class="public-login" href="{{ route('login') }}">登录</a>
                <a class="public-console" href="{{ route('login') }}">开始使用 <span>↗</span></a>
            @endauth
        </div>
    </header>

    <main id="main-content">@yield('content')</main>

    <footer class="public-footer">
        <a class="public-brand public-brand-footer" href="{{ route('home') }}"><span class="public-brand-mark">K<i></i></span><span><strong>{{ config('app.name', 'Kjaiu') }}</strong><small>CLOUD INFRASTRUCTURE</small></span></a>
        <p>让基础设施保持稳定，让每一笔服务清晰可见。</p>
        <span>© {{ now()->year }} {{ config('app.name', 'Kjaiu') }}</span>
    </footer>
</body>
</html>
