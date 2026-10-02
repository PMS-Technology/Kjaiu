{{-- Recharge panel body, injected by /recharge_page and /pay?action=recharge. --}}
@php $A = $Addfunds; @endphp

<div class="space-y-4">
    <div class="rounded-lg bg-slate-50 px-4 py-3">
        <div class="flex items-center justify-between text-sm">
            <span class="text-slate-500">当前余额</span>
            <span class="font-semibold text-slate-800">
                {{ $A['currency']['prefix'] }}{{ $A['credit'] }}{{ $A['currency']['suffix'] }}
            </span>
        </div>
    </div>

    @unless ($A['enabled'])
        <div class="alert-error mb-0">充值功能未开启。</div>
    @else
        <div class="beforecheck hidden"></div>

        <div>
            <label class="form-label" for="rechargeAmount">充值金额</label>
            <input type="number" id="rechargeAmount" class="form-input" min="{{ $A['addfunds_minimum'] }}"
                   max="{{ $A['addfunds_maximum'] }}" step="0.01" placeholder="{{ $A['addfunds_minimum'] }}">
            <p class="form-hint">
                单次限额 {{ $A['currency']['prefix'] }}{{ $A['addfunds_minimum'] }} -
                {{ $A['currency']['prefix'] }}{{ $A['addfunds_maximum'] }}，
                余额上限 {{ $A['currency']['prefix'] }}{{ $A['addfunds_maximum_balance'] }}
            </p>
        </div>

        <div>
            <label class="form-label">支付方式</label>
            <div class="space-y-2">
                @forelse ($A['gateways'] as $gateway)
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-4 py-2.5 hover:border-brand-300">
                        <input type="radio" name="recharge_gateway" value="{{ $gateway['name'] }}" class="form-radio"
                               @checked($loop->first)>
                        <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
                    </label>
                @empty
                    <p class="text-sm text-slate-500">暂无可用的支付方式。</p>
                @endforelse
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="button" id="rechargeSubmit" class="btn-primary btn-sm pay-now-btn">立即充值</button>
        </div>
    @endunless
</div>

<script>
    document.getElementById('rechargeSubmit')?.addEventListener('click', async () => {
        const amount = Number(document.getElementById('rechargeAmount').value || 0);
        const gateway = document.querySelector('input[name="recharge_gateway"]:checked');

        if (amount <= 0) {
            window.Kj.toastError('请输入充值金额');
            return;
        }

        if (!gateway) {
            window.Kj.toastError('请选择支付方式');
            return;
        }

        try {
            const data = await window.Kj.post('/recharge', {
                beforeCheck: 1,
                amount: amount,
                payment: gateway.value,
            });

            // The server validates the amount before creating the invoice.
            if (data.amount) {
                const real = await window.Kj.post('/recharge', { amount: amount, payment: gateway.value });
                const html = real.pay_html || {};

                if (html.type === 'jump' && html.data) {
                    window.open(html.data, '_blank');
                }

                window.location.href = `/viewbilling?id=${real.invoiceid}&wakeup=1`;
            }
        } catch (error) {
            window.Kj.toastError(error.message);
        }
    });
</script>
