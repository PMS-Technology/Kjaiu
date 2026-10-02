{{-- 升降级商品 step one: pick the target product and cycle. --}}
<div id="modalUpgradeStepOne">
    <h3 class="mb-1 text-base font-semibold text-slate-900">升级产品</h3>
    <p class="mb-4 text-xs text-slate-500">选择目标产品后系统会计算需补的差价。</p>

    @if (empty($products))
        <p class="text-sm text-slate-500">暂无可升级的产品。</p>
    @else
        <form method="post" action="/servicedetail?id={{ $host->id }}&action=upgrade" class="space-y-4"
              data-upgrade-form data-host="{{ $host->id }}">
            @csrf
            <input type="hidden" name="id" value="{{ $host->id }}">

            <div>
                <label class="form-label" for="upgradeProductId">目标产品</label>
                <select name="upgrade_product_id" id="upgradeProductId" class="form-select">
                    @foreach ($products as $product)
                        <option value="{{ $product['id'] }}">{{ $product['name'] }}</option>
                    @endforeach
                </select>
            </div>

            @php $cycles = $products[0]['cycle'] ?? []; @endphp

            @if (! empty($cycles))
                <div>
                    <label class="form-label">计费周期</label>
                    <div class="space-y-2">
                        @foreach ($cycles as $cycle)
                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-2.5 hover:border-brand-300">
                                <span class="flex items-center gap-3">
                                    <input type="radio" name="billingcycle" value="{{ $cycle['billingcycle'] }}" class="form-radio"
                                           @checked($cycle['billingcycle'] === $host->billingcycle)>
                                    <span class="text-sm text-slate-800">{{ $cycle['billingcycle_zh'] }}</span>
                                </span>
                                <span class="text-sm text-slate-500">
                                    {{ $currency['prefix'] }}{{ $cycle['amount'] }}{{ $currency['suffix'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
                <button type="submit" class="btn-primary btn-sm">下一步</button>
            </div>
        </form>
    @endif
</div>
