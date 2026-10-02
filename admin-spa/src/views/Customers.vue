<template>
    <div class="page-card">
        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
        >
            <template #toolbar>
                <el-input
                    v-model="query.username"
                    placeholder="姓名/邮箱/手机"
                    clearable
                    style="width: 190px"
                    @keyup.enter="search"
                />
                <el-input v-model="query.email" placeholder="邮箱" clearable style="width: 170px" @keyup.enter="search" />
                <el-input
                    v-model="query.phonenumber"
                    placeholder="手机号"
                    clearable
                    style="width: 150px"
                    @keyup.enter="search"
                />
                <el-select v-model="query.groupid" placeholder="客户分组" clearable style="width: 150px" @change="search">
                    <el-option v-for="group in clientGroups" :key="group.id" :label="group.group_name" :value="group.id" />
                </el-select>
                <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="search">
                    <el-option label="正常" :value="1" />
                    <el-option label="禁用" :value="0" />
                </el-select>
                <el-select v-model="query.sale" placeholder="销售" clearable filterable style="width: 140px" @change="search">
                    <el-option v-for="sale in sales" :key="sale.id" :label="sale.user_nickname" :value="sale.id" />
                </el-select>
                <el-date-picker
                    v-model="createdRange"
                    type="datetimerange"
                    value-format="x"
                    start-placeholder="注册开始"
                    end-placeholder="注册结束"
                    style="width: 340px"
                    @change="search"
                />

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button type="success" @click="openCreate">添加客户</el-button>
            </template>

            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column label="姓名" min-width="140">
                <template #default="{ row }">
                    <el-link type="primary" @click="openDetail(row)">{{ row.username }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="phonenumber" label="手机号/邮箱" min-width="180">
                <template #default="{ row }">{{ row.phonenumber || row.email }}</template>
            </el-table-column>
            <el-table-column prop="host_total" label="服务" width="80" align="center" />
            <el-table-column label="收入/支出" width="140" align="right">
                <template #default="{ row }">
                    <el-tag type="success" size="small" effect="plain">+{{ formatMoney(row.amount_in) }}</el-tag>
                    <el-tag type="danger" size="small" effect="plain">-{{ formatMoney(row.amount_out) }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="credit" label="余额" width="110" align="right">
                <template #default="{ row }">{{ formatMoney(row.credit) }}</template>
            </el-table-column>
            <el-table-column prop="group_name" label="客户分组" width="135" align="center" />
            <el-table-column label="状态" width="85" align="center">
                <template #default="{ row }">
                    <el-tag :type="Number(row.status) === 1 ? 'success' : 'danger'" size="small">
                        {{ Number(row.status) === 1 ? '正常' : '禁用' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="user_nickname" label="销售" width="110" />
            <el-table-column label="信用额（已用/总计）" width="180">
                <template #default="{ row }">
                    <span v-if="Number(row.credit_limit) > 0">
                        {{ formatMoney(row.credit_limit_balance) }} / {{ formatMoney(row.credit_limit) }}
                    </span>
                    <span v-else class="muted">未开启</span>
                </template>
            </el-table-column>
            <el-table-column label="创建时间" width="160" align="center">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="230" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
                    <el-button link type="primary" size="small" @click="loginAs(row)">登录</el-button>
                    <el-button link type="warning" size="small" @click="toggleStatus(row)">
                        {{ Number(row.status) === 1 ? '禁用' : '启用' }}
                    </el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>

        <el-dialog v-model="createVisible" title="添加客户" width="720px" destroy-on-close>
            <el-form ref="createFormRef" :model="createForm" :rules="createRules" label-width="130px">
                <el-row :gutter="12">
                    <el-col :span="12">
                        <el-form-item label="姓名" prop="username">
                            <el-input v-model="createForm.username" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="性别">
                            <el-select v-model="createForm.sex" placeholder="请选择">
                                <el-option label="未知" value="0" />
                                <el-option label="男" value="1" />
                                <el-option label="女" value="2" />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="邮箱" prop="email">
                            <el-input v-model="createForm.email" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="手机" prop="phonenumber">
                            <el-input v-model="createForm.phonenumber" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="密码" prop="password">
                            <el-input v-model="createForm.password" type="password" show-password />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="所在公司">
                            <el-input v-model="createForm.companyname" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="QQ">
                            <el-input v-model="createForm.qq" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="国家">
                            <el-input v-model="createForm.country" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="省/州">
                            <el-input v-model="createForm.province" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="地址">
                            <el-input v-model="createForm.address1" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="邮编">
                            <el-input v-model="createForm.postcode" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="了解途径">
                            <el-input v-model="createForm.know_us" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="支付方式">
                            <el-select v-model="createForm.defaultgateway" placeholder="请选择" clearable>
                                <el-option
                                    v-for="gateway in gateways"
                                    :key="gateway.name"
                                    :label="gateway.title"
                                    :value="gateway.name"
                                />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="语言">
                            <el-select v-model="createForm.language">
                                <el-option v-for="lang in languages" :key="lang.value" :label="lang.label" :value="lang.value" />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="销售">
                            <el-select v-model="createForm.sale_id" clearable filterable>
                                <el-option v-for="sale in sales" :key="sale.id" :label="sale.user_nickname" :value="sale.id" />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="客户分组">
                            <el-select v-model="createForm.groupid" clearable>
                                <el-option
                                    v-for="group in clientGroups"
                                    :key="group.id"
                                    :label="group.group_name"
                                    :value="group.id"
                                />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="状态">
                            <el-switch v-model="createForm.status" :active-value="1" :inactive-value="0" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="接收营销信息">
                            <el-switch v-model="createForm.marketing_emails_opt_in" :active-value="1" :inactive-value="0" />
                        </el-form-item>
                    </el-col>
                    <el-col v-for="field in customFields" :key="field.id" :span="12">
                        <el-form-item :label="field.fieldname">
                            <el-select
                                v-if="field.fieldtype === 'dropdown'"
                                v-model="createForm.custom[field.id]"
                                clearable
                            >
                                <el-option
                                    v-for="option in (field.fieldoptions || '').split('|')"
                                    :key="option"
                                    :label="option"
                                    :value="option"
                                />
                            </el-select>
                            <el-input
                                v-else
                                v-model="createForm.custom[field.id]"
                                :type="field.fieldtype === 'text' ? 'textarea' : 'text'"
                            />
                        </el-form-item>
                    </el-col>
                    <el-col :span="24">
                        <el-form-item label="管理员备注">
                            <el-input v-model="createForm.notes" type="textarea" :rows="2" />
                        </el-form-item>
                    </el-col>
                </el-row>
            </el-form>

            <template #footer>
                <el-button @click="createVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitCreate">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList, rangeToSeconds } from '../utils/format';

const router = useRouter();

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const createVisible = ref(false);
const createFormRef = ref(null);
const createdRange = ref([]);

const clientGroups = ref([]);
const sales = ref([]);
const gateways = ref([]);
const customFields = ref([]);
const languages = ref([
    { label: '简体中文', value: 'zh-cn' },
    { label: 'English', value: 'en-us' },
]);

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    username: '',
    email: '',
    phonenumber: '',
    status: '',
    groupid: '',
    sale: '',
});

const emptyCreateForm = () => ({
    marketing_emails_opt_in: 1,
    password: '',
    status: 1,
    language: 'zh-cn',
    phone_code: 86,
    defaultgateway: '',
    groupid: '',
    country: '中国',
    province: '',
    sale_id: 0,
    initiative_renew: 0,
    username: '',
    sex: '0',
    companyname: '',
    address1: '',
    postcode: '',
    know_us: '',
    phonenumber: '',
    email: '',
    qq: '',
    notes: '',
    custom: {},
});

const createForm = reactive(emptyCreateForm());

const createRules = {
    username: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
    email: [{ required: true, message: '请输入邮箱', trigger: 'blur' }],
    password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
};

const load = async () => {
    loading.value = true;

    try {
        const [start, end] = rangeToSeconds(createdRange.value);
        const response = await client.post('client_list', {
            ...query,
            start_time: start,
            end_time: end,
        });
        const payload = pickList(response.data);

        rows.value = payload.list;
        total.value = payload.total;

        // The list response also carries the dictionaries the filters need.
        const data = response.data || {};
        if (data.search_data) {
            clientGroups.value = data.search_data.client_groups || clientGroups.value;
            sales.value = data.search_data.sale || sales.value;
        }
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const search = () => {
    query.page = 1;
    load();
};

const reset = () => {
    Object.assign(query, {
        page: 1,
        limit: query.limit,
        order: 'id',
        sort: 'DESC',
        username: '',
        email: '',
        phonenumber: '',
        status: '',
        groupid: '',
        sale: '',
    });
    createdRange.value = [];
    load();
};

const openDetail = (row) => router.push(`/customers/${row.id}`);

const loginAs = async (row) => {
    try {
        const response = await client.get(`login_by_user/${row.id}`);
        const url = response.data.url || response.data;

        if (typeof url === 'string' && url.length > 0) {
            window.open(url, '_blank');
        } else {
            ElMessage.error('未获取到免登录地址');
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const toggleStatus = async (row) => {
    const target = Number(row.status) === 1 ? 0 : 1;

    try {
        await client.get(`close_client/${row.id}`, { params: { status: target } });
        ElMessage.success('操作成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除客户「${row.username}」？该操作不可恢复。`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`delete_client/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openCreate = async () => {
    Object.assign(createForm, emptyCreateForm());
    createVisible.value = true;

    try {
        const response = await client.get('create_client');
        const data = response.data || {};
        customFields.value = data.custom_list || [];
        if (Array.isArray(data.language_list) && data.language_list.length > 0) {
            languages.value = data.language_list;
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitCreate = async () => {
    const valid = await createFormRef.value.validate().catch(() => false);

    if (!valid) {
        return;
    }

    saving.value = true;

    try {
        await client.post('create_client_post', { ...createForm });
        ElMessage.success('添加成功');
        createVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    try {
        const [groups, saleList, gatewayList] = await Promise.all([
            client.get('common/get_client_groups'),
            client.get('common/sale_list'),
            client.get('common/get_getways'),
        ]);
        clientGroups.value = groups.data || [];
        sales.value = saleList.data || [];
        gateways.value = gatewayList.data || [];
    } catch {
        // Filter dictionaries are optional; the list still renders without them.
    }

    load();
});
</script>

<style scoped>
.muted {
    color: #9ca3af;
}
</style>
