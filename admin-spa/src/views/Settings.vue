<template>
    <div class="page-card" v-loading="loading">
        <el-tabs v-model="activeTab" @tab-change="loadTab">
            <el-tab-pane label="常规" name="general" />
            <el-tab-pane label="财务" name="finance" />
            <el-tab-pane label="发票" name="invoice" />
            <el-tab-pane label="充值" name="recharge" />
            <el-tab-pane label="推介" name="affiliate" />
            <el-tab-pane label="安全" name="safe" />
            <el-tab-pane label="其他" name="other" />
        </el-tabs>

        <el-form :model="model" label-width="220px" class="edit-form">
            <template v-for="field in fields" :key="field.key">
                <el-form-item :label="field.label">
                    <el-input
                        v-if="field.type === 'text'"
                        v-model="model[field.key]"
                        :placeholder="field.placeholder || ''"
                        :maxlength="field.maxlength || undefined"
                    />
                    <el-input
                        v-else-if="field.type === 'textarea'"
                        v-model="model[field.key]"
                        type="textarea"
                        :rows="3"
                    />
                    <el-input-number
                        v-else-if="field.type === 'number'"
                        v-model="model[field.key]"
                        :min="field.min ?? 0"
                        :max="field.max ?? 999999"
                        :controls="false"
                    />
                    <el-switch
                        v-else-if="field.type === 'switch'"
                        v-model="model[field.key]"
                        :active-value="field.active ?? 1"
                        :inactive-value="field.inactive ?? 0"
                    />
                    <el-select v-else-if="field.type === 'select'" v-model="model[field.key]" clearable>
                        <el-option v-for="option in field.options" :key="option.value" :label="option.label" :value="option.value" />
                    </el-select>
                    <el-radio-group v-else-if="field.type === 'radio'" v-model="model[field.key]">
                        <el-radio v-for="option in field.options" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </el-radio>
                    </el-radio-group>
                </el-form-item>
            </template>

            <el-form-item>
                <el-button type="primary" :loading="saving" @click="save">保存设置</el-button>
                <el-button @click="loadTab(activeTab)">取消更改</el-button>
            </el-form-item>
        </el-form>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';

import client from '../api/client';

const activeTab = ref('general');
const loading = ref(false);
const saving = ref(false);

const model = reactive({});
const fields = ref([]);

// Each tab maps onto the `config_general/<action>` pair the platform exposes.
// Field lists follow the keys documented for the original screen.
const tabDefinitions = {
    general: {
        get: 'config_general/general',
        post: 'config_general/general',
        fields: [
            { key: 'company_name', label: '品牌名', type: 'text' },
            { key: 'domain', label: '系统链接', type: 'text' },
            { key: 'system_url', label: '网站域名', type: 'text' },
            { key: 'main_phone', label: '手机', type: 'text' },
            { key: 'company_qq', label: 'QQ', type: 'text' },
            { key: 'main_address', label: '地址', type: 'text' },
            { key: 'record_no', label: '备案号', type: 'text' },
            { key: 'seo_keywords', label: '关键字', type: 'text' },
            { key: 'seo_desc', label: '描述', type: 'textarea' },
            { key: 'company_profile', label: '公司简介', type: 'textarea' },
            { key: 'per_page_limit', label: '每页显示记录', type: 'number' },
            { key: 'allow_custom_clients_id', label: '自定义起始客户ID', type: 'switch' },
            { key: 'custom_clients_id_start', label: '客户ID前缀', type: 'text', maxlength: 10 },
            { key: 'cancellation_time', label: '管理员登录时长(天)', type: 'number' },
            { key: 'home_ip_check', label: '前台登录IP检查', type: 'switch' },
            { key: 'admin_ip_check', label: '后台登录IP检查', type: 'switch' },
            { key: 'main_tenance_mode', label: '维护模式', type: 'switch' },
            { key: 'main_tenance_mode_message', label: '维护模式信息', type: 'textarea' },
            { key: 'main_tenance_mode_url', label: '维护模式重定向的链接', type: 'text' },
            { key: 'shd_debug_model', label: 'Debug调试（将给予厂商管理员权限）', type: 'switch', active: 1, inactive: 0 },
        ],
    },
    finance: {
        get: 'config_general/recharge',
        post: 'config_general/recharge',
        fields: [
            { key: 'credit_limit', label: '前台信用额', type: 'switch' },
            { key: 'default_currency', label: '默认货币', type: 'text' },
            { key: 'recharge_min', label: '最小充值金额', type: 'number' },
            { key: 'recharge_max', label: '最大充值金额', type: 'number' },
            { key: 'invoice_pay_days', label: '账单支付期限(天)', type: 'number' },
            { key: 'allow_recharge', label: '允许客户充值', type: 'switch' },
        ],
    },
    invoice: {
        get: 'config_general/invoice',
        post: 'config_general/invoice',
        fields: [
            { key: 'invoice_prefix', label: '账单号前缀', type: 'text' },
            { key: 'invoice_number', label: '账单号起始值', type: 'number' },
            { key: 'tax_enabled', label: '启用税费', type: 'switch' },
            { key: 'tax_rate', label: '税率(%)', type: 'number' },
            { key: 'invoice_notes', label: '账单备注', type: 'textarea' },
            { key: 'send_invoice_email', label: '账单生成时发送邮件', type: 'switch' },
        ],
    },
    recharge: {
        get: 'config_general/recharge',
        post: 'config_general/recharge',
        fields: [
            { key: 'recharge_prefix', label: '充值账单前缀', type: 'text' },
            { key: 'auto_recharge_amount', label: '自动充值金额', type: 'number' },
            { key: 'recharge_credit_limit', label: '充值计入信用额', type: 'switch' },
            { key: 'recharge_notice', label: '充值说明', type: 'textarea' },
        ],
    },
    affiliate: {
        get: 'config_general/affiliate',
        post: 'config_general/affiliate',
        fields: [
            { key: 'affiliate_enabled', label: '开启推介计划', type: 'switch' },
            { key: 'affiliate_bates', label: '推介比例(%)', type: 'number' },
            { key: 'affiliate_type', label: '推介比例类型', type: 'select', options: [{ label: '按百分比', value: 1 }, { label: '固定金额', value: 2 }] },
            { key: 'affiliate_cookie', label: 'cookie 有效期(天)', type: 'number' },
            { key: 'affiliate_invited', label: '邀请奖励', type: 'number' },
            { key: 'affiliate_invited_type', label: '邀请奖励类型', type: 'select', options: [{ label: '按百分比', value: 1 }, { label: '固定金额', value: 2 }] },
            { key: 'affiliate_invited_money', label: '邀请奖励金额', type: 'number' },
        ],
    },
    safe: {
        get: 'config_general/safe',
        post: 'config_general/safe',
        fields: [
            { key: 'second_verify', label: '二次验证', type: 'switch' },
            { key: 'second_verify_admin', label: '后台开启二次验证', type: 'switch' },
            { key: 'second_verify_home', label: '会员中心开启二次验证', type: 'switch' },
            { key: 'login_error_switch', label: '登录失败限制', type: 'switch' },
            { key: 'login_error_max_num', label: '最大登录失败次数', type: 'number' },
            { key: 'is_captcha', label: '启用图形验证码', type: 'switch' },
            { key: 'captcha_length', label: '验证码长度', type: 'number' },
        ],
    },
    other: {
        get: 'config_general/other',
        post: 'config_general/other',
        fields: [
            { key: 'language', label: '默认语言', type: 'text' },
            { key: 'allow_user_language', label: '启用语言选择菜单', type: 'switch' },
            { key: 'clientarea_default_themes', label: '会员中心默认主题', type: 'text' },
            { key: 'shd_allow_sms_send', label: '允许发送短信', type: 'switch' },
            { key: 'shd_allow_email_send', label: '允许发送邮件', type: 'switch' },
        ],
    },
};

const loadTab = async (name) => {
    const definition = tabDefinitions[name];

    if (!definition) {
        return;
    }

    loading.value = true;
    fields.value = definition.fields;

    try {
        const response = await client.get(definition.get);
        const data = response.data || {};

        Object.keys(model).forEach((key) => delete model[key]);

        definition.fields.forEach((field) => {
            const value = data[field.key];

            if (field.type === 'number') {
                model[field.key] = value === undefined || value === null || value === '' ? 0 : Number(value);
            } else if (field.type === 'switch') {
                model[field.key] = Number(value ?? (field.inactive ?? 0));
            } else {
                model[field.key] = value === undefined || value === null ? '' : value;
            }
        });
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const save = async () => {
    const definition = tabDefinitions[activeTab.value];

    saving.value = true;

    try {
        await client.post(definition.post, { ...model });
        ElMessage.success('保存成功');
        loadTab(activeTab.value);
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(() => loadTab('general'));
</script>

<style scoped>
.edit-form {
    max-width: 860px;
    margin-top: 8px;
}
</style>
