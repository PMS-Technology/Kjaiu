@extends('web.layouts.client')

@section('content')
@php $user = $Userinfo['user']; @endphp

<div class="mx-auto max-w-3xl">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">个人信息</h2>
            <span class="badge-slate">{{ $Userinfo['client_group']['group_name'] }}</span>
        </div>

        <form method="post" action="/details" class="card-body space-y-5">
            @csrf

            {{-- Read-only contact identity --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label">邮箱地址</label>
                    <input type="text" class="form-input" value="{{ $user['email'] ?: '未绑定' }}" disabled>
                    <p class="form-hint">
                        如需更换请前往
                        <a href="/security" class="text-brand-600 hover:underline">安全中心</a>
                    </p>
                </div>
                <div>
                    <label class="form-label">手机号</label>
                    <input type="text" class="form-input" value="{{ $user['phonenumber'] ?: '未绑定' }}" disabled>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-5">
                <h3 class="mb-4 text-sm font-semibold text-slate-800">基本资料</h3>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="username">姓名 / 称呼</label>
                        <input type="text" id="username" name="username" class="form-input"
                               value="{{ old('username', $user['username']) }}">
                    </div>
                    <div>
                        <label class="form-label" for="companyname">公司名称</label>
                        <input type="text" id="companyname" name="companyname" class="form-input"
                               value="{{ old('companyname', $user['companyname'] ?? '') }}">
                    </div>
                    <div>
                        <label class="form-label" for="qq">QQ</label>
                        <input type="text" id="qq" name="qq" class="form-input" value="{{ old('qq', $user['qq'] ?? '') }}">
                    </div>
                    <div>
                        <label class="form-label" for="defaultgateway">默认支付方式</label>
                        <select id="defaultgateway" name="defaultgateway" class="form-select">
                            <option value="">请选择</option>
                            @foreach ($Userinfo['gateways'] as $gateway)
                                <option value="{{ $gateway['name'] }}"
                                        @selected(old('defaultgateway', $user['defaultgateway'] ?? '') === $gateway['name'])>
                                    {{ $gateway['title'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-5">
                <h3 class="mb-4 text-sm font-semibold text-slate-800">通信地址</h3>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="form-label" for="country">国家 / 地区</label>
                        <select id="country" name="country" class="form-select">
                            <option value="">请选择</option>
                            @foreach ($Details['areas']['country'] as $country)
                                <option value="{{ $country['name'] }}"
                                        @selected(old('country', $user['country'] ?? '') === $country['name'])>
                                    {{ $country['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="province">省份</label>
                        <input type="text" id="province" name="province" class="form-input"
                               value="{{ old('province', $user['province'] ?? '') }}">
                    </div>
                    <div>
                        <label class="form-label" for="city">城市</label>
                        <input type="text" id="city" name="city" class="form-input"
                               value="{{ old('city', $user['city'] ?? '') }}">
                    </div>
                    <div>
                        <label class="form-label" for="region">区 / 县</label>
                        <input type="text" id="region" name="region" class="form-input"
                               value="{{ old('region', $user['region'] ?? '') }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="form-label" for="address1">详细地址</label>
                        <input type="text" id="address1" name="address1" class="form-input"
                               value="{{ old('address1', $user['address1'] ?? '') }}">
                    </div>
                    <div>
                        <label class="form-label" for="postcode">邮政编码</label>
                        <input type="text" id="postcode" name="postcode" class="form-input"
                               value="{{ old('postcode', $user['postcode'] ?? '') }}">
                    </div>
                </div>
            </div>

            {{-- Custom client fields --}}
            @if (! empty($Userinfo['customs']))
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="mb-4 text-sm font-semibold text-slate-800">其他信息</h3>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($Userinfo['customs'] as $custom)
                            <div>
                                <label class="form-label" for="custom{{ $custom['id'] }}">
                                    {{ $custom['fieldname'] }}
                                    @if ($custom['required']) <span class="text-rose-500">*</span> @endif
                                </label>

                                @if ($custom['fieldtype'] === 'dropdown')
                                    <select id="custom{{ $custom['id'] }}" name="custom[{{ $custom['id'] }}]" class="form-select">
                                        <option value="">请选择</option>
                                        @foreach ($custom['fieldoptions'] as $option)
                                            <option value="{{ $option }}" @selected($custom['value'] === $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($custom['fieldtype'] === 'textarea')
                                    <textarea id="custom{{ $custom['id'] }}" name="custom[{{ $custom['id'] }}]"
                                              class="form-textarea">{{ $custom['value'] }}</textarea>
                                @elseif ($custom['fieldtype'] === 'tickbox')
                                    <label class="flex items-center gap-2 text-sm text-slate-600">
                                        <input type="checkbox" name="custom[{{ $custom['id'] }}]" value="1"
                                               class="form-checkbox" @checked($custom['value'] === '1')>
                                        {{ $custom['description'] ?: $custom['fieldname'] }}
                                    </label>
                                @else
                                    <input type="text" id="custom{{ $custom['id'] }}" name="custom[{{ $custom['id'] }}]"
                                           class="form-input" value="{{ $custom['value'] }}">
                                @endif

                                @if (! empty($custom['description']) && $custom['fieldtype'] !== 'tickbox')
                                    <p class="form-hint">{{ $custom['description'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="border-t border-slate-100 pt-5">
                <h3 class="mb-4 text-sm font-semibold text-slate-800">通知设置</h3>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="marketing_emails_opt_in" value="1" class="form-checkbox"
                               @checked((int) ($user['marketing_emails_opt_in'] ?? 0) === 1)>
                        接收产品与活动推广邮件
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="send_close" value="1" class="form-checkbox"
                               @checked((int) ($user['send_close'] ?? 0) === 1)>
                        接收服务到期与关闭通知
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                <a href="/clientarea" class="btn-secondary btn-sm">返回</a>
                <button type="submit" class="btn-primary btn-sm">保存修改</button>
            </div>
        </form>
    </div>
</div>
@endsection
