<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $Title ? $Title . ' - ' : '' }}{{ $Setting['web_name'] }}</title>
    @if (! empty($Setting['logo_url']))
        <link rel="icon" href="{{ $Setting['logo_url'] }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-slate-50">

<div id="kj-flash">
    @if (session('success'))
        <span data-flash="success" class="hidden">{{ session('success') }}</span>
    @endif
    @if (session('error'))
        <span data-flash="error" class="hidden">{{ session('error') }}</span>
    @endif
</div>

{{-- ============ Public header ============ --}}
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex h-16 max-w-7xl items-center gap-4 px-4 lg:px-8">
        <a href="/" class="flex items-center gap-2">
            @if (! empty($Setting['logo_url_home']) || ! empty($Setting['logo_url']))
                <img src="{{ $Setting['logo_url_home'] ?: $Setting['logo_url'] }}" alt="{{ $Setting['web_name'] }}" class="h-8">
            @else
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">
                    {{ mb_substr($Setting['web_name'], 0, 1) }}
                </span>
                <span class="text-base font-semibold text-slate-900">{{ $Setting['web_name'] }}</span>
            @endif
        </a>

        <nav class="hidden items-center gap-1 md:flex">
            <a href="/" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">首页</a>
            <a href="/cart" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">订购产品</a>
            <a href="/news" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">新闻中心</a>
            <a href="/knowledgebase" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">帮助中心</a>
            <a href="/downloads" class="rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900">资源下载</a>
        </nav>

        <div class="flex-1"></div>

        <form action="/news" method="get" class="hidden lg:block">
            <input type="search" name="keywords" value="{{ request('keywords') }}" placeholder="搜索新闻、帮助…"
                   class="form-input w-56 py-1.5 text-sm">
        </form>

        @if (! empty($Setting['allow_user_language']))
            <form method="get">
                @foreach (request()->except('language') as $key => $value)
                    @if (! is_array($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <select name="language" class="form-select w-28 py-1.5 text-xs" onchange="this.form.submit()">
                    @foreach ($Language as $key => $list)
                        <option value="{{ $key }}" @selected(app()->getLocale() === $key)>{{ $list['display_name'] }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if ($Userinfo)
            <a href="/clientarea" class="btn-primary btn-sm">用户中心</a>
        @else
            <a href="/login" class="btn-secondary btn-sm">登录</a>
            <a href="/register" class="btn-primary btn-sm">注册</a>
        @endif
    </div>
</header>

<main class="flex-1">
    @yield('content')
</main>

<footer class="mt-12 border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4 lg:px-8">
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">{{ $Setting['company_name'] }}</h3>
            <p class="text-sm text-slate-500">提供稳定的云计算与服务器托管服务。</p>
        </div>
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">产品服务</h3>
            <ul class="space-y-2 text-sm text-slate-500">
                <li><a href="/cart" class="hover:text-brand-600">订购产品</a></li>
                <li><a href="/service" class="hover:text-brand-600">我的服务</a></li>
            </ul>
        </div>
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">服务支持</h3>
            <ul class="space-y-2 text-sm text-slate-500">
                <li><a href="/knowledgebase" class="hover:text-brand-600">帮助中心</a></li>
                <li><a href="/submitticket" class="hover:text-brand-600">提交工单</a></li>
                <li><a href="/downloads" class="hover:text-brand-600">资源下载</a></li>
            </ul>
        </div>
        <div>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">关于我们</h3>
            <ul class="space-y-2 text-sm text-slate-500">
                <li><a href="/news" class="hover:text-brand-600">新闻中心</a></li>
                @if (! empty($Setting['web_tos_url']))
                    <li><a href="{{ $Setting['web_tos_url'] }}" target="_blank" rel="noopener" class="hover:text-brand-600">服务条款</a></li>
                @endif
                @if (! empty($Setting['web_privacy_url']))
                    <li><a href="{{ $Setting['web_privacy_url'] }}" target="_blank" rel="noopener" class="hover:text-brand-600">隐私政策</a></li>
                @endif
            </ul>
        </div>
    </div>
    <div class="border-t border-slate-100 px-4 py-4 text-center text-xs text-slate-400 lg:px-8">
        &copy; {{ date('Y') }} {{ $Setting['company_name'] }}. All rights reserved.
    </div>
</footer>

@stack('scripts')
</body>
</html>
