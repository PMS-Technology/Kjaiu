@extends('layouts.admin')
@section('title', '系统设置')
@section('eyebrow', 'KJAIU / SETTINGS')
@section('description', '配置站点品牌、邮件发送和用户可选的收款方式。')
@section('content')
<section class="dashboard-grid">
<article class="panel"><header class="panel-head"><div><p class="panel-kicker">SITE IDENTITY</p><h2>站点设置</h2></div></header>
<form method="POST" action="{{ route('admin.settings.site') }}">@csrf @method('PUT')<input type="hidden" name="_form" value="site"><div class="modal-body form-grid">
<label class="field"><span>站点名称</span><input name="site_name" value="{{ old('site_name', $settings->site_name) }}" required maxlength="100"></label>
<label class="field"><span>站点网址</span><input type="url" name="site_url" value="{{ old('site_url', $settings->site_url) }}" required></label>
<label class="field"><span>Logo 地址</span><input type="url" name="logo_url" value="{{ old('logo_url', $settings->logo_url) }}" placeholder="https://..."></label>
<label class="field"><span>Favicon 地址</span><input type="url" name="favicon_url" value="{{ old('favicon_url', $settings->favicon_url) }}" placeholder="https://..."></label>
</div><footer class="modal-foot"><button class="button button-primary">保存站点设置</button></footer></form></article>
<article class="panel"><header class="panel-head"><div><p class="panel-kicker">SMTP</p><h2>邮件配置</h2></div></header>
<form method="POST" action="{{ route('admin.settings.mail') }}">@csrf @method('PUT')<input type="hidden" name="_form" value="mail"><div class="modal-body form-grid">
<label class="field"><span>SMTP 主机</span><input name="host" value="{{ old('host', $mail['host'] ?? '') }}" required></label><label class="field"><span>端口</span><input type="number" name="port" value="{{ old('port', $mail['port'] ?? 587) }}" min="1" max="65535" required></label>
<label class="field"><span>加密方式</span><select name="scheme"><option value="smtp" @selected(($mail['scheme'] ?? '')==='smtp')>STARTTLS / SMTP</option><option value="smtps" @selected(($mail['scheme'] ?? '')==='smtps')>SMTPS</option><option value="none" @selected(blank($mail['scheme'] ?? null))>无</option></select></label><label class="field"><span>SMTP 用户名</span><input name="username" value="{{ old('username', $mail['username'] ?? '') }}"></label>
<label class="field"><span>SMTP 密码 <small>留空保留</small></span><div class="password-field"><input type="password" name="password" autocomplete="new-password"><button type="button" data-password-toggle aria-label="显示密码">查看</button></div></label><label class="field"><span>发件邮箱</span><input type="email" name="from_address" value="{{ old('from_address', $mail['from_address'] ?? '') }}" required></label>
<label class="field field-full"><span>发件名称</span><input name="from_name" value="{{ old('from_name', $mail['from_name'] ?? '') }}" required></label>
</div><footer class="modal-foot"><button class="button button-primary">保存邮件配置</button></footer></form>
<form class="modal-body form-grid" method="POST" action="{{ route('admin.settings.mail.test') }}">@csrf<label class="field"><span>测试收件邮箱</span><input type="email" name="recipient" required></label><button class="button button-secondary" type="submit">发送测试邮件</button></form></article>
</section>
<section class="panel resource-panel"><header class="panel-head"><div><p class="panel-kicker">PAYMENT METHODS</p><h2>收款方式</h2></div><button class="button button-primary" type="button" data-dialog-open="gateway-create">添加方式</button></header><div class="table-scroll"><table class="data-table"><thead><tr><th>标识</th><th>显示名称</th><th>状态</th><th>排序</th><th>操作</th></tr></thead><tbody>@foreach($gateways as $gateway)<tr><td>{{ $gateway->name }}</td><td>{{ $gateway->title }}</td><td>{{ $gateway->is_active?'启用':'停用' }}</td><td>{{ $gateway->sort_order }}</td><td><button class="row-action" type="button" data-dialog-open="gateway-{{ $gateway->id }}">编辑</button></td></tr>@endforeach</tbody></table></div></section>
@foreach($gateways as $gateway)<dialog class="modal" id="gateway-{{ $gateway->id }}"><form method="POST" action="{{ route('admin.settings.gateways.update', $gateway) }}">@csrf @method('PUT') @include('admin.settings.gateway-form', ['gateway' => $gateway])</form></dialog>@endforeach
<dialog class="modal" id="gateway-create"><form method="POST" action="{{ route('admin.settings.gateways.store') }}">@csrf @include('admin.settings.gateway-form', ['gateway' => null])</form></dialog>
@endsection
