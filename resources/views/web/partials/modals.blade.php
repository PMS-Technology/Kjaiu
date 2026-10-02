@php
    // Shared confirmation / second-verification modal plumbing. The original
    // defines these in includes/modal.tpl + assets/js/modal.js and injects a
    // small set of globals the scripts read.
    $secondVerifyAllowed = (bool) ($Userinfo['allow_second_verify'] ?? false);
    $secondVerifyEnabled = (int) ($Userinfo['user']['second_verify'] ?? 0) === 1;
@endphp

<script>
    window.Userinfo_allow_second_verify = {{ $secondVerifyAllowed ? 'true' : 'false' }};
    window.Userinfo_user_second_verify = {{ $secondVerifyEnabled ? 'true' : 'false' }};
    window.Userinfo_second_verify_action_home = @json(\App\Support\Settings::secondVerifyActions());
</script>

{{-- Generic confirm modal --}}
<div id="kjConfirmModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
    <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
        <h3 id="kjConfirmTitle" class="mb-2 text-base font-semibold text-slate-900">操作确认</h3>
        <p id="kjConfirmBody" class="mb-5 text-sm text-slate-600">确定要执行该操作吗？</p>
        <div class="flex justify-end gap-2">
            <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
            <button type="button" id="kjConfirmOk" class="btn-primary btn-sm">确定</button>
        </div>
    </div>
</div>

{{-- 二次验证 modal --}}
@if ($secondVerifyAllowed && $Userinfo)
    <div id="secondVerifyModal" data-modal class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
        <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
            <h3 class="mb-1 text-base font-semibold text-slate-900">二次验证</h3>
            <p class="mb-4 text-xs text-slate-500">为保障账号安全，请完成验证码校验。</p>

            <div class="space-y-3">
                <div>
                    <label class="form-label" for="secondVerifyType">验证方式</label>
                    <select id="secondVerifyType" class="form-select"></select>
                </div>
                <div>
                    <label class="form-label" for="secondVerifyCode">验证码</label>
                    <div class="flex gap-2">
                        <input type="text" id="secondVerifyCode" class="form-input" autocomplete="one-time-code" maxlength="6">
                        <button type="button" id="secondVerifySend" class="btn-secondary btn-sm whitespace-nowrap">发送验证码</button>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" data-modal-close class="btn-secondary btn-sm">取消</button>
                <button type="button" id="secondVerifySubmit" class="btn-primary btn-sm">确定</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('secondVerifyModal');
            const typeSelect = document.getElementById('secondVerifyType');
            const codeInput = document.getElementById('secondVerifyCode');
            const sendButton = document.getElementById('secondVerifySend');
            let pending = null;

            async function loadTypes(action) {
                const url = action === 'login' ? '/login/second_verify_page' : '/second_verify_page';

                try {
                    const data = await window.Kj.get(url, { action: action });
                    const types = data.allow_type || [];

                    typeSelect.innerHTML = types
                        .map((item) => `<option value="${item.name}">${item.name_zh}（${item.account}）</option>`)
                        .join('');

                    return types.length > 0;
                } catch (error) {
                    window.Kj.toastError(error.message);
                    return false;
                }
            }

            // Any AJAX handler can ask for a verification code by dispatching
            // this event with the action name it needs cleared.
            window.addEventListener('kj:second-verify', async (event) => {
                pending = event.detail || {};
                const action = pending.action || '';

                if (!(await loadTypes(action))) {
                    return;
                }

                codeInput.value = '';
                window.Kj.openModal('secondVerifyModal');
            });

            sendButton?.addEventListener('click', async () => {
                const action = (pending && pending.action) || '';
                const url = action === 'login' ? '/login/second_verify_send' : '/second_verify_send';

                try {
                    await window.Kj.post(url, { action: action, type: typeSelect.value });
                    window.Kj.toastSuccess('验证码已发送');
                    window.Kj.startCountdown(sendButton);
                } catch (error) {
                    window.Kj.toastError(error.message);
                }
            });

            document.getElementById('secondVerifySubmit')?.addEventListener('click', async () => {
                const code = codeInput.value.trim();

                if (code === '') {
                    window.Kj.toastError('请输入验证码');
                    return;
                }

                if (pending && typeof pending.onVerified === 'function') {
                    window.Kj.closeModal('secondVerifyModal');
                    pending.onVerified({ code: code, code_type: typeSelect.value });
                    return;
                }

                // No continuation supplied: attach the code to the originating
                // form and submit it, which is how the original does it.
                if (pending && pending.form) {
                    const form = pending.form;

                    const inject = (name, value) => {
                        let input = form.querySelector(`input[name="${name}"]`);

                        if (!input) {
                            input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = name;
                            form.appendChild(input);
                        }

                        input.value = value;
                    };

                    inject('code', code);
                    inject('code_type', typeSelect.value);
                    window.Kj.closeModal('secondVerifyModal');
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            });
        })();
    </script>
@endif
