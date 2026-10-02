@extends('web.layouts.client')

@section('content')
@php
    $h = $Detail['host_data'];
    $typeZh = \App\Support\StatusMap::productType($h['type']);
@endphp

{{-- ============ Header ============ --}}
<div class="card">
    <div class="card-body flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-900">{{ $h['productname'] }}</h2>
                <span class="badge-{{ $h['domainstatus_color'] }}">{{ $h['domainstatus_desc'] }}</span>
                <span class="badge-slate">{{ $typeZh }}</span>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-500">
                <span>服务 ID：<span class="font-mono text-slate-700">#{{ $h['id'] }}</span></span>
                @if (! empty($h['domain']))
                    <span>主机名：<span class="font-mono text-slate-700">{{ $h['domain'] }}</span></span>
                @endif
                @if (! empty($h['dedicatedip']))
                    <span>IP：<button type="button" data-copy="{{ $h['dedicatedip'] }}" class="font-mono text-slate-700 hover:text-brand-600">{{ $h['dedicatedip'] }}</button></span>
                @endif
                <span>开通时间：{{ $h['regdate'] ? date('Y-m-d H:i', $h['regdate']) : '—' }}</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" data-modal-open="modifyRemarkModal" class="btn-secondary btn-sm">修改备注</button>
            @if ($h['billingcycle'] !== 'free' && $h['billingcycle'] !== 'onetime')
                <button type="button" data-modal-open="modalRenew" class="btn-primary btn-sm">续费</button>
            @endif
            @if ($h['cancel_control'] === 1 && empty($Cancel['host_cancel']))
                <button type="button" data-modal-open="cancelRequireModal" class="btn-secondary btn-sm text-rose-600">申请取消</button>
            @endif
        </div>
    </div>
</div>

@if (! empty($Cancel['host_cancel']))
    <div class="alert-error mt-4">
        已提交取消申请：{{ $Cancel['host_cancel']['type'] }}｜原因：{{ $Cancel['host_cancel']['reason'] }}
        @if (! empty($Cancel['host_cancel']['status']))（{{ $Cancel['host_cancel']['status'] }}）@endif
    </div>
@endif

{{-- ============ Tabs ============ --}}
<div class="mt-4" x-data="{ tab: 'general' }">
    <div class="flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ([
            'general' => '基本信息',
            'billing' => '财务信息',
            'settings1' => '操作日志',
            'upgrade' => '升降级',
            'downloads' => '文件下载',
            'cancel' => '取消服务',
        ] as $key => $label)
            <button type="button" data-tab-btn="{{ $key }}"
                    class="tab-link {{ $key === 'general' ? 'tab-link-active' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    {{-- ---------- 基本信息 ---------- --}}
    <div data-tab-panel="general" class="mt-4">
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header"><h3 class="card-title">服务信息</h3></div>
                <dl class="grid gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-2">
                    @php
                        $info = [
                            '产品名称' => $h['productname'],
                            '主机名' => $h['domain'] ?: '—',
                            '服务状态' => $h['domainstatus_desc'],
                            '计费周期' => $h['billingcycle_desc'],
                            '开通时间' => $h['regdate'] ? date('Y-m-d H:i', $h['regdate']) : '—',
                            '到期时间' => $h['nextduedate'] ? date('Y-m-d H:i', $h['nextduedate']) : '—',
                            '首次付款' => $Currency['prefix'] . $h['firstpaymentamount_desc'] . $Currency['suffix'],
                            '当前费用' => $Currency['prefix'] . $h['price_desc'] . $Currency['suffix'],
                            '带宽使用' => $h['bwlimit'] > 0 ? $h['bwusage'] . ' / ' . $h['bwlimit'] . ' GB' : '不限',
                            '磁盘使用' => $h['disklimit'] > 0 ? $h['diskusage'] . ' / ' . $h['disklimit'] . ' MB' : '不限',
                        ];
                    @endphp
                    @foreach ($info as $label => $value)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-slate-800">{{ $value }}</dd>
                        </div>
                    @endforeach

                    @if (! empty($h['username']))
                        <div>
                            <dt class="text-xs text-slate-400">用户名</dt>
                            <dd class="mt-0.5 flex items-center gap-2 font-mono text-sm text-slate-800">
                                {{ $h['username'] }}
                                <button type="button" data-copy="{{ $h['username'] }}" class="text-xs text-brand-600 hover:underline">复制</button>
                            </dd>
                        </div>
                    @endif

                    @if (! empty($h['password']))
                        <div>
                            <dt class="text-xs text-slate-400">密码</dt>
                            <dd class="mt-0.5 flex items-center gap-2 text-sm text-slate-800">
                                <input type="password" value="{{ $h['password'] }}" readonly
                                       class="w-32 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-mono text-xs" id="hostPassword">
                                <button type="button" data-reveal="#hostPassword" class="text-xs text-brand-600 hover:underline">显示</button>
                                <button type="button" data-copy="{{ $h['password'] }}" class="text-xs text-brand-600 hover:underline">复制</button>
                            </dd>
                        </div>
                    @endif

                    @if (! empty($h['remark']))
                        <div class="sm:col-span-2">
                            <dt class="text-xs text-slate-400">备注</dt>
                            <dd class="mt-0.5 text-sm text-slate-800">{{ $h['remark'] }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">到期提醒</h3></div>
                <div class="card-body">
                    <div class="text-2xl font-semibold {{ $h['format_nextduedate']['class'] }}">
                        {{ $h['format_nextduedate']['msg'] }}
                    </div>
                    @if ($h['nextduedate'])
                        <div class="mt-1 text-sm text-slate-500">{{ date('Y-m-d H:i', $h['nextduedate']) }}</div>
                    @endif

                    @if (! in_array($h['billingcycle'], ['free', 'onetime'], true))
                        <label class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" id="autoRenewToggle" class="form-checkbox"
                                   @checked($h['initiative_renew'] === 1)>
                            到期自动续费
                        </label>
                    @endif
                </div>
            </div>
        </div>

        {{-- Configurable options --}}
        @if (! empty($Detail['config_options']))
            <div class="card mt-4">
                <div class="card-header"><h3 class="card-title">配置选项</h3></div>
                <dl class="grid gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-3">
                    @foreach ($Detail['config_options'] as $option)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $option['option_name'] }}</dt>
                            <dd class="mt-0.5 text-sm text-slate-800">
                                {{ $option['sub_name'] ?: '—' }}
                                @if ($option['qty'] > 1) × {{ $option['qty'] }} {{ $option['unit'] }} @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        {{-- Custom fields --}}
        @if (! empty($Detail['custom_field_data']))
            <div class="card mt-4">
                <div class="card-header"><h3 class="card-title">其他信息</h3></div>
                <dl class="grid gap-x-6 gap-y-3 px-5 py-4 sm:grid-cols-3">
                    @foreach ($Detail['custom_field_data'] as $field)
                        <div>
                            <dt class="text-xs text-slate-400">{{ $field['fieldname'] }}</dt>
                            <dd class="mt-0.5 text-sm text-slate-800">{{ $field['value'] ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>

    {{-- ---------- 财务信息 ---------- --}}
    <div data-tab-panel="billing" class="mt-4 hidden">
        <div class="card">
            <div class="card-header"><h3 class="card-title">账单记录</h3></div>
            <div id="finance" class="overflow-x-auto">
                <div class="px-5 py-8 text-center text-sm text-slate-400">正在加载…</div>
            </div>
        </div>
    </div>

    {{-- ---------- 操作日志 ---------- --}}
    <div data-tab-panel="settings1" class="mt-4 hidden">
        <div class="card">
            <div class="card-header"><h3 class="card-title">操作日志</h3></div>
            <div id="settings1" class="overflow-x-auto">
                <div class="px-5 py-8 text-center text-sm text-slate-400">正在加载…</div>
            </div>
        </div>
    </div>

    {{-- ---------- 升降级 ---------- --}}
    <div data-tab-panel="upgrade" class="mt-4 hidden">
        <div class="card">
            <div class="card-header"><h3 class="card-title">升级 / 降级</h3></div>
            <div class="card-body space-y-3">
                @if ($h['allow_upgrade_product'])
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4">
                        <div>
                            <div class="text-sm font-medium text-slate-900">升级产品</div>
                            <div class="text-xs text-slate-500">变更到同分组下的其他产品</div>
                        </div>
                        <button type="button" id="upgradeProductBtn" class="btn-secondary btn-sm">选择产品</button>
                    </div>
                @endif

                @if ($h['allow_upgrade_config'])
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-4">
                        <div>
                            <div class="text-sm font-medium text-slate-900">升降级配置选项</div>
                            <div class="text-xs text-slate-500">调整 CPU、内存、带宽等可配置项</div>
                        </div>
                        <button type="button" id="upgradeConfigBtn" class="btn-secondary btn-sm">调整配置</button>
                    </div>
                @endif

                @if (! $h['allow_upgrade_product'] && ! $h['allow_upgrade_config'])
                    <p class="text-sm text-slate-500">该产品暂不支持升降级。</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ---------- 文件下载 ---------- --}}
    <div data-tab-panel="downloads" class="mt-4 hidden">
        <div class="card">
            <div class="card-header"><h3 class="card-title">相关文件</h3></div>
            @if (empty($Detail['download_data']))
                <div class="px-5 py-8 text-center text-sm text-slate-400">暂无相关文件</div>
            @else
                <table class="data-table">
                    <thead>
                    <tr><th>文件名称</th><th>类型</th><th>更新时间</th><th>下载次数</th><th class="text-right">操作</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($Detail['download_data'] as $file)
                        <tr>
                            <td>{{ $file['title'] }}</td>
                            <td>{{ [1 => '压缩包', 2 => '图片', 3 => '文档'][$file['type']] ?? '其他' }}</td>
                            <td>{{ $file['create_time'] ? date('Y-m-d H:i', $file['create_time']) : '—' }}</td>
                            <td>{{ $file['downloads'] }}</td>
                            <td class="text-right">
                                <a href="{{ $file['down_link'] }}" target="_blank" rel="noopener" class="btn-ghost btn-sm">下载</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ---------- 取消服务 ---------- --}}
    <div data-tab-panel="cancel" class="mt-4 hidden">
        <div class="card">
            <div class="card-header"><h3 class="card-title">取消服务</h3></div>
            <div class="card-body">
                @if ($h['cancel_control'] !== 1)
                    <p class="text-sm text-slate-500">该产品不支持自助取消，请提交工单联系我们。</p>
                @elseif (! empty($Cancel['host_cancel']))
                    <p class="text-sm text-slate-600">
                        已提交取消申请（{{ $Cancel['host_cancel']['type'] }}），原因：{{ $Cancel['host_cancel']['reason'] }}。
                    </p>
                @else
                    <p class="mb-4 text-sm text-slate-500">
                        提交取消申请后，我们将按您选择的生效时间停止服务，服务数据将在到期后被清理。
                    </p>
                    <button type="button" data-modal-open="cancelRequireModal" class="btn-danger btn-sm">提交取消申请</button>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ============ Modals ============ --}}

{{-- 备注 --}}
<div id="modifyRemarkModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-4 text-base font-semibold text-slate-900">修改备注</h3>
        <input type="text" id="remarkInp" class="form-input" value="{{ $h['remark'] }}" placeholder="请输入备注" maxlength="255">
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="button" id="remarkSubmit" class="btn-primary btn-sm">保存</button>
        </div>
    </div>
</div>

{{-- 续费 --}}
@if ($h['billingcycle'] !== 'free' && $h['billingcycle'] !== 'onetime')
    <div id="modalRenew" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
        <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-semibold text-slate-900">续费</h3>
                <button type="button" data-modal-close class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
            <div id="renewBox" class="max-h-[70vh] overflow-y-auto p-5">
                <div class="py-8 text-center text-sm text-slate-400">正在加载…</div>
            </div>
        </div>
    </div>
@endif

{{-- 取消申请 --}}
<div id="cancelRequireModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
        <h3 class="mb-4 text-base font-semibold text-slate-900">取消服务申请</h3>
        <form id="cancelRequireForm" class="space-y-4">
            <div>
                <label class="form-label" for="cancelType">生效时间</label>
                <select name="type" id="cancelType" class="form-select">
                    <option value="Immediate">立即</option>
                    <option value="Endofbilling">等待账单周期结束</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="cancelReason">取消原因</label>
                <select name="reason_id" id="cancelReasonSel" class="form-select">
                    @foreach ($Cancel['cancelist'] as $reason)
                        <option value="{{ $reason['reason'] }}">{{ $reason['reason'] }}</option>
                    @endforeach
                </select>
                <input type="text" name="reason" id="cancelReason" class="form-input mt-2" placeholder="补充说明（可选）">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
                <button type="submit" class="btn-danger btn-sm">提交申请</button>
            </div>
        </form>
    </div>
</div>

{{-- 升降级容器 --}}
<div id="upgradeProductDiv" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"></div>
<div id="upgradeConfigDiv" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4"></div>

@include('web.partials.modals')
@endsection

@push('scripts')
<script>
    (function () {
        const hostId = {{ $h['id'] }};

        // -------- Tabs -----------------------------------------------------
        const buttons = document.querySelectorAll('[data-tab-btn]');
        const panels = document.querySelectorAll('[data-tab-panel]');
        const loaded = {};

        async function openTab(key) {
            buttons.forEach((button) => button.classList.toggle('tab-link-active', button.dataset.tabBtn === key));
            panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.tabPanel !== key));

            // 财务信息 and 操作日志 are HTML fragments fetched on first open.
            if ((key === 'billing' || key === 'settings1') && !loaded[key]) {
                loaded[key] = true;
                const action = key === 'billing' ? 'billing_page' : 'log_page';
                const target = document.getElementById(key === 'billing' ? 'finance' : 'settings1');

                try {
                    const response = await fetch(`/servicedetail?id=${hostId}&action=${action}&page=1&limit=10`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    target.innerHTML = await response.text();
                } catch (error) {
                    target.innerHTML = '<div class="px-5 py-8 text-center text-sm text-rose-600">加载失败</div>';
                }
            }
        }

        buttons.forEach((button) => button.addEventListener('click', () => openTab(button.dataset.tabBtn)));

        // -------- 备注 ------------------------------------------------------
        document.getElementById('remarkSubmit')?.addEventListener('click', async () => {
            try {
                await window.Kj.post('/host/remark', { id: hostId, remark: document.getElementById('remarkInp').value });
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- 自动续费 --------------------------------------------------
        document.getElementById('autoRenewToggle')?.addEventListener('change', async (event) => {
            try {
                await window.Kj.post('/host/autorenew', {
                    hostid: hostId,
                    initiative_renew: event.target.checked ? 1 : 0,
                });
                window.Kj.toastSuccess(event.target.checked ? '已开启自动续费' : '已关闭自动续费');
            } catch (error) {
                window.Kj.toastError(error.message);
                event.target.checked = !event.target.checked;
            }
        });

        // -------- 续费 modal ------------------------------------------------
        document.querySelector('[data-modal-open="modalRenew"]')?.addEventListener('click', async () => {
            const box = document.getElementById('renewBox');
            box.innerHTML = '<div class="py-8 text-center text-sm text-slate-400">正在加载…</div>';

            try {
                const response = await fetch(`/servicedetail?id=${hostId}&action=renew`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                box.innerHTML = await response.text();
            } catch (error) {
                box.innerHTML = '<div class="py-8 text-center text-sm text-rose-600">加载失败</div>';
            }
        });

        // -------- 取消申请 --------------------------------------------------
        document.getElementById('cancelRequireForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const reasonSelect = document.getElementById('cancelReasonSel').value;
            const extra = document.getElementById('cancelReason').value.trim();

            try {
                await window.Kj.post('/host/cancel', {
                    _method: 'DELETE',
                    id: hostId,
                    type: document.getElementById('cancelType').value,
                    reason: extra ? `${reasonSelect}｜${extra}` : reasonSelect,
                });
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- 升降级 ----------------------------------------------------
        async function loadUpgrade(containerId, action) {
            const container = document.getElementById(containerId);
            container.classList.remove('hidden');
            container.classList.add('flex');
            container.innerHTML = '<div class="w-full max-w-2xl rounded-xl bg-white p-5 text-center text-sm text-slate-400">正在加载…</div>';

            try {
                const response = await fetch(`/servicedetail?id=${hostId}&action=${action}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                container.innerHTML = await response.text();

                // The fragment may itself contain a modal wrapper.
                const inner = container.firstElementChild;
                if (inner) {
                    inner.classList.add('mx-auto', 'w-full', 'max-w-2xl', 'rounded-xl', 'bg-white', 'p-5');
                }
            } catch (error) {
                container.innerHTML = '<div class="w-full max-w-2xl rounded-xl bg-white p-5 text-center text-sm text-rose-600">加载失败</div>';
            }
        }

        document.getElementById('upgradeProductBtn')?.addEventListener('click', () => loadUpgrade('upgradeProductDiv', 'upgrade_page'));
        document.getElementById('upgradeConfigBtn')?.addEventListener('click', () => loadUpgrade('upgradeConfigDiv', 'upgrade_configoption_page'));
    })();
</script>
@endpush
