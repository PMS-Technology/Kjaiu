@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 lg:px-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">订购产品</h1>
            <p class="mt-1 text-sm text-slate-500">选择合适的产品与计费周期</p>
        </div>

        <form method="get" action="/cart" class="flex gap-2">
            <input type="hidden" name="action" value="product">
            <input type="hidden" name="fid" value="{{ $Cart['fid'] }}">
            <input type="text" name="keywords" value="{{ $Cart['keywords'] }}" placeholder="搜索产品名称"
                   class="form-input w-56 py-1.5 text-sm">
            <button type="submit" class="btn-primary btn-sm">搜索</button>
        </form>
    </div>

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        {{-- ============ Sidebar categories ============ --}}
        <aside class="space-y-4">
            @foreach ($Cart['product_groups'] as $group)
                <div class="card p-4">
                    <h2 class="mb-2 text-sm font-semibold text-slate-900">{{ $group['name'] }}</h2>
                    <ul class="space-y-0.5">
                        @foreach ($group['second'] as $second)
                            <li>
                                <a href="/cart?fid={{ $group['id'] }}&gid={{ $second['id'] }}"
                                   class="aside-link {{ $Cart['product_groups_checked']['id'] === $second['id'] ? 'aside-link-active' : '' }}">
                                    {{ $second['name'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach

            @if (! empty($Cart['product_groups_checked']['name']))
                <div class="card p-4">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $Cart['product_groups_checked']['name'] }}</h2>
                    @if (! empty($Cart['product_groups_checked']['headline']))
                        <p class="mt-1 text-xs text-slate-500">{{ $Cart['product_groups_checked']['headline'] }}</p>
                    @endif
                    @if (! empty($Cart['product_groups_checked']['tagline']))
                        <p class="mt-1 text-xs text-slate-400">{{ $Cart['product_groups_checked']['tagline'] }}</p>
                    @endif
                </div>
            @endif
        </aside>

        {{-- ============ Product grid ============ --}}
        <div>
            @if (empty($Cart['products']))
                <div class="card p-14 text-center">
                    <p class="text-sm text-slate-500">该分类下暂无可订购的产品。</p>
                    <a href="/cart" class="btn-secondary btn-sm mt-4">查看全部产品</a>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($Cart['products'] as $product)
                        <div class="card flex flex-col p-5">
                            <div class="mb-2 flex items-start justify-between gap-2">
                                <h3 class="font-semibold text-slate-900">{{ $product['name'] }}</h3>
                                <span class="badge-slate shrink-0">{{ $product['type_zh'] }}</span>
                            </div>

                            <p class="mb-4 line-clamp-3 flex-1 text-sm leading-6 text-slate-500">
                                {{ $product['description'] ?: '暂无产品描述' }}
                            </p>

                            <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                @if ($product['stock_control'] === 1)
                                    <span>库存：{{ $product['qty'] > 0 ? $product['qty'] : '售罄' }}</span>
                                @endif
                                @if ($product['allow_qty'])
                                    <span>支持多数量购买</span>
                                @endif
                            </div>

                            <div class="mb-4">
                                <span class="text-2xl font-semibold text-brand-600">
                                    {{ $Cart['currency']['prefix'] }}{{ $product['sale_price'] }}
                                </span>
                                <span class="text-sm text-slate-500">/{{ $product['billingcycle_zh'] }}</span>
                            </div>

                            @if ($product['stock_control'] === 1 && $product['qty'] < 1)
                                <span class="btn-secondary w-full cursor-not-allowed opacity-60">已售罄</span>
                            @else
                                <a href="/cart?action=configureproduct&pid={{ $product['id'] }}" class="btn-primary w-full">立即购买</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
