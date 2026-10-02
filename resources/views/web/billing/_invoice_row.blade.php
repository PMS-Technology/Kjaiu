{{-- One invoice rendered for a modal. --}}
<div class="space-y-3">
    <div class="flex items-center justify-between">
        <span class="font-mono text-sm text-slate-700">#{{ $invoice->invoiceNumber() }}</span>
        <span class="badge-{{ \App\Support\StatusMap::invoiceStatusColor((string) $invoice->status) }}">
            {{ \App\Support\StatusMap::invoiceStatus((string) $invoice->status, (int) $invoice->use_credit_limit === 1) }}
        </span>
    </div>

    <dl class="space-y-1.5 text-sm">
        <div class="flex justify-between">
            <dt class="text-slate-500">应付金额</dt>
            <dd class="font-semibold text-brand-600">{{ $currency['prefix'] }}{{ $total }}{{ $currency['suffix'] }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">生成时间</dt>
            <dd class="text-slate-800">{{ $created ? date('Y-m-d H:i', $created) : '—' }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">逾期时间</dt>
            <dd class="text-slate-800">{{ $due ? date('Y-m-d H:i', $due) : '—' }}</dd>
        </div>
    </dl>

    <div class="flex justify-end gap-2 border-t border-slate-100 pt-3">
        <a href="/viewbilling?id={{ $invoice->id }}" class="btn-secondary btn-sm">查看详情</a>
        @if ((string) $invoice->status === 'Unpaid')
            <a href="/viewbilling?id={{ $invoice->id }}&wakeup=1" class="btn-primary btn-sm">立即支付</a>
        @endif
    </div>
</div>
