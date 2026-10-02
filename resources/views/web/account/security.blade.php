@extends('web.layouts.client')

@section('content')
@php $user = $Userinfo['user']; @endphp

{{-- ============ Header ============ --}}
<div class="card">
    <div class="card-body flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-4">
            <span class="grid h-12 w-12 place-items-center rounded-full bg-brand-600 text-lg font-semibold text-white">
                {{ mb_substr($user['username'], 0, 1) }}
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-base font-semibold text-slate-900">{{ $user['username'] }}</span>
                    @if ($Security['certifi_status'] === 1)
                        <span class="badge-emerald">已实名认证</span>
                    @else
                        <span class="badge-slate">未实名认证</span>
                    @endif
                </div>
                <div class="mt-0.5 text-sm text-slate-500">
                    {{ $Security['email'] ?: '未绑定邮箱' }} · {{ $Security['phonenumber'] ?: '未绑定手机' }}
                </div>
            </div>
        </div>

        <div class="min-w-48">
            <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                <span>{{ $percentage[0] }}</span>
                <span>{{ $percentage[1] }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full {{ $percentage[1] >= 80 ? 'bg-emerald-500' : ($percentage[1] >= 50 ? 'bg-amber-500' : 'bg-rose-500') }}"
                     style="width: {{ $percentage[1] }}%"></div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">

    {{-- ============ Password ============ --}}
    @if ($user['is_password'])
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">登录密码</h2>
                <span class="badge-{{ $user['is_password'] ? 'emerald' : 'rose' }}">
                    {{ $user['is_password'] ? '已设置' : '未设置' }}
                </span>
            </div>
            <form id="passwordForm" class="card-body space-y-3">
                <input type="hidden" name="flag" value="{{ $user['is_password'] ? 1 : 2 }}">

                @if ($user['is_password'])
                    <div>
                        <label class="form-label" for="oldPassword">当前密码</label>
                        <input type="password" id="oldPassword" name="old_password" class="form-input" data-encrypt required
                               autocomplete="current-password">
                    </div>
                @endif

                <div>
                    <label class="form-label" for="newPassword">新密码</label>
                    <input type="password" id="newPassword" name="password" class="form-input" data-encrypt required
                           autocomplete="new-password">
                    <p class="form-hint">至少 6 位字符</p>
                </div>

                <div>
                    <label class="form-label" for="rePassword">确认新密码</label>
                    <input type="password" id="rePassword" name="re_password" class="form-input" data-encrypt required
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn-primary btn-sm">修改密码</button>
            </form>
        </div>
    @endif

    {{-- ============ Phone ============ --}}
    @if (! empty($Userinfo['shd_allow_sms_send']))
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">手机绑定</h2>
                <span class="badge-{{ $Security['phonenumber'] ? 'emerald' : 'amber' }}">
                    {{ $Security['phonenumber'] ?: '未绑定' }}
                </span>
            </div>

            <div class="card-body space-y-3">
                <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                    <button type="button" data-phone-mode="bind"
                            class="phone-mode-tab flex-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-brand-700 shadow-sm">
                        {{ $Security['phonenumber'] ? '换绑手机' : '绑定手机' }}
                    </button>
                    @if ($Security['phonenumber'])
                        <button type="button" data-phone-mode="remind"
                                class="phone-mode-tab flex-1 rounded-md px-3 py-1.5 text-xs font-medium text-slate-500">
                            登录提醒
                        </button>
                    @endif
                </div>

                {{-- Bind / rebind --}}
                <div data-phone-panel="bind" class="space-y-3">
                    <div>
                        <label class="form-label" for="bindPhoneInput">手机号</label>
                        <div class="flex gap-2">
                            <select id="bindPhoneCode" class="form-select w-28">
                                @foreach ($SmsCountry as $country)
                                    <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                                @endforeach
                            </select>
                            <input type="tel" id="bindPhoneInput" class="form-input" value="{{ $Security['phonenumber'] }}">
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="bindPhoneVerifyCode">验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="bindPhoneVerifyCode" class="form-input" maxlength="6">
                            <button type="button" id="bindPhoneSend" data-action="bind_phone" data-name="phone"
                                    class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                        </div>
                    </div>

                    <button type="button" id="bindPhoneSubmit" class="btn-primary btn-sm">保存</button>
                </div>

                {{-- Login SMS reminder --}}
                @if ($Security['phonenumber'])
                    <div data-phone-panel="remind" class="hidden space-y-3">
                        <p class="text-xs text-slate-500">开启后每次登录都会发送短信提醒。</p>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" id="smsRemindToggle" class="form-checkbox"
                                   @checked((int) $user['is_login_sms_reminder'] === 1)>
                            开启登录短信提醒
                        </label>
                        <div id="smsRemindCodeBox" class="hidden">
                            <label class="form-label" for="smsRemindCode">验证码</label>
                            <div class="flex gap-2">
                                <input type="text" id="smsRemindCode" class="form-input" maxlength="6">
                                <button type="button" id="smsRemindSend" data-action="remind_send" data-name="phone"
                                        class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============ Email ============ --}}
    @if (! empty($Userinfo['shd_allow_email_send']))
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">邮箱绑定</h2>
                <span class="badge-{{ $Security['email'] ? 'emerald' : 'amber' }}">
                    {{ $Security['email'] ?: '未绑定' }}
                </span>
            </div>

            <div class="card-body space-y-3">
                <div class="flex gap-1 rounded-lg bg-slate-100 p-1">
                    <button type="button" data-email-mode="bind"
                            class="email-mode-tab flex-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-brand-700 shadow-sm">
                        {{ $Security['email'] ? '更换邮箱' : '绑定邮箱' }}
                    </button>
                    @if ($Security['email'])
                        <button type="button" data-email-mode="remind"
                                class="email-mode-tab flex-1 rounded-md px-3 py-1.5 text-xs font-medium text-slate-500">
                            登录提醒
                        </button>
                    @endif
                </div>

                <div data-email-panel="bind" class="space-y-3">
                    <div>
                        <label class="form-label" for="bindEmailInput">邮箱地址</label>
                        <input type="email" id="bindEmailInput" class="form-input" value="{{ $Security['email'] }}">
                    </div>

                    <div>
                        <label class="form-label" for="bindEmailVerifyCode">验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="bindEmailVerifyCode" class="form-input" maxlength="6">
                            <button type="button" id="bindEmailSend" data-action="bind_email" data-name="email"
                                    class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                        </div>
                    </div>

                    <button type="button" id="bindEmailSubmit" class="btn-primary btn-sm">保存</button>
                </div>

                @if ($Security['email'])
                    <div data-email-panel="remind" class="hidden space-y-3">
                        <p class="text-xs text-slate-500">开启后每次登录都会发送邮件提醒。</p>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" id="emailRemindToggle" class="form-checkbox"
                                   @checked((int) $user['email_remind'] === 1)>
                            开启登录邮件提醒
                        </label>
                        <div id="emailRemindCodeBox" class="hidden">
                            <label class="form-label" for="emailRemindCode">验证码</label>
                            <div class="flex gap-2">
                                <input type="text" id="emailRemindCode" class="form-input" maxlength="6">
                                <button type="button" id="emailRemindSend" data-action="remind_email_send" data-name="email"
                                        class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============ Second verify ============ --}}
    @if (! empty($Userinfo['allow_second_verify']))
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">二次验证</h2>
                <span class="badge-{{ (int) $user['second_verify'] === 1 ? 'emerald' : 'slate' }}">
                    {{ (int) $user['second_verify'] === 1 ? '已开启' : '未开启' }}
                </span>
            </div>

            <div class="card-body space-y-3">
                <p class="text-xs text-slate-500">
                    开启后，重置密码、重装系统等敏感操作需要额外验证码。
                    当前受保护的操作：{{ implode('、', \App\Support\Settings::secondVerifyActions()) ?: '未配置' }}
                </p>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" id="secondVerifyToggle" class="form-checkbox"
                           @checked((int) $user['second_verify'] === 1)>
                    开启二次验证
                </label>

                <div id="secondVerifyCodeBox" class="hidden">
                    <label class="form-label" for="secondVerifyCardCode">验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="secondVerifyCardCode" class="form-input" maxlength="6">
                        <button type="button" id="secondVerifyCardSend" class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                    </div>
                    <p class="form-hint">关闭二次验证需要验证当前手机号或邮箱。</p>
                </div>
            </div>
        </div>
    @endif

    {{-- ============ Certification ============ --}}
    @if ($Setting['certifi_open'])
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">实名认证</h2>
                <span class="badge-{{ $Security['certifi_status'] === 1 ? 'emerald' : 'slate' }}">
                    {{ \App\Support\StatusMap::CERTIFI_STATUS[$Security['certifi_status']] ?? '未认证' }}
                </span>
            </div>
            <div class="card-body">
                <p class="mb-3 text-xs text-slate-500">完成实名认证后可购买部分特定产品。</p>
                <a href="/verified" class="btn-secondary btn-sm">前往实名认证</a>
            </div>
        </div>
    @endif

    {{-- ============ API key (legacy modal) ============ --}}
    <div class="card">
        <div class="card-header"><h2 class="card-title">API 密钥</h2></div>
        <div class="card-body space-y-3">
            <p class="text-xs text-slate-500">用于下游系统对接的接口密钥，请妥善保管。</p>
            <div class="flex items-center gap-2">
                <input type="text" id="apiKeyField" class="form-input font-mono" value="{{ $user['api_password'] ?: '未生成' }}" readonly>
                <button type="button" id="apiKeyReveal" class="btn-secondary btn-sm whitespace-nowrap">查看</button>
            </div>
            <a href="/apimanage" class="btn-ghost btn-sm">前往 API 管理</a>
        </div>
    </div>
</div>

@include('web.partials.modals')
@endsection

@push('scripts')
<script>
    (function () {
        const post = window.Kj.post;
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        // -------- Panel toggles --------------------------------------
        function bindModeTabs(attr, panelAttr) {
            document.querySelectorAll(`[data-${attr}-mode]`).forEach((tab) => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll(`[data-${attr}-mode]`).forEach((t) => {
                        t.classList.remove('bg-white', 'text-brand-700', 'shadow-sm');
                        t.classList.add('text-slate-500');
                    });
                    tab.classList.add('bg-white', 'text-brand-700', 'shadow-sm');
                    tab.classList.remove('text-slate-500');

                    document.querySelectorAll(`[data-${panelAttr}-panel]`).forEach((panel) => {
                        panel.classList.toggle('hidden', panel.dataset[panelAttr + 'Panel'] !== tab.dataset[attr + 'Mode']);
                    });
                });
            });
        }

        bindModeTabs('phone', 'phone');
        bindModeTabs('email', 'email');

        // -------- Code senders (getCheckCode) ------------------------
        document.querySelectorAll('[id$="Send"]').forEach((button) => {
            if (!button.dataset.action) {
                return;
            }

            button.addEventListener('click', async () => {
                const name = button.dataset.name;
                const value = name === 'email'
                    ? document.getElementById('bindEmailInput')?.value.trim()
                    : document.getElementById('bindPhoneInput')?.value.trim();

                if (!value) {
                    window.Kj.toastError('请先填写接收账号');
                    return;
                }

                try {
                    await post('/get_check_code', {
                        action: button.dataset.action,
                        type: name,
                        [name]: value,
                        phone_code: document.getElementById('bindPhoneCode')?.value || '86',
                    });
                    window.Kj.toastSuccess('验证码已发送');
                    window.Kj.startCountdown(button);
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });
        });

        // -------- Password -------------------------------------------
        document.getElementById('passwordForm')?.addEventListener('submit', async (event) => {
            event.preventDefault();

            const form = event.currentTarget;
            const flag = form.querySelector('[name="flag"]').value;

            try {
                const data = await post('/modify_password', new FormData(form));
                window.Kj.toastSuccess('密码修改成功，正在跳转登录…');
                window.setTimeout(() => { window.location.href = data.url || '/login'; }, 1500);
            } catch (error) {
                if (Number(error.status) === 1002) {
                    window.dispatchEvent(new CustomEvent('kj:second-verify', {
                        detail: {
                            action: 'modify_password',
                            onVerified: ({ code, code_type }) => {
                                const body = new FormData(form);
                                body.append('code', code);
                                body.append('code_type', code_type);
                                post('/modify_password', body)
                                    .then((data) => {
                                        window.Kj.toastSuccess('密码修改成功');
                                        window.setTimeout(() => { window.location.href = data.url || '/login'; }, 1200);
                                    })
                                    .catch((inner) => window.Kj.toastError(inner.message));
                            },
                        },
                    }));
                    return;
                }

                window.Kj.toastError(error.message);
            }
        });

        // -------- Phone / email bind ---------------------------------
        document.getElementById('bindPhoneSubmit')?.addEventListener('click', async () => {
            const phone = document.getElementById('bindPhoneInput').value.trim();

            if (!phone) {
                window.Kj.toastError('请输入手机号');
                return;
            }

            const hasOld = @json((string) $Security['phonenumber'] !== '');

            // Rebinding verifies the old number first, then the new one.
            if (hasOld && phone !== @json((string) $Security['phonenumber'])) {
                try {
                    await post('/bind_phone_change', {
                        phone_code: document.getElementById('bindPhoneCode').value,
                        tel: @json((string) $Security['phonenumber']),
                        code: document.getElementById('bindPhoneVerifyCode').value,
                        type: 1,
                    });

                    await post('/bind_phone_change', {
                        phone_code: document.getElementById('bindPhoneCode').value,
                        phone: phone,
                        code: document.getElementById('bindPhoneVerifyCode').value,
                        type: 2,
                    });
                } catch (error) {
                    window.Kj.toastError(error.message);
                    return;
                }
            } else {
                try {
                    await post('/bind_phone_handle', {
                        phone_code: document.getElementById('bindPhoneCode').value,
                        phone: phone,
                        code: document.getElementById('bindPhoneVerifyCode').value,
                    });
                } catch (error) {
                    window.Kj.toastError(error.message);
                    return;
                }
            }

            window.Kj.toastSuccess('手机绑定已更新');
            window.setTimeout(() => window.location.reload(), 800);
        });

        document.getElementById('bindEmailSubmit')?.addEventListener('click', async () => {
            const email = document.getElementById('bindEmailInput').value.trim();

            if (!email) {
                window.Kj.toastError('请输入邮箱地址');
                return;
            }

            try {
                await post('/bind_email_handle', {
                    email: email,
                    code: document.getElementById('bindEmailVerifyCode').value,
                });
                window.Kj.toastSuccess('邮箱绑定已更新');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- Login reminders ------------------------------------
        async function toggleReminder(channel, enabled, codeInput) {
            const url = channel === 'email' ? '/login_email_reminder' : '/login_sms_reminder';

            try {
                await post(url, { status: enabled ? 1 : 0, code: codeInput });
                window.Kj.toastSuccess(enabled ? '已开启登录提醒' : '已关闭登录提醒');
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        }

        document.getElementById('smsRemindToggle')?.addEventListener('change', (event) => {
            const box = document.getElementById('smsRemindCodeBox');

            if (event.target.checked) {
                toggleReminder('phone', true, '');
                return;
            }

            // Turning it off needs a code.
            box.classList.remove('hidden');
            event.target.checked = true;
        });

        document.getElementById('smsRemindCode')?.addEventListener('change', (event) => {
            if (event.target.value.length === 6) {
                toggleReminder('phone', false, event.target.value);
            }
        });

        document.getElementById('emailRemindToggle')?.addEventListener('change', (event) => {
            const box = document.getElementById('emailRemindCodeBox');

            if (event.target.checked) {
                toggleReminder('email', true, '');
                return;
            }

            box.classList.remove('hidden');
            event.target.checked = true;
        });

        document.getElementById('emailRemindCode')?.addEventListener('change', (event) => {
            if (event.target.value.length === 6) {
                toggleReminder('email', false, event.target.value);
            }
        });

        // -------- Second verify --------------------------------------
        document.getElementById('secondVerifyToggle')?.addEventListener('change', async (event) => {
            if (event.target.checked) {
                try {
                    await post('/toggle_second_verify', { second_verify: 1 });
                    window.Kj.toastSuccess('二次验证已开启');
                } catch (error) {
                    window.Kj.toastError(error.message);
                    event.target.checked = false;
                }
                return;
            }

            document.getElementById('secondVerifyCodeBox').classList.remove('hidden');
            event.target.checked = true;
        });

        document.getElementById('secondVerifyCardSend')?.addEventListener('click', async (jqEvent) => {
            try {
                await post('/second_verify_send', { action: 'modify_password', type: '', second_action: 'modify_password' });
                window.Kj.toastSuccess('验证码已发送');
                window.Kj.startCountdown(jqEvent.currentTarget);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        document.getElementById('secondVerifyCardCode')?.addEventListener('change', async (event) => {
            try {
                await post('/toggle_second_verify', { second_verify: 0, code: event.target.value });
                window.Kj.toastSuccess('二次验证已关闭');
                window.setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                window.Kj.toastError(error.message);
            }
        });

        // -------- API key --------------------------------------------
        document.getElementById('apiKeyReveal')?.addEventListener('click', async () => {
            try {
                const data = await window.Kj.get('/get_api_pwd');
                document.getElementById('apiKeyField').value = data.api;
            } catch (error) {
                if (Number(error.status) === 1002) {
                    window.dispatchEvent(new CustomEvent('kj:second-verify', {
                        detail: {
                            action: 'get_api_pwd',
                            onVerified: ({ code }) => {
                                window.Kj.get('/get_api_pwd', { code: code })
                                    .then((data) => { document.getElementById('apiKeyField').value = data.api; })
                                    .catch((inner) => window.Kj.toastError(inner.message));
                            },
                        },
                    }));
                    return;
                }

                window.Kj.toastError(error.message);
            }
        });
    })();
</script>
@endpush
