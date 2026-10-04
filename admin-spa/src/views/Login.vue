<template>
    <div class="login-page">
        <div class="login-card">
            <div class="login-brand">
                <span class="brand-mark">K</span>
                <div>
                    <h1>Kjaiu 管理后台</h1>
                    <p>请使用管理员账号登录</p>
                </div>
            </div>

            <el-form ref="formRef" :model="form" :rules="rules" @submit.prevent="submit">
                <el-form-item prop="username">
                    <el-input v-model="form.username" size="large" placeholder="用户名" autocomplete="username" />
                </el-form-item>
                <el-form-item prop="password">
                    <el-input
                        v-model="form.password"
                        type="password"
                        size="large"
                        show-password
                        placeholder="密码"
                        autocomplete="current-password"
                        @keyup.enter="submit"
                    />
                </el-form-item>

                <el-form-item v-if="captchaEnabled" prop="captcha">
                    <div class="captcha-row">
                        <el-input v-model="form.captcha" placeholder="图形验证码" @keyup.enter="submit" />
                        <img v-if="captchaImage" :src="captchaImage" class="captcha-img" alt="验证码" @click="loadCaptcha" />
                    </div>
                </el-form-item>

                <el-button type="primary" size="large" class="submit" :loading="loading" @click="submit">
                    登录
                </el-button>
            </el-form>

            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';

import client from '../api/client';

const router = useRouter();
const formRef = ref(null);
const loading = ref(false);
const error = ref('');
const captchaEnabled = ref(false);
const captchaImage = ref('');

const form = reactive({ username: '', password: '', captcha: '' });

const rules = {
    username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
};

// The captcha endpoint answers with PNG bytes when the toggle is on and with a
// JSON 400 envelope when it is off — the SPA sniffs the body, exactly as the
// original does, so the setting needs no second round-trip.
const CAPTCHA_NAME = 'allow_login_admin_captcha';

const loadCaptcha = async () => {
    try {
        const response = await client.get('verify', {
            params: { name: CAPTCHA_NAME },
            responseType: 'arraybuffer',
        });

        const bytes = new Uint8Array(response);

        // A disabled captcha arrives as the JSON envelope, not an image.
        const head = new TextDecoder().decode(bytes.slice(0, 16));

        if (head.includes('400')) {
            captchaEnabled.value = false;
            captchaImage.value = '';

            return;
        }

        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });

        captchaImage.value = `data:image/png;base64,${btoa(binary)}`;
    } catch {
        captchaEnabled.value = false;
        captchaImage.value = '';
    }
};

const submit = async () => {
    error.value = '';

    const valid = await formRef.value.validate().catch(() => false);
    if (!valid) return;

    loading.value = true;

    try {
        await client.post('login', {
            username: form.username,
            password: form.password,
            captcha: form.captcha,
        });

        ElMessage.success('登录成功');
        router.push('/dashboard');
    } catch (e) {
        error.value = e.message;
        if (captchaEnabled.value) loadCaptcha();
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    try {
        const response = await client.get('login_page');
        captchaEnabled.value = Boolean(Number(response.data.is_captcha || 0));
        if (captchaEnabled.value) loadCaptcha();
    } catch {
        // Login still works when the page-config call is unavailable.
    }
});
</script>

<style scoped>
.login-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 55%, #3b82f6 100%);
}

.login-card {
    width: 380px;
    background: #fff;
    border-radius: 12px;
    padding: 32px 30px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.25);
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

.brand-mark {
    display: inline-flex;
    width: 42px;
    height: 42px;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #2563eb;
    color: #fff;
    font-size: 20px;
    font-weight: 700;
}

.login-brand h1 {
    font-size: 17px;
    margin: 0;
}

.login-brand p {
    margin: 2px 0 0;
    font-size: 12px;
    color: #6b7280;
}

.captcha-row {
    display: flex;
    gap: 10px;
    width: 100%;
}

.captcha-img {
    height: 40px;
    border-radius: 4px;
    cursor: pointer;
    border: 1px solid #e5e7eb;
}

.submit {
    width: 100%;
}

.error {
    color: #dc2626;
    font-size: 13px;
    margin: 10px 0 0;
    text-align: center;
}
</style>
