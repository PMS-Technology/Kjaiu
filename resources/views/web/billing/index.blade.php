@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">账单列表</h2>
        <span class="text-xs text-slate-500">共 {{ $Total }} 条账单</span>
    </div>

    <form method="get" action="/billing" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <label class="form-label" for="billStatus">账单状态</label>
            <select name="status" id="billStatus" class="form-select w-40 py-1.5 text-sm">
                <option value="">全部</option>
                @foreach (['Unpaid' => '未支付', 'Paid' => '已支付', 'Cancelled' => '被取消', 'Refunded' => '已退款'] as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="form-label" for="billKeywords">账单号</label>
            <input type="text" name="keywords" id="billKeywords" class="form-input w-48 py-1.5 text-sm"
                   value="{{ $keywords }}" placeholder="账单号 / ID">
        </div>

        <button type="submit" class="btn-primary btn-sm">筛选</button>
        <a href="/billing" class="btn-secondary btn-sm">重置</a>
    </form>

    @if (empty($bills))
        <div class="px-5 py-14 text-center text-sm text-slate-500">暂无账单记录</div>
    @else
        <form action="/combinebilling" method="post" id="combineForm">
            @csrf
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th class="w-10"></th>
                        <th>账单号</th>
                        <th>类型</th>
                        <th>金额</th>
                        <th>生成时间</th>
                        <th>支付时间</th>
                        <th>支付方式</th>
                        <th>逾期时间</th>
                        <th>状态</th>
                        <th class="text-right">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($bills as $index => $bill)
                        <tr>
                            <td>
                                @if ($bill['status'] === 'Unpaid')
                                    <input type="checkbox" name="ids[{{ $index }}]" value="{{ $bill['id'] }}"
                                           class="form-checkbox combine-check">
                                @endif
                            </td>
                            <td>
                                <a href="/viewbilling?id={{ $bill['id'] }}" class="font-mono text-brand-600 hover:underline">
                                    #{{ $bill['invoice_num'] }}
                                </a>
                            </td>
                            <td>{{ $bill['type_zh'] }}</td>
                            <td class="font-medium">{{ $Currency['prefix'] }}{{ $bill['subtotal'] }}{{ $Currency['suffix'] }}</td>
                            <td>{{ $bill['create_time'] ? date('Y-m-d H:i', $bill['create_time']) : '—' }}</td>
                            <td>{{ $bill['paid_time'] ? date('Y-m-d H:i', $bill['paid_time']) : '—' }}</td>
                            <td>{{ $bill['payment_zh'] ?: '—' }}</td>
                            <td>{{ $bill['due_time'] ? date('Y-m-d H:i', $bill['due_time']) : '—' }}</td>
                            <td>
                                <span class="badge-{{ $bill['status_zh']['color'] }}">{{ $bill['status_zh']['name'] }}</span>
                                @if ($bill['use_credit_limit'])
                                    <span class="badge-slate mt-1">信用额</span>
                                @endif
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end gap-1.5">
                                    <a href="/viewbilling?id={{ $bill['id'] }}" class="btn-ghost btn-sm">查看</a>
                                    @if ($bill['status'] === 'Unpaid')
                                        <a href="/viewbilling?id={{ $bill['id'] }}&wakeup=1" class="btn-primary btn-sm">支付</a>
                                    @endif
                                    @unless (in_array($bill['status'], ['Paid'], true))
                                        <button type="button" class="btn-ghost btn-sm text-rose-600"
                                                data-confirm="确定要删除该账单吗？" data-delete-invoice="{{ $bill['id'] }}">删除</button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
                <span class="text-xs text-slate-500" id="combineHint">勾选多个未支付账单可合并支付</span>
                <div class="flex items-center gap-2">
                    <span class="hidden text-sm text-slate-700" id="combineTotal"></span>
                    <button type="submit" id="pay-combine" disabled class="btn-primary btn-sm disabled:opacity-50">
                        合并支付
                    </button>
                </div>
            </div>
        </form>

        @include('web.partials.pagination', ['total' => $Total, 'pages' => $Pages, 'page' => $Page])
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const checks = document.querySelectorAll('.combine-check');
        const button = document.getElementById('pay-combine');
        const totalBox = document.getElementById('combineTotal');
        const hint = document.getElementById('combineHint');

        async function refresh() {
            const ids = [...checks].filter((c) => c.checked).map((c) => c.value);

            if (ids.length < 2) {
                button.disabled = true;
                totalBox.classList.add('hidden');
                hint.textContent = ids.length === 1 ? '至少选择两个账单才能合并支付' : '勾选多个未支付账单可合并支付';
                return;
            }

            try {
                const data = await window.Kj.get('/get_combine_invoices', { ids: ids });
                button.disabled = false;
                totalBox.classList.remove('hidden');
                totalBox.textContent = `已选 ${data.count} 个账单，合计 ${data.total}`;
                hint.textContent = '';
            } catch (error) {
                hint.textContent = error.message;
            }
        }

        checks.forEach((check) => check.addEventListener('change', refresh));

        document.querySelectorAll('[data-delete-invoice]').forEach((node) => {
            node.addEventListener('click', async () => {
                try {
                    await window.Kj.post(`/invoices/${node.dataset.deleteInvoice}`, new FormData(), { method: 'DELETE' });
                    window.Kj.toastSuccess('删除成功');
                    window.setTimeout(() => window.location.reload(), 600);
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });
        });
    })();
</script>
@endpush
