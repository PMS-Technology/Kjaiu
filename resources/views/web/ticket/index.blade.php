@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">工单列表</h2>
        <a href="/submitticket" class="btn-primary btn-sm">提交工单</a>
    </div>

    @if (empty($tickets))
        <div class="px-5 py-14 text-center">
            <p class="text-sm text-slate-500">暂无工单记录。</p>
            <a href="/submitticket" class="btn-primary btn-sm mt-4">提交工单</a>
        </div>
    @else
        <form method="get" action="/supporttickets" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
            <div>
                <label class="form-label" for="ticketStatusFilter">工单状态</label>
                <select name="status_id" id="ticketStatusFilter" class="form-select w-40 py-1.5 text-sm">
                    <option value="">全部</option>
                    @foreach ($TicketStatuses as $status)
                        <option value="{{ $status['id'] }}" @selected((int) request('status_id') === $status['id'])>
                            {{ $status['title'] }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label" for="ticketKeywords">关键词</label>
                <input type="text" name="keywords" id="ticketKeywords" class="form-input w-48 py-1.5 text-sm"
                       value="{{ request('keywords') }}" placeholder="标题 / 工单号">
            </div>
            <button type="submit" class="btn-primary btn-sm">筛选</button>
            <a href="/supporttickets" class="btn-secondary btn-sm">重置</a>
        </form>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr>
                    <th>工单部门</th>
                    <th>标题</th>
                    <th>优先级</th>
                    <th>创建时间</th>
                    <th>更新时间</th>
                    <th>状态</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($tickets as $ticket)
                    <tr>
                        <td>{{ $ticket['department_name'] }}</td>
                        <td>
                            <a href="/viewticket?tid={{ $ticket['tid'] }}&c={{ $ticket['c'] }}"
                               class="font-medium text-brand-600 hover:underline">
                                #{{ $ticket['tid'] }} - {{ $ticket['title'] }}
                            </a>
                            @if ($ticket['client_unread'])
                                <span class="badge-rose ml-1">新回复</span>
                            @endif
                        </td>
                        <td>{{ $ticket['priority'] }}</td>
                        <td>{{ $ticket['create_time'] ? date('Y-m-d H:i', $ticket['create_time']) : '—' }}</td>
                        <td>{{ $ticket['last_reply_time'] ? date('Y-m-d H:i', $ticket['last_reply_time']) : '—' }}</td>
                        <td>
                            {{-- Colour and wording are administrator-editable rows. --}}
                            <span class="badge" style="background-color: {{ $ticket['status']['color'] }}1a; color: {{ $ticket['status']['color'] }}; box-shadow: inset 0 0 0 1px {{ $ticket['status']['color'] }}33;">
                                {{ $ticket['status']['title'] }}
                            </span>
                        </td>
                        <td class="text-right">
                            <a href="/viewticket?tid={{ $ticket['tid'] }}&c={{ $ticket['c'] }}" class="btn-ghost btn-sm">查看</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
    @endif
</div>
@endsection
