@extends('web.layouts.client')

@section('content')
<div class="card">
    <div class="card-header">
        <h2 class="card-title">产品与服务</h2>
        <span class="text-xs text-slate-500">共 {{ $Service['Total'] }} 个服务</span>
    </div>

    {{-- ============ Filters ============ --}}
    <form method="get" action="/service" class="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <label class="form-label" for="serviceGroup">产品分组</label>
            <select name="groupid" id="serviceGroup" class="form-select w-40 py-1.5 text-sm">
                <option value="">全部分组</option>
                @foreach ($Service['groups'] as $group)
                    <option value="{{ $group['id'] }}" @selected($Service['groupid'] === $group['id'])>{{ $group['name'] }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="form-label" for="serviceKeywords">关键词</label>
            <input type="text" name="keywords" id="serviceKeywords" class="form-input w-48 py-1.5 text-sm"
                   value="{{ $Service['keywords'] }}" placeholder="产品名 / IP / ID">
        </div>

        <div>
            <label class="form-label">状态</label>
            <div class="flex flex-wrap gap-1.5">
                @php $selected = $Service['selected_status']; @endphp
                @foreach ($Service['domainstatus'] as $key => $label)
                    <label class="badge cursor-pointer {{ in_array($key, $selected, true) ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-200' : 'bg-slate-100 text-slate-600' }}">
                        <input type="checkbox" name="domain_status[]" value="{{ $key }}" class="hidden"
                               @checked(in_array($key, $selected, true))>
                        {{ $label }}
                    </label>
                @endforeach
            </div>
        </div>

        <button type="submit" class="btn-primary btn-sm">筛选</button>
        <a href="/service" class="btn-secondary btn-sm">重置</a>
    </form>

    {{-- ============ Bulk operations ============ --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-5 py-3">
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" id="serviceSelectAll" class="form-checkbox"> 全选
        </label>
        <span class="text-slate-200">|</span>
        <button type="button" data-bulk="on" class="btn-secondary btn-sm">开机</button>
        <button type="button" data-bulk="off" class="btn-secondary btn-sm">关机</button>
        <button type="button" data-bulk="reboot" class="btn-secondary btn-sm">重启</button>
        <button type="button" data-bulk="hard_off" class="btn-secondary btn-sm">硬关机</button>
        <button type="button" data-bulk="hard_reboot" class="btn-secondary btn-sm">硬重启</button>
        <span class="text-slate-200">|</span>
        <button type="button" id="serviceBulkRenew" class="btn-secondary btn-sm">续费</button>
        <button type="button" id="serviceRefreshStatus" class="btn-ghost btn-sm">刷新状态</button>
        <span id="serviceBulkMsg" class="ml-auto text-xs text-slate-500"></span>
    </div>

    {{-- ============ Table ============ --}}
    @if (empty($Service['list']))
        <div class="px-5 py-14 text-center">
            <p class="text-sm text-slate-500">没有符合条件的服务。</p>
            <a href="/cart" class="btn-primary btn-sm mt-4">订购产品</a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                <tr>
                    <th class="w-10"></th>
                    <th>状态</th>
                    <th>产品</th>
                    <th>IP</th>
                    <th>到期时间</th>
                    <th>费用</th>
                    <th>系统</th>
                    <th>备注</th>
                    <th class="text-right">操作</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($Service['list'] as $row)
                    <tr data-host="{{ $row['id'] }}">
                        <td>
                            <input type="checkbox" class="form-checkbox service-row-check" value="{{ $row['id'] }}"
                                   data-status="{{ $row['domainstatus_desc'] }}">
                        </td>
                        <td>
                            <span class="badge-{{ $row['domainstatus_color'] }}" data-status-badge>{{ $row['domainstatus_desc'] }}</span>
                            <span class="status-spin ml-1 hidden" data-power-spinner></span>
                        </td>
                        <td>
                            <a href="/servicedetail?id={{ $row['id'] }}" class="font-medium text-brand-600 hover:underline">
                                {{ $row['productname'] }}
                            </a>
                            @if (! empty($row['domain']))
                                <div class="text-xs text-slate-400">{{ $row['domain'] }}</div>
                            @endif
                            @if (! empty($row['host_cancel']))
                                <span class="badge-rose mt-1" title="取消时间：{{ $row['host_cancel']['type'] }}｜原因：{{ $row['host_cancel']['reason'] }}">
                                    已申请取消
                                </span>
                            @endif
                            @if ($row['initiative_renew'] && ! in_array($row['billingcycle'], ['free', 'onetime'], true))
                                <span class="badge-emerald mt-1">自动续费</span>
                            @endif
                        </td>
                        <td>
                            @if (! empty($row['dedicatedip']))
                                <button type="button" data-copy="{{ $row['dedicatedip'] }}" class="font-mono text-xs hover:text-brand-600">
                                    {{ $row['dedicatedip'] }}
                                </button>
                                @if (count($row['assignedips']) > 1)
                                    <div class="mt-0.5 text-[11px] text-slate-400" title="{{ implode(', ', $row['assignedips']) }}">
                                        共 {{ count($row['assignedips']) }} 个 IP
                                    </div>
                                @endif
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td>{{ $row['nextduedate'] ? date('Y-m-d', $row['nextduedate']) : '—' }}</td>
                        <td>
                            @if ($row['billingcycle'] === 'free')
                                免费
                            @else
                                {{ $Currency['prefix'] }}{{ $row['price_desc'] }}
                                <span class="text-xs text-slate-400">/{{ $row['cycle_short'] }}</span>
                            @endif
                        </td>
                        <td>
                            @if (! empty($row['os_url']))
                                <img src="{{ $row['os_url'] }}" alt="{{ $row['os'] }}" class="h-4 w-4">
                            @else
                                <span class="text-xs text-slate-400">{{ $row['os'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td>
                            <input type="text" value="{{ $row['notes'] }}" placeholder="点击添加备注"
                                   data-remark="{{ $row['id'] }}"
                                   class="w-32 rounded border border-transparent bg-transparent px-1.5 py-1 text-xs hover:border-slate-200 focus:border-brand-400 focus:bg-white focus:outline-none">
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1.5">
                                <a href="/servicedetail?id={{ $row['id'] }}" class="btn-ghost btn-sm">管理</a>
                                <a href="/servicedetail?id={{ $row['id'] }}&action=renew" class="btn-secondary btn-sm">续费</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @include('web.partials.pagination', [
            'total' => $Service['Total'],
            'pages' => $Service['Pages'],
            'page' => $Service['Page'],
        ])
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const checks = () => [...document.querySelectorAll('.service-row-check')].filter((c) => c.checked);
        const message = document.getElementById('serviceBulkMsg');

        function ids() {
            return checks().map((c) => Number(c.value));
        }

        function say(text, isError = false) {
            message.textContent = text;
            message.className = 'ml-auto text-xs ' + (isError ? 'text-rose-600' : 'text-slate-500');
        }

        document.getElementById('serviceSelectAll')?.addEventListener('change', (event) => {
            document.querySelectorAll('.service-row-check').forEach((check) => { check.checked = event.target.checked; });
        });

        // -------- Power state, read through /provision/default --------------
        function paint(hostId, state) {
            const row = document.querySelector(`tr[data-host="${hostId}"]`);

            if (!row) {
                return;
            }

            const spinner = row.querySelector('[data-power-spinner]');
            const badge = row.querySelector('[data-status-badge]');

            if (state === 'process') {
                spinner.classList.remove('hidden');
                spinner.textContent = '';
                spinner.classList.add('animate-spin');
                return;
            }

            spinner.classList.add('hidden');
            spinner.classList.remove('animate-spin');

            if (state === 'on' || state === 'off') {
                // The original leaves the database status in place and only
                // colours the dot; the badge text stays authoritative.
                return;
            }

            badge?.classList.add('opacity-60');
        }

        async function refreshStatus(targetIds) {
            const list = targetIds && targetIds.length ? targetIds : [...document.querySelectorAll('tr[data-host]')].map((r) => Number(r.dataset.host));

            if (list.length === 0) {
                return;
            }

            say('正在获取电源状态…');

            try {
                const data = await window.Kj.post('/provision/default', { id: list, func: 'status', code: '' });

                Object.entries(data || {}).forEach(([hostId, result]) => {
                    if (result && result.data) {
                        paint(Number(hostId), result.data.status);
                    }
                });

                say('状态已更新');
            } catch (error) {
                say(error.message, true);
            }
        }

        document.getElementById('serviceRefreshStatus')?.addEventListener('click', () => refreshStatus(null));

        document.querySelectorAll('[data-bulk]').forEach((button) => {
            button.addEventListener('click', async () => {
                const list = ids();

                if (list.length === 0) {
                    say('请先选择产品', true);
                    return;
                }

                const blocked = checks().some((c) => c.dataset.status !== '已激活');
                const func = button.dataset.bulk;

                if (['off', 'reboot', 'hard_off', 'hard_reboot'].includes(func) && list.length === 0) {
                    return;
                }

                if (func !== 'on' && blocked) {
                    say('所选产品中包含未激活的服务', true);
                    return;
                }

                if (!window.confirm(`确定对选中的 ${list.length} 个产品执行「${button.textContent.trim()}」？`)) {
                    return;
                }

                say('正在提交…');

                try {
                    const data = await window.Kj.post('/provision/default', { id: list, func: func, code: '' });

                    Object.keys(data || {}).forEach((hostId) => paint(Number(hostId), 'process'));
                    say('操作已提交，正在执行');
                    window.setTimeout(() => refreshStatus(list), 15000);
                } catch (error) {
                    if (Number(error.status) === 1002) {
                        window.dispatchEvent(new CustomEvent('kj:second-verify', {
                            detail: {
                                action: func,
                                onVerified: async ({ code }) => {
                                    try {
                                        await window.Kj.post('/provision/default', { id: list, func: func, code: code });
                                        say('操作已提交，正在执行');
                                    } catch (inner) {
                                        say(inner.message, true);
                                    }
                                },
                            },
                        }));

                        return;
                    }

                    say(error.message, true);
                }
            });
        });

        document.getElementById('serviceBulkRenew')?.addEventListener('click', () => {
            const list = ids();

            if (list.length === 0) {
                say('请先选择产品', true);
                return;
            }

            const blocked = checks().some((c) => !['已激活', '已暂停'].includes(c.dataset.status));

            if (blocked) {
                say('只有已激活或已暂停的产品可以续费', true);
                return;
            }

            window.location.href = '/mulitrenew?' + list.map((id) => `host_ids[]=${id}`).join('&');
        });

        // -------- Inline remark editing ------------------------------------
        document.querySelectorAll('[data-remark]').forEach((input) => {
            const original = input.value;

            const save = async () => {
                if (input.value === original) {
                    return;
                }

                try {
                    await window.Kj.post('/host/remark', { id: input.dataset.remark, remark: input.value });
                    window.Kj.toastSuccess('备注已保存');
                } catch (error) {
                    window.Kj.toastError(error.message);
                    input.value = original;
                }
            };

            input.addEventListener('blur', save);
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    input.blur();
                }
            });
        });

        // Coloured status chips drive the checkbox state, as in the original.
        document.querySelectorAll('#serviceGroup ~ div label.badge, form label.badge').forEach((label) => {
            const box = label.querySelector('input[type="checkbox"]');

            label.addEventListener('click', () => {
                if (box) {
                    box.checked = !box.checked;
                    label.className = box.checked
                        ? 'badge cursor-pointer bg-brand-50 text-brand-700 ring-1 ring-brand-200'
                        : 'badge cursor-pointer bg-slate-100 text-slate-600';
                }
            });
        });

        refreshStatus(null);
    })();
</script>
@endpush
