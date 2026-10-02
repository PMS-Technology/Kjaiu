@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">{{ $Title }}</h2>
        <span class="text-xs text-slate-500">共 {{ $Total }} 条记录</span>
    </div>

    {{-- The three log pages link to each other. --}}
    <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-5">
        <a href="/systemlog" class="tab-link {{ $logTemplate === 'systemlog' ? 'tab-link-active' : '' }}">系统日志</a>
        <a href="/loginlog" class="tab-link {{ $logTemplate === 'loginlog' ? 'tab-link-active' : '' }}">登录日志</a>
        <a href="/apilog" class="tab-link {{ $logTemplate === 'apilog' ? 'tab-link-active' : '' }}">API 日志</a>
    </div>

    <form method="get" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <label class="form-label" for="logKeywords">关键词</label>
            <input type="text" name="keywords" id="logKeywords" class="form-input w-56 py-1.5 text-sm"
                   value="{{ request('keywords') }}" placeholder="操作详情 / IP 地址">
        </div>
        <button type="submit" class="btn-primary btn-sm">搜索</button>
        <a href="{{ request()->url() }}" class="btn-secondary btn-sm">重置</a>
    </form>

    @if (empty($logs))
        <div class="px-5 py-14 text-center text-sm text-slate-500">暂无日志记录</div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr>
                    <th>操作详情</th>
                    <th>操作时间</th>
                    <th>IP 地址</th>
                    <th>操作人</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td>{{ $log['description'] }}</td>
                        <td class="whitespace-nowrap">{{ $log['format_time'] }}</td>
                        <td class="font-mono text-xs">{{ $log['ipaddr'] }}</td>
                        <td>{{ $log['user'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
    @endif
</div>
@endsection
