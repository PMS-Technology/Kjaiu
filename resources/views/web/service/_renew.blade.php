{{-- 续费 modal body: cycle radios priced for this service. --}}
<form method="post" action="/servicedetail?id={{ $host->id }}&action=renew" class="space-y-4">
    @csrf
    <input type="hidden" name="id" value="{{ $host->id }}">

    <div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
        {{ $host->product?->name }} · 当前周期 {{ \App\Support\StatusMap::cycle((string) $host->billingcycle) }}
        @if ($host->nextduedate)
            · 到期 {{ date('Y-m-d', (int) $host->nextduedate) }}
        @endif
    </div>

    @if (empty($Renew['cycle']))
        <p class="text-sm text-slate-500">该产品暂无可续费的计费周期。</p>
    @else
        <div class="space-y-2">
            @foreach ($Renew['cycle'] as $cycle)
                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
                    <span class="flex items-center gap-3">
                        <input type="radio" name="billingcycles" value="{{ $cycle['billingcycle'] }}" class="form-radio"
                               @checked($loop->first)>
                        <span class="text-sm font-medium text-slate-800">{{ $cycle['billingcycle_zh'] }}</span>
                    </span>
                    <span class="text-sm font-semibold text-brand-600">
                        {{ $Renew['currency']['prefix'] }}{{ $cycle['amount'] }}{{ $Renew['currency']['suffix'] }}
                    </span>
                </label>
            @endforeach
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="submit" class="btn-primary btn-sm">生成续费账单</button>
        </div>
    @endif
</form>
