@extends('layouts.admin')

@section('title', '运营总览')
@section('eyebrow', 'KJAIU / COMMAND CENTER')
@section('description', '把收入、应收和交付状态放在同一张运营地图上。')

@section('actions')
    <span class="live-indicator"><i></i> 数据已同步</span>
    <a class="button button-primary" href="{{ route('admin.invoices.index') }}">查看账单 <span>↗</span></a>
@endsection

@section('content')
    <section class="metric-grid">
        <article class="metric-card metric-dark">
            <div class="metric-top"><span>本月实收</span><span class="metric-index">01</span></div>
            <strong><small>¥</small>{{ number_format((float) $metrics['monthlyRevenue'], 2) }}</strong>
            <div class="metric-foot"><span>MONTHLY REVENUE</span><i></i></div>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>待收账款</span><span class="metric-index">02</span></div>
            <strong><small>¥</small>{{ number_format((float) $metrics['outstanding'], 2) }}</strong>
            <div class="metric-foot"><span>OUTSTANDING</span><i></i></div>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>有效客户</span><span class="metric-index">03</span></div>
            <strong>{{ number_format($metrics['clients']) }}<small> 户</small></strong>
            <div class="metric-foot"><span>ACTIVE CLIENTS</span><i></i></div>
        </article>
        <article class="metric-card metric-accent">
            <div class="metric-top"><span>运行中服务</span><span class="metric-index">04</span></div>
            <strong>{{ number_format($metrics['activeServices']) }}<small> 项</small></strong>
            <div class="metric-foot"><span>ACTIVE SERVICES</span><i></i></div>
        </article>
    </section>

    <section class="dashboard-grid dashboard-grid-main">
        <article class="panel revenue-panel">
            <header class="panel-head">
                <div><p class="panel-kicker">REVENUE PULSE</p><h2>近六个月收入</h2></div>
                <span class="panel-meta">{{ now()->subMonths(5)->format('Y.m') }} — {{ now()->format('Y.m') }}</span>
            </header>
            <div class="chart-area">
                <div class="chart-scale" aria-hidden="true"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0</span></div>
                <div class="bar-chart">
                    @foreach ($chart as $month)
                        @php($height = max(4, round(($month['amount'] / $maxRevenue) * 100, 2)))
                        <div class="bar-column" title="{{ $month['label'] }}：¥{{ number_format($month['amount'], 2) }}">
                            <span class="bar-value">{{ $month['amount'] > 0 ? '¥'.number_format($month['amount'], 0) : '—' }}</span>
                            <i style="--bar-height: {{ $height }}%"></i>
                            <small>{{ $month['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>

        <article class="panel transaction-panel">
            <header class="panel-head">
                <div><p class="panel-kicker">LATEST MOVEMENT</p><h2>最近资金动态</h2></div>
                <a class="text-link" href="{{ route('admin.finance.index') }}">全部流水 →</a>
            </header>
            <div class="movement-list">
                @forelse ($recentTransactions as $transaction)
                    <a href="{{ $transaction->invoice_id ? route('admin.invoices.show', $transaction->invoice_id) : route('admin.finance.index') }}" class="movement-row">
                        <span class="movement-mark {{ (float) $transaction->amount_in > 0 ? 'is-in' : 'is-out' }}">{{ (float) $transaction->amount_in > 0 ? '↙' : '↗' }}</span>
                        <span class="movement-copy"><strong>{{ $transaction->user?->name ?? '未知客户' }}</strong><small>{{ $transaction->gateway }} · {{ $transaction->paid_at?->format('m-d H:i') }}</small></span>
                        <strong class="movement-amount {{ (float) $transaction->amount_in > 0 ? 'amount-in' : '' }}">
                            {{ (float) $transaction->amount_in > 0 ? '+' : '-' }}¥{{ number_format((float) ((float) $transaction->amount_in > 0 ? $transaction->amount_in : $transaction->amount_out), 2) }}
                        </strong>
                    </a>
                @empty
                    <div class="empty-state compact"><span>↙</span><p>暂无资金流水</p></div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="dashboard-grid dashboard-grid-lower">
        <article class="panel invoice-panel">
            <header class="panel-head">
                <div><p class="panel-kicker">INVOICE DESK</p><h2>最新账单</h2></div>
                <a class="text-link" href="{{ route('admin.invoices.index') }}">账单中心 →</a>
            </header>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>编号</th><th>客户</th><th>金额</th><th>状态</th><th>时间</th></tr></thead>
                    <tbody>
                    @forelse ($recentInvoices as $invoice)
                        <tr>
                            <td data-label="编号"><a class="mono-link" href="{{ route('admin.invoices.show', $invoice) }}">#{{ $invoice->number }}</a></td>
                            <td data-label="客户"><strong>{{ $invoice->user?->name ?? '未知客户' }}</strong><small class="cell-sub">{{ $invoice->user?->email }}</small></td>
                            <td data-label="金额" class="money-cell">¥{{ number_format((float) $invoice->total, 2) }}</td>
                            <td data-label="状态"><span class="status status-{{ strtolower($invoice->status) }}">{{ ['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status }}</span></td>
                            <td data-label="时间"><span class="muted">{{ $invoice->created_at->format('m-d H:i') }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state compact"><p>还没有账单记录</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel audit-panel">
            <header class="panel-head"><div><p class="panel-kicker">AUDIT TRAIL</p><h2>操作记录</h2></div></header>
            <div class="audit-list">
                @forelse ($recentAudits as $audit)
                    <div class="audit-row">
                        <span class="audit-dot"></span>
                        <div><strong>{{ str_replace(['.', '_'], ' / ', $audit->action) }}</strong><p>{{ $audit->actor?->name ?? '系统任务' }} · {{ $audit->created_at->diffForHumans() }}</p></div>
                    </div>
                @empty
                    <div class="empty-state compact"><p>暂无审计记录</p></div>
                @endforelse
            </div>
        </article>
    </section>
@endsection
