@extends('web.layouts.auth')

@section('content')
<div class="card">
    <div class="card-body">
        <h1 class="mb-1 text-lg font-semibold text-slate-900">登录</h1>
        <p class="mb-5 text-sm text-slate-500">欢迎回来，请登录您的账号</p>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        {{-- Tab switcher: only the modes enabled in 面板 render. --}}
        @php
            $tabs = collect([
                'email' => ['label' => '邮箱登录', 'enabled' => $Login['allow_login_email']],
                'phone' => ['label' => '手机号登录', 'enabled' => $Login['allow_login_phone']],
            ])->filter(fn ($tab) => $tab['enabled']);
            $firstTab = $tabs->keys()->first() ?? 'email';
        @endphp

        @if ($tabs->count() > 1)
            <div class="mb-5 flex gap-1 rounded-lg bg-slate-100 p-1">
                @foreach ($tabs as $key => $tab)
                    <button type="button" data-tab="{{ $key }}"
                            class="kj-auth-tab flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors
                                   {{ $key === $firstTab ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700' }}">
                        {{ $tab['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        {{-- ============ Email tab ============ --}}
        @if ($Login['allow_login_email'])
            <form method="post" action="/login?action=email" id="tab-email" data-encrypt="true"
                  class="kj-auth-panel space-y-4 {{ $firstTab === 'email' ? '' : 'hidden' }}">
                @csrf
                <input type="hidden" name="action" value="email">
                @if (! empty($redirect_url))
                    <input type="hidden" name="redirect" value="{{ $redirect_url }}">
                @endif

                <div>
                    <label class="form-label" for="email">邮箱地址</label>
                    <input type="email" id="email" name="email" class="form-input" required
                           value="{{ old('email') }}" placeholder="you@example.com" autocomplete="username">
                </div>

                <div>
                    <label class="form-label" for="emailPwdInp">登录密码</label>
                    <input type="password" id="emailPwdInp" name="password" class="form-input" data-encrypt required
                           placeholder="请输入密码" autocomplete="current-password">
                </div>

                @if ($Login['allow_login_email_captcha'])
                    <div>
                        <label class="form-label" for="emailCaptcha">图形验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="emailCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                            <img data-captcha-for="allow_login_email_captcha" alt="验证码"
                                 class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                            <button type="button" data-captcha-refresh="allow_login_email_captcha" class="btn-secondary btn-sm">换一张</button>
                        </div>
                    </div>
                @endif

                <div class="flex items-center justify-between text-sm">
                    <label class="flex items-center gap-2 text-slate-600">
                        <input type="checkbox" name="remember" value="1" class="form-checkbox">
                        记住我
                    </label>
                    <a href="/pwreset" class="text-brand-600 hover:underline">忘记密码？</a>
                </div>

                <button type="submit" class="btn-primary w-full">登录</button>
            </form>
        @endif

        {{-- ============ Phone tab ============ --}}
        @if ($Login['allow_login_phone'])
            <div id="tab-phone" class="kj-auth-panel {{ $firstTab === 'phone' ? '' : 'hidden' }}">
                {{-- Password mode --}}
                <form method="post" action="/login?action=phone" id="phonePassForm" data-encrypt="true" class="space-y-4">
                    @csrf
                    <input type="hidden" name="action" value="phone">
                    @if (! empty($redirect_url))
                        <input type="hidden" name="redirect" value="{{ $redirect_url }}">
                    @endif

                    <div>
                        <label class="form-label" for="phone">手机号</label>
                        <div class="flex gap-2">
                            @if ($Login['allow_login_register_sms_global'])
                                <select name="phone_code" class="form-select w-28">
                                    @foreach ($SmsCountry as $country)
                                        <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="phone_code" value="86">
                            @endif
                            <input type="tel" id="phone" name="phone" class="form-input" required
                                   value="{{ old('phone') }}" placeholder="请输入手机号" autocomplete="tel">
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="phonePwdInp">登录密码</label>
                        <input type="password" id="phonePwdInp" name="password" class="form-input" data-encrypt required
                               placeholder="请输入密码" autocomplete="current-password">
                    </div>

                    @if ($Login['allow_login_phone_captcha'])
                        <div>
                            <label class="form-label" for="phoneCaptcha">图形验证码</label>
                            <div class="flex gap-2">
                                <input type="text" id="phoneCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                                <img data-captcha-for="allow_login_phone_captcha" alt="验证码"
                                     class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                                <button type="button" data-captcha-refresh="allow_login_phone_captcha" class="btn-secondary btn-sm">换一张</button>
                            </div>
                        </div>
                    @endif

                    <button type="submit" class="btn-primary w-full">登录</button>
                </form>

                {{-- SMS-code mode, toggled client-side by `phoneCheck()` in the original --}}
                <form method="post" action="/login?action=phone_code" id="phoneCodeForm" class="mt-4 hidden space-y-4">
                    @csrf
                    <input type="hidden" name="action" value="phone_code">
                    @if (! empty($redirect_url))
                        <input type="hidden" name="redirect" value="{{ $redirect_url }}">
                    @endif
                    <input type="hidden" name="phone_code" value="86">

                    <div>
                        <label class="form-label" for="phoneCodePhone">手机号</label>
                        <input type="tel" id="phoneCodePhone" name="phone" class="form-input" placeholder="请输入手机号" autocomplete="tel">
                    </div>

                    <div>
                        <label class="form-label" for="phoneLoginCode">短信验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="phoneLoginCode" name="code" class="form-input" maxlength="6" autocomplete="one-time-code">
                            <button type="button" id="phoneCodeSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                        </div>
                    </div>

                    @if ($Login['allow_login_code_captcha'])
                        <div>
                            <label class="form-label" for="codeCaptcha">图形验证码</label>
                            <div class="flex gap-2">
                                <input type="text" id="codeCaptcha" name="captcha" class="form-input" maxlength="8" autocomplete="off">
                                <img data-captcha-for="allow_login_code_captcha" alt="验证码"
                                     class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                                <button type="button" data-captcha-refresh="allow_login_code_captcha" class="btn-secondary btn-sm">换一张</button>
                            </div>
                        </div>
                    @endif

                    <button type="submit" class="btn-primary w-full">登录</button>
                </form>

                <button type="button" id="phoneModeToggle" class="btn-ghost mt-3 w-full text-sm">
                    使用短信验证码登录
                </button>
            </div>
        @endif

        @if ($Login['allow_register'])
            <p class="mt-5 border-t border-slate-100 pt-4 text-center text-sm text-slate-500">
                还没有账号？<a href="/register" class="font-medium text-brand-600 hover:underline">立即注册</a>
            </p>
        @endif
    </div>
</div>

@include('web.partials.modals')
@endsection

@push('scripts')
<script>
    (function () {
        // Auth tabs
        const tabs = document.querySelectorAll('.kj-auth-tab');
        const panels = document.querySelectorAll('.kj-auth-panel');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((t) => t.classList.remove('bg-white', 'text-brand-700', 'shadow-sm'));
                tabs.forEach((t) => t.classList.add('text-slate-500'));
                tab.classList.add('bg-white', 'text-brand-700', 'shadow-sm');
                tab.classList.remove('text-slate-500');

                panels.forEach((panel) => panel.classList.add('hidden'));
                document.getElementById('tab-' + tab.dataset.tab)?.classList.remove('hidden');
            });
        });

        // Phone password / SMS-code mode switch
        const toggle = document.getElementById('phoneModeToggle');
        const passForm = document.getElementById('phonePassForm');
        const codeForm = document.getElementById('phoneCodeForm');

        toggle?.addEventListener('click', () => {
            const usingCode = !codeForm.classList.contains('hidden');
            passForm.classList.toggle('hidden', usingCode);
            codeForm.classList.toggle('hidden', !usingCode);
            toggle.textContent = usingCode ? '使用短信验证码登录' : '使用密码登录';

            if (!usingCode) {
                document.getElementById('phoneCodePhone').value = document.getElementById('phone').value;
            }
        });

        // SMS sender for the code-login tab
        document.getElementById('phoneCodeSend')?.addEventListener('click', (event) => {
            const phone = document.getElementById('phoneCodePhone').value.trim();

            if (phone === '') {
                window.Kj.toastError('请输入手机号');
                return;
            }

            window.Kj.sendCode(event.currentTarget, '/login_send', {
                mk: @json($Setting['msfntk'] ?? ''),
                phone: phone,
                phone_code: '86',
                captcha: document.getElementById('codeCaptcha')?.value || '',
            });
        });
    })();
</script>
@endpush
