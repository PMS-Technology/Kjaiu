@extends('web.layouts.public')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 lg:px-8">
    <h1 class="mb-6 text-xl font-semibold text-slate-900">购物车</h1>

    @if (empty($ShopData['cart_products']))
        <div class="card p-14 text-center">
            <p class="text-sm text-slate-500">购物车是空的。</p>
            <a href="/cart" class="btn-primary btn-sm mt-4">去选购产品</a>
        </div>
    @else
        <form method="post" action="/cart?action=viewcart&statuscart=checkout" id="submit-form">
            @csrf
            <input type="hidden" name="statuscart" value="checkout">

            <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
                {{-- ============ Items ============ --}}
                <div class="space-y-4">

                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">商品清单</h2>
                            <button type="button" id="clearCart" class="btn-ghost btn-sm text-rose-600">清空购物车</button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                <tr>
                                    <th>产品</th>
                                    <th>配置</th>
                                    <th>周期</th>
                                    <th>数量</th>
                                    <th>金额</th>
                                    <th class="text-right">操作</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($ShopData['cart_products'] as $item)
                                    <tr data-position="{{ $item['position'] }}">
                                        <td>
                                            <div class="font-medium text-slate-800">{{ $item['productsname'] }}</div>
                                            @if (! empty($item['conf']['host']))
                                                <div class="text-xs text-slate-400">{{ $item['conf']['host'] }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @forelse ($item['conf_child'] as $child)
                                                <div class="text-xs text-slate-500">{{ $child['name'] }}：{{ $child['sub_name'] }}</div>
                                            @empty
                                                <span class="text-xs text-slate-400">默认配置</span>
                                            @endforelse
                                        </td>
                                        <td>{{ $item['cycle_desc'] }}</td>
                                        <td>
                                            @if ($item['allow_qty'])
                                                <div class="flex items-center gap-1.5">
                                                    <button type="button" class="btn-secondary btn-sm" data-qty-change="-1">−</button>
                                                    <input type="number" value="{{ $item['qty'] }}" min="1"
                                                           class="cart-qty w-16 rounded border border-slate-300 px-1.5 py-1 text-center text-sm"
                                                           data-position="{{ $item['position'] }}">
                                                    <button type="button" class="btn-secondary btn-sm" data-qty-change="1">+</button>
                                                </div>
                                            @else
                                                <span class="text-sm text-slate-600">{{ $item['qty'] }}</span>
                                            @endif
                                        </td>
                                        <td class="font-medium text-slate-800">
                                            {{ $ShopData['currency']['prefix'] }}{{ $item['sale_price'] }}
                                        </td>
                                        <td class="text-right">
                                            <div class="flex justify-end gap-1.5">
                                                <a href="/cart?action=configureproduct&pid={{ $item['productid'] }}&i={{ $item['position'] }}"
                                                   class="btn-ghost btn-sm">编辑</a>
                                                <button type="button" class="btn-ghost btn-sm text-rose-600"
                                                        data-remove="{{ $item['position'] }}">删除</button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Promo code --}}
                        <div class="border-t border-slate-100 px-5 py-4">
                            @if (! empty($ShopData['promo']))
                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-emerald-50 px-4 py-3">
                                    <span class="text-sm text-emerald-800">
                                        {{ $ShopData['promo']['promo_desc_str'] }}
                                        （已优惠 {{ $ShopData['currency']['prefix'] }}{{ $ShopData['promo']['discount'] }}）
                                    </span>
                                    <button type="button" id="removepromo" class="text-sm text-rose-600 hover:underline">移除</button>
                                </div>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" name="promo" id="promoInput" class="form-input w-56 py-1.5 text-sm"
                                           placeholder="输入优惠码">
                                    <button type="button" id="applyPromo" class="btn-secondary btn-sm">使用优惠码</button>
                                </div>
                            @endif
                        </div>

                        {{-- Notes --}}
                        <div class="border-t border-slate-100 px-5 py-4">
                            <label class="form-label" for="notesInp">订单备注</label>
                            <textarea name="notes" id="notesInp" maxlength="200" class="form-textarea"
                                      placeholder="如有特殊要求请在此说明">{{ $ShopData['notes'] }}</textarea>
                        </div>
                    </div>

                    {{-- ============ Guest registration ============ --}}
                    @unless ($logined)
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title">结算方式</h2>
                                <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                                    <button type="button" data-reg-mode="old"
                                            class="reg-mode-tab rounded-md bg-white px-3 py-1 text-xs font-medium text-brand-700 shadow-sm">已有账号</button>
                                    <button type="button" data-reg-mode="new"
                                            class="reg-mode-tab rounded-md px-3 py-1 text-xs font-medium text-slate-500">注册新账号</button>
                                </div>
                            </div>

                            <div class="card-body space-y-4">
                                <input type="hidden" name="register_or_login" id="registerMode" value="login">

                                {{-- Existing account --}}
                                <div data-reg-panel="old" class="space-y-4">
                                    <div class="flex gap-4">
                                        <label class="flex items-center gap-2 text-sm text-slate-600">
                                            <input type="radio" name="login_type" value="email" class="form-radio" checked>
                                            邮箱登录
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-slate-600">
                                            <input type="radio" name="login_type" value="phone" class="form-radio">
                                            手机号登录
                                        </label>
                                    </div>

                                    <div>
                                        <label class="form-label" for="loginAccount">账号</label>
                                        <input type="text" id="loginAccount" name="account" class="form-input"
                                               placeholder="邮箱或手机号" autocomplete="username">
                                    </div>

                                    <div>
                                        <label class="form-label" for="loginPassword">密码</label>
                                        <input type="password" id="loginPassword" name="password" class="form-input"
                                               data-encrypt autocomplete="current-password">
                                    </div>

                                    <p class="text-xs text-slate-400">
                                        登录后系统会自动关联当前购物车。
                                    </p>
                                </div>

                                {{-- New account --}}
                                <div data-reg-panel="new" class="hidden space-y-4">
                                    <div>
                                        <label class="form-label" for="regEmail">邮箱地址</label>
                                        <input type="email" id="regEmail" name="email" class="form-input" autocomplete="email">
                                    </div>

                                    <div>
                                        <label class="form-label" for="regPhone">手机号</label>
                                        <div class="flex gap-2">
                                            <select name="phone_code" class="form-select w-28">
                                                @foreach ($SmsCountry as $country)
                                                    <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                                                @endforeach
                                            </select>
                                            <input type="tel" id="regPhone" name="phone" class="form-input" autocomplete="tel">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label" for="regCode">验证码</label>
                                        <div class="flex gap-2">
                                            <input type="text" id="regCode" name="code" class="form-input" maxlength="6">
                                            <button type="button" id="regCodeSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label" for="regPassword">设置密码</label>
                                        <input type="password" id="regPassword" name="repassword" class="form-input"
                                               data-encrypt autocomplete="new-password">
                                    </div>

                                    @foreach ($Register['fields'] as $field)
                                        <div>
                                            <label class="form-label" for="cartField{{ $field['id'] }}">
                                                {{ $field['fieldname'] }}
                                                @if ($field['required']) <span class="text-rose-500">*</span> @endif
                                            </label>
                                            @if ($field['fieldtype'] === 'dropdown')
                                                <select id="cartField{{ $field['id'] }}" name="fields[{{ $field['id'] }}]" class="form-select">
                                                    <option value="">请选择</option>
                                                    @foreach ($field['dropdown_option'] as $option)
                                                        <option value="{{ $option }}">{{ $option }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input type="text" id="cartField{{ $field['id'] }}" name="fields[{{ $field['id'] }}]" class="form-input">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endunless
                </div>

                {{-- ============ Summary ============ --}}
                <aside class="lg:sticky lg:top-24 lg:h-fit">
                    <div class="card">
                        <div class="card-header"><h2 class="card-title">订单金额</h2></div>

                        <div class="card-body space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-slate-500">商品合计</span>
                                <span class="text-slate-800" id="cartSubtotal">
                                    {{ $ShopData['currency']['prefix'] }}{{ $ShopData['subtotal'] }}
                                </span>
                            </div>
                            @if ((float) $ShopData['discount'] > 0)
                                <div class="flex justify-between">
                                    <span class="text-slate-500">优惠</span>
                                    <span class="text-emerald-600">-{{ $ShopData['currency']['prefix'] }}{{ $ShopData['discount'] }}</span>
                                </div>
                            @endif
                            <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                                <span>应付金额</span>
                                <span class="text-brand-600" id="cartTotal">
                                    {{ $ShopData['currency']['prefix'] }}{{ $ShopData['total_price'] }}
                                </span>
                            </div>
                        </div>

                        {{-- Payment selection --}}
                        <div class="space-y-2 border-t border-slate-100 px-5 py-4">
                            <div class="text-xs font-medium text-slate-500">支付方式</div>

                            @if ($logined)
                                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                    <span class="flex items-center gap-2">
                                        <input type="radio" name="paymt" value="credit" class="form-radio" checked>
                                        <span class="text-sm text-slate-800">余额支付</span>
                                    </span>
                                    <span class="text-xs text-slate-500">
                                        {{ $ShopData['currency']['prefix'] }}{{ $ShopData['paymt']['credit'] }}
                                    </span>
                                </label>

                                @if ($ShopData['paymt']['is_open_credit_limit'] === 1)
                                    <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                        <span class="flex items-center gap-2">
                                            <input type="radio" name="paymt" value="credit_limit" class="form-radio">
                                            <span class="text-sm text-slate-800">信用额支付</span>
                                        </span>
                                        <span class="text-xs text-slate-500">
                                            可用 {{ $ShopData['currency']['prefix'] }}{{ $ShopData['paymt']['credit_limit_balance'] }}
                                        </span>
                                    </label>
                                @endif
                            @endif

                            <div class="text-xs font-medium text-slate-500">在线支付</div>
                            @forelse ($ShopData['gateways'] as $gateway)
                                <label class="flex cursor-pointer items-center justify-between rounded-lg border border-slate-200 px-3 py-2 hover:border-brand-300">
                                    <span class="flex items-center gap-2">
                                        <input type="radio" name="payment" value="{{ $gateway['name'] }}" class="form-radio">
                                        <span class="text-sm text-slate-800">{{ $gateway['title'] }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="text-xs text-slate-400">暂无可用的在线支付方式</p>
                            @endforelse
                        </div>

                        <div class="space-y-3 border-t border-slate-100 px-5 py-4">
                            <label class="flex items-start gap-2 text-xs text-slate-600">
                                <input type="checkbox" name="terms" value="1" id="termsCheck" class="form-checkbox mt-0.5" required>
                                <span>
                                    我已阅读并同意
                                    @if (! empty($Setting['web_tos_url']))
                                        <a href="{{ $Setting['web_tos_url'] }}" target="_blank" rel="noopener" class="text-brand-600 hover:underline">《服务条款》</a>
                                    @else
                                        《服务条款》
                                    @endif
                                </span>
                            </label>

                            <button type="submit" class="btn-primary w-full">提交订单</button>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const form = document.getElementById('submit-form');

        if (!form) {
            return;
        }

        // -------- Guest mode switcher -------------------------------
        const modeInput = document.getElementById('registerMode');
        const panels = document.querySelectorAll('[data-reg-panel]');

        document.querySelectorAll('.reg-mode-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                const mode = tab.dataset.regMode;

                document.querySelectorAll('.reg-mode-tab').forEach((t) => {
                    t.classList.remove('bg-white', 'text-brand-700', 'shadow-sm');
                    t.classList.add('text-slate-500');
                });
                tab.classList.add('bg-white', 'text-brand-700', 'shadow-sm');
                tab.classList.remove('text-slate-500');

                panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.regPanel !== mode));
                modeInput.value = mode === 'new' ? 'register' : 'login';
            });
        });

        document.getElementById('regCodeSend')?.addEventListener('click', (event) => {
            const email = document.getElementById('regEmail').value.trim();
            const phone = document.getElementById('regPhone').value.trim();

            if (email === '' && phone === '') {
                window.Kj.toastError('请填写邮箱或手机号');
                return;
            }

            const url = email !== '' ? '/register_email_send' : '/register_phone_send';

            window.Kj.sendCode(event.currentTarget, url, {
                mk: @json($Setting['msfntk']),
                email: email,
                phone: phone,
                phone_code: '86',
            });
        });

        // -------- Promo code ----------------------------------------
        document.getElementById('applyPromo')?.addEventListener('click', async () => {
            const code = document.getElementById('promoInput').value.trim();

            if (code === '') {
                window.Kj.toastError('请输入优惠码');
                return;
            }

            try {
                await window.Kj.post('/cart/add_promo', { promo: code });
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('removepromo')?.addEventListener('click', async () => {
            try {
                await window.Kj.post('/cart/remove_promo', {});
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Remove / quantity / clear -------------------------
        document.querySelectorAll('[data-remove]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('确定要删除该商品吗？')) {
                    return;
                }

                try {
                    await window.Kj.post('/cart/remove_product', { i: [Number(button.dataset.remove)] });
                    window.location.reload();
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });
        });

        async function changeQty(position, delta) {
            const input = document.querySelector(`.cart-qty[data-position="${position}"]`);
            const next = Math.max(1, Number(input.value) + delta);

            const body = new FormData();
            body.append('i[]', position);
            body.append('qty', next);
            body.append('ajax', 'true');

            try {
                await window.Kj.post('/cart?action=viewcart&statuscart=change&ajax=true', body);
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        }

        document.querySelectorAll('[data-qty-change]').forEach((button) => {
            button.addEventListener('click', () => {
                const position = Number(button.closest('tr').dataset.position);
                changeQty(position, Number(button.dataset.qtyChange));
            });
        });

        document.querySelectorAll('.cart-qty').forEach((input) => {
            input.addEventListener('change', () => {
                changeQty(Number(input.dataset.position), 0);
            });
        });

        document.getElementById('clearCart')?.addEventListener('click', async () => {
            if (!window.confirm('确定要清空购物车吗？')) {
                return;
            }

            try {
                await window.Kj.post('/cart/clear', {});
                window.location.reload();
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Checkout guard ------------------------------------
        form.addEventListener('submit', (event) => {
            const terms = document.getElementById('termsCheck');

            if (terms && !terms.checked) {
                event.preventDefault();
                window.Kj.toastError('请先阅读并同意服务条款');
                return;
            }
        });
    })();
</script>
@endpush
