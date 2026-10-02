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
<body>
<div id="kj-flash">
    @if (session('success'))
        <span data-flash="success" class="hidden">{{ session('success') }}</span>
    @endif
    @if (session('error'))
        <span data-flash="error" class="hidden">{{ session('error') }}</span>
    @endif
</div>

<div class="flex min-h-screen">

    {{-- ============ Sidebar ============ --}}
    <aside id="kj-sidebar"
           class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white
                  transition-transform duration-200 lg:static lg:translate-x-0">

        <div class="flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 px-5">
            <a href="/clientarea" class="flex items-center gap-2">
                @if (! empty($Setting['logo_url_home_mini']) || ! empty($Setting['logo_url']))
                    <img src="{{ $Setting['logo_url_home_mini'] ?: $Setting['logo_url'] }}" alt="{{ $Setting['web_name'] }}" class="h-8">
                @else
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">
                        {{ mb_substr($Setting['web_name'], 0, 1) }}
                    </span>
                @endif
                <span class="text-base font-semibold text-slate-900">{{ $Setting['web_name'] }}</span>
            </a>
        </div>

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
            @foreach ($Nav as $nv)
                @if (empty($nv['child']))
                    <a href="/{{ $nv['url'] }}"
                       data-hidden-url="{{ implode(',', $nv['hidden_url']) }}"
                       class="nav-link">
                        @if (! empty($nv['fa_icon']))
                            <i class="{{ $nv['fa_icon'] }} text-base"></i>
                        @else
                            <i class="bx bx-circle text-base text-slate-300"></i>
                        @endif
                        <span>{{ $nv['name'] }}</span>
                    </a>
                @else
                    <div>
                        <button type="button" data-collapse="#nav-group-{{ $nv['id'] }}" aria-expanded="true"
                                class="nav-group-link">
                            @if (! empty($nv['fa_icon']))
                                <i class="{{ $nv['fa_icon'] }} text-base"></i>
                            @else
                                <i class="bx bx-circle text-base text-slate-300"></i>
                            @endif
                            <span class="flex-1 text-left">{{ $nv['name'] }}</span>
                            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                        <div id="nav-group-{{ $nv['id'] }}" class="mt-0.5 space-y-0.5 pl-4">
                            @foreach ($nv['child'] as $child)
                                <a href="/{{ $child['url'] }}"
                                   data-hidden-url="{{ implode(',', $child['hidden_url']) }}"
                                   class="nav-sub-link">
                                    {{ $child['name'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        @if ($Userinfo)
            <div class="border-t border-slate-200 p-3">
                <div class="rounded-lg bg-slate-50 p-3">
                    <div class="flex items-center justify-between text-xs text-slate-500">
                        <span>账户余额</span>
                        <a href="/addfunds" class="text-brand-600 hover:underline">充值</a>
                    </div>
                    <div class="mt-1 text-lg font-semibold text-slate-900">
                        {{ $Currency['prefix'] }}{{ number_format((float) $Userinfo['user']['credit'], 2) }}{{ $Currency['suffix'] }}
                    </div>
                </div>
            </div>
        @endif
    </aside>

    {{-- Scrim for the mobile drawer --}}
    <div id="kj-sidebar-scrim" class="fixed inset-0 z-30 hidden bg-slate-900/40 lg:hidden"></div>

    {{-- ============ Main column ============ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/95 px-4 backdrop-blur lg:px-6">
            <button type="button" id="kj-sidebar-toggle"
                    class="grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zm0 5A.75.75 0 012.75 9h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 9.75zm0 5a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75z" clip-rule="evenodd"/>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                <h1 class="page-title truncate">{{ $Title }}</h1>
            </div>

            {{-- Language switcher --}}
            @if ($Setting['allow_user_language'])
                <form method="get" class="hidden sm:block">
                    @foreach (request()->except('language') as $key => $value)
                        @if (! is_array($value))
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <select name="language" class="form-select w-32 py-1.5 text-xs" onchange="this.form.submit()">
                        @foreach ($Language as $key => $list)
                            <option value="{{ $key }}" @selected(app()->getLocale() === $key)>{{ $list['display_name'] }}</option>
                        @endforeach
                    </select>
                </form>
            @endif

            {{-- Currency --}}
            <span class="hidden rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 sm:inline-block">
                {{ $Currency['code'] }} {{ $Currency['prefix'] }}
            </span>

            @if ($Userinfo)
                {{-- Cart --}}
                <a href="/cart?action=viewcart" class="relative grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M2.5 3A1.5 1.5 0 014 1.5h.5a1.5 1.5 0 011.47 1.2l.2 1.05h9.08a1 1 0 01.98 1.2l-1.2 6A1.5 1.5 0 0113.55 12H7.5a1.5 1.5 0 01-1.47-1.2L4.6 4.03a.5.5 0 00-.49-.4H4A.5.5 0 013.5 3l-.5-.5A1.5 1.5 0 012.5 3z"/>
                        <circle cx="7.5" cy="16" r="1.25"/><circle cx="14" cy="16" r="1.25"/>
                    </svg>
                </a>

                {{-- Messages --}}
                <a href="/message" class="relative grid h-9 w-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 2a5 5 0 00-5 5v3.6l-1.2 2.1a.75.75 0 00.65 1.13h11.1a.75.75 0 00.65-1.13L15 10.6V7a5 5 0 00-5-5z"/>
                        <path d="M8.2 15.5a1.8 1.8 0 003.6 0H8.2z"/>
                    </svg>
                    @php
                        $unread = \App\Models\SystemMessage::query()
                            ->where('uid', $Userinfo['user']['id'])
                            ->where('read_time', 0)
                            ->count();
                    @endphp
                    @if ($unread > 0)
                        <span class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white">
                            {{ $unread > 99 ? '99+' : $unread }}
                        </span>
                    @endif
                </a>
            @endif

            {{-- Account menu --}}
            <div class="relative">
                <button type="button" data-collapse="#kj-account-menu"
                        class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100">
                    @if ($Userinfo)
                        <span class="grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white">
                            {{ mb_substr($Userinfo['user']['username'], 0, 1) }}
                        </span>
                        <span class="hidden max-w-24 truncate sm:block">{{ $Userinfo['user']['username'] }}</span>
                    @else
                        <span>请登录</span>
                    @endif
                    <svg class="h-4 w-4 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                    </svg>
                </button>

                <div id="kj-account-menu" class="absolute right-0 z-30 mt-1 hidden w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                    @if ($Userinfo)
                        <a href="/details" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">个人信息</a>
                        <a href="/security" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">安全中心</a>
                        <a href="/message" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">消息中心</a>
                        <a href="/apimanage" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">API管理</a>
                        <div class="my-1 border-t border-slate-100"></div>
                        <a href="/logout" class="block px-4 py-2 text-sm text-rose-600 hover:bg-rose-50">退出登录</a>
                    @else
                        <a href="/login" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">登录</a>
                        <a href="/register" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">注册</a>
                        <a href="/cart" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">订购产品</a>
                    @endif
                </div>
            </div>
        </header>

        {{-- Page body --}}
        <main class="flex-1 px-4 py-6 lg:px-6">
            @yield('content')
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 text-xs text-slate-400 lg:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span>&copy; {{ date('Y') }} {{ $Setting['company_name'] }}</span>
                <span class="flex gap-3">
                    <a href="/news" class="hover:text-slate-600">新闻中心</a>
                    <a href="/knowledgebase" class="hover:text-slate-600">帮助中心</a>
                    @if (! empty($Setting['web_tos_url']))
                        <a href="{{ $Setting['web_tos_url'] }}" target="_blank" rel="noopener" class="hover:text-slate-600">服务条款</a>
                    @endif
                </span>
            </div>
        </footer>
    </div>
</div>

<script>
    document.getElementById('kj-sidebar-toggle')?.addEventListener('click', () => {
        const sidebar = document.getElementById('kj-sidebar');
        const scrim = document.getElementById('kj-sidebar-scrim');
        sidebar.classList.toggle('-translate-x-full');
        scrim.classList.toggle('hidden');
    });

    document.getElementById('kj-sidebar-scrim')?.addEventListener('click', () => {
        document.getElementById('kj-sidebar').classList.add('-translate-x-full');
        document.getElementById('kj-sidebar-scrim').classList.add('hidden');
    });
</script>
@stack('scripts')
</body>
</html>
