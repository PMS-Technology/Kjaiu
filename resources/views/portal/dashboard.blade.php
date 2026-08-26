@extends('layouts.portal')

@section('title', '账户概览')
@section('eyebrow', 'KJAIU / YOUR ACCOUNT')
@section('description', '余额、待付款项和服务交付状态，一处掌握。')

@section('actions')
    @if (auth()->user()->isAdministrator())
        <a class="button button-secondary" href="{{ route('admin.dashboard') }}">管理后台 ↗</a>
    @endif
    <a class="button button-primary" href="{{ route('portal.products.index') }}">选购产品 <span>↗</span></a>
@endsection

@section('content')
    <section class="metric-grid portal-metrics">
        <article class="metric-card metric-dark">
            <div class="metric-top"><span>账户余额</span><span class="metric-index">01</span></div>
            <strong><small>¥</small>{{ number_format((float) $metrics['balance'], 2) }}</strong>
            <div class="metric-foot"><span>AVAILABLE CREDIT</span><i></i></div>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>待支付账单</span><span class="metric-index">02</span></div>
            <strong>{{ number_format($metrics['unpaidCount']) }}<small> 笔</small></strong>
            <div class="metric-foot"><span>合计 ¥{{ number_format((float) $metrics['unpaidTotal'], 2) }}</span><i></i></div>
        </article>
        <article class="metric-card metric-accent">
            <div class="metric-top"><span>运行中服务</span><span class="metric-index">03</span></div>
            <strong>{{ number_format($metrics['activeServices']) }}<small> 项</small></strong>
            <div class="metric-foot"><span>ACTIVE SERVICES</span><i></i></div>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>待交付服务</span><span class="metric-index">04</span></div>
            <strong>{{ number_format($metrics['pendingServices']) }}<small> 项</small></strong>
            <div class="metric-foot"><span>PENDING DELIVERY</span><i></i></div>
        </article>
    </section>

    <section class="portal-dashboard-grid">
        <article class="panel">
            <header class="panel-head">
                <div><p class="panel-kicker">RECENT INVOICES</p><h2>最近账单</h2></div>
                <a class="text-link" href="{{ route('portal.invoices.index') }}">全部账单 →</a>
            </header>
            <div class="table-scroll">
                <table class="data-table resource-table">
                    <thead><tr><th>编号</th><th>金额</th><th>到期日</th><th>状态</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($recentInvoices as $invoice)
                        <tr>
                            <td data-label="编号"><a class="mono-link" href="{{ route('portal.invoices.show', $invoice) }}">#{{ $invoice->number }}</a></td>
                            <td data-label="金额" class="money-cell">¥{{ number_format((float) $invoice->total, 2) }}</td>
                            <td data-label="到期日"><span class="muted">{{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</span></td>
                            <td data-label="状态"><span class="status status-{{ strtolower($invoice->status) }}">{{ ['Paid'=>'已支付','Unpaid'=>'待支付','Cancelled'=>'已取消','Refunded'=>'已退款'][$invoice->status] ?? $invoice->status }}</span></td>
                            <td data-label="操作" class="align-right"><a class="row-action" href="{{ route('portal.invoices.show', $invoice) }}">查看 →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state compact"><p>还没有账单记录</p></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel">
            <header class="panel-head">
                <div><p class="panel-kicker">YOUR SERVICES</p><h2>最近服务</h2></div>
                <a class="text-link" href="{{ route('portal.services.index') }}">全部服务 →</a>
            </header>
            <div class="portal-compact-list">
                @forelse ($recentServices as $service)
                    <a class="portal-compact-row" href="{{ route('portal.services.show', $service) }}">
                        <span class="service-symbol">{{ strtoupper(mb_substr($service->type, 0, 2)) }}</span>
                        <span class="portal-compact-copy"><strong>{{ $service->name }}</strong><small>{{ $service->product?->name ?? $service->type }} · {{ $service->next_due_at?->format('Y-m-d') ?? '无固定到期日' }}</small></span>
                        <span class="status status-{{ strtolower($service->status) }}">{{ ['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'][$service->status] ?? $service->status }}</span>
                    </a>
                @empty
                    <div class="empty-state compact"><p>还没有服务，先去选择产品</p></div>
                @endforelse
            </div>
        </article>
    </section>
@endsection
