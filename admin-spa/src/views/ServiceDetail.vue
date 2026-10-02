<template>
    <div class="page-card" v-loading="loading">
        <div class="detail-head">
            <div>
                <h2>{{ host.productname || host.domain || '业务详情' }} <span class="uid">#{{ hostId }}</span></h2>
                <p class="sub">
                    客户：<el-link type="primary" @click="$router.push(`/customers/${host.uid}`)">{{ host.username }}</el-link>
                    <el-tag :type="statusTagType(form.domainstatus)" size="small">
                        {{ form.domainstatus }}
                    </el-tag>
                </p>
            </div>

            <div class="head-actions">
                <el-button
                    v-for="action in moduleActions"
                    :key="action.func"
                    :type="action.type || 'default'"
                    :loading="runningAction === action.func"
                    @click="runModuleAction(action)"
                >
                    {{ action.label }}
                </el-button>
            </div>
        </div>

        <el-tabs v-model="activeTab">
            <el-tab-pane label="主机信息" name="info">
                <el-form :model="form" label-width="130px" class="edit-form">
                    <el-row :gutter="12">
                        <el-col :span="12">
                            <el-form-item label="订单">
                                <el-input v-model="form.orderid" disabled />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="商品/服务">
                                <el-select v-model="form.productid" filterable>
                                    <el-option
                                        v-for="product in products"
                                        :key="product.id"
                                        :label="product.name"
                                        :value="product.id"
                                    />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="接口/供应商">
                                <el-select v-model="form.serverid" clearable placeholder="本地接口">
                                    <el-option v-for="server in servers" :key="server.id" :label="server.name" :value="server.id" />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="主机名">
                                <el-input v-model="form.domain" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="IP地址">
                                <el-input v-model="form.dedicatedip" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="端口">
                                <el-input-number v-model="form.port" :min="0" :controls="false" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="用户名">
                                <el-input v-model="form.username" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="密码">
                                <el-input v-model="form.password" show-password />
                            </el-form-item>
                        </el-col>
                        <el-col :span="24">
                            <el-form-item label="其他IP">
                                <el-input
                                    v-model="form.assignedips"
                                    type="textarea"
                                    :rows="2"
                                    placeholder="英文半角逗号分隔"
                                />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="首付金额">
                                <el-input-number v-model="form.firstpaymentamount" :precision="2" :controls="false" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="续费金额">
                                <el-input-number v-model="form.amount" :precision="2" :controls="false" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="付款方式">
                                <el-select v-model="form.payment" clearable>
                                    <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="付款周期">
                                <el-select v-model="form.billingcycle" @change="cycleChange">
                                    <el-option v-for="cycle in cycles" :key="cycle.value" :label="cycle.label" :value="cycle.value" />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="订购时间">
                                <el-date-picker v-model="regdate" type="datetime" value-format="x" style="width: 100%" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="到期时间">
                                <el-date-picker v-model="nextduedate" type="datetime" value-format="x" style="width: 100%" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="余额自动续费">
                                <el-switch v-model="form.initiative_renew" :active-value="1" :inactive-value="0" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="状态">
                                <el-select v-model="form.domainstatus" @change="domainStatusChange">
                                    <el-option
                                        v-for="option in hostStatuses"
                                        :key="option"
                                        :label="option"
                                        :value="option"
                                    />
                                </el-select>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="成本">
                                <el-input v-model="form.upstream_cost" />
                            </el-form-item>
                        </el-col>
                        <el-col :span="24">
                            <el-form-item label="客户备注">
                                <el-input v-model="form.remark" type="textarea" :rows="2" disabled />
                            </el-form-item>
                        </el-col>
                        <el-col :span="24">
                            <el-form-item label="管理员备注">
                                <el-input v-model="form.notes" type="textarea" :rows="2" />
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <template v-if="configOptions.length > 0">
                        <h3 class="block-title">配置项</h3>
                        <el-row :gutter="12">
                            <el-col v-for="option in configOptions" :key="option.id" :span="12">
                                <el-form-item :label="option.option_name">
                                    <el-input v-model="configForm[option.id]" />
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </template>

                    <el-form-item>
                        <el-button type="primary" :loading="saving" @click="save">保存修改</el-button>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="账单" name="invoices">
                <el-table :data="invoices" size="small" border empty-text="暂无数据">
                    <el-table-column prop="id" label="账单" width="100" align="center" />
                    <el-table-column prop="description" label="描述" min-width="220" />
                    <el-table-column prop="amount" label="金额" width="110" align="right" />
                    <el-table-column label="生成时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column prop="status" label="状态" width="110" />
                </el-table>
            </el-tab-pane>

            <el-tab-pane label="交易流水" name="accounts">
                <el-table :data="accounts" size="small" border empty-text="暂无数据">
                    <el-table-column label="时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column prop="gateway" label="付款方式" width="130" />
                    <el-table-column prop="description" label="描述" min-width="220" />
                    <el-table-column prop="amount_in" label="收入" width="110" align="right" />
                    <el-table-column prop="amount_out" label="支出" width="110" align="right" />
                </el-table>
            </el-tab-pane>

            <el-tab-pane label="工单" name="tickets">
                <el-table :data="tickets" size="small" border empty-text="暂无数据">
                    <el-table-column prop="id" label="ID" width="80" align="center" />
                    <el-table-column prop="title" label="标题" min-width="220" />
                    <el-table-column prop="status_title" label="状态" width="110" />
                    <el-table-column label="提交时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column label="操作" width="90">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="$router.push(`/tickets/${row.id}`)">
                                查看
                            </el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </el-tab-pane>
        </el-tabs>

        <el-dialog v-model="pauseVisible" title="暂停产品" width="480px">
            <el-form label-width="100px">
                <el-form-item label="暂停原因">
                    <el-select v-model="pauseForm.reason_type">
                        <el-option v-for="reason in cancelReasons" :key="reason.id" :label="reason.reason" :value="reason.reason" />
                    </el-select>
                </el-form-item>
                <el-form-item label="备注">
                    <el-input v-model="pauseForm.reason" maxlength="20" show-word-limit />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="pauseVisible = false">取消</el-button>
                <el-button type="primary" @click="submitPause">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage } from 'element-plus';

import client from '../api/client';
import { formatMoney, formatTime, statusTagType, toSeconds } from '../utils/format';

const route = useRoute();
const hostId = computed(() => route.params.id);

const loading = ref(false);
const saving = ref(false);
const runningAction = ref('');
const activeTab = ref('info');

const host = ref({});
const products = ref([]);
const servers = ref([]);
const gateways = ref([]);
const configOptions = ref([]);
const configForm = reactive({});
const invoices = ref([]);
const accounts = ref([]);
const tickets = ref([]);
const cancelReasons = ref([]);

const pauseVisible = ref(false);
const pauseForm = reactive({ reason_type: '', reason: '' });

const regdate = ref('');
const nextduedate = ref('');

const hostStatuses = ['Pending', 'Active', 'Suspended', 'Cancelled', 'Deleted', 'Fraud', 'Completed'];

const cycles = [
    { label: '一次性', value: 'onetime' },
    { label: '小时', value: 'hour' },
    { label: '日', value: 'day' },
    { label: '月', value: 'monthly' },
    { label: '季', value: 'quarterly' },
    { label: '半年', value: 'semiannually' },
    { label: '年', value: 'annually' },
    { label: '两年', value: 'biennially' },
    { label: '三年', value: 'triennially' },
];

const moduleActions = [
    { func: 'create', label: '开通' },
    { func: 'suspend', label: '暂停' },
    { func: 'unsuspend', label: '解除暂停' },
    { func: 'renew', label: '续费' },
    { func: 'sync', label: '同步' },
    { func: 'crack_pass', label: '重置密码' },
    { func: 'terminate', label: '删除', type: 'danger' },
];

const form = reactive({
    id: '',
    orderid: '',
    productid: '',
    serverid: '',
    regdate: '',
    domain: '',
    payment: '',
    firstpaymentamount: 0,
    amount: 0,
    billingcycle: '',
    nextduedate: '',
    domainstatus: '',
    username: '',
    password: '',
    notes: '',
    remark: '',
    assignedips: '',
    dedicatedip: '',
    port: 0,
    upstream_cost: '',
    initiative_renew: 0,
});

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('clients_services', {
            params: { hostselect: hostId.value, uid: route.query.uid || '' },
        });
        const data = response.data || {};
        const record = data.host || data;

        host.value = record;
        Object.keys(form).forEach((key) => {
            if (record[key] !== undefined && record[key] !== null) {
                form[key] = record[key];
            }
        });

        regdate.value = record.regdate ? record.regdate * 1000 : '';
        nextduedate.value = record.nextduedate ? record.nextduedate * 1000 : '';

        configOptions.value = data.config_options || record.configoption || [];
        (configOptions.value || []).forEach((option) => {
            configForm[option.id] = option.option_value ?? option.value ?? '';
        });

        products.value = data.products || [];
        servers.value = data.servers || [];
        gateways.value = data.gateways || [];
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const loadTab = async (name) => {
    try {
        if (name === 'invoices') {
            const response = await client.get('user_productinvoice', { params: { hostid: hostId.value } });
            invoices.value = response.data.list || response.data || [];
        } else if (name === 'accounts') {
            const response = await client.get('user_productaccounts', { params: { hostid: hostId.value } });
            accounts.value = response.data.list || response.data || [];
        } else if (name === 'tickets') {
            const response = await client.get('client_ticket', { params: { hostid: hostId.value } });
            tickets.value = response.data.list || response.data || [];
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const cycleChange = (value) => {
    form.billingcycle = value;
};

const domainStatusChange = (value) => {
    form.domainstatus = value;
};

const save = async () => {
    saving.value = true;

    try {
        await client.post('clients_services/info', {
            ...form,
            regdate: toSeconds(regdate.value),
            nextduedate: toSeconds(nextduedate.value),
            configoption: { ...configForm },
        });
        ElMessage.success('保存成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const runModuleAction = async (action) => {
    if (action.func === 'suspend') {
        try {
            const response = await client.get('clients_services/host_suspend', { params: { id: hostId.value } });
            cancelReasons.value = response.data.reasons || [];
        } catch {
            cancelReasons.value = [];
        }

        pauseForm.reason_type = '';
        pauseForm.reason = '';
        pauseVisible.value = true;
        return;
    }

    runningAction.value = action.func;

    try {
        await client.post('provision/default', { id: hostId.value, func: action.func });
        ElMessage.success('操作已提交');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        runningAction.value = '';
    }
};

const submitPause = async () => {
    try {
        await client.post('provision/default', {
            id: hostId.value,
            func: 'suspend',
            reason_type: pauseForm.reason_type,
            reason: pauseForm.reason,
        });
        ElMessage.success('操作已提交');
        pauseVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    await load();
    await loadTab('invoices');
});

watch(activeTab, (name) => {
    if (name !== 'info') {
        loadTab(name);
    }
});
</script>

<style scoped>
.detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}

.detail-head h2 {
    margin: 0;
    font-size: 19px;
}

.uid {
    color: #9ca3af;
    font-size: 14px;
    font-weight: 400;
}

.sub {
    margin: 6px 0 0;
    color: #6b7280;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.head-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.edit-form {
    max-width: 1000px;
    margin-top: 8px;
}

.block-title {
    font-size: 14px;
    margin: 12px 0 10px;
}
</style>
