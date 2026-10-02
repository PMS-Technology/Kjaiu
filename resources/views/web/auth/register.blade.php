@extends('web.layouts.auth')

@section('content')
<div class="card">
    <div class="card-body">
        <h1 class="mb-1 text-lg font-semibold text-slate-900">注册账号</h1>
        <p class="mb-5 text-sm text-slate-500">创建账号后即可订购产品</p>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @php
            $tabs = collect([
                'email' => ['label' => '邮箱注册', 'enabled' => $Register['allow_register_email']],
                'phone' => ['label' => '手机号注册', 'enabled' => $Register['allow_register_phone']],
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

        {{-- Shared custom fields are rendered inside each tab so both modes
             submit the same names the server reads. --}}
        @php
            $customFieldMarkup = function () use ($Register) {
                return $Register['fields'];
            };
        @endphp

        @if ($Register['allow_register_email'])
            <form method="post" action="/register?action=email" id="tab-email" data-encrypt="true"
                  class="kj-auth-panel space-y-4 {{ $firstTab === 'email' ? '' : 'hidden' }}">
                @csrf
                <input type="hidden" name="action" value="email">

                <div>
                    <label class="form-label" for="registerEmail">邮箱地址</label>
                    <input type="email" id="registerEmail" name="email" class="form-input" required
                           value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email">
                </div>

                @if ($Register['allow_email_register_code'])
                    <div>
                        <label class="form-label" for="registerEmailCode">邮箱验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="registerEmailCode" name="code" class="form-input" maxlength="6" autocomplete="one-time-code">
                            <button type="button" id="registerEmailSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                        </div>
                    </div>
                @endif

                @include('web.auth._register_fields', ['prefix' => 'email'])

                @if ($Register['allow_register_email_captcha'])
                    <div>
                        <label class="form-label" for="registerEmailCaptcha">图形验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="registerEmailCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                            <img data-captcha-for="allow_register_email_captcha" alt="验证码"
                                 class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                            <button type="button" data-captcha-refresh="allow_register_email_captcha" class="btn-secondary btn-sm">换一张</button>
                        </div>
                    </div>
                @endif

                @include('web.auth._register_common')

                <button type="submit" class="btn-primary w-full">注册</button>
            </form>
        @endif

        @if ($Register['allow_register_phone'])
            <form method="post" action="/register?action=phone" id="tab-phone" data-encrypt="true"
                  class="kj-auth-panel space-y-4 {{ $firstTab === 'phone' ? '' : 'hidden' }}">
                @csrf
                <input type="hidden" name="action" value="phone">

                <div>
                    <label class="form-label" for="registerPhone">手机号</label>
                    <div class="flex gap-2">
                        <select name="phone_code" class="form-select w-28">
                            @foreach ($SmsCountry as $country)
                                <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                            @endforeach
                        </select>
                        <input type="tel" id="registerPhone" name="phone" class="form-input" required
                               value="{{ old('phone') }}" placeholder="请输入手机号" autocomplete="tel">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="registerPhoneCode">短信验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="registerPhoneCode" name="code" class="form-input" maxlength="6" autocomplete="one-time-code">
                        <button type="button" id="registerPhoneSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                    </div>
                </div>

                @include('web.auth._register_fields', ['prefix' => 'phone'])

                @if ($Register['allow_register_phone_captcha'])
                    <div>
                        <label class="form-label" for="registerPhoneCaptcha">图形验证码</label>
                        <div class="flex gap-2">
                            <input type="text" id="registerPhoneCaptcha" name="captcha" class="form-input" required maxlength="8" autocomplete="off">
                            <img data-captcha-for="allow_register_phone_captcha" alt="验证码"
                                 class="h-10 w-28 cursor-pointer rounded-lg border border-slate-200 bg-slate-50">
                            <button type="button" data-captcha-refresh="allow_register_phone_captcha" class="btn-secondary btn-sm">换一张</button>
                        </div>
                    </div>
                @endif

                @include('web.auth._register_common')

                <button type="submit" class="btn-primary w-full">注册</button>
            </form>
        @endif

        <p class="mt-5 border-t border-slate-100 pt-4 text-center text-sm text-slate-500">
            已有账号？<a href="/login" class="font-medium text-brand-600 hover:underline">立即登录</a>
        </p>
    </div>
</div>

@include('web.partials.modals')
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

        document.getElementById('registerEmailSend')?.addEventListener('click', (event) => {
            const email = document.getElementById('registerEmail').value.trim();

            if (email === '') {
                window.Kj.toastError('请输入邮箱地址');
                return;
            }

            window.Kj.sendCode(event.currentTarget, '/register_email_send', {
                mk: @json($Setting['msfntk']),
                email: email,
                captcha: document.getElementById('registerEmailCaptcha')?.value || '',
            });
        });

        document.getElementById('registerPhoneSend')?.addEventListener('click', (event) => {
            const phone = document.getElementById('registerPhone').value.trim();

            if (phone === '') {
                window.Kj.toastError('请输入手机号');
                return;
            }

            window.Kj.sendCode(event.currentTarget, '/register_phone_send', {
                mk: @json($Setting['msfntk']),
                phone: phone,
                phone_code: document.querySelector('#tab-phone select[name="phone_code"]').value,
                captcha: document.getElementById('registerPhoneCaptcha')?.value || '',
            });
        });
    })();
</script>
@endpush
