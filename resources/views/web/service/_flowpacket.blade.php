{{-- 流量包 modal body. --}}
<div>
    <h3 class="mb-4 text-base font-semibold text-slate-900">购买流量包</h3>

    @if (empty($Flowpacket))
        <p class="text-sm text-slate-500">当前没有可购买的流量包。</p>
    @else
        <div class="space-y-2">
            @foreach ($Flowpacket as $packet)
                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 hover:border-brand-300">
                    <span class="flex items-center gap-3">
                        <input type="radio" name="fid" value="{{ $packet['id'] }}" class="form-radio" @checked($loop->first)>
                        <span>
                            <span class="block text-sm font-medium text-slate-800">{{ $packet['name'] }}</span>
                            <span class="block text-xs text-slate-400">容量 {{ $packet['capacity'] }}</span>
                        </span>
                    </span>
                    <span class="text-sm font-semibold text-brand-600">
                        {{ $currency['prefix'] }}{{ number_format($packet['price'], 2) }}{{ $currency['suffix'] }}
                    </span>
                </label>
            @endforeach
        </div>

        <div class="mt-4 flex justify-end gap-2">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="button" id="flowPacketBuy" data-host="{{ $host->id }}" class="btn-primary btn-sm">立即购买</button>
        </div>
    @endif
</div>

<script>
    document.getElementById('flowPacketBuy')?.addEventListener('click', async (event) => {
        const selected = document.querySelector('input[name="fid"]:checked');

        if (!selected) {
            window.Kj.toastError('请选择流量包');
            return;
        }

        try {
            const data = await window.Kj.post('/dcim/buy_flow_packet', {
                id: event.currentTarget.dataset.host,
                fid: selected.value,
            });

            if (data && data.invoiceid) {
                window.location.href = `/viewbilling?id=${data.invoiceid}&wakeup=1`;
            }
        } catch (error) {
            window.Kj.toastError(error.message);
        }
    });
</script>
