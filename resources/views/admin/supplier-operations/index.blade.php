@extends('layouts.admin')

@section('title', '上游操作')
@section('eyebrow', 'KJAIU / SUPPLIER OPERATIONS')
@section('description', '审阅上游交付状态，并仅通过已有账单、主机证据或只读轮询执行受控恢复。')

@section('content')
    @php
        $pageItems = $operations->getCollection();
        $attentionCount = $pageItems->whereIn('status', ['ambiguous', 'blocked_credit', 'failed'])->count();
        $waitingCount = $pageItems->where('status', 'awaiting_confirmation')->count();
    @endphp

    <div class="notice notice-error" role="note">
        <span class="notice-icon">!</span>
        <span>结果不明确的操作必须先取得供应商账单或主机证据，系统不会盲目重放清空购物车、加购、结算或创建订单。</span>
    </div>

    <section class="summary-strip">
        <div><span>匹配操作</span><strong>{{ number_format($operations->total()) }}</strong></div>
        <i></i>
        <div><span>本页待人工复核</span><strong>{{ $attentionCount }}</strong></div>
        <i></i>
        <div><span>本页等待上游确认</span><strong>{{ $waitingCount }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar resource-toolbar-wrap">
            <form class="form-grid" method="GET" style="width: min(720px, 100%); align-items: end">
                <label class="field">
                    <span>操作状态</span>
                    <select name="status">
                        <option value="">全部状态</option>
                        @foreach($statusLabels as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>供应商账户</span>
                    <select name="supplier">
                        <option value="">全部供应商</option>
                        @foreach($accounts as $accountOption)
                            <option value="{{ $accountOption['id'] }}" @selected($supplierId === $accountOption['id'])>{{ $accountOption['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>操作动作</span>
                    <select name="action">
                        <option value="">全部动作</option>
                        @foreach($actionLabels as $value => $label)
                            <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="button button-primary" type="submit">应用筛选</button>
            </form>
            <span class="result-count">仅显示安全摘要 · 每页 25 项</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead>
                    <tr><th>操作 / 本地服务</th><th>供应商</th><th>动作与状态</th><th>执行进度</th><th>上游关联</th><th>更新时间</th><th class="align-right">审阅</th></tr>
                </thead>
                <tbody>
                @forelse($operations as $operation)
                    <tr>
                        <td data-label="操作 / 本地服务">
                            <strong>操作 #{{ $operation['id'] }}</strong>
                            <small class="cell-sub">服务 #{{ $operation['service_id'] ?? '—' }} · 账单 #{{ $operation['invoice_id'] ?? '—' }} · 订单 #{{ $operation['order_id'] ?? '—' }}</small>
                        </td>
                        <td data-label="供应商"><strong>{{ $operation['supplier_name'] }}</strong></td>
                        <td data-label="动作与状态"><strong>{{ $operation['action_label'] }}</strong><small class="cell-sub"><span class="status {{ $operation['status_class'] }}">{{ $operation['status_label'] }}</span></small></td>
                        <td data-label="执行进度"><strong>{{ $operation['step'] }}</strong><small class="cell-sub">尝试 {{ $operation['attempts'] }} 次</small></td>
                        <td data-label="上游关联"><strong>主机 {{ $operation['upstream_host_id'] }}</strong><small class="cell-sub">账单 {{ $operation['upstream_invoice_id'] }} · 订单 {{ $operation['upstream_order_id'] }}</small></td>
                        <td data-label="更新时间"><strong>{{ $operation['updated_at'] ?? '—' }}</strong><small class="cell-sub">创建 {{ $operation['created_at'] ?? '—' }}</small></td>
                        <td data-label="审阅" class="align-right"><button class="row-action" type="button" data-dialog-open="supplier-operation-{{ $operation['id'] }}">查看详情 →</button></td>
                    </tr>

                @empty
                    <tr><td colspan="7"><div class="empty-state"><span>OPS</span><h3>没有匹配的上游操作</h3><p>调整状态、供应商或动作筛选条件继续查找。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @foreach($operations as $operation)
            @include('admin.supplier-operations._dialog', ['operation' => $operation])
        @endforeach
        <div class="pagination-wrap">{{ $operations->links() }}</div>
    </section>
@endsection
