@extends('layouts.portal')

@section('title', '账户资料')
@section('eyebrow', 'KJAIU / ACCOUNT SECURITY')
@section('description', '维护姓名、公司、电话和界面语言。登录邮箱暂不支持自助修改。')

@section('content')
    <section class="profile-layout">
        <article class="panel">
            <header class="panel-head"><div><p class="panel-kicker">PROFILE</p><h2>基本资料</h2></div></header>
            <form class="portal-form profile-form" method="POST" action="{{ route('portal.profile.update') }}">
                @csrf @method('PATCH')
                <div class="form-grid">
                    <label class="field"><span>显示名称</span><input name="name" value="{{ old('name', $user->name) }}" maxlength="255" required></label>
                    <label class="field"><span>登录邮箱 <small>只读</small></span><input type="email" value="{{ $user->email }}" readonly aria-readonly="true"><small class="field-hint">如需变更，请联系 {{ config('kjaiu.company_email') }}。</small></label>
                    <label class="field"><span>姓名 <small>选填</small></span><input name="real_name" value="{{ old('real_name', $user->real_name) }}" maxlength="255"></label>
                    <label class="field"><span>公司 <small>选填</small></span><input name="company_name" value="{{ old('company_name', $user->company_name) }}" maxlength="255"></label>
                    <label class="field"><span>电话区号</span><input name="phone_code" value="{{ old('phone_code', $user->phone_code) }}" maxlength="8" placeholder="+86" required></label>
                    <label class="field"><span>联系电话 <small>选填</small></span><input name="phone" value="{{ old('phone', $user->phone) }}" maxlength="32"></label>
                    <label class="field field-full"><span>界面语言</span><select name="locale" required><option value="zh_CN" @selected(old('locale', $user->locale) === 'zh_CN')>简体中文</option><option value="en_US" @selected(old('locale', $user->locale) === 'en_US')>English</option></select></label>
                </div>
                <button class="button button-primary" type="submit">保存基本资料</button>
            </form>
        </article>

        <article class="panel">
            <header class="panel-head"><div><p class="panel-kicker">PASSWORD</p><h2>修改密码</h2></div></header>
            <form class="portal-form profile-form" method="POST" action="{{ route('portal.profile.password') }}" data-confirm="确认修改账户密码吗？">
                @csrf @method('PUT')
                <label class="field"><span>当前密码</span><input type="password" name="current_password" autocomplete="current-password" required></label>
                <label class="field"><span>新密码</span><input type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                <label class="field"><span>确认新密码</span><input type="password" name="password_confirmation" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                <div class="security-card"><span>SESSION SECURITY</span><p>修改密码会撤销其他浏览器会话和此前签发的 API 令牌，同时安全保留当前浏览器会话。</p></div>
                <button class="button button-primary" type="submit">更新账户密码</button>
            </form>
        </article>
    </section>
@endsection
