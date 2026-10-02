@extends('web.layouts.auth')

@section('content')
<div class="card">
    <div class="card-body">
        <h1 class="mb-1 text-lg font-semibold text-slate-900">找回密码</h1>
        <p class="mb-5 text-sm text-slate-500">通过邮箱或手机号重置登录密码</p>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @php
            $tabs = collect([
                'email' => ['label' => '邮箱找回', 'enabled' => $Pwreset['allow_login_email']],
                'phone' => ['label' => '手机号找回', 'enabled' => $Pwreset['allow_login_phone']],
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

        @if ($Pwreset['allow_login_email'])
            <form method="post" action="/pwreset?action=email" id="tab-email" data-encrypt="true"
                  class="kj-auth-panel space-y-4 {{ $firstTab === 'email' ? '' : 'hidden' }}">
                @csrf
                <input type="hidden" name="action" value="email">

                <div>
                    <label class="form-label" for="resetEmail">邮箱地址</label>
                    <input type="email" id="resetEmail" name="email" class="form-input" required
                           value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email">
                </div>

                <div>
                    <label class="form-label" for="resetEmailCode">邮箱验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="resetEmailCode" name="code" class="form-input" required maxlength="6" autocomplete="one-time-code">
                        <button type="button" id="resetEmailSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                    </div>
                </div>

                @include('web.auth._password_fields', ['prefix' => 'reset-email'])

                @if ($Verify['allow_email_forgetpwd_captcha'])
                    <div>
                        <label class="form-label" for="resetEmailCaptcha">图形验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="resetEmailCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                            <img data-captcha-for="allow_email_forgetpwd_captcha" alt="验证码"
                                 class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                            <button type="button" data-captcha-refresh="allow_email_forgetpwd_captcha" class="btn-secondary btn-sm">换一张</button>
                        </div>
                    </div>
                @endif

                <button type="submit" class="btn-primary w-full">重置密码</button>
            </form>
        @endif

        @if ($Pwreset['allow_login_phone'])
            <form method="post" action="/pwreset?action=phone" id="tab-phone" data-encrypt="true"
                  class="kj-auth-panel space-y-4 {{ $firstTab === 'phone' ? '' : 'hidden' }}">
                @csrf
                <input type="hidden" name="action" value="phone">

                <div>
                    <label class="form-label" for="resetPhone">手机号</label>
                    <div class="flex gap-2">
                        <select name="phone_code" class="form-select w-28">
                            @foreach ($SmsCountry as $country)
                                <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                            @endforeach
                        </select>
                        <input type="tel" id="resetPhone" name="phone" class="form-input" required
                               value="{{ old('phone') }}" placeholder="请输入手机号" autocomplete="tel">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="resetPhoneCode">短信验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="resetPhoneCode" name="code" class="form-input" required maxlength="6" autocomplete="one-time-code">
                        <button type="button" id="resetPhoneSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                    </div>
                </div>

                @include('web.auth._password_fields', ['prefix' => 'reset-phone'])

                @if ($Verify['allow_phone_forgetpwd_captcha'])
                    <div>
                        <label class="form-label" for="resetPhoneCaptcha">图形验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="resetPhoneCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                            <img data-captcha-for="allow_phone_forgetpwd_captcha" alt="验证码"
                                 class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                            <button type="button" data-captcha-refresh="allow_phone_forgetpwd_captcha" class="btn-secondary btn-sm">换一张</button>
                        </div>
                    </div>
                @endif

                <button type="submit" class="btn-primary w-full">重置密码</button>
            </form>
        @endif

        <p class="mt-5 border-t border-slate-100 pt-4 text-center text-sm text-slate-500">
            想起密码了？<a href="/login" class="font-medium text-brand-600 hover:underline">返回登录</a>
        </p>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const tabs = document.querySelectorAll('.kj-auth-tab');
        const panels = document.querySelectorAll('.kj-auth-panel');

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((t) => { t.classList.remove('bg-white', 'text-brand-700', 'shadow-sm'); t.classList.add('text-slate-500'); });
                tab.classList.add('bg-white', 'text-brand-700', 'shadow-sm');
                tab.classList.remove('text-slate-500');

                panels.forEach((panel) => panel.classList.add('hidden'));
                document.getElementById('tab-' + tab.dataset.tab)?.classList.remove('hidden');
            });
        });

        document.getElementById('resetEmailSend')?.addEventListener('click', (event) => {
            const email = document.getElementById('resetEmail').value.trim();

            if (email === '') {
                window.Kj.toastError('请输入邮箱地址');
                return;
            }

            window.Kj.sendCode(event.currentTarget, '/reset_email_send', {
                mk: @json($Setting['msfntk']),
                email: email,
                captcha: document.getElementById('resetEmailCaptcha')?.value || '',
            });
        });

        document.getElementById('resetPhoneSend')?.addEventListener('click', (event) => {
            const phone = document.getElementById('resetPhone').value.trim();

            if (phone === '') {
                window.Kj.toastError('请输入手机号');
                return;
            }

            window.Kj.sendCode(event.currentTarget, '/reset_phone_send', {
                mk: @json($Setting['msfntk']),
                phone: phone,
                phone_code: document.querySelector('#tab-phone select[name="phone_code"]').value,
                captcha: document.getElementById('resetPhoneCaptcha')?.value || '',
            });
        });
    })();
</script>
@endpush
