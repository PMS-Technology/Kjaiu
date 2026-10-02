@extends('web.layouts.client')

@section('content')
@php $T = $Transaction; @endphp

<div class="card">
    <div class="card-header">
        <h2 class="card-title">交易记录</h2>

        <div class="flex flex-wrap items-center gap-2">
            <select id="accountsRecordSel" class="form-select w-40 py-1.5 text-sm">
                @php
                    $options = [
                        'accounts_record' => '交易流水',
                        'credit_record' => '余额记录',
                        'recharge_record' => '充值记录',
                        'refund_record' => '退款记录',
                        'consume_record' => '消费记录',
                        'withdraw_record' => '提现记录',
                    ];
                    if ($T['is_open_credit_limit'] === 1) {
                        $options['credit_limit'] = '信用额记录';
                    }
                @endphp
                @foreach ($options as $key => $label)
                    <option value="{{ $key }}" @selected($T['action'] === $key)>{{ $label }}</option>
                @endforeach
            </select>

            <form method="get" action="/transaction" class="flex gap-2">
                <input type="hidden" name="action" id="transactionAction" value="{{ $T['action'] }}">
                <input type="text" name="keywords" value="{{ $T['keywords'] }}" class="form-input w-44 py-1.5 text-sm"
                       placeholder="搜索描述 / 流水号">
                <button type="submit" class="btn-secondary btn-sm">搜索</button>
            </form>
        </div>
    </div>

    <div class="overflow-x-auto">
        {{-- 交易流水 --}}
        @if ($T['action'] === 'accounts_record')
            @if (empty($T['accounts_record']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无交易流水</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>ID</th><th>账单号</th><th>金额</th><th>描述</th>
                        <th>支付方式</th><th>类型</th><th>交易时间</th><th>流水号</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($T['accounts_record'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td>
                                @if ($row['invoice_id'])
                                    <a href="/viewbilling?id={{ $row['invoice_id'] }}" class="text-brand-600 hover:underline">
                                        #{{ $row['invoice_id'] }}
                                    </a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="font-medium {{ $row['refund'] ? 'text-rose-600' : 'text-emerald-600' }}">
                                {{ $row['refund'] ? '-' : '+' }}{{ $Currency['prefix'] }}{{ $row['amount'] }}
                            </td>
                            <td class="max-w-xs truncate">{{ $row['description'] }}</td>
                            <td>{{ $row['payment_zh'] ?: '—' }}</td>
                            <td>{{ $row['type_zh'] }}</td>
                            <td>{{ $row['pay_time'] ? date('Y-m-d H:i', $row['pay_time']) : '—' }}</td>
                            <td class="font-mono text-xs">{{ $row['trans_id'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 余额 --}}
        @elseif ($T['action'] === 'credit_record')
            @if (empty($T['credit_record']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无余额记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>金额</th><th>余额</th><th>描述</th><th>类型</th><th>支付时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['credit_record'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td class="font-medium {{ (float) $row['amount'] >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                {{ (float) $row['amount'] >= 0 ? '+' : '' }}{{ $row['amount'] }}
                            </td>
                            <td>{{ $Currency['prefix'] }}{{ $row['balance'] }}</td>
                            <td class="max-w-xs truncate">{{ $row['description'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td>{{ $row['create_time'] ? date('Y-m-d H:i', $row['create_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 信用额 --}}
        @elseif ($T['action'] === 'credit_limit')
            @if (empty($T['credit_limit']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无信用额记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>账单号</th><th>金额</th><th>类型</th><th>状态</th><th>交易时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['credit_limit'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td><a href="/viewbilling?id={{ $row['id'] }}" class="text-brand-600 hover:underline">#{{ $row['id'] }}</a></td>
                            <td>{{ $Currency['prefix'] }}{{ $row['subtotal'] }}</td>
                            <td>{{ $row['type_zh'] }}</td>
                            <td><span class="badge-{{ $row['status_color'] }}">{{ $row['status_zh'] }}</span></td>
                            <td>{{ $row['create_time'] ? date('Y-m-d H:i', $row['create_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 充值 --}}
        @elseif ($T['action'] === 'recharge_record')
            @if (empty($T['recharge_record']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无充值记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>账单号</th><th>金额</th><th>支付方式</th><th>描述</th><th>充值时间</th><th>流水号</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['recharge_record'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td>
                                @if ($row['invoice_id'])
                                    <a href="/viewbilling?id={{ $row['invoice_id'] }}" class="text-brand-600 hover:underline">#{{ $row['invoice_id'] }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="font-medium text-emerald-600">+{{ $Currency['prefix'] }}{{ $row['amount_in'] }}</td>
                            <td>{{ $row['payment_zh'] ?: '—' }}</td>
                            <td class="max-w-xs truncate">{{ $row['description'] }}</td>
                            <td>{{ $row['pay_time'] ? date('Y-m-d H:i', $row['pay_time']) : '—' }}</td>
                            <td class="font-mono text-xs">{{ $row['trans_id'] ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 退款 --}}
        @elseif ($T['action'] === 'refund_record')
            @if (empty($T['refund_record']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无退款记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>账单号</th><th>金额</th><th>描述</th><th>退款时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['refund_record'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td>
                                @if ($row['invoice_id'])
                                    <a href="/viewbilling?id={{ $row['invoice_id'] }}" class="text-brand-600 hover:underline">#{{ $row['invoice_id'] }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="font-medium text-rose-600">-{{ $Currency['prefix'] }}{{ $row['amount_out'] }}</td>
                            <td class="max-w-xs truncate">{{ $row['description'] }}</td>
                            <td>{{ $row['pay_time'] ? date('Y-m-d H:i', $row['pay_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 消费 --}}
        @elseif ($T['action'] === 'consume_record')
            @if (empty($T['rows']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无消费记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>账单号</th><th>金额</th><th>描述</th><th>消费时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['rows'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td>
                                @if ($row['invoice_id'])
                                    <a href="/viewbilling?id={{ $row['invoice_id'] }}" class="text-brand-600 hover:underline">#{{ $row['invoice_id'] }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="font-medium">{{ $Currency['prefix'] }}{{ $row['amount_out'] }}</td>
                            <td class="max-w-xs truncate">{{ $row['description'] }}</td>
                            <td>{{ $row['pay_time'] ? date('Y-m-d H:i', $row['pay_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif

        {{-- 提现 --}}
        @else
            @if (empty($T['withdraw_record']))
                <div class="px-5 py-12 text-center text-sm text-slate-400">暂无提现记录</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>ID</th><th>金额</th><th>类型</th><th>来源</th><th>状态</th><th>提现时间</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($T['withdraw_record'] as $row)
                        <tr>
                            <td>{{ $row['id'] }}</td>
                            <td class="font-medium">{{ $Currency['prefix'] }}{{ $row['num'] }}</td>
                            <td>{{ $row['type'] }}</td>
                            <td>{{ $row['reason'] ?: $row['des'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['create_time'] ? date('Y-m-d H:i', $row['create_time']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>

    @include('web.partials.pagination', [
        'total' => $Pager['Total'],
        'pages' => $Pager['Pages'],
        'page' => $Pager['Page'],
    ])
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('accountsRecordSel')?.addEventListener('change', (event) => {
        // The select switches the `?action=` view, exactly as the original does.
        window.location.href = `/transaction?action=${event.target.value}`;
    });
</script>
@endpush
