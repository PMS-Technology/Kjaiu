@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-10 lg:px-8">
    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside class="space-y-4">
            <div class="card p-4">
                <h2 class="mb-2 text-sm font-semibold text-slate-900">分类</h2>
                <ul class="space-y-0.5">
                    <li>
                        <a href="/news" class="aside-link {{ $cate === 0 ? 'aside-link-active' : '' }}">
                            全部新闻
                        </a>
                    </li>
                    @foreach ($classify as $category)
                        <li>
                            <a href="/news?cate={{ $category['id'] }}"
                               class="aside-link {{ $cate === $category['id'] ? 'aside-link-active' : '' }}">
                                {{ $category['title'] }}
                                <span class="text-xs text-slate-400">({{ $category['count'] }})</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card p-4">
                <form method="get" action="/news" class="space-y-2">
                    @if ($cate)
                        <input type="hidden" name="cate" value="{{ $cate }}">
                    @endif
                    <input type="text" name="keywords" value="{{ $keywords }}" class="form-input py-1.5 text-sm"
                           placeholder="搜索新闻标题">
                    <button type="submit" class="btn-primary btn-sm w-full">搜索</button>
                </form>
            </div>
        </aside>

        <div class="space-y-4">
            @if (empty($NewsList))
                <div class="card p-14 text-center text-sm text-slate-500">暂无新闻内容</div>
            @else
                @foreach ($NewsList as $news)
                    <a href="/newsview?id={{ $news['id'] }}" class="card flex gap-4 p-5 transition-shadow hover:shadow-md">
                        @if (! empty($news['head_img']))
                            <img src="{{ $news['head_img'] }}" alt="{{ $news['title'] }}" class="h-20 w-28 shrink-0 rounded-lg object-cover">
                        @endif
                        <div class="min-w-0">
                            <h3 class="font-semibold text-slate-900">{{ $news['title'] }}</h3>
                            @if (! empty($news['description']))
                                <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-500">{{ $news['description'] }}</p>
                            @endif
                            <div class="mt-2 text-xs text-slate-400">
                                {{ $news['push_time'] ? date('Y-m-d H:i', $news['push_time']) : '' }}
                            </div>
                        </div>
                    </a>
                @endforeach

                @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
            @endif
        </div>
    </div>
</div>
@endsection
