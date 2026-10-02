@extends('web.layouts.public')

@section('content')
@php $article = $ViewAnnouncement; @endphp

<div class="mx-auto max-w-3xl px-4 py-10 lg:px-8">
    <nav class="mb-4 flex items-center gap-2 text-sm text-slate-500">
        <a href="/news" class="hover:text-brand-600">新闻中心</a>
        @if (! empty($article['cate_name']))
            <span>/</span>
            <span>{{ $article['cate_name'] }}</span>
        @endif
    </nav>

    <article class="card p-6 lg:p-8">
        <h1 class="text-2xl font-semibold leading-snug text-slate-900">{{ $article['title'] }}</h1>
        <div class="mt-3 flex flex-wrap gap-3 text-xs text-slate-400">
            @if ($article['push_time'])
                <span>发布时间：{{ date('Y-m-d H:i', $article['push_time']) }}</span>
            @endif
            @if (! empty($article['cate_name']))
                <span>分类：{{ $article['cate_name'] }}</span>
            @endif
        </div>

        <div class="prose-content mt-6 border-t border-slate-100 pt-6">
            {!! $article['content'] !!}
        </div>
    </article>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @if (! empty($article['prev']))
            <a href="/newsview?id={{ $article['prev']['id'] }}" class="card p-4 hover:border-brand-300">
                <div class="text-xs text-slate-400">上一篇</div>
                <div class="mt-0.5 truncate text-sm text-slate-800">{{ $article['prev']['title'] }}</div>
            </a>
        @endif
        @if (! empty($article['next']))
            <a href="/newsview?id={{ $article['next']['id'] }}" class="card p-4 hover:border-brand-300 sm:text-right">
                <div class="text-xs text-slate-400">下一篇</div>
                <div class="mt-0.5 truncate text-sm text-slate-800">{{ $article['next']['title'] }}</div>
            </a>
        @endif
    </div>

    <div class="mt-6 text-center">
        <a href="/news" class="btn-secondary btn-sm">返回列表</a>
    </div>
</div>
@endsection
