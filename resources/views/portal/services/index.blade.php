@extends('layouts.portal')

@section('title', '我的服务')
@section('eyebrow', 'KJAIU / SERVICE INVENTORY')
@section('description', '查看服务状态、续费金额、到期计划和自动续费设置。')

@section('content')
    <section class="panel resource-panel">
        <header class="resource-toolbar resource-toolbar-wrap">
            <form class="portal-filter" method="GET">
                <label class="field"><span>服务状态</span><select name="status"><option value="">全部状态</option>@foreach(['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Failed'=>'交付失败','Deleted'=>'已删除'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label>
                <button class="button button-primary" type="submit">筛选</button>
            </form>
            <span class="result-count">{{ $services->total() }} 项服务</span>
        </header>
        <div class="service-grid portal-service-grid">
            @forelse ($services as $service)
                <a class="service-card portal-service-card" href="{{ route('portal.services.show', $service) }}">
                    <header>
                        <div class="service-symbol">{{ strtoupper(mb_substr($service->type, 0, 2)) }}</div>
                        <div class="service-title"><span>{{ $service->product?->name ?? $service->type }}</span><h2>{{ $service->name }}</h2><p>#{{ $service->id }} · {{ $service->domain ?: '未绑定域名' }}</p></div>
                        <span class="status status-{{ strtolower($service->status) }}">{{ ['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'][$service->status] ?? $service->status }}</span>
                    </header>
                    <div class="service-facts">
                        <div><span>主 IP</span><strong>{{ $service->dedicated_ip ?: '尚未分配' }}</strong></div>
                        <div><span>付款周期</span><strong>{{ $service->billing_cycle }}</strong></div>
                        <div><span>续费金额</span><strong>¥{{ number_format((float) $service->renew_amount, 2) }}</strong></div>
                        <div><span>下次到期</span><strong>{{ $service->next_due_at?->format('Y-m-d') ?? '一次性' }}</strong></div>
                    </div>
                    <footer><span><span class="auto-renew-dot {{ $service->auto_renew && ! $service->supplier_managed ? 'is-on' : '' }}"></span>{{ $service->supplier_managed ? '当前服务不支持自动续费' : ($service->auto_renew ? '余额自动续费已开启' : '未开启自动续费') }}</span><span class="row-action">查看详情 →</span></footer>
                </a>
            @empty
                <div class="empty-state empty-state-wide"><span>SRV</span><h3>没有匹配的服务</h3><p>订单付款并完成交付后，服务会出现在这里。</p></div>
            @endforelse
        </div>
        @if ($services->hasPages())<div class="pagination-wrap">{{ $services->links() }}</div>@endif
    </section>
@endsection
