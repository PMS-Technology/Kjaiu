@extends('layouts.admin')
@section('title', '操作记录')
@section('eyebrow', 'KJAIU / AUDIT TRAIL')
@section('description', '按人员、动作和对象查看后台变更，字段变化以修改前后值展示。')
@section('content')
<section class="panel resource-panel">
    <header class="resource-toolbar"><form class="search-form" method="GET"><input name="q" value="{{ $keyword }}" placeholder="搜索管理员、动作、对象 ID 或 IP"><button>搜索</button></form><span class="result-count">{{ $logs->total() }} 条记录</span></header>
    <div class="table-scroll"><table class="data-table resource-table"><thead><tr><th>时间</th><th>操作人</th><th>做了什么</th><th>对象</th><th>变更内容</th><th>来源</th></tr></thead><tbody>
    @forelse($logs as $log)<tr>
        <td data-label="时间"><strong>{{ $log->created_at->format('Y-m-d H:i:s') }}</strong></td>
        <td data-label="操作人"><strong>{{ $log->actor?->name ?? '系统' }}</strong><small class="cell-sub">{{ $log->actor?->email }}</small></td>
        <td data-label="做了什么"><strong>{{ $log->action_label }}</strong><small class="cell-sub">{{ $log->action }}</small></td>
        <td data-label="对象">{{ $log->subject_label ?: '系统' }}</td>
        <td data-label="变更内容"><details><summary>查看详情</summary><div class="audit-change">@if($log->before)<b>修改前</b><pre>{{ json_encode($log->before, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@endif @if($log->after)<b>修改后</b><pre>{{ json_encode($log->after, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@endif</div></details></td>
        <td data-label="来源"><strong>{{ $log->ip_address }}</strong></td>
    </tr>@empty<tr><td colspan="6"><div class="empty-state"><h3>暂无操作记录</h3></div></td></tr>@endforelse
    </tbody></table></div><div class="pagination-wrap">{{ $logs->links() }}</div>
</section>
@endsection
