<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · Kjaiu</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-frame">
        <section class="login-story">
            <div class="story-grid" aria-hidden="true"></div>
            <a class="brand brand-light" href="/">
                <span class="brand-mark">K<span></span></span>
                <span class="brand-copy"><strong>Kjaiu</strong><small>FINANCE OS</small></span>
            </a>
            <div class="story-copy">
                <p class="eyebrow eyebrow-light">FINANCE, IN FULL VIEW</p>
                <h1>每一笔资金，<br><em>都有迹可循。</em></h1>
                <p>从商品、订单到入账与服务交付，Kjaiu 将运营数据收束在同一条可信链路上。</p>
            </div>
            <div class="story-foot">
                <span>01</span>
                <p>一致的账本<br>清晰的责任边界</p>
                <i></i>
                <span>2026</span>
            </div>
        </section>

        <section class="login-panel">
            <div class="login-box">
                <div class="login-intro">
                    <p class="eyebrow">SECURE CONSOLE</p>
                    <h2>欢迎回来</h2>
                    <p>使用你的账户进入 Kjaiu 服务与运营工作台。</p>
                </div>

                @if ($errors->any())
                    <div class="notice notice-error" role="alert"><span class="notice-icon">!</span><span>{{ $errors->first() }}</span></div>
                @endif

                <form class="auth-form" method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <label class="field">
                        <span>账户邮箱</span>
                        <input type="email" name="email" value="{{ old('email') }}" maxlength="191" autocomplete="username" placeholder="name@company.com" required autofocus>
                    </label>
                    <label class="field">
                        <span>密码</span>
                        <input type="password" name="password" autocomplete="current-password" placeholder="输入账户密码" required>
                    </label>
                    <label class="check-field">
                        <input type="checkbox" name="remember" value="1">
                        <span>保持登录状态</span>
                    </label>
                    <button class="button button-primary button-wide" type="submit">
                        <span>进入工作台</span><span aria-hidden="true">↗</span>
                    </button>
                </form>

                <p class="security-note"><span></span>登录受 CSRF、防暴力破解与加密会话保护</p>
            </div>
        </section>
    </main>
</body>
</html>
