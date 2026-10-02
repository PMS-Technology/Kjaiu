{{-- Payment panel body, injected into the pay modal by /pay?action=billing. --}}
@php
    $currency = $Pay['currency'];
    $pending = (float) $Pay['total'];
@endphp

<div class="space-y-4">
    <div class="rounded-lg bg-slate-50 px-4 py-3">
        <div class="flex items-center justify-between text-sm">
            <span class="text-slate-500">账单编号</span>
            <span class="font-mono text-slate-800">#{{ $invoice->invoiceNumber() }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between">
            <span class="text-sm text-slate-500">待支付金额</span>
            <span class="text-lg font-semibold text-brand-600">
                {{ $currency['prefix'] }}{{ number_format($pending, 2) }}{{ $currency['suffix'] }}
            </span>
        </div>
    </div>

    {{-- Balance --}}
    @if ($Pay['use_credit'])
        <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
            <span class="flex items-center gap-2">
                <input type="radio" name="pay_method" value="credit" class="form-radio" @checked($use_credit)>
                <span class="text-sm text-slate-800">余额支付</span>
            </span>
            <span class="text-xs {{ $Pay['credit_enough'] ? 'text-slate-500' : 'text-rose-500' }}">
                余额 {{ $currency['prefix'] }}{{ $Pay['credit'] }}
            </span>
        </label>
    @endif

    {{-- Credit limit --}}
    @if ($Pay['use_credit_limit'])
        <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
            <span class="flex items-center gap-2">
                <input type="radio" name="pay_method" value="credit_limit" class="form-radio" @checked($use_credit_limit)>
                <span class="text-sm text-slate-800">信用额支付</span>
            </span>
            <span class="text-xs text-slate-500">
                可用 {{ $currency['prefix'] }}{{ $Pay['credit_limit_balance'] }}
            </span>
        </label>
    @endif

    {{-- Gateways --}}
    @foreach ($Pay['gateway_list'] as $gateway)
        <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
            <span class="flex items-center gap-2">
                <input type="radio" name="pay_method" value="gateway" class="form-radio"
                       data-gateway="{{ $gateway['name'] }}"
                       @checked($selected_gateway === $gateway['name'])>
                <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
            </span>
        </label>
    @endforeach

    @if (empty($Pay['gateway_list']) && ! $Pay['use_credit'] && ! $Pay['use_credit_limit'])
        <p class="text-sm text-slate-500">暂无可用的支付方式，请先充值或联系管理员。</p>
    @endif

    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
        <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
        <button type="button" id="payConfirm" class="btn-primary btn-sm">确认支付</button>
    </div>
</div>
