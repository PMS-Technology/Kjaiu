@extends('layouts.admin')

@section('title', '服务交付')
@section('eyebrow', 'KJAIU / SERVICE OPERATIONS')
@section('description', '查看客户产品、到期计划与交付状态。')

@section('content')
    @php
        $pageItems = $services->getCollection();
        $activeCount = $pageItems->where('status', 'Active')->count();
        $pendingCount = $pageItems->where('status', 'Pending')->count();
        $expiringCount = $pageItems->filter(fn($service) => $service->next_due_at && $service->next_due_at->between(now(), now()->addDays(7)))->count();
    @endphp
    <section class="summary-strip">
        <div><span>服务总数</span><strong>{{ number_format($services->total()) }}</strong></div>
        <i></i>
        <div><span>本页运行中</span><strong>{{ $activeCount }}</strong></div>
        <i></i>
        <div><span>待交付 / 近七日到期</span><strong>{{ $pendingCount }} / {{ $expiringCount }}</strong></div>
    </section>

    <section class="panel resource-panel">
        <header class="resource-toolbar resource-toolbar-wrap">
            <form class="search-form search-form-grow" method="GET">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 19.6-5.2-5.2a7 7 0 1 0-1.4 1.4l5.2 5.2 1.4-1.4ZM5 10a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z"/></svg>
                <input name="q" value="{{ $keyword }}" placeholder="搜索服务名、域名、IP 或客户邮箱">
                <select name="status" aria-label="服务状态"><option value="">全部状态</option>@foreach(['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select>
                <button type="submit">筛选</button>
            </form>
            <span class="result-count">{{ $services->total() }} 项服务</span>
        </header>

        <div class="service-grid">
            @forelse($services as $service)
                @php
                    $expiring = $service->next_due_at && $service->next_due_at->between(now(), now()->addDays(7));
                    $supplierManaged = $service->has_supplier_service_link || $service->has_nonterminal_supplier_operation;
                    $serviceStatusLabel = ['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'][$service->status] ?? $service->status;
                @endphp
                <article class="service-card">
                    <header>
                        <div class="service-symbol">{{ strtoupper(mb_substr($service->type, 0, 2)) }}</div>
                        <div class="service-title"><span>{{ $service->product?->name ?? $service->type }}</span><h2>{{ $service->name }}</h2><p>#{{ $service->id }} · {{ $service->user?->name }}</p></div>
                        <span class="status status-{{ strtolower($service->status) }}">{{ $serviceStatusLabel }}</span>
                    </header>
                    <div class="service-facts">
                        <div><span>主 IP</span><strong>{{ $service->dedicated_ip ?: '尚未分配' }}</strong></div>
                        <div><span>付款周期</span><strong>{{ $service->billing_cycle }}</strong></div>
                        <div><span>续费金额</span><strong>¥{{ number_format((float) $service->renew_amount, 2) }}</strong></div>
                        <div><span>下次到期</span><strong class="{{ $expiring ? 'danger-text' : '' }}">{{ $service->next_due_at?->format('Y-m-d') ?? '一次性' }}</strong></div>
                    </div>
                    <footer>
                        <div><span class="auto-renew-dot {{ $service->auto_renew ? 'is-on' : '' }}"></span>{{ $service->auto_renew ? '余额自动续费' : '未启用自动续费' }}</div>
                        <button class="row-action" type="button" data-dialog-open="service-{{ $service->id }}">管理服务 →</button>
                    </footer>
                </article>

                <dialog class="modal" id="service-{{ $service->id }}" @if($errors->any() && (int) old('_service_id') === $service->id) open @endif>
                    <form method="POST" action="{{ route('admin.services.update', $service) }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="_service_id" value="{{ $service->id }}">
                        <header class="modal-head"><div><p class="panel-kicker">SERVICE #{{ $service->id }}</p><h2>{{ $supplierManaged ? '更新服务信息' : '更新交付状态' }}</h2></div><button type="button" data-dialog-close aria-label="关闭">×</button></header>
                        <div class="modal-body">
                            <div class="service-modal-summary"><span class="service-symbol">{{ strtoupper(mb_substr($service->type, 0, 2)) }}</span><div><strong>{{ $service->name }}</strong><p>{{ $service->user?->name }} · {{ $service->user?->email }}</p></div></div>
                            <div class="form-grid">
                                @if($supplierManaged)
                                    <label class="field field-full"><span>服务状态 <small>只读</small></span><input value="{{ $serviceStatusLabel }}" readonly aria-readonly="true"><small class="field-hint">该服务由供应商上游状态与对账流程控制，通用服务管理不能修改状态。@if(Route::has('admin.supplier-operations.index')) <a href="{{ route('admin.supplier-operations.index') }}">查看上游操作</a>@endif</small></label>
                                @else
                                    <label class="field"><span>服务状态</span><select name="status" required>@foreach(['Pending'=>'待交付','Active'=>'运行中','Suspended'=>'已暂停','Cancelled'=>'已取消','Deleted'=>'已删除','Failed'=>'交付失败'] as $value => $label)@if(in_array($value, $transitions[$service->status] ?? [$service->status], true))<option value="{{ $value }}" @selected(old('_service_id') == $service->id ? old('status') === $value : $service->status === $value)>{{ $label }}</option>@endif @endforeach</select></label>
                                @endif
                                <label class="field"><span>主 IP <small>选填</small></span><input name="dedicated_ip" value="{{ old('_service_id') == $service->id ? old('dedicated_ip') : $service->dedicated_ip }}" placeholder="192.0.2.1"></label>
                                <label class="field field-full"><span>内部运营备注</span><textarea name="internal_notes" rows="4" maxlength="5000" placeholder="交付信息、暂停原因或其他内部记录，不向客户展示">{{ old('_service_id') == $service->id ? old('internal_notes') : $service->internal_notes }}</textarea></label>
                            </div>
                        </div>
                        <footer class="modal-foot"><button class="button button-ghost" type="button" data-dialog-close>取消</button><button class="button button-primary" type="submit">{{ $supplierManaged ? '保存信息' : '保存状态' }}</button></footer>
                    </form>
                </dialog>
            @empty
                <div class="empty-state empty-state-wide"><span>SRV</span><h3>没有匹配的服务</h3><p>服务会在订单付款后自动生成，也可以调整筛选条件继续查找。</p></div>
            @endforelse
        </div>
        <div class="pagination-wrap">{{ $services->links() }}</div>
    </section>
@endsection
