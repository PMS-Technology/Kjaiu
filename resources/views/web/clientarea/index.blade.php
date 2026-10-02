@extends('web.layouts.client')

@section('content')
@php
    $index = $ClientArea['index'];
    $credit = (float) $index['client']['credit'];
    $unpaid = (float) $index['invoice_unpaid'];
    $max = max($credit + $unpaid, 1);
@endphp

{{-- ============ Identity + counters ============ --}}
<div class="grid gap-4 lg:grid-cols-4">
    <div class="card lg:col-span-2">
        <div class="card-body flex items-center gap-4">
            <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-600 text-xl font-semibold text-white">
                {{ mb_substr($Userinfo['user']['username'], 0, 1) }}
            </span>
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="truncate text-lg font-semibold text-slate-900">{{ $Userinfo['user']['username'] }}</span>
                    @if ($Userinfo['user']['certifi']['status'] == 1)
                        <span class="badge-emerald">已实名认证</span>
                    @else
                        <span class="badge-slate">未认证</span>
                    @endif
                </div>
                <div class="mt-1 text-sm text-slate-500">
                    UID: {{ $Userinfo['user']['id'] }}
                    @if (! empty($Userinfo['user']['phonenumber']))
                        · {{ \Illuminate\Support\Str::mask($Userinfo['user']['phonenumber'], '*', 3, 4) }}
                    @endif
                </div>
                @if ($Userinfo['user']['create_time'])
                    <div class="mt-0.5 text-xs text-slate-400">
                        注册于 {{ date('Y-m-d', $Userinfo['user']['create_time']) }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <a href="/supporttickets" class="stat-tile hover:border-brand-300">
        <span class="grid h-10 w-10 place-items-center rounded-lg bg-amber-50 text-amber-600">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M2 5a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 100 4v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2a2 2 0 100-4V5z"/>
            </svg>
        </span>
        <span>
            <span class="stat-value">{{ $index['ticket_count'] }}</span>
            <span class="stat-label block">待处理工单</span>
        </span>
    </a>

    <a href="/billing?status=Unpaid" class="stat-tile hover:border-brand-300">
        <span class="grid h-10 w-10 place-items-center rounded-lg bg-rose-50 text-rose-600">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm1 4h10v2H5V7zm0 4h7v2H5v-2z"/>
            </svg>
        </span>
        <span>
            <span class="stat-value">{{ $index['order_count'] }}</span>
            <span class="stat-label block">未支付订单</span>
        </span>
    </a>
</div>

{{-- ============ Finance ============ --}}
<div class="mt-4 grid gap-4 lg:grid-cols-3">
    <div class="card lg:col-span-2">
        <div class="card-header">
            <h2 class="card-title">账户概览</h2>
            <a href="/transaction" class="btn-ghost btn-sm">交易记录</a>
        </div>
        <div class="card-body grid gap-4 sm:grid-cols-3">
            <div>
                <div class="stat-label">账户余额</div>
                <div class="stat-value">{{ $Currency['prefix'] }}{{ number_format($credit, 2) }}<span class="ml-1 text-sm font-normal text-slate-500">{{ $Currency['suffix'] }}</span></div>
            </div>
            <div>
                <div class="stat-label">未支付账单</div>
                <div class="stat-value text-rose-600">{{ $Currency['prefix'] }}{{ number_format($unpaid, 2) }}</div>
            </div>
            <div>
                <div class="stat-label">本月消费</div>
                <div class="stat-value">{{ $Currency['prefix'] }}{{ number_format((float) $index['intotal'], 2) }}</div>
            </div>

            <div class="sm:col-span-3">
                <div class="mb-1.5 flex items-center justify-between text-xs text-slate-500">
                    <span>余额 / 待付账单占比</span>
                    <span>合计 {{ $Currency['prefix'] }}{{ number_format($credit + $unpaid, 2) }}</span>
                </div>
                <div class="flex h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-brand-500" style="width: {{ round(($credit / $max) * 100, 2) }}%"></div>
                    <div class="h-full bg-rose-400" style="width: {{ round(($unpaid / $max) * 100, 2) }}%"></div>
                </div>
                <div class="mt-2 flex gap-4 text-xs text-slate-500">
                    <span class="flex items-center gap-1.5"><i class="inline-block h-2 w-2 rounded-full bg-brand-500"></i>余额</span>
                    <span class="flex items-center gap-1.5"><i class="inline-block h-2 w-2 rounded-full bg-rose-400"></i>待支付</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3">
            @if ($index['allow_recharge'] === '1')
                <a href="/addfunds" class="btn-primary btn-sm">账户充值</a>
            @endif
            <a href="/billing" class="btn-secondary btn-sm">我的账单</a>
            <a href="/service" class="btn-secondary btn-sm">我的服务</a>
            <a href="/submitticket" class="btn-secondary btn-sm">提交工单</a>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">公告</h2>
            <a href="/news" class="btn-ghost btn-sm">全部</a>
        </div>
        <div class="card-body">
            @forelse ($index['news'] as $news)
                <a href="/newsview?id={{ $news['id'] }}" class="block border-b border-slate-100 py-2.5 last:border-0 hover:text-brand-600">
                    <div class="truncate text-sm font-medium">{{ $news['title'] }}</div>
                    <div class="mt-0.5 text-xs text-slate-400">{{ date('Y-m-d H:i', $news['push_time']) }}</div>
                </a>
            @empty
                <p class="text-sm text-slate-400">暂无公告</p>
            @endforelse
        </div>
    </div>
</div>

{{-- ============ Product groups ============ --}}
@if (! empty($index['host_nav']))
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($index['host_nav'] as $group)
            <a href="/service?groupid={{ $group['id'] }}" class="badge-brand hover:bg-brand-100">
                {{ $group['groupname'] }} ({{ $group['count'] }})
            </a>
        @endforeach
    </div>
@endif

{{-- ============ Resource list ============ --}}
<div class="card mt-4">
    <div class="card-header">
        <h2 class="card-title">我的产品</h2>
        <div class="flex items-center gap-2">
            <select id="sourcelimitSel" class="form-select w-24 py-1.5 text-xs">
                @foreach ([5, 10, 15, 20, 50, 100] as $size)
                    <option value="{{ $size }}" @selected($size === 10)>{{ $size }} 条/页</option>
                @endforeach
            </select>
            <a href="/service" class="btn-secondary btn-sm">全部服务</a>
        </div>
    </div>

    <div id="sourceListBox" class="overflow-x-auto">
        @include('web.clientarea._list', ['rows' => [], 'ClientArea' => ['Total' => 0, 'Limit' => 10, 'Page' => 1, 'Pages' => 1]])
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const box = document.getElementById('sourceListBox');
        const limitSel = document.getElementById('sourcelimitSel');

        // The original loads this fragment with $.get(...).html(data); the same
        // endpoint is reused here.
        async function load(page = 1, orderby = 'nextduedate', sort = 'ASC') {
            const limit = limitSel.value;
            box.style.opacity = '0.5';

            try {
                const response = await fetch(
                    `/clientarea?action=list&page=${page}&limit=${limit}&orderby=${orderby}&sort=${sort}`,
                    { headers: { 'X-Requested-With': 'XMLHttpRequest' } },
                );

                box.innerHTML = await response.text();
                bindSort();
            } catch (error) {
                box.innerHTML = '<div class="p-6 text-sm text-rose-600">加载失败，请刷新页面重试</div>';
            } finally {
                box.style.opacity = '1';
            }
        }

        // Pagination links inside the fragment are followed in place.
        function bindSort() {
            box.querySelectorAll('a[data-page]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    load(link.dataset.page, link.dataset.orderby || 'nextduedate', link.dataset.sort || 'ASC');
                });
            });

            box.querySelectorAll('[data-orderby]').forEach((button) => {
                button.addEventListener('click', () => {
                    const next = button.dataset.sort === 'ASC' ? 'DESC' : 'ASC';
                    load(1, button.dataset.orderby, next);
                });
            });
        }

        limitSel?.addEventListener('change', () => load(1));
        load(1);
    })();
</script>
@endpush
