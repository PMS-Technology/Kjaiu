@extends('web.layouts.client')

@section('content')
@php $A = $Addfunds['addfunds']; @endphp

<div class="mx-auto max-w-2xl">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">账户充值</h2>
            <span class="text-sm text-slate-500">
                当前余额
                <span class="font-semibold text-slate-800">{{ $A['currency']['prefix'] }}{{ $A['credit'] }}{{ $A['currency']['suffix'] }}</span>
            </span>
        </div>

        @unless ($A['enabled'])
            <div class="card-body">
                <div class="alert-error mb-0">充值功能未开启，请联系管理员。</div>
            </div>
        @else
            <div class="card-body space-y-5">
                <div class="beforecheck hidden"></div>

                <div>
                    <label class="form-label" for="addfundsInp">充值金额</label>
                    <input type="number" id="addfundsInp" class="form-input" step="0.01"
                           min="{{ $A['addfunds_minimum'] }}" max="{{ $A['addfunds_maximum'] }}"
                           placeholder="{{ $A['addfunds_minimum'] }}">
                    <p class="form-hint">
                        单次限额 {{ $A['currency']['prefix'] }}{{ $A['addfunds_minimum'] }} -
                        {{ $A['currency']['prefix'] }}{{ $A['addfunds_maximum'] }}，
                        余额上限 {{ $A['currency']['prefix'] }}{{ $A['addfunds_maximum_balance'] }}
                    </p>
                </div>

                <div>
                    <label class="form-label">快捷金额</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ([50, 100, 200, 500, 1000, 2000] as $quick)
                            <button type="button" class="btn-secondary btn-sm quick-amount" data-amount="{{ $quick }}">
                                {{ $A['currency']['prefix'] }}{{ $quick }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="form-label">支付方式</label>
                    <div class="space-y-2">
                        @forelse ($A['gateways'] as $gateway)
                            <label class="addfunds-payment flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300"
                                   data-payment="{{ $gateway['name'] }}">
                                <span class="flex items-center gap-2">
                                    <input type="radio" name="payment" value="{{ $gateway['name'] }}" class="form-radio"
                                           @checked($loop->first)>
                                    <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">暂无可用的支付方式，请联系管理员。</p>
                        @endforelse
                    </div>
                </div>

                <button type="button" class="btn-primary w-full pay-now-btn">立即充值</button>
            </div>
        @endunless
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h2 class="card-title">最近充值记录</h2>
            <a href="/transaction?action=recharge_record" class="btn-ghost btn-sm">全部记录</a>
        </div>
        <div class="px-5 py-4 text-sm text-slate-500">
            充值成功后可前往 <a href="/billing" class="text-brand-600 hover:underline">账单列表</a> 查看支付结果。
        </div>
    </div>
</div>

{{-- Recharge modal, populated from /recharge_page. --}}
<div id="pay" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900">账户充值</h3>
            <button type="button" data-modal-close class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>
        <div class="modal-body max-h-[60vh] overflow-y-auto p-5">
            <div class="py-8 text-center text-sm text-slate-400">正在加载…</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const amountInput = document.getElementById('addfundsInp');
        const max = {{ (float) $A['addfunds_maximum'] }};
        const min = {{ (float) $A['addfunds_minimum'] }};

        document.querySelectorAll('.quick-amount').forEach((button) => {
            button.addEventListener('click', () => {
                amountInput.value = button.dataset.amount;
            });
        });

        // Keep the typed amount inside the configured window.
        amountInput?.addEventListener('change', () => {
            let value = Number(amountInput.value || 0);

            if (value < min) value = min;
            if (value > max) value = max;

            amountInput.value = value.toFixed(2);
        });

        document.querySelector('.pay-now-btn')?.addEventListener('click', async () => {
            const amount = Number(amountInput.value || 0);
            const gateway = document.querySelector('input[name="payment"]:checked');

            if (amount <= 0) {
                window.Kj.toastError('请输入充值金额');
                return;
            }

            if (!gateway) {
                window.Kj.toastError('请选择支付方式');
                return;
            }

            try {
                // The original pre-checks the amount and swaps in an inline
                // alert when the server rejects it.
                await window.Kj.post('/recharge', { beforeCheck: 1, amount: amount, payment: gateway.value });

                const data = await window.Kj.post('/recharge', { amount: amount, payment: gateway.value });
                const html = data.pay_html || {};

                if (html.type === 'jump' && html.data) {
                    window.open(html.data, '_blank');
                }

                window.location.href = `/viewbilling?id=${data.invoiceid}&wakeup=1`;
            } catch (error) {
                const box = document.querySelector('.beforecheck');

                if (box) {
                    box.classList.remove('hidden');
                    box.className = 'beforecheck alert-error';
                    box.textContent = error.message;
                }

                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
@endpush
