@extends('layouts.portal')

@section('title', $service->name)
@section('eyebrow', 'KJAIU / SERVICE #'.$service->id)
@section('description', $service->product?->name ?? $service->type)
@section('actions')
    <a class="button button-secondary" href="{{ route('portal.services.index') }}">← 返回服务</a>
@endsection

@section('content')
    <section class="service-detail-hero">
        <div class="service-detail-title"><span class="service-symbol">{{ strtoupper(mb_substr($service->type, 0, 2)) }}</span><div><p>{{ $service->type }} / #{{ $service->id }}</p><h2>{{ $service->name }}</h2></div></div>
        <span class="status status-{{ strtolower($service->status) }}">{{ ['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'][$service->status] ?? $service->status }}</span>
    </section>

    <section class="detail-summary-grid service-summary-grid">
        <article><span>主 IP</span><strong>{{ $service->dedicated_ip ?: '尚未分配' }}</strong></article>
        <article><span>域名</span><strong>{{ $service->domain ?: '未绑定' }}</strong></article>
        <article><span>付款周期</span><strong>{{ $service->billing_cycle }}</strong></article>
        <article><span>续费金额</span><strong>¥{{ number_format((float) $service->renew_amount, 2) }}</strong></article>
        <article><span>开通时间</span><strong>{{ $service->activated_at?->format('Y-m-d H:i') ?? '等待交付' }}</strong></article>
        <article><span>下次到期</span><strong>{{ $service->next_due_at?->format('Y-m-d H:i') ?? '无固定到期日' }}</strong></article>
    </section>

    <section class="service-management-grid">
        <article class="panel">
            <header class="panel-head"><div><p class="panel-kicker">SERVICE INFORMATION</p><h2>服务信息</h2></div></header>
            <dl class="portal-definition-list">
                <div><dt>产品</dt><dd>{{ $service->product?->name ?? '原产品已归档' }}</dd></div>
                <div><dt>首次付款</dt><dd>¥{{ number_format((float) $service->first_payment_amount, 2) }}</dd></div>
                <div><dt>注册时间</dt><dd>{{ $service->registered_at?->format('Y-m-d H:i') ?? $service->created_at->format('Y-m-d H:i') }}</dd></div>
                <div><dt>附加 IP</dt><dd>{{ collect($service->assigned_ips ?? [])->join('、') ?: '无' }}</dd></div>
            </dl>
            @if ($service->notes)<div class="portal-public-note"><span>服务备注</span><p>{{ $service->notes }}</p></div>@endif
        </article>

        <div class="portal-management-stack">
            <article class="panel">
                <header class="panel-head"><div><p class="panel-kicker">AUTO RENEW</p><h2>余额自动续费</h2></div></header>
                @if ($supplierManaged)
                    <div class="empty-state compact"><p>当前版本暂不支持上游供应商服务自动续费</p></div>
                    @if ($service->auto_renew)
                        <form class="portal-form" method="POST" action="{{ route('portal.services.auto-renew', $service) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="auto_renew" value="0">
                            <button class="button button-secondary button-wide" type="submit"><span>关闭原有自动续费设置</span><span>→</span></button>
                        </form>
                    @endif
                @else
                    <form class="portal-form" method="POST" action="{{ route('portal.services.auto-renew', $service) }}">
                        @csrf @method('PATCH')
                        <input type="hidden" name="auto_renew" value="0">
                        <label class="switch-field portal-switch"><input type="checkbox" name="auto_renew" value="1" @checked($service->auto_renew)><span></span><b>到期时尝试使用账户余额续费</b></label>
                        <p class="gateway-disclaimer">仅运行中的周期服务可以启用。余额不足时不会完成付款。</p>
                        <button class="button button-secondary button-wide" type="submit"><span>保存自动续费设置</span><span>→</span></button>
                    </form>
                @endif
            </article>

            <article class="panel">
                <header class="panel-head"><div><p class="panel-kicker">RENEW SERVICE</p><h2>创建续费账单</h2></div></header>
                @if ($renewalCycles !== [])
                    <form class="portal-form" method="POST" action="{{ route('portal.services.renewal', $service) }}" data-confirm="确认创建这项服务的续费账单吗？">
                        @csrf
                        <label class="field"><span>续费周期</span><select name="billing_cycle" required>@foreach($renewalCycles as $cycle => $price)<option value="{{ $cycle }}">{{ $cycle }} · ¥{{ number_format((float) $price, 2) }}</option>@endforeach</select></label>
                        <button class="button button-primary button-wide" type="submit"><span>创建续费账单</span><span>↗</span></button>
                    </form>
                @elseif ($supplierManaged)
                    <div class="empty-state compact"><p>当前版本暂不支持上游供应商服务续费</p></div>
                @else
                    <div class="empty-state compact"><p>当前服务没有可用续费周期</p></div>
                @endif
            </article>
        </div>
    </section>
@endsection
