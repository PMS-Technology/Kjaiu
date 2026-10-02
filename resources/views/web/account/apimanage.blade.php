@extends('web.layouts.client')

@section('content')
@php $API = array_merge($API['client'], ['form_api' => $API['form_api'], 'free_products' => $API['free_products']]); @endphp

@if ($api_open === 0)
    {{-- ============ Not enabled ============ --}}
    <div class="mx-auto max-w-2xl">
        <div class="card p-8 text-center">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-slate-100 text-slate-400">
                <svg class="h-7 w-7" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 1a4 4 0 00-4 4v3H5a2 2 0 00-2 2v7a2 2 0 002 2h10a2 2 0 002-2v-7a2 2 0 00-2-2h-1V5a4 4 0 00-4-4zm2 7V5a2 2 0 10-4 0v3h4z" clip-rule="evenodd"/>
                </svg>
            </span>

            <h1 class="mt-4 text-lg font-semibold text-slate-900">API 对接</h1>

            @if ($api_open_total === 0)
                <p class="mt-2 text-sm text-slate-500">暂未开启API对接功能，请联系管理员开通。</p>
            @elseif ($need_bind_phone === 1)
                <p class="mt-2 text-sm text-slate-500">
                    使用API功能需要绑定手机，
                    <a href="/security" class="text-brand-600 hover:underline">去绑定手机</a>
                </p>
            @elseif ($need_certify === 1)
                <p class="mt-2 text-sm text-slate-500">
                    使用API功能需要实名认证，
                    <a href="/verified" class="text-brand-600 hover:underline">去实名认证</a>
                </p>
            @else
                <p class="mt-2 text-sm text-slate-500">
                    开启后可通过 API 密钥访问平台接口，用于自有系统对接。
                </p>

                <label class="mt-5 flex items-center justify-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" id="agreeOn" class="form-checkbox">
                    我已阅读并同意
                    @if (! empty($server_clause_url))
                        <a href="{{ $server_clause_url }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">《API服务协议》</a>
                    @else
                        《API服务协议》
                    @endif
                </label>

                <p id="apiOnHint" class="no-check-pro mt-3 hidden text-xs text-rose-600">请先勾选同意服务协议</p>

                <button type="button" id="apiOn" class="btn-primary mt-5">立即开启</button>
            @endif
        </div>
    </div>
@else
    {{-- ============ Enabled ============ --}}
    @if ($api_open === 2)
        <div class="alert-error">
            该账号的 API 功能已被管理员锁定{{ $lock_reason ? '：' . $lock_reason : '。' }}
            <a href="/submitticket" class="underline">提交工单</a> 了解详情。
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- API key --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">API 密钥</h2>
                <span class="badge-emerald">已开启</span>
            </div>
            <div class="card-body space-y-4">
                <div>
                    <label class="form-label">密钥</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" id="apiPassword" class="form-input flex-1 font-mono"
                               value="{{ $API['api_password'] }}" readonly>
                        <button type="button" data-copy="{{ $API['api_password'] }}" class="btn-secondary btn-sm">复制</button>
                    </div>
                    <p class="form-hint">请勿泄露该密钥；重置后旧密钥立即失效。</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="text-xs text-slate-400">开通时间</div>
                        <div class="mt-0.5 text-sm text-slate-800">
                            {{ $API['api_create_time'] ? date('Y-m-d H:i:s', $API['api_create_time']) : '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400">认证方式</div>
                        <div class="mt-0.5 text-sm text-slate-800">
                            <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">authorization: JWT &lt;token&gt;</code>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <button type="button" id="resetApiPwd" class="btn-secondary btn-sm">重置 API 密钥</button>
                    <button type="button" id="closeApi" class="btn-secondary btn-sm text-rose-600">关闭 API</button>
                </div>
            </div>
        </div>

        {{-- Usage stats --}}
        <div class="card">
            <div class="card-header"><h2 class="card-title">使用概况</h2></div>
            <dl class="space-y-3 px-5 py-4 text-sm">
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500">API产品数量</dt>
                    <dd class="text-slate-800">{{ $API['active_count'] }}/{{ $API['host_count'] }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500">代理商品数量</dt>
                    <dd class="text-slate-800">{{ $API['agent_count'] }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500">今日请求数</dt>
                    <dd class="flex items-center gap-1 text-slate-800">
                        {{ $API['api_count'] }}
                        @if ($API['up'])
                            <span class="text-emerald-600">▲ {{ $API['ratio'] }}%</span>
                        @else
                            <span class="text-rose-600">▼ {{ $API['ratio'] }}%</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-slate-500">累计请求数</dt>
                    <dd class="text-slate-800">{{ $API['total_api_count'] }}</dd>
                </div>
            </dl>
        </div>
    </div>

    {{-- Seven-day chart --}}
    <div class="card mt-4">
        <div class="card-header"><h2 class="card-title">近七天请求次数</h2></div>
        <div class="card-body">
            <div id="api-charts-main" class="flex h-48 items-end gap-2" data-series='@json($API["form_api"])'>
                {{-- Rendered as plain bars so the page works without a chart library. --}}
                @php
                    $series = $API['form_api'];
                    $max = max(1, max($series ?: [0]));
                    $labels = [];
                    for ($i = 6; $i >= 0; $i--) { $labels[] = date('m-d', strtotime("-{$i} days")); }
                @endphp
                @foreach ($series as $index => $value)
                    <div class="flex flex-1 flex-col items-center justify-end gap-1">
                        <span class="text-xs text-slate-500">{{ $value }}</span>
                        <div class="w-full rounded-t bg-brand-500/80" style="height: {{ max(2, ($value / $max) * 140) }}px"></div>
                        <span class="text-[11px] text-slate-400">{{ $labels[$index] ?? '' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Exempt products --}}
    @if (! empty($API['free_products']))
        <div class="card mt-4">
            <div class="card-header"><h2 class="card-title">豁免产品列表</h2></div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                    <tr><th>产品名称</th><th>试用数量</th><th>最大购买数量</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($API['free_products'] as $product)
                        <tr>
                            <td>{{ $product['name'] }}</td>
                            <td>{{ $product['ontrial'] }}</td>
                            <td>{{ $product['qty'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($api_open === 2)
        {{-- Locked overlay, matching the original's `.api-manage-modal`. --}}
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/60 p-4">
            <div class="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-xl">
                <h3 class="text-base font-semibold text-slate-900">API 功能已锁定</h3>
                <p class="mt-2 text-sm text-slate-500">{{ $lock_reason ?: '请联系管理员了解详情。' }}</p>
                <a href="/submitticket" class="btn-primary mt-4 w-full">提交工单</a>
            </div>
        </div>
    @endif
@endif
@endsection

@push('scripts')
<script>
    (function () {
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        // -------- Enable ---------------------------------------------------
        document.getElementById('apiOn')?.addEventListener('click', async () => {
            const agreed = document.getElementById('agreeOn')?.checked;

            if (!agreed) {
                document.getElementById('apiOnHint')?.classList.remove('hidden');
                return;
            }

            if (!window.confirm('确定要开启API功能吗？')) {
                return;
            }

            try {
                await window.Kj.post('/zjmf_finance_api/open', { api_open: 1 });
                window.Kj.toastSuccess('API已开启');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Reset ----------------------------------------------------
        document.getElementById('resetApiPwd')?.addEventListener('click', async () => {
            if (!window.confirm('是否确定重置API密钥？重置后旧密钥将立即失效。')) {
                return;
            }

            try {
                const data = await window.Kj.post('/zjmf_finance_api/reset', {});
                document.getElementById('apiPassword').value = data.api_password;
                window.Kj.toastSuccess('API密钥已重置');
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Disable --------------------------------------------------
        document.getElementById('closeApi')?.addEventListener('click', async () => {
            if (!window.confirm('是否确定关闭API？')) {
                return;
            }

            try {
                await window.Kj.post('/zjmf_finance_api/open', { api_open: 0 });
                window.Kj.toastSuccess('API已关闭');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
@endpush
