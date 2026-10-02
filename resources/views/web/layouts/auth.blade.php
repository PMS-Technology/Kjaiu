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
{{-- Login, register, password reset and bind render without the sidebar and
     topbar chrome, matching the original's $TplName check in header.tpl. --}}
<body class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">

<div id="kj-flash">
    @if (session('success'))
        <span data-flash="success" class="hidden">{{ session('success') }}</span>
    @endif
    @if (session('error'))
        <span data-flash="error" class="hidden">{{ session('error') }}</span>
    @endif
</div>

<div class="w-full max-w-md">
    <div class="mb-6 flex flex-col items-center gap-2">
        <a href="/" class="flex items-center gap-2">
            @if (! empty($Setting['logo_url']) || ! empty($Setting['logo_url_home']))
                <img src="{{ $Setting['logo_url'] ?: $Setting['logo_url_home'] }}" alt="{{ $Setting['web_name'] }}" class="h-10">
            @else
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-600 text-lg font-bold text-white">
                    {{ mb_substr($Setting['web_name'], 0, 1) }}
                </span>
                <span class="text-lg font-semibold text-slate-900">{{ $Setting['web_name'] }}</span>
            @endif
        </a>
    </div>

    @yield('content')

    <div class="mt-6 text-center text-xs text-slate-400">
        &copy; {{ date('Y') }} {{ $Setting['company_name'] }}
    </div>
</div>

@stack('scripts')
</body>
</html>
