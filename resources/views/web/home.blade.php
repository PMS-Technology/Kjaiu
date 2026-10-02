@extends('web.layouts.public')

@section('content')
{{-- ============ Hero ============ --}}
<section class="border-b border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 lg:grid-cols-2 lg:px-8 lg:py-20">
        <div class="flex flex-col justify-center">
            <span class="badge-brand mb-4 self-start">稳定可靠的云服务</span>
            <h1 class="text-3xl font-bold leading-tight tracking-tight text-slate-900 sm:text-4xl">
                {{ $Setting['web_name'] }} · 一站式云计算服务
            </h1>
            <p class="mt-4 text-base leading-7 text-slate-600">
                提供云服务器、独立服务器、虚拟主机与企业级 CDN 服务。按需计费，弹性伸缩，
                7×24 小时技术支持。
            </p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="/cart" class="btn-primary btn-lg">立即订购</a>
                <a href="/knowledgebase" class="btn-secondary btn-lg">了解更多</a>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @forelse ($announcements as $item)
                <a href="/newsview?id={{ $item['id'] }}"
                   class="card p-5 transition-shadow hover:shadow-md {{ $loop->first ? 'sm:col-span-2' : '' }}">
                    <div class="mb-1 text-xs text-slate-400">{{ $item['push_time'] ? date('Y-m-d', $item['push_time']) : '' }}</div>
                    <div class="font-medium text-slate-900">{{ $item['title'] }}</div>
                    @if (! empty($item['description']))
                        <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ $item['description'] }}</p>
                    @endif
                </a>
            @empty
                <div class="card p-6 sm:col-span-2">
                    <p class="text-sm text-slate-500">暂无公告，欢迎浏览我们的产品。</p>
                </div>
            @endforelse
        </div>
    </div>
</section>

{{-- ============ Featured products ============ --}}
<section class="mx-auto max-w-7xl px-4 py-14 lg:px-8">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">热门产品</h2>
            <p class="mt-1 text-sm text-slate-500">按需选择计费周期，随时升降级</p>
        </div>
        <a href="/cart" class="btn-ghost btn-sm">查看全部产品</a>
    </div>

    @if (empty($featured))
        <div class="card p-10 text-center">
            <p class="text-sm text-slate-500">暂无上架产品，请联系管理员。</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($featured as $product)
                <div class="card flex flex-col p-5">
                    <div class="mb-2 flex items-start justify-between gap-2">
                        <h3 class="font-semibold text-slate-900">{{ $product['name'] }}</h3>
                        <span class="badge-slate shrink-0">{{ $product['type_zh'] }}</span>
                    </div>
                    <p class="mb-4 line-clamp-3 flex-1 text-sm leading-6 text-slate-500">
                        {{ $product['description'] ?: '暂无产品描述' }}
                    </p>

                    @if ($product['price'] !== null)
                        <div class="mb-4">
                            <span class="text-2xl font-semibold text-brand-600">{{ $Currency['prefix'] }}{{ $product['price'] }}</span>
                            <span class="text-sm text-slate-500">/{{ $product['cycle_zh'] }}</span>
                        </div>
                    @else
                        <div class="mb-4 text-sm text-slate-500">价格请咨询客服</div>
                    @endif

                    @if ($product['sold_out'])
                        <span class="btn-secondary w-full cursor-not-allowed opacity-60">已售罄</span>
                    @else
                        <a href="/cart?action=configureproduct&pid={{ $product['id'] }}" class="btn-primary w-full">立即订购</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>

{{-- ============ Categories ============ --}}
@if (! empty($categories))
    <section class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-14 lg:px-8">
            <h2 class="mb-6 text-xl font-semibold text-slate-900">产品分类</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($categories as $category)
                    <div class="card p-5">
                        <h3 class="mb-3 font-semibold text-slate-900">{{ $category['name'] }}</h3>
                        <ul class="space-y-1.5">
                            @foreach ($category['groups'] as $group)
                                <li>
                                    <a href="/cart?fid={{ $category['id'] }}&gid={{ $group['id'] }}"
                                       class="text-sm text-slate-500 hover:text-brand-600">{{ $group['name'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
@endsection
