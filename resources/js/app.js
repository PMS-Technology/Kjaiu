/**
 * Client-area behaviour.
 *
 * The original ships jQuery, Bootstrap, CryptoJS and a handful of page scripts;
 * this module reproduces only the parts the pages actually depend on:
 *
 *  - AES-128-CBC password encryption before submit (key `idcsmart.finance`,
 *    IV `9311019310287172`, base64 output) via WebCrypto — see
 *    `PasswordHasher::acceptedPlain()` on the server, which accepts either
 *    encrypted or plain input;
 *  - the `{ status, msg, data }` envelope check used by every AJAX call;
 *  - graphic-captcha refresh and the 60-second verification-code countdown;
 *  - confirmation and second-verification modals;
 *  - toast feedback.
 */
const AES_KEY = 'idcsmart.finance';
const AES_IV = '9311019310287172';

/**
 * Encrypt a password exactly the way the server expects to decrypt it:
 * AES-128-CBC, PKCS7 padding, base64 output.
 *
 * CryptoJS is not a dependency of this application, so the browser's own
 * WebCrypto implementation is used — the wire format is identical.
 */
export async function encryptPassword(value) {
    if (!value) {
        return value;
    }

    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey(
        'raw',
        encoder.encode(AES_KEY),
        { name: 'AES-CBC' },
        false,
        ['encrypt'],
    );

    const encrypted = await crypto.subtle.encrypt(
        { name: 'AES-CBC', iv: encoder.encode(AES_IV) },
        key,
        encoder.encode(value),
    );

    return btoa(String.fromCharCode(...new Uint8Array(encrypted)));
}

/**
 * Mark every [data-encrypt] input's value before the form leaves the browser.
 */
async function encryptFormPasswords(form) {
    await Promise.all([...form.querySelectorAll('[data-encrypt]')].map(async (input) => {
        input.value = await encryptPassword(input.value);
    }));
}

// ---------------------------------------------------------------------------
// Toasts
// ---------------------------------------------------------------------------

function toastStack() {
    let stack = document.getElementById('kj-toast-stack');

    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'kj-toast-stack';
        document.body.appendChild(stack);
    }

    return stack;
}

export function toast(message, type = 'info', timeout = 3200) {
    if (!message) {
        return;
    }

    const node = document.createElement('div');
    node.className = `kj-toast kj-toast-${type}`;
    node.textContent = message;
    toastStack().appendChild(node);

    window.setTimeout(() => node.remove(), timeout);
}

export const toastSuccess = (message) => toast(message, 'success');
export const toastError = (message) => toast(message, 'error');

// ---------------------------------------------------------------------------
// AJAX helper
// ---------------------------------------------------------------------------

/**
 * POST a form (or plain object) and normalise the platform envelope.
 *
 * Resolves with `data` on `status === 200`, rejects with an Error carrying
 * `status` and `data` otherwise — including the `1001` soft-success and `1002`
 * second-verification prompts, which callers handle themselves.
 */
export async function post(url, payload = {}, options = {}) {
    const body = payload instanceof FormData ? payload : toFormData(payload);

    const response = await fetch(url, {
        method: options.method || 'POST',
        body,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(options.headers || {}),
        },
        credentials: 'same-origin',
    });

    const contentType = response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        const text = await response.text();

        if (!response.ok) {
            throw Object.assign(new Error('请求失败'), { status: response.status, html: text });
        }

        return text;
    }

    const json = await response.json();

    if (Number(json.status) === 200) {
        return json.data;
    }

    throw Object.assign(new Error(json.msg || '操作失败'), {
        status: Number(json.status),
        data: json.data,
        payload: json,
    });
}

/**
 * GET variant of `post`, for the endpoints the original reads with `$.get`.
 */
export async function get(url, params = {}) {
    const query = new URLSearchParams(flatten(params)).toString();
    const target = query ? `${url}${url.includes('?') ? '&' : '?'}${query}` : url;

    const response = await fetch(target, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        credentials: 'same-origin',
    });

    const json = await response.json();

    if (Number(json.status) === 200) {
        return json.data;
    }

    throw Object.assign(new Error(json.msg || '操作失败'), { status: Number(json.status), data: json.data });
}

export function toFormData(source, form = new FormData(), prefix = '') {
    if (source instanceof FormData) {
        return source;
    }

    Object.entries(source || {}).forEach(([key, value]) => {
        const name = prefix ? `${prefix}[${key}]` : key;

        if (value === null || value === undefined) {
            return;
        }

        if (value instanceof File || value instanceof Blob) {
            form.append(name, value);
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                if (item !== null && typeof item === 'object') {
                    toFormData(item, form, `${name}[${index}]`);
                } else {
                    form.append(`${name}[${index}]`, item);
                }
            });
            return;
        }

        if (typeof value === 'object') {
            toFormData(value, form, name);
            return;
        }

        form.append(name, value === true ? '1' : value === false ? '0' : value);
    });

    return form;
}

function flatten(source, form = {}, prefix = '') {
    Object.entries(source || {}).forEach(([key, value]) => {
        const name = prefix ? `${prefix}[${key}]` : key;

        if (value === null || value === undefined) {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item, index) => {
                form[`${name}[${index}]`] = item;
            });
            return;
        }

        if (typeof value === 'object') {
            flatten(value, form, name);
            return;
        }

        form[name] = value;
    });

    return form;
}

// ---------------------------------------------------------------------------
// Captcha + verification codes
// ---------------------------------------------------------------------------

/**
 * Point a captcha <img> at a fresh code. The endpoint returns image bytes.
 */
export function refreshCaptcha(img, name = 'default') {
    if (!img) {
        return;
    }

    img.src = `/verify?name=${encodeURIComponent(name)}&t=${Date.now()}`;
}

/**
 * Start a 60-second countdown on a "send code" button.
 */
export function startCountdown(button, seconds = 60) {
    if (!button) {
        return;
    }

    const original = button.dataset.label || button.textContent;
    button.dataset.label = original;
    button.disabled = true;
    let remaining = seconds;
    button.textContent = `${remaining}s`;

    const timer = window.setInterval(() => {
        remaining -= 1;

        if (remaining <= 0) {
            window.clearInterval(timer);
            button.disabled = false;
            button.textContent = original;
            return;
        }

        button.textContent = `${remaining}s`;
    }, 1000);
}

/**
 * Send a verification code and start the button countdown.
 *
 * `url` is one of the original's sender endpoints (`register_email_send`,
 * `login_send`, `bind_phone`, ...).
 */
export async function sendCode(button, url, payload = {}) {
    try {
        await post(url, payload);
        toastSuccess('验证码已发送');
        startCountdown(button);
        return true;
    } catch (error) {
        toastError(error.message);
        refreshCaptcha(document.querySelector(`[data-captcha-for="${payload.for || 'default'}"]`));
        return false;
    }
}

// ---------------------------------------------------------------------------
// Modals
// ---------------------------------------------------------------------------

export function openModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

export function closeModal(id) {
    const modal = typeof id === 'string' ? document.getElementById(id) : id;

    if (!modal) {
        return;
    }

    modal.classList.add('hidden');
    modal.classList.remove('flex');

    if (!document.querySelector('[data-modal]:not(.hidden)')) {
        document.body.classList.remove('overflow-hidden');
    }
}

/**
 * Confirmation prompt used by every destructive action.
 */
export function confirmAction(message, onConfirm) {
    if (window.confirm(message)) {
        onConfirm();
    }
}

// ---------------------------------------------------------------------------
// Form plumbing
// ---------------------------------------------------------------------------

/**
 * Intercept `[data-ajax-form]` submissions and post them through `post()`.
 *
 * The handler reads `data-success-url` for the redirect target and
 * `data-reload` to refresh in place.
 */
function bindAjaxForms() {
    document.querySelectorAll('[data-ajax-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submit = form.querySelector('[type="submit"]');
            const original = submit ? submit.textContent : '';

            if (submit) {
                submit.disabled = true;
                submit.textContent = '处理中…';
            }

            if (form.dataset.encrypt === 'true') {
                await encryptFormPasswords(form);
            }

            const payload = new FormData(form);

            try {
                const data = await post(form.action || window.location.href, payload);

                if (data && data.url) {
                    window.location.href = data.url;
                    return;
                }

                if (form.dataset.reload === 'true') {
                    window.location.reload();
                    return;
                }

                toastSuccess('操作成功');

                if (form.dataset.successUrl) {
                    window.location.href = form.dataset.successUrl;
                }
            } catch (error) {
                if (Number(error.status) === 1002) {
                    window.dispatchEvent(new CustomEvent('kj:second-verify', {
                        detail: { action: form.dataset.secondVerify || '', form },
                    }));
                } else if (Number(error.status) === 401) {
                    toastError('请先登录');
                    window.setTimeout(() => { window.location.href = '/login'; }, 800);
                } else {
                    toastError(error.message);
                }
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            }
        });
    });
}

/**
 * Encrypt passwords on ordinary (non-AJAX) form submissions.
 */
function bindPasswordEncryption() {
    document.querySelectorAll('form[data-encrypt="true"]:not([data-ajax-form])').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.encrypted === 'true') {
                return;
            }

            // Encrypt once, then re-submit natively so the browser performs the
            // normal navigation.
            event.preventDefault();
            await encryptFormPasswords(form);
            form.dataset.encrypted = 'true';
            form.submit();
        });
    });

    // The inline register form on the cart page toggles between modes.
    document.querySelectorAll('[data-toggle-group]').forEach((toggle) => {
        const name = toggle.dataset.toggleGroup;

        toggle.addEventListener('change', () => {
            document.querySelectorAll(`[data-toggle-target="${name}"]`).forEach((node) => {
                node.classList.toggle('hidden', node.dataset.toggleValue !== toggle.value);
            });
        });
    });
}

/**
 * Click-to-refresh captchas.
 */
function bindCaptchaRefresh() {
    document.querySelectorAll('[data-captcha-refresh]').forEach((button) => {
        button.addEventListener('click', () => {
            const img = document.querySelector(`[data-captcha-for="${button.dataset.captchaRefresh}"]`);
            refreshCaptcha(img, button.dataset.captchaRefresh);
        });
    });

    document.querySelectorAll('[data-captcha-for]').forEach((img) => {
        refreshCaptcha(img, img.dataset.captchaFor);
    });
}

/**
 * Copy-to-clipboard buttons, which the API and IP cells use.
 */
function bindCopy() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.dataset.copy;

            try {
                await navigator.clipboard.writeText(value);
                toastSuccess('已复制');
            } catch {
                window.prompt('请手动复制', value);
            }
        });
    });
}

/**
 * Disclosure toggles for password fields and sidebar groups.
 */
function bindToggles() {
    document.querySelectorAll('[data-reveal]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.querySelector(button.dataset.reveal);

            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });

    document.querySelectorAll('[data-collapse]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.querySelector(button.dataset.collapse);

            if (target) {
                target.classList.toggle('hidden');
                button.setAttribute('aria-expanded', target.classList.contains('hidden') ? 'false' : 'true');
            }
        });
    });
}

/**
 * Mark the current sidebar entry, including the child pages that keep their
 * parent highlighted (the original's `hidden_url` map).
 */
function highlightNav() {
    const path = window.location.pathname.replace(/^\/+|\/+$/g, '');

    document.querySelectorAll('.nav-link, .nav-sub-link, .nav-group-link').forEach((link) => {
        const url = (link.getAttribute('href') || '').split('?')[0].replace(/^\/+|\/+$/g, '');
        const hidden = (link.dataset.hiddenUrl || '').split(',').filter(Boolean);

        if (url !== '' && (url === path || hidden.includes(path))) {
            link.classList.add('nav-link-active');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindAjaxForms();
    bindPasswordEncryption();
    bindCaptchaRefresh();
    bindCopy();
    bindToggles();
    highlightNav();

    document.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => closeModal(button.closest('[data-modal]')));
    });

    document.querySelectorAll('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => openModal(button.dataset.modalOpen));
    });

    document.querySelectorAll('[data-confirm]').forEach((node) => {
        node.addEventListener('click', (event) => {
            if (!window.confirm(node.dataset.confirm)) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
        });
    });

    // Flash messages rendered by the layout become toasts.
    const flash = document.getElementById('kj-flash');

    if (flash) {
        flash.querySelectorAll('[data-flash]').forEach((node) => {
            toast(node.textContent.trim(), node.dataset.flash, 4000);
        });
    }
});

window.Kj = {
    encryptPassword,
    post,
    get,
    toast,
    toastSuccess,
    toastError,
    openModal,
    closeModal,
    sendCode,
    refreshCaptcha,
    startCountdown,
};
