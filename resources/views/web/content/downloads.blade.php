@extends('web.layouts.public')

@section('content')
@php $D = $Downloads['downloads']; @endphp

<div class="mx-auto max-w-7xl px-4 py-10 lg:px-8">
    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <aside class="space-y-4">
            <div class="card p-4">
                <h2 class="mb-2 text-sm font-semibold text-slate-900">资源分类</h2>
                <ul class="space-y-0.5">
                    <li>
                        <a href="/downloads" class="aside-link {{ $D['cate_id'] === 0 ? 'aside-link-active' : '' }}">全部资源</a>
                    </li>
                    @foreach ($D['cate_data'] as $category)
                        <li>
                            <a href="/downloads?cate_id={{ $category['id'] }}"
                               class="aside-link {{ $D['cate_id'] === $category['id'] ? 'aside-link-active' : '' }}">
                                {{ $category['name'] }}
                                <span class="text-xs text-slate-400">({{ $category['file_count'] }})</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card p-4">
                <form method="get" action="/downloads" class="space-y-2">
                    @if ($D['cate_id'])
                        <input type="hidden" name="cate_id" value="{{ $D['cate_id'] }}">
                    @endif
                    <input type="text" name="keywords" value="{{ $D['keywords'] }}" class="form-input py-1.5 text-sm"
                           placeholder="搜索资源名称">
                    <button type="submit" class="btn-primary btn-sm w-full">搜索</button>
                </form>
            </div>
        </aside>

        <div>
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">资源下载</h1>
                    <span class="text-xs text-slate-500">共 {{ $D['Total'] }} 个资源</span>
                </div>

                @if (empty($D['downloads']))
                    <div class="px-5 py-14 text-center text-sm text-slate-500">该分类下暂无资源</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>资源名称</th>
                                <th>类型</th>
                                <th>更新时间</th>
                                <th>下载次数</th>
                                <th class="text-right">操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($D['downloads'] as $file)
                                <tr>
                                    <td>
                                        <div class="font-medium text-slate-800">{{ $file['title'] }}</div>
                                        @if (! empty($file['description']))
                                            <div class="mt-0.5 text-xs text-slate-400">{{ $file['description'] }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- 1 = zip, 2 = image, 3 = text --}}
                                        @php
                                            $typeMap = [
                                                1 => ['label' => '压缩包', 'class' => 'badge-amber'],
                                                2 => ['label' => '图片', 'class' => 'badge-emerald'],
                                                3 => ['label' => '文档', 'class' => 'badge-sky'],
                                            ];
                                            $meta = $typeMap[$file['type']] ?? ['label' => '其他', 'class' => 'badge-slate'];
                                        @endphp
                                        <span class="{{ $meta['class'] }}">{{ $meta['label'] }}</span>
                                    </td>
                                    <td>{{ $file['update_time'] ? date('Y-m-d H:i', $file['update_time']) : '—' }}</td>
                                    <td>{{ $file['downloads'] }}</td>
                                    <td class="text-right">
                                        @if (! empty($file['down_link']))
                                            <a href="{{ $file['down_link'] }}" target="_blank" rel="noopener" class="btn-primary btn-sm">下载</a>
                                        @else
                                            <span class="text-xs text-slate-400">暂无链接</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @include('web.partials.pagination', ['total' => $D['Total'], 'pages' => $D['Pages'], 'page' => $D['Page']])
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
