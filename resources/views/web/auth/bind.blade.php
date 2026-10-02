@extends('web.layouts.auth')

@section('content')
<div class="card">
    <div class="card-body">
        <h1 class="mb-1 text-lg font-semibold text-slate-900">绑定账号</h1>
        <p class="mb-5 text-sm text-slate-500">绑定邮箱或手机号后即可用于登录与找回密码</p>

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @php
            // $CallbackInfo: 0 = both tabs, 1 = phone only, 2 = email only.
            $showEmail = in_array($CallbackInfo, [0, 2], true);
            $showPhone = in_array($CallbackInfo, [0, 1], true);
        @endphp

        @if ($showEmail)
            <form method="post" action="/bind?action=email" class="space-y-4">
                @csrf
                <input type="hidden" name="action" value="email">

                <div>
                    <label class="form-label" for="bindEmail">邮箱地址</label>
                    <input type="email" id="bindEmail" name="email" class="form-input" required placeholder="you@example.com">
                </div>

                <div>
                    <label class="form-label" for="bindEmailCode">邮箱验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="bindEmailCode" name="code" class="form-input" required maxlength="6" autocomplete="one-time-code">
                        <button type="button" id="bindEmailSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full">绑定邮箱</button>
            </form>
        @endif

        @if ($showPhone)
            <form method="post" action="/bind?action=phone" class="{{ $showEmail ? 'mt-6 border-t border-slate-100 pt-6' : '' }} space-y-4">
                @csrf
                <input type="hidden" name="action" value="phone">

                <div>
                    <label class="form-label" for="bindPhone">手机号</label>
                    <div class="flex gap-2">
                        <select name="phone_code" class="form-select w-28">
                            @foreach ($SmsCountry as $country)
                                <option value="{{ $country['phone_code'] }}">{{ $country['link'] }}</option>
                            @endforeach
                        </select>
                        <input type="tel" id="bindPhone" name="phone" class="form-input" required placeholder="请输入手机号">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="bindPhoneCode">短信验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="bindPhoneCode" name="code" class="form-input" required maxlength="6" autocomplete="one-time-code">
                        <button type="button" id="bindPhoneSend" class="btn-secondary btn-sm whitespace-nowrap">获取验证码</button>
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full">绑定手机号</button>
            </form>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.getElementById('bindEmailSend')?.addEventListener('click', (event) => {
        const email = document.getElementById('bindEmail').value.trim();

        if (email === '') {
            window.Kj.toastError('请输入邮箱地址');
            return;
        }

        window.Kj.sendCode(event.currentTarget, '/oauth/bind_email_send', {
            mk: @json($Setting['msfntk']),
            email: email,
        });
    });

    document.getElementById('bindPhoneSend')?.addEventListener('click', (event) => {
        const phone = document.getElementById('bindPhone').value.trim();

        if (phone === '') {
            window.Kj.toastError('请输入手机号');
            return;
        }

        window.Kj.sendCode(event.currentTarget, '/oauth/bind_phone_send', {
            mk: @json($Setting['msfntk']),
            phone: phone,
        });
    });
</script>
@endpush
