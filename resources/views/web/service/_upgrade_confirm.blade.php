{{-- 升降级商品 step two: confirm the prorated difference. --}}
<div id="modalUpgradeStepTwo">
    <h3 class="mb-4 text-base font-semibold text-slate-900">确认升级</h3>

    <dl class="mb-4 space-y-2 rounded-lg bg-slate-50 p-4 text-sm">
        <div class="flex justify-between">
            <dt class="text-slate-500">当前产品</dt>
            <dd class="text-slate-800">{{ $host->product?->name }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">升级到</dt>
            <dd class="text-slate-800">{{ $product?->name }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">计费周期</dt>
            <dd class="text-slate-800">{{ \App\Support\StatusMap::cycle($cycle) }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">当前费用</dt>
            <dd class="text-slate-800">{{ $currency['prefix'] }}{{ $current_amount }}{{ $currency['suffix'] }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-slate-500">目标费用</dt>
            <dd class="text-slate-800">{{ $currency['prefix'] }}{{ $target_amount }}{{ $currency['suffix'] }}</dd>
        </div>
        @if (! empty($promo))
            <div class="flex justify-between">
                <dt class="text-slate-500">优惠码</dt>
                <dd class="text-emerald-600">{{ $promo['code'] }}</dd>
            </div>
        @endif
        <div class="flex justify-between border-t border-slate-200 pt-2 font-semibold">
            <dt>需补差价</dt>
            <dd class="text-brand-600">{{ $currency['prefix'] }}{{ $difference }}{{ $currency['suffix'] }}</dd>
        </div>
    </dl>

    <div class="mb-4 flex gap-2">
        <input type="text" id="upgradePromoCode" class="form-input" placeholder="输入优惠码">
        <button type="button" id="upgradePromoApply" data-host="{{ $host->id }}" class="btn-secondary btn-sm whitespace-nowrap">使用</button>
        @if (! empty($promo))
            <button type="button" id="upgradePromoRemove" data-host="{{ $host->id }}" class="btn-ghost btn-sm whitespace-nowrap">移除</button>
        @endif
    </div>

    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
        <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
        <button type="button" id="upgradeSettle" data-host="{{ $host->id }}" class="btn-primary btn-sm">确认升级并生成账单</button>
    </div>
</div>

<script>
    (function () {
        const hostId = {{ $host->id }};

        document.getElementById('upgradePromoApply')?.addEventListener('click', async () => {
            const code = document.getElementById('upgradePromoCode').value.trim();

            if (code === '') {
                window.Kj.toastError('请输入优惠码');
                return;
            }

            try {
                // The original spells the parameter `pormo_code`; the endpoint
                // accepts both.
                await window.Kj.post('/upgrade/add_promo_code_product', { hid: hostId, pormo_code: code, upgrade_type: 'product' });
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('upgradePromoRemove')?.addEventListener('click', async () => {
            try {
                await window.Kj.post('/upgrade/remove_promo_code_product', { hid: hostId });
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('upgradeSettle')?.addEventListener('click', async () => {
            try {
                const data = await window.Kj.post('/upgrade/checkout_upgrade_product', { hid: hostId });

                if (data && data.invoiceid) {
                    window.location.href = `/viewbilling?id=${data.invoiceid}&wakeup=1`;
                }
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
