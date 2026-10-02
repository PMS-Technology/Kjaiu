@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 lg:px-8">
    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside class="space-y-4">
            <div class="card p-4">
                <h2 class="mb-2 text-sm font-semibold text-slate-900">帮助分类</h2>
                <ul class="space-y-0.5">
                    <li>
                        <a href="/knowledgebase" class="aside-link {{ $cate === 0 ? 'aside-link-active' : '' }}">全部文章</a>
                    </li>
                    @foreach ($classify as $category)
                        <li>
                            <a href="/knowledgebase?cate={{ $category['id'] }}"
                               class="aside-link {{ $cate === $category['id'] ? 'aside-link-active' : '' }}">
                                {{ $category['title'] }}
                                <span class="text-xs text-slate-400">({{ $category['count'] }})</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card p-4">
                <form method="get" action="/knowledgebase" class="space-y-2">
                    @if ($cate)
                        <input type="hidden" name="cate" value="{{ $cate }}">
                    @endif
                    <input type="text" name="keywords" value="{{ $keywords }}" class="form-input py-1.5 text-sm"
                           placeholder="搜索帮助内容">
                    <button type="submit" class="btn-primary btn-sm w-full">搜索</button>
                </form>
            </div>
        </aside>

        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-semibold text-slate-900">帮助中心</h1>
                <span class="text-xs text-slate-500">共 {{ $Total }} 篇文章</span>
            </div>

            @if (empty($help))
                <div class="card p-14 text-center text-sm text-slate-500">暂无帮助文档</div>
            @else
                @foreach ($help as $article)
                    <a href="/knowledgebaseview?id={{ $article['id'] }}" class="card block p-5 transition-shadow hover:shadow-md">
                        <h3 class="font-medium text-slate-900">{{ $article['title'] }}</h3>
                        <div class="mt-1.5 flex flex-wrap gap-3 text-xs text-slate-400">
                            <span>{{ $article['create_time'] ? date('Y-m-d H:i', $article['create_time']) : '' }}</span>
                            <span>浏览 {{ $article['views'] }}</span>
                            <span>有用 {{ $article['useful'] }}</span>
                            @if (! empty($article['public_by']))
                                <span>发布者 {{ $article['public_by'] }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach

                @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
            @endif
        </div>
    </div>
</div>
@endsection
