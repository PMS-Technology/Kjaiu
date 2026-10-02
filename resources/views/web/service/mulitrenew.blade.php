@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">批量续费</h2>
        <span class="text-xs text-slate-500">共 {{ count($Renew['hosts']) }} 个服务</span>
    </div>

    <form method="post" action="/mulitrenew?action=batchrenew" id="batchRenewForm">
        @csrf

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr>
                    <th>产品</th>
                    <th>到期时间</th>
                    <th>续费周期</th>
                    <th>续费后到期</th>
                    <th>金额</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($Renew['hosts'] as $index => $item)
                    <tr>
                        <td>
                            <input type="hidden" name="host_ids[{{ $index }}]" value="{{ $item['host']->id }}">
                            <div class="font-medium text-slate-800">{{ $item['host']->product?->name }}</div>
                            <div class="text-xs text-slate-400">{{ $item['host']->domain ?: '#' . $item['host']->id }}</div>
                        </td>
                        <td>{{ $item['nextduedate_renew'] ? date('Y-m-d H:i', $item['nextduedate_renew']) : '—' }}</td>
                        <td>
                            @if (empty($item['allow_billingcycle']))
                                <span class="text-xs text-rose-600">无可续费周期</span>
                            @else
                                <select name="cycles[{{ $item['host']->id }}]" class="form-select w-40 py-1.5 text-sm batch-cycle"
                                        data-host="{{ $item['host']->id }}" data-amount="{{ $item['allow_billingcycle'][0]['amount'] }}">
                                    @foreach ($item['allow_billingcycle'] as $cycle)
                                        <option value="{{ $cycle['billingcycle'] }}" data-amount="{{ $cycle['amount'] }}">
                                            {{ $cycle['billingcycle_zh'] }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td data-next-due="{{ $item['host']->id }}">
                            {{ $item['nextduedate_renew'] ? date('Y-m-d H:i', $item['nextduedate_renew']) : '—' }}
                        </td>
                        <td class="batch-amount" data-host="{{ $item['host']->id }}">
                            {{ $Renew['currency']['prefix'] }}
                            {{ $item['allow_billingcycle'][0]['amount'] ?? '0.00' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr class="bg-slate-50 font-semibold">
                    <td colspan="4" class="text-right">合计</td>
                    <td>{{ $Renew['currency']['prefix'] }}<span id="batchTotal">{{ $Total }}</span>{{ $Renew['currency']['suffix'] }}</td>
                </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4">
            <a href="/service" class="btn-secondary btn-sm">返回服务列表</a>
            <button type="submit" class="btn-primary btn-sm">生成续费账单</button>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('batchRenewForm');
        const totalBox = document.getElementById('batchTotal');

        function recomputeTotal() {
            let total = 0;

            document.querySelectorAll('.batch-cycle').forEach((select) => {
                const amount = Number(select.selectedOptions[0]?.dataset.amount || 0);
                const cell = document.querySelector(`.batch-amount[data-host="${select.dataset.host}"]`);
                total += amount;

                if (cell) {
                    cell.textContent = @json($Renew['currency']['prefix']) + amount.toFixed(2);
                }
            });

            totalBox.textContent = total.toFixed(2);
        }

        // Ask the server to recalculate due dates whenever a cycle changes.
        document.querySelectorAll('.batch-cycle').forEach((select) => {
            select.addEventListener('change', async () => {
                recomputeTotal();

                try {
                    const data = await window.Kj.post('/host/batchrenewpage', new FormData(form));

                    (data.hosts || []).forEach((host) => {
                        const cell = document.querySelector(`[data-next-due="${host.hostid}"]`);

                        if (cell && host.nextduedate_renew) {
                            const date = new Date(host.nextduedate_renew * 1000);
                            const pad = (n) => String(n).padStart(2, '0');
                            cell.textContent = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
                        }
                    });
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });
        });

        recomputeTotal();
    })();
</script>
@endpush
