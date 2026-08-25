@extends('layouts.admin')

@section('title', '资金流水')
@section('eyebrow', 'KJAIU / MONEY MOVEMENT')
@section('description', '审阅收支流水，并在严格审计下执行客户余额调账。')

@section('actions')
    <button class="button button-primary" type="button" data-dialog-open="adjust-dialog">余额调账 <span>±</span></button>
@endsection

@section('content')
    <section class="finance-summary">
        <article><span>累计流入</span><strong class="amount-in"><small>¥</small>{{ number_format((float) $summary['in'], 2) }}</strong><p>外部收款、充值与正向调整</p></article>
        <article><span>累计流出</span><strong><small>¥</small>{{ number_format((float) $summary['out'], 2) }}</strong><p>余额支付与负向调整</p></article>
        <article><span>累计手续费</span><strong><small>¥</small>{{ number_format((float) $summary['fees'], 2) }}</strong><p>支付渠道手续费记录</p></article>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar">
            <form class="search-form" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索流水号、渠道、客户或邮箱">
                <button type="submit">搜索</button>
            </form>
            <span class="result-count">{{ $transactions->total() }} 条资金记录</span>
        </header>

        <div class="table-scroll">
            <table class="data-table resource-table">
                <thead><tr><th>流水号</th><th>客户</th><th>类型 / 渠道</th><th>流入</th><th>流出</th><th>变动后余额</th><th>入账时间</th><th class="align-right">关联</th></tr></thead>
                <tbody>
                @forelse($transactions as $transaction)
                    <tr>
                        <td data-label="流水号"><span class="mono-link mono-static">{{ $transaction->transaction_number }}</span><small class="cell-sub">ID {{ $transaction->id }}</small></td>
                        <td data-label="客户"><strong>{{ $transaction->user?->name ?? '未知客户' }}</strong><small class="cell-sub">{{ $transaction->user?->email }}</small></td>
                        <td data-label="类型 / 渠道"><span class="type-chip">{{ strtoupper($transaction->type) }}</span><small class="cell-sub">{{ $transaction->gateway }}</small></td>
                        <td data-label="流入" class="money-cell amount-in">{{ (float)$transaction->amount_in > 0 ? '+ ¥'.number_format((float)$transaction->amount_in, 2) : '—' }}</td>
                        <td data-label="流出" class="money-cell">{{ (float)$transaction->amount_out > 0 ? '- ¥'.number_format((float)$transaction->amount_out, 2) : '—' }}</td>
                        <td data-label="变动后余额" class="money-cell">¥{{ number_format((float)$transaction->balance_after, 2) }}</td>
                        <td data-label="入账时间"><span class="muted">{{ $transaction->paid_at?->format('Y-m-d H:i:s') }}</span></td>
                        <td data-label="关联" class="align-right">@if($transaction->invoice)<a class="row-action" href="{{ route('admin.invoices.show', $transaction->invoice) }}">账单 #{{ $transaction->invoice_id }} →</a>@else<span class="muted">余额账</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="empty-state"><span>TX</span><h3>没有资金流水</h3><p>客户付款、充值或管理员调账后，流水会出现在这里。</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $transactions->links() }}</div>
    </section>

    <dialog class="modal" id="adjust-dialog" @if($errors->any()) open @endif>
        <form method="POST" action="{{ route('admin.finance.adjust') }}" data-confirm="余额调账会立即改变客户可用余额并生成不可删除的流水与审计记录，确认继续？">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <header class="modal-head"><div><p class="panel-kicker">BALANCE ADJUSTMENT</p><h2>客户余额调账</h2></div><button type="button" data-dialog-close aria-label="关闭">×</button></header>
            <div class="modal-body">
                <div class="risk-note"><strong>这是资金操作</strong><p>正数增加余额，负数扣减余额。调整后余额不能小于零，每次操作都会记录管理员身份、原因与 IP。</p></div>
                <div class="form-grid">
                    <label class="field field-full"><span>客户账户</span><select name="user_id" required><option value="">选择客户</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string) old('user_id') === (string) $customer->id)>{{ $customer->name }} · {{ $customer->email }} · 当前 ¥{{ number_format((float)$customer->credit, 2) }}</option>@endforeach</select></label>
                    <label class="field field-full"><span>调整金额</span><div class="money-input"><b>¥</b><input type="number" name="amount" value="{{ old('amount') }}" step="0.01" required placeholder="增加填 100.00，扣减填 -100.00"></div></label>
                    <label class="field field-full"><span>调账原因</span><textarea name="reason" rows="3" maxlength="1000" required placeholder="说明资金依据，便于后续审计追溯">{{ old('reason') }}</textarea></label>
                </div>
            </div>
            <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">确认调账并记账</button></footer>
        </form>
    </dialog>
@endsection
