<template>
    <div class="page-card">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="API设置" name="api">
                <el-form :model="form" label-width="200px" class="edit-form">
                    <el-form-item label="是否开启资源API">
                        <el-switch v-model="form.allow_resource_api" active-value="1" inactive-value="0" />
                    </el-form-item>

                    <template v-if="form.allow_resource_api === '1'">
                        <el-alert
                            type="info"
                            :closable="false"
                            title="API秘钥获取条件 — 只有满足以下已开启的条件才能获取秘钥"
                            style="margin-bottom: 14px"
                        />
                        <el-form-item label="实名认证">
                            <el-switch v-model="form.allow_resource_api_realname" active-value="1" inactive-value="0" />
                        </el-form-item>
                        <el-form-item label="是否需要绑定手机号">
                            <el-switch v-model="form.allow_resource_api_phone" active-value="1" inactive-value="0" />
                        </el-form-item>
                    </template>

                    <el-form-item>
                        <el-button type="primary" :loading="saving" @click="submitForm">保存更改</el-button>
                        <el-button @click="load">取消更改</el-button>
                    </el-form-item>
                </el-form>

                <h3 class="block-title">下游API用户</h3>
                <DataTable
                    v-model:page="query.page"
                    v-model:limit="query.limit"
                    :rows="users"
                    :total="total"
                    :loading="loading"
                    @pagination="loadUsers"
                >
                    <template #toolbar>
                        <el-input v-model="query.username" placeholder="客户/邮箱" clearable style="width: 190px" @keyup.enter="searchUsers" />
                        <el-select v-model="query.api_open" placeholder="API状态" clearable style="width: 140px" @change="searchUsers">
                            <el-option label="已开启" :value="1" />
                            <el-option label="未开启" :value="0" />
                        </el-select>
                        <span class="spacer" />
                        <el-button type="primary" @click="searchUsers">搜索</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column label="客户" min-width="150">
                        <template #default="{ row }">
                            <el-link type="primary" @click="$router.push(`/customers/${row.id}`)">{{ row.username }}</el-link>
                        </template>
                    </el-table-column>
                    <el-table-column prop="email" label="邮箱" min-width="180" />
                    <el-table-column label="API状态" width="120" align="center">
                        <template #default="{ row }">
                            <el-tag :type="Number(row.api_open) === 1 ? 'success' : 'info'" size="small">
                                {{ Number(row.api_open) === 1 ? '已开启' : '未开启' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="开启时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.api_create_time) }}</template>
                    </el-table-column>
                    <el-table-column prop="lock_reason" label="锁定原因" min-width="160" show-overflow-tooltip />
                    <el-table-column label="操作" width="290" fixed="right">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="toggleApi(row)">
                                {{ Number(row.api_open) === 1 ? '禁用' : '开启' }}
                            </el-button>
                            <el-button link type="primary" size="small" @click="openFreeLogin(row)">免登录页</el-button>
                            <el-button link type="warning" size="small" @click="resetKey(row)">重置密钥</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane label="免登录页面" name="free">
                <el-form :model="freeForm" label-width="160px" class="edit-form">
                    <el-form-item label="客户">
                        <el-autocomplete
                            v-model="freeForm.username"
                            :fetch-suggestions="fetchClients"
                            style="width: 320px"
                            @select="onClientSelect"
                        />
                    </el-form-item>
                    <el-form-item label="允许的IP">
                        <el-input v-model="freeForm.ip" type="textarea" :rows="3" placeholder="每行一个 IP，留空表示不限制" />
                    </el-form-item>
                    <el-form-item label="白名单域名">
                        <el-input v-model="freeForm.domain" type="textarea" :rows="3" placeholder="每行一个域名" />
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" :loading="saving" @click="saveFreePage">保存</el-button>
                        <el-button type="danger" @click="removeFreePage">删除规则</el-button>
                    </el-form-item>
                </el-form>
            </el-tab-pane>
        </el-tabs>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatTime, pickList } from '../utils/format';

const activeTab = ref('api');
const loading = ref(false);
const saving = ref(false);
const users = ref([]);
const total = ref(0);

const form = reactive({
    allow_resource_api: '0',
    allow_resource_api_realname: '0',
    allow_resource_api_phone: '0',
});

const freeForm = reactive({ uid: '', username: '', ip: '', domain: '' });

const query = reactive({ page: 1, limit: 20, username: '', api_open: '' });

const load = async () => {
    try {
        const response = await client.get('config_general/apiconfig');
        const data = response.data || {};

        form.allow_resource_api = String(data.allow_resource_api ?? '0');
        form.allow_resource_api_realname = String(data.allow_resource_api_realname ?? '0');
        form.allow_resource_api_phone = String(data.allow_resource_api_phone ?? '0');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitForm = async () => {
    saving.value = true;

    try {
        await client.post('config_general/apiconfig', { ...form });
        ElMessage.success('保存成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const loadUsers = async () => {
    loading.value = true;

    try {
        const response = await client.get('client_list_resource', { params: { ...query } });
        const payload = pickList(response.data);

        users.value = payload.list;
        total.value = payload.total;
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const searchUsers = () => {
    query.page = 1;
    loadUsers();
};

const toggleApi = async (row) => {
    const target = Number(row.api_open) === 1 ? 0 : 1;

    try {
        await client.post('zjmf_finance_api/toggle', { uid: row.id, api_open: target });
        ElMessage.success('操作成功');
        loadUsers();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const resetKey = async (row) => {
    try {
        await ElMessageBox.confirm('重置后该客户的旧密钥立即失效，确定继续？', '重置确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('zjmf_finance_api/reset', { uid: row.id });
        ElMessage.success('重置成功');
        loadUsers();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openFreeLogin = async (row) => {
    activeTab.value = 'free';
    freeForm.uid = row.id;
    freeForm.username = row.username;

    try {
        const response = await client.get('zjmf_finance_api/freepage', { params: { uid: row.id } });
        const data = response.data || {};
        freeForm.ip = Array.isArray(data.ip) ? data.ip.join('\n') : data.ip || '';
        freeForm.domain = Array.isArray(data.domain) ? data.domain.join('\n') : data.domain || '';
    } catch {
        freeForm.ip = '';
        freeForm.domain = '';
    }
};

const onClientSelect = (item) => {
    freeForm.uid = item.id;
};

const fetchClients = async (keyword, callback) => {
    if (!keyword) {
        callback([]);
        return;
    }

    try {
        const response = await client.get('order/getclients', { params: { username: keyword } });
        callback((response.data || []).map((item) => ({ value: item.username, ...item })));
    } catch {
        callback([]);
    }
};

const saveFreePage = async () => {
    saving.value = true;

    try {
        await client.post('zjmf_finance_api/freepage', { ...freeForm });
        ElMessage.success('保存成功');
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeFreePage = async () => {
    try {
        await client.delete('zjmf_finance_api/freepage', { params: { uid: freeForm.uid } });
        ElMessage.success('已删除');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(() => {
    load();
    loadUsers();
});
</script>

<style scoped>
.edit-form {
    max-width: 760px;
    margin-top: 8px;
}

.block-title {
    font-size: 14px;
    margin: 20px 0 10px;
}
</style>
