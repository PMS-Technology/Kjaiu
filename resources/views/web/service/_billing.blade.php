{{-- 财务信息 tab fragment for one service. --}}
@if (empty($invoices))
    <div class="px-5 py-8 text-center text-sm text-slate-400">暂无账单记录</div>
@else
    <table class="data-table">
        <thead>
        <tr>
            <th>账单号</th>
            <th>金额</th>
            <th>生成时间</th>
            <th>到期时间</th>
            <th>状态</th>
            <th class="text-right">操作</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($invoices as $invoice)
            <tr>
                <td><a href="/viewbilling?id={{ $invoice['id'] }}" class="text-brand-600 hover:underline">#{{ $invoice['id'] }}</a></td>
                <td>{{ $Currency['prefix'] }}{{ $invoice['subtotal'] }}{{ $Currency['suffix'] }}</td>
                <td>{{ $invoice['create_time'] ? date('Y-m-d H:i', $invoice['create_time']) : '—' }}</td>
                <td>{{ $invoice['due_time'] ? date('Y-m-d H:i', $invoice['due_time']) : '—' }}</td>
                <td><span class="badge-{{ $invoice['status_color'] }}">{{ $invoice['status_zh'] }}</span></td>
                <td class="text-right">
                    <a href="/viewbilling?id={{ $invoice['id'] }}" class="btn-ghost btn-sm">查看</a>
                </td>
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
