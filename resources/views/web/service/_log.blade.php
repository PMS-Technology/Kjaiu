{{-- 操作日志 tab fragment for one service. --}}
@if (empty($logs))
    <div class="px-5 py-8 text-center text-sm text-slate-400">暂无操作记录</div>
@else
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
                <td>{{ $log['create_time'] ? date('Y-m-d H:i', $log['create_time']) : '—' }}</td>
                <td class="font-mono text-xs">{{ $log['ipaddr'] }}</td>
                <td>{{ $log['user'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @include('web.partials.pagination', [
        'total' => $Total,
        'pages' => $Pages,
        'page' => $Page,
    ])
@endif
