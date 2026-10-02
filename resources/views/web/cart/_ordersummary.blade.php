{{-- Order summary fragment for the configure-product page; the original fetches
     this from `POST ?action=ordersummary`. --}}
@php $T = $ConfigureTotal; @endphp

<div class="space-y-2 text-sm">
    <div class="flex justify-between">
        <span class="text-slate-500">{{ $T['product_name'] }}</span>
        <span class="text-slate-800">{{ $T['currency']['prefix'] }}{{ $T['product_price'] }}</span>
    </div>

    @foreach ($T['child'] as $child)
        <div class="flex justify-between">
            <span class="text-slate-500">
                {{ $child['option_name'] }}
                @if (! empty($child['sub_name'])) · {{ $child['sub_name'] }} @endif
                @if (($child['qty'] ?? 1) > 1) × {{ $child['qty'] }} @endif
            </span>
            <span class="text-slate-800">{{ $T['currency']['prefix'] }}{{ $child['suboption_price'] }}</span>
        </div>
    @endforeach

    @if ((float) $T['product_setup_fee'] > 0)
        <div class="flex justify-between">
            <span class="text-slate-500">初装费</span>
            <span class="text-slate-800">{{ $T['currency']['prefix'] }}{{ $T['product_setup_fee'] }}</span>
        </div>
    @endif

    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
        <span>合计</span>
        <span class="text-brand-600">{{ $T['currency']['prefix'] }}{{ $T['total'] }}</span>
    </div>
</div>
