{{-- New password + confirmation, encrypted client side before submit. --}}
<div>
    <label class="form-label" for="newPassword-{{ $prefix }}">新密码</label>
    <input type="password" id="newPassword-{{ $prefix }}" name="password" class="form-input" data-encrypt required
           placeholder="至少 6 位字符" autocomplete="new-password">
</div>

<div>
    <label class="form-label" for="newPasswordConfirm-{{ $prefix }}">确认新密码</label>
    <input type="password" id="newPasswordConfirm-{{ $prefix }}" name="checkPassword" class="form-input" data-encrypt required
           placeholder="请再次输入新密码" autocomplete="new-password">
</div>
