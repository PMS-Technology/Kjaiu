@extends('web.layouts.public')

@section('content')
@php $A = $Affiliates; @endphp

<div class="mx-auto max-w-5xl px-4 py-10 lg:px-8">
    <h1 class="mb-6 text-xl font-semibold text-slate-900">推介计划</h1>

    @if (! empty($A['aff']))
        {{-- ============ Activated ============ --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                '可提现余额' => $A['data']['balance'],
                '提现中' => $A['data']['withdraw_ing'],
                '已审核' => $A['data']['audited_balance'],
                '推广收入' => $A['data']['payamount'],
            ] as $label => $value)
                <div class="stat-tile">
                    <span>
                        <span class="stat-value">{{ $Currency['prefix'] }}{{ $value }}</span>
                        <span class="stat-label block">{{ $label }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header"><h2 class="card-title">推广链接</h2></div>
                <div class="card-body space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" class="form-input flex-1 font-mono text-xs" value="{{ $A['data']['url'] }}" readonly>
                        <button type="button" data-copy="{{ $A['data']['url'] }}" class="btn-secondary btn-sm">复制链接</button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <div class="text-xs text-slate-400">访问量</div>
                            <div class="mt-0.5 text-lg font-semibold text-slate-900">{{ $A['data']['visitors'] }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3">
                            <div class="text-xs text-slate-400">注册人数</div>
                            <div class="mt-0.5 text-lg font-semibold text-slate-900">{{ $A['data']['registcount'] }}</div>
                        </div>
                    </div>

                    <button type="button" data-modal-open="withdrawModal" class="btn-primary btn-sm">申请提现</button>
                    @if ($A['affiliate_withdraw'] > 0)
                        <p class="form-hint">最低提现金额 {{ $Currency['prefix'] }}{{ number_format($A['affiliate_withdraw'], 2) }}</p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">说明</h2></div>
                <div class="card-body text-xs leading-6 text-slate-500">
                    通过推广链接注册并完成付款的用户，会按平台配置的比例计入您的推广收益。
                    收益需经管理员审核后方可提现。
                </div>
            </div>
        </div>

        {{-- ============ Records ============ --}}
        <div class="mt-4 card">
            <div class="card-header">
                <h2 class="card-title">推广明细</h2>
                <div class="flex gap-1">
                    @foreach (['affpage' => '全部', 'affbuyrecord' => '推广记录', 'withdrawrecord' => '提现记录', 'useraffilist' => '推广用户'] as $key => $label)
                        <a href="/affiliates?action={{ $key }}"
                           class="rounded-md px-2.5 py-1 text-xs {{ $action === $key ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($action === 'affbuyrecord' || $action === 'affpage')
                @if (empty($A['buy_records']))
                    <div class="px-5 py-10 text-center text-sm text-slate-400">暂无推广记录</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                            <tr><th>账单号</th><th>时间</th><th>金额</th><th>佣金</th><th>状态</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($A['buy_records'] as $record)
                                <tr>
                                    <td><a href="/viewbilling?id={{ $record['id'] }}" class="text-brand-600 hover:underline">#{{ $record['id'] }}</a></td>
                                    <td>{{ $record['create_time'] ? date('Y-m-d H:i', $record['create_time']) : '—' }}</td>
                                    <td>{{ $Currency['prefix'] }}{{ $record['subtotal'] }}</td>
                                    <td class="text-emerald-600">{{ $Currency['prefix'] }}{{ $record['commission'] }}</td>
                                    <td><span class="badge-{{ $record['is_aff'] === '已确认' ? 'emerald' : 'amber' }}">{{ $record['is_aff'] }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($action === 'withdrawrecord' || $action === 'affpage')
                @if (empty($A['withdraw_records']))
                    <div class="px-5 py-10 text-center text-sm text-slate-400">暂无提现记录</div>
                @else
                    <div class="overflow-x-auto border-t border-slate-100">
                        <table class="data-table">
                            <thead>
                            <tr><th>ID</th><th>金额</th><th>类型</th><th>状态</th><th>提现时间</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($A['withdraw_records'] as $record)
                                <tr>
                                    <td>{{ $record['id'] }}</td>
                                    <td>{{ $Currency['prefix'] }}{{ $record['num'] }}</td>
                                    <td>{{ $record['type'] }}</td>
                                    <td>{{ $record['status'] }}</td>
                                    <td>{{ $record['create_time'] ? date('Y-m-d H:i', $record['create_time']) : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($action === 'useraffilist' || $action === 'affpage')
                @if (empty($A['user_list']))
                    <div class="px-5 py-10 text-center text-sm text-slate-400">暂无推广用户</div>
                @else
                    <div class="overflow-x-auto border-t border-slate-100">
                        <table class="data-table">
                            <thead>
                            <tr><th>用户名</th><th>邮箱</th><th>手机号</th><th>注册时间</th><th>最后登录</th></tr>
                            </thead>
                            <tbody>
                            @foreach ($A['user_list'] as $user)
                                <tr>
                                    <td>{{ $user['username'] }}</td>
                                    <td>{{ $user['email'] ?: '—' }}</td>
                                    <td>{{ $user['phonenumber'] ?: '—' }}</td>
                                    <td>{{ $user['create_time'] ? date('Y-m-d H:i', $user['create_time']) : '—' }}</td>
                                    <td>{{ $user['lastlogin'] ? date('Y-m-d H:i', $user['lastlogin']) : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>

        {{-- ============ Withdraw modal ============ --}}
        <div id="withdrawModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                <h3 class="mb-4 text-base font-semibold text-slate-900">申请提现</h3>

                <div class="space-y-3">
                    <div>
                        <label class="form-label" for="withdrawAmount">提现金额</label>
                        <input type="number" id="withdrawAmount" class="form-input" step="0.01"
                               max="{{ $A['data']['balance'] }}" placeholder="0.00">
                        <p class="form-hint">可提现余额 {{ $Currency['prefix'] }}{{ $A['data']['balance'] }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="withdrawMethod">收款方式</label>
                        <select id="withdrawMethod" class="form-select">
                            <option value="0">余额</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
                    <button type="button" id="withdrawSubmit" class="btn-primary btn-sm">提交申请</button>
                </div>
            </div>
        </div>
    @else
        {{-- ============ Not activated ============ --}}
        <div class="card p-10 text-center">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-brand-50 text-brand-600">
                <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M2 10a8 8 0 1116 0 8 8 0 01-16 0zm8.9-3.5a1 1 0 10-1.8 0l-.3.9a1 1 0 01-.6.6l-.9.3a1 1 0 000 1.8l.9.3a1 1 0 01.6.6l.3.9a1 1 0 001.8 0l.3-.9a1 1 0 01.6-.6l.9-.3a1 1 0 000-1.8l-.9-.3a1 1 0 01-.6-.6l-.3-.9z"/>
                </svg>
            </span>

            <h2 class="mt-4 text-lg font-semibold text-slate-900">开通推介计划</h2>
            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500">
                开通后可获得专属推广链接，邀请好友注册并下单，即可按平台比例获得推广收益。
            </p>

            <button type="button" id="activateAffiliate" class="btn-primary mt-5">立即开通</button>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        document.getElementById('activateAffiliate')?.addEventListener('click', async () => {
            try {
                await window.Kj.post('/activation', {});
                window.Kj.toastSuccess('开通成功');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('withdrawSubmit')?.addEventListener('click', async () => {
            const amount = Number(document.getElementById('withdrawAmount').value || 0);

            if (amount <= 0) {
                window.Kj.toastError('请输入提现金额');
                return;
            }

            try {
                await window.Kj.post('/withdraw', {
                    num: amount,
                    account_id: document.getElementById('withdrawMethod').value,
                });
                window.Kj.toastSuccess('提现申请已提交');
                window.setTimeout(() => window.location.reload(), 900);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
@endpush
