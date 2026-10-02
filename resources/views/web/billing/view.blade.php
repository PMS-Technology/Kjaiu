@extends('web.layouts.client')

@section('content')
@php $detail = $ViewBilling['detail']; @endphp

<div class="grid gap-4 lg:grid-cols-3">
    {{-- ============ Invoice ============ --}}
    <div class="card lg:col-span-2" id="invoicePrintable">
        <div class="card-header">
            <div>
                <h2 class="card-title">账单 #{{ $detail['invoice_num'] }}</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    生成于 {{ $detail['create_time'] ? date('Y-m-d H:i', $detail['create_time']) : '—' }}
                </p>
            </div>
            <span class="badge-{{ $detail['status_color'] }}">{{ $detail['status_zh'] }}</span>
        </div>

        <div class="grid gap-4 border-b border-slate-100 px-5 py-4 sm:grid-cols-2">
            <div class="space-y-1.5 text-sm">
                <div class="flex gap-2"><span class="w-20 text-slate-400">公司名称</span><span class="text-slate-800">{{ $detail['companyname'] ?: '—' }}</span></div>
                <div class="flex gap-2"><span class="w-20 text-slate-400">联系人</span><span class="text-slate-800">{{ $detail['username'] ?: '—' }}</span></div>
                <div class="flex gap-2"><span class="w-20 text-slate-400">联系电话</span><span class="text-slate-800">{{ $detail['phonenumber'] ?: '—' }}</span></div>
            </div>
            <div class="space-y-1.5 text-sm">
                <div class="flex gap-2"><span class="w-20 text-slate-400">支付方式</span><span class="text-slate-800">{{ $detail['payment_zh'] ?: '—' }}</span></div>
                <div class="flex gap-2"><span class="w-20 text-slate-400">支付时间</span><span class="text-slate-800">{{ $detail['paid_time'] ? date('Y-m-d H:i', $detail['paid_time']) : '—' }}</span></div>
                <div class="flex gap-2"><span class="w-20 text-slate-400">逾期时间</span><span class="text-slate-800">{{ $detail['due_time'] ? date('Y-m-d H:i', $detail['due_time']) : '—' }}</span></div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr><th>类型</th><th>说明</th><th class="text-right">金额</th></tr>
                </thead>
                <tbody>
                @foreach ($ViewBilling['invoice_items'] as $item)
                    <tr>
                        <td class="whitespace-nowrap">{{ $item['type_zh'] }}</td>
                        <td>
                            @foreach (explode("\n", $item['description']) as $line)
                                <div>{{ $line }}</div>
                            @endforeach
                        </td>
                        <td class="text-right whitespace-nowrap">{{ $Currency['prefix'] }}{{ $item['amount'] }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="2" class="text-right text-slate-500">小计</td>
                    <td class="text-right">{{ $Currency['prefix'] }}{{ $detail['subtotal'] }}</td>
                </tr>
                @if ((float) $detail['tax'] > 0)
                    <tr>
                        <td colspan="2" class="text-right text-slate-500">税费</td>
                        <td class="text-right">{{ $Currency['prefix'] }}{{ $detail['tax'] }}</td>
                    </tr>
                @endif
                @if ((float) $detail['credit'] > 0)
                    <tr>
                        <td colspan="2" class="text-right text-slate-500">已抵扣</td>
                        <td class="text-right text-emerald-600">-{{ $Currency['prefix'] }}{{ $detail['credit'] }}</td>
                    </tr>
                @endif
                <tr class="bg-slate-50 font-semibold">
                    <td colspan="2" class="text-right">应付总额</td>
                    <td class="text-right text-brand-600">{{ $Currency['prefix'] }}{{ $detail['total'] }}</td>
                </tr>
                </tfoot>
            </table>
        </div>

        @if (! empty($ViewBilling['accounts']))
            <div class="border-t border-slate-100 px-5 py-4">
                <h3 class="mb-2 text-sm font-semibold text-slate-800">支付记录</h3>
                <table class="data-table">
                    <thead>
                    <tr><th>流水号</th><th>金额</th><th>支付方式</th><th>支付时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($ViewBilling['accounts'] as $account)
                        <tr>
                            <td class="font-mono text-xs">{{ $account['trans_id'] }}</td>
                            <td>{{ $Currency['prefix'] }}{{ $account['amount_in'] }}</td>
                            <td>{{ $account['gateway'] }}</td>
                            <td>{{ $account['pay_time'] ? date('Y-m-d H:i', $account['pay_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ============ Payment panel ============ --}}
    <aside class="space-y-4">
        <div class="card">
            <div class="card-header"><h2 class="card-title">支付</h2></div>
            <div class="card-body space-y-4">
                <div>
                    <div class="text-xs text-slate-400">待支付金额</div>
                    <div class="text-2xl font-semibold text-slate-900">
                        {{ $Pay['currency']['prefix'] }}{{ $Pay['total'] }}{{ $Pay['currency']['suffix'] }}
                    </div>
                </div>

                @if ($detail['status'] === 'Paid')
                    <div class="alert-success mb-0">该账单已支付。</div>
                @else
                    <div class="space-y-2">
                        @if ($Pay['use_credit'])
                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                <span class="flex items-center gap-2">
                                    <input type="radio" name="pay_choice" value="credit" class="form-radio" checked
                                           data-pay-credit>
                                    <span class="text-sm text-slate-800">余额支付</span>
                                </span>
                                <span class="text-xs {{ $Pay['credit_enough'] ? 'text-slate-500' : 'text-rose-500' }}">
                                    {{ $Pay['currency']['prefix'] }}{{ $Pay['credit'] }}
                                </span>
                            </label>
                        @endif

                        @if ($Pay['use_credit_limit'])
                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                <span class="flex items-center gap-2">
                                    <input type="radio" name="pay_choice" value="credit_limit" class="form-radio">
                                    <span class="text-sm text-slate-800">信用额支付</span>
                                </span>
                                <span class="text-xs text-slate-500">
                                    可用 {{ $Pay['currency']['prefix'] }}{{ $Pay['credit_limit_balance'] }}
                                </span>
                            </label>
                        @endif

                        @foreach ($Pay['gateway_list'] as $gateway)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                <input type="radio" name="pay_choice" value="gateway" class="form-radio"
                                       data-gateway="{{ $gateway['name'] }}">
                                <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
                            </label>
                        @endforeach
                    </div>

                    <button type="button" id="payamount" data-invoice="{{ $detail['id'] }}" class="btn-primary mt-4 w-full">
                        立即支付
                    </button>

                    @if ((int) $paymt['is_open_credit_limit'] === 1 && ! $Pay['use_credit_limit'])
                        <p class="mt-2 text-xs text-slate-400">信用额额度已用尽。</p>
                    @endif
                @endif
            </div>

            <div class="flex gap-2 border-t border-slate-100 px-5 py-3">
                <button type="button" id="printInvoice" class="btn-secondary btn-sm">打印账单</button>
                <button type="button" id="downloadInvoice" class="btn-secondary btn-sm">下载 PDF</button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">账单编号</h2></div>
            <div class="card-body">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm text-slate-700">{{ $detail['invoice_num'] }}</span>
                    <button type="button" data-copy="{{ $detail['invoice_num'] }}" class="text-xs text-brand-600 hover:underline">复制</button>
                </div>
            </div>
        </div>
    </aside>
</div>

{{-- ============ Payment modal ============ --}}
<div id="payModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900">订单支付</h3>
            <button type="button" data-modal-close class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>
        <div id="payBox" class="max-h-[60vh] overflow-y-auto p-5">
            <div class="py-8 text-center text-sm text-slate-400">正在加载…</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const invoiceId = {{ $detail['id'] }};
        const box = document.getElementById('payBox');

        // -------- Open the pay panel, optionally straight after checkout -----
        async function openPay() {
            const choice = document.querySelector('input[name="pay_choice"]:checked');
            const payload = { invoiceid: invoiceId };

            if (choice) {
                if (choice.value === 'credit') payload.use_credit = 1;
                if (choice.value === 'credit_limit') payload.use_credit_limit = 1;
                if (choice.value === 'gateway') payload.payment = choice.dataset.gateway;
            }

            box.innerHTML = '<div class="py-8 text-center text-sm text-slate-400">正在加载…</div>';
            window.Kj.openModal('payModal');

            try {
                const response = await fetch('/pay?action=billing', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: new URLSearchParams(payload),
                });

                box.innerHTML = await response.text();
                bindPayBox();
            } catch (error) {
                box.innerHTML = '<div class="py-8 text-center text-sm text-rose-600">加载失败</div>';
            }
        }

        function bindPayBox() {
            box.querySelector('#payConfirm')?.addEventListener('click', () => submitPay(false));
            box.querySelector('#payWithCreditLimit')?.addEventListener('click', () => submitPay(true));
        }

        async function submitPay(useCreditLimit) {
            const choice = document.querySelector('input[name="pay_choice"]:checked');
            const payload = { invoiceid: invoiceId, pay: 1 };

            if (!useCreditLimit && choice) {
                if (choice.value === 'credit') payload.use_credit = 1;
                if (choice.value === 'gateway') payload.payment = choice.dataset.gateway;
            }

            if (useCreditLimit) {
                payload.use_credit_limit = 1;
            }

            const body = new FormData();
            Object.entries(payload).forEach(([key, value]) => body.append(key, value));
            body.append('_token', document.querySelector('meta[name="csrf-token"]').content);

            try {
                const data = await window.Kj.post('/pay?action=billing&pay=true', body, { method: 'POST' });

                // pay_html drives how the gateway hand-off is presented.
                const html = data.pay_html || {};

                if (html.type === 'jump' && html.data) {
                    window.open(html.data, '_blank');
                } else if (html.type === 'url' && html.data) {
                    box.innerHTML = `<div class="text-center"><img src="${html.data}" alt="支付二维码" class="mx-auto h-48 w-48"></div>
                        <p class="mt-3 text-center text-sm text-slate-500">请扫码完成支付</p>`;
                    pollStatus();
                    return;
                } else if (html.type === 'html' && html.data) {
                    box.innerHTML = `<div class="text-sm text-slate-600">${html.data}</div>`;
                }

                pollStatus();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        }

        // -------- Poll /check_order until the invoice settles ---------------
        function pollStatus() {
            let attempts = 0;

            const timer = window.setInterval(async () => {
                attempts++;

                try {
                    const data = await window.Kj.post('/check_order', { id: invoiceId });

                    if (Number(data.status) === 1000) {
                        window.clearInterval(timer);
                        window.Kj.toastSuccess('支付成功');
                        window.location.href = data.url || window.location.href;
                        return;
                    }
                } catch (error) {
                    // Keep polling; a transient failure is not fatal.
                }

                if (attempts > 60) {
                    window.clearInterval(timer);
                    window.Kj.toast('未检测到支付结果，请稍后刷新页面确认', 'info');
                }
            }, 3000);
        }

        document.getElementById('payamount')?.addEventListener('click', openPay);

        // `?wakeup=1` opens the payment panel automatically after checkout.
        if ({{ (int) $wakeup }} === 1 && '{{ $detail['status'] }}' !== 'Paid') {
            openPay();
        } else if ({{ (int) $wakeup }} === 1) {
            pollStatus();
        }

        document.getElementById('printInvoice')?.addEventListener('click', () => window.print());

        // PDF export builds a minimal document from the printable region so no
        // extra library is pulled in.
        document.getElementById('downloadInvoice')?.addEventListener('click', () => {
            const printWindow = window.open('', '_blank');

            if (!printWindow) {
                window.Kj.toastError('请允许弹出窗口以下载账单');
                return;
            }

            printWindow.document.write(`
                <html><head><title>账单 #${@json($detail['invoice_num'])}</title>
                <style>body{font-family:sans-serif;padding:24px;color:#1e293b}table{width:100%;border-collapse:collapse}
                th,td{border:1px solid #e2e8f0;padding:8px;text-align:left;font-size:13px}</style></head>
                <body>${document.getElementById('invoicePrintable').innerHTML}</body></html>
            `);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        });
    })();
</script>
@endpush
