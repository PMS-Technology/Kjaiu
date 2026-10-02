{{-- 升降级配置选项 step one. --}}
<div id="modalUpgradeConfigStepOne">
    <h3 class="mb-1 text-base font-semibold text-slate-900">升降级配置选项</h3>
    <p class="mb-4 text-xs text-slate-500">调整后系统会按剩余周期计算差额。</p>

    @if (empty($options))
        <p class="text-sm text-slate-500">该产品没有可调整的配置选项。</p>
    @else
        <form method="post" action="/servicedetail?id={{ $host->id }}&action=upgrade_config" class="space-y-4">
            @csrf
            <input type="hidden" name="id" value="{{ $host->id }}">

            @foreach ($options as $option)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="mb-2 text-sm font-medium text-slate-800">{{ $option['option_name'] }}</div>

                    @if ($option['option_type'] === 4)
                        <div class="flex items-center gap-2">
                            <input type="number" name="configoption[{{ $option['configid'] }}][value]"
                                   value="{{ $option['optionid'] }}"
                                   min="{{ $option['qty_minimum'] ?: 1 }}" max="{{ $option['qty_maximum'] ?: 999 }}"
                                   step="{{ $option['qty_stage'] ?: 1 }}" class="form-input w-32">
                            <span class="text-sm text-slate-500">{{ $option['unit'] }}</span>
                        </div>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($option['sub_options'] as $sub)
                                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                    <span class="flex items-center gap-2">
                                        <input type="radio" name="configoption[{{ $option['configid'] }}]"
                                               value="{{ $sub['id'] }}" class="form-radio"
                                               @checked($sub['id'] === $option['optionid'])>
                                        <span class="text-sm text-slate-800">{{ $sub['option_name'] }}</span>
                                    </span>
                                    <span class="text-xs text-slate-500">
                                        {{ $currency['prefix'] }}{{ $sub['price'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
                <button type="submit" class="btn-primary btn-sm">下一步</button>
            </div>
        </form>
    @endif
</div>
