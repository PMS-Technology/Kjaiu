{{-- Credit-limit repayment panel. --}}
<div class="space-y-4">
    <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm">
        <div class="flex items-center justify-between">
            <span class="text-slate-500">信用额已用</span>
            <span class="font-semibold text-slate-800">
                {{ $currency['prefix'] }}{{ $credit_limit_balance }}{{ $currency['suffix'] }}
            </span>
        </div>
    </div>

    @if ($invoice)
        <div class="text-sm text-slate-600">
            账单 #{{ $invoice->invoiceNumber() }} 待还金额
            {{ $currency['prefix'] }}{{ number_format((float) $invoice->total, 2) }}{{ $currency['suffix'] }}。
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="button" id="creditLimitPrepay" data-invoice="{{ $invoice->id }}" class="btn-primary btn-sm">提前还款</button>
        </div>
    @else
        <p class="text-sm text-slate-500">没有需要还款的信用额账单。</p>
    @endif
</div>

<script>
    document.getElementById('creditLimitPrepay')?.addEventListener('click', async (event) => {
        try {
            const data = await window.Kj.post('/credit_limit/prepayment', { invoiceid: event.currentTarget.dataset.invoice });

            if (data.invoiceid) {
                window.location.href = `/viewbilling?id=${data.invoiceid}&wakeup=1`;
            }
        } catch (error) {
            window.Kj.toastError(error.message);
        }
    });
</script>
