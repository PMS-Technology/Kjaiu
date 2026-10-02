@extends('web.layouts.public')

@section('content')
@php
    $product = $CartConfig['product'];
    $currency = $currency;
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 lg:px-8">
    <nav class="mb-4 flex items-center gap-2 text-sm text-slate-500">
        <a href="/cart" class="hover:text-brand-600">订购产品</a>
        <span>/</span>
        <span class="text-slate-800">{{ $product->name }}</span>
    </nav>

    <form method="post" action="/cart?action=configureproduct&pid={{ $product->id }}"
          id="addCartForm" class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        @csrf
        <input type="hidden" name="pid" value="{{ $product->id }}">
        <input type="hidden" name="currencyid" value="{{ $CartConfig['dafault_currencyid'] }}">
        <input type="hidden" name="qty" id="cartQty" value="{{ $CartConfig['qty'] }}">
        @if ($position > 0)
            <input type="hidden" name="i" value="{{ $position }}">
        @endif
        @if (! empty($addParam['promocode']))
            <input type="hidden" name="promocode" value="{{ $addParam['promocode'] }}">
        @endif
        @if (! empty($addParam['aff']))
            <input type="hidden" name="aff" value="{{ $addParam['aff'] }}">
        @endif
        @if (! empty($addParam['sale']))
            <input type="hidden" name="sale" value="{{ $addParam['sale'] }}">
        @endif

        {{-- ============ Left: configuration ============ --}}
        <div class="space-y-4">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title">{{ $product->name }}</h1>
                    <span class="badge-slate">{{ \App\Support\StatusMap::productType((string) $product->type) }}</span>
                </div>
                @if (! empty($product->description))
                    <div class="card-body text-sm leading-6 text-slate-600">{!! nl2br(e($product->description)) !!}</div>
                @endif
            </div>

            {{-- Billing cycle --}}
            @if (! empty($CartConfig['cycle']))
                <div class="card">
                    <div class="card-header"><h2 class="card-title">计费周期</h2></div>
                    <div class="card-body space-y-2">
                        @foreach ($CartConfig['cycle'] as $cycle)
                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-4 py-3 transition-colors hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                <span class="flex items-center gap-3">
                                    <input type="radio" name="billingcycle" value="{{ $cycle['billingcycle'] }}"
                                           class="form-radio cycle-radio"
                                           data-amount="{{ $cycle['amount'] }}"
                                           data-setup="{{ $cycle['setup_fee'] }}"
                                           data-label="{{ $cycle['billingcycle_zh'] }}"
                                           @checked($cycle['billingcycle'] === $CartConfig['billingcycle'])>
                                    <span class="text-sm font-medium text-slate-800">{{ $cycle['billingcycle_zh'] }}</span>
                                </span>
                                <span class="text-right">
                                    <span class="block text-sm font-semibold text-brand-600">
                                        {{ $currency['prefix'] }}{{ $cycle['amount'] }}{{ $currency['suffix'] }}
                                    </span>
                                    @if ((float) $cycle['setup_fee'] > 0)
                                        <span class="block text-xs text-slate-400">初装费 {{ $currency['prefix'] }}{{ $cycle['setup_fee'] }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Configurable options --}}
            @if (! empty($CartConfig['option']))
                <div class="card">
                    <div class="card-header"><h2 class="card-title">配置选项</h2></div>
                    <div class="card-body space-y-5">
                        @foreach ($CartConfig['option'] as $option)
                            <div>
                                <div class="mb-2 flex items-baseline justify-between">
                                    <label class="text-sm font-medium text-slate-800">{{ $option['option_name'] }}</label>
                                    @if (! empty($option['notes']))
                                        <span class="text-xs text-slate-400">{{ $option['notes'] }}</span>
                                    @endif
                                </div>

                                @if ($option['option_type'] === 1)
                                    {{-- dropdown --}}
                                    <select name="configoption[{{ $option['id'] }}]" class="form-select">
                                        @foreach ($option['sub'] as $sub)
                                            <option value="{{ $sub['id'] }}" data-price="{{ $sub['price'] }}"
                                                    @selected($sub['selected'])>
                                                {{ $sub['option_name'] }}
                                                @if ((float) $sub['price'] > 0)（+{{ $currency['prefix'] }}{{ $sub['price'] }}）@endif
                                            </option>
                                        @endforeach
                                    </select>

                                @elseif ($option['option_type'] === 3)
                                    {{-- checkbox / multi-select --}}
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($option['sub'] as $sub)
                                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                                <span class="flex items-center gap-2">
                                                    <input type="checkbox" name="configoption[{{ $option['id'] }}][]"
                                                           value="{{ $sub['id'] }}" class="form-checkbox"
                                                           data-price="{{ $sub['price'] }}"
                                                           @checked($sub['selected'])>
                                                    <span class="text-sm text-slate-800">{{ $sub['option_name'] }}</span>
                                                </span>
                                                @if ((float) $sub['price'] > 0)
                                                    <span class="text-xs text-slate-500">+{{ $currency['prefix'] }}{{ $sub['price'] }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>

                                @elseif ($option['option_type'] === 4)
                                    {{-- quantity --}}
                                    <div class="flex items-center gap-3">
                                        <button type="button" class="btn-secondary btn-sm" data-qty-step="-{{ $option['qty_stage'] ?: 1 }}"
                                                data-qty-target="option-{{ $option['id'] }}">−</button>
                                        <input type="number" id="option-{{ $option['id'] }}"
                                               name="configoption[{{ $option['id'] }}][value]"
                                               value="{{ $option['qty'] }}"
                                               min="{{ $option['qty_minimum'] ?: 1 }}"
                                               max="{{ $option['qty_maximum'] ?: 999 }}"
                                               step="{{ $option['qty_stage'] ?: 1 }}"
                                               class="form-input w-28 text-center">
                                        <button type="button" class="btn-secondary btn-sm" data-qty-step="{{ $option['qty_stage'] ?: 1 }}"
                                                data-qty-target="option-{{ $option['id'] }}">+</button>
                                        <span class="text-sm text-slate-500">{{ $option['unit'] }}</span>
                                    </div>
                                    <p class="form-hint">
                                        区间 {{ $option['qty_minimum'] }} - {{ $option['qty_maximum'] }} {{ $option['unit'] }}
                                    </p>

                                @else
                                    {{-- radio (types 2, 5, 12 and anything else) --}}
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        @foreach ($option['sub'] as $sub)
                                            <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                                                <span class="flex items-center gap-2">
                                                    <input type="radio" name="configoption[{{ $option['id'] }}]"
                                                           value="{{ $sub['id'] }}" class="form-radio"
                                                           data-price="{{ $sub['price'] }}"
                                                           @checked($sub['selected'])>
                                                    <span class="text-sm text-slate-800">{{ $sub['option_name'] }}</span>
                                                </span>
                                                @if ((float) $sub['price'] > 0)
                                                    <span class="text-xs text-slate-500">+{{ $currency['prefix'] }}{{ $sub['price'] }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Custom fields from the product group --}}
            @if (! empty($CartConfig['custom_fields']))
                <div class="card">
                    <div class="card-header"><h2 class="card-title">自定义信息</h2></div>
                    <div class="card-body space-y-4">
                        @foreach ($CartConfig['custom_fields'] as $field)
                            <div>
                                <label class="form-label" for="custom-{{ $field['id'] }}">{{ $field['fieldname'] }}</label>
                                <input type="text" id="custom-{{ $field['id'] }}" name="customfield[{{ $field['id'] }}]"
                                       class="form-input" value="{{ $field['value'] }}">
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Hostname + password --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">主机信息</h2></div>
                <div class="card-body space-y-4">
                    <div>
                        <label class="form-label" for="hostInp">主机名 / 域名</label>
                        <input type="text" id="hostInp" name="host" class="form-input"
                               value="{{ $CartConfig['host'] }}" placeholder="example.com">
                    </div>
                    <div>
                        <label class="form-label" for="passwordInp">初始密码</label>
                        <div class="flex gap-2">
                            <input type="text" id="passwordInp" name="password" class="form-input font-mono"
                                   value="{{ $CartConfig['password'] ?: \Illuminate\Support\Str::password(12, true, true, false, false) }}">
                            <button type="button" id="generatePassword" class="btn-secondary btn-sm whitespace-nowrap">随机生成</button>
                        </div>
                        <p class="form-hint">密码长度 6-20 位，禁止包含 ^ 和 % 字符</p>
                    </div>

                    @if ($CartConfig['allow_qty'])
                        <div>
                            <label class="form-label" for="qtyInp">购买数量</label>
                            <div class="flex items-center gap-3">
                                <button type="button" class="btn-secondary btn-sm" data-qty-step="-1" data-qty-target="qtyInp">−</button>
                                <input type="number" id="qtyInp" value="{{ $CartConfig['qty'] }}" min="1" class="form-input w-24 text-center">
                                <button type="button" class="btn-secondary btn-sm" data-qty-step="1" data-qty-target="qtyInp">+</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============ Right: summary ============ --}}
        <aside class="lg:sticky lg:top-24 lg:h-fit">
            <div class="card">
                <div class="card-header"><h2 class="card-title">费用明细</h2></div>
                <div class="configoption_total card-body">
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-slate-500">产品费用</span>
                            <span class="text-slate-800" id="summaryProduct">
                                {{ $currency['prefix'] }}{{ $CartConfig['cycle'][0]['amount'] ?? '0.00' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">配置选项</span>
                            <span class="text-slate-800" id="summaryOptions">{{ $currency['prefix'] }}0.00</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-500">初装费</span>
                            <span class="text-slate-800" id="summarySetup">{{ $currency['prefix'] }}0.00</span>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                            <span>合计</span>
                            <span class="text-brand-600" id="summaryTotal">
                                {{ $currency['prefix'] }}{{ $CartConfig['cycle'][0]['amount'] ?? '0.00' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 border-t border-slate-100 px-5 py-4">
                    <button type="submit" id="addToCartBtn" class="btn-primary w-full">
                        {{ $position > 0 ? '保存配置' : '加入购物车' }}
                    </button>
                    <a href="/cart?action=viewcart" class="btn-secondary w-full">查看购物车</a>
                </div>
            </div>
        </aside>
    </form>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const currencyPrefix = @json($currency['prefix']);

        // -------- Quantity steppers --------------------------------
        document.querySelectorAll('[data-qty-step]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.qtyTarget);

                if (!input) {
                    return;
                }

                const min = Number(input.min || 1);
                const max = Number(input.max || 9999);
                const step = Math.abs(Number(button.dataset.qtyStep) || 1);
                const next = Number(input.value) + (Number(button.dataset.qtyStep) < 0 ? -step : step);

                input.value = Math.min(max, Math.max(min, next));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        // The hidden qty field mirrors the visible one.
        document.getElementById('qtyInp')?.addEventListener('change', (event) => {
            document.getElementById('cartQty').value = event.target.value;
            recompute();
        });

        document.getElementById('generatePassword')?.addEventListener('click', () => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$&*';
            let out = '';

            for (let i = 0; i < 12; i++) {
                out += chars[Math.floor(Math.random() * chars.length)];
            }

            document.getElementById('passwordInp').value = out;
        });

        // -------- Live totals -------------------------------------
        function selectedCycle() {
            return document.querySelector('.cycle-radio:checked');
        }

        function recompute() {
            const cycle = selectedCycle();
            const base = Number(cycle?.dataset.amount || 0);
            const setup = Number(cycle?.dataset.setup || 0);

            let options = 0;

            // Radio groups contribute their selected price once.
            document.querySelectorAll('input.form-radio[data-price]:checked').forEach((input) => {
                options += Number(input.dataset.price || 0);
            });

            // Checkbox groups add up.
            document.querySelectorAll('input.form-checkbox[data-price]:checked').forEach((input) => {
                options += Number(input.dataset.price || 0);
            });

            const qtyInput = document.getElementById('qtyInp');
            const qty = qtyInput ? Math.max(1, Number(qtyInput.value || 1)) : 1;
            const total = (base + options) * qty + setup;

            document.getElementById('summaryProduct').textContent = currencyPrefix + (base * qty).toFixed(2);
            document.getElementById('summaryOptions').textContent = currencyPrefix + (options * qty).toFixed(2);
            document.getElementById('summarySetup').textContent = currencyPrefix + setup.toFixed(2);
            document.getElementById('summaryTotal').textContent = currencyPrefix + total.toFixed(2);
        }

        document.querySelectorAll('.cycle-radio, input[data-price]').forEach((input) => {
            input.addEventListener('change', recompute);
        });

        recompute();

        // -------- Password rule check ------------------------------
        document.getElementById('addCartForm')?.addEventListener('submit', (event) => {
            const password = document.getElementById('passwordInp')?.value || '';

            if (password !== '') {
                if (password.length < 6 || password.length > 20) {
                    event.preventDefault();
                    window.Kj.toastError('密码长度需为 6-20 位');
                    return;
                }

                if (password.includes('^') || password.includes('%')) {
                    event.preventDefault();
                    window.Kj.toastError('密码不能包含 ^ 或 % 字符');
                }
            }
        });
    })();
</script>
@endpush
