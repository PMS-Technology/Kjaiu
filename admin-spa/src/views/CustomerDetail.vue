<template>
    <div class="page-card" v-loading="loading">
        <div class="detail-head">
            <div>
                <h2>{{ summary.username || '客户' }} <span class="uid">#{{ uid }}</span></h2>
                <p class="sub">
                    {{ summary.companyname || '个人客户' }}
                    <el-tag :type="Number(summary.status) === 1 ? 'success' : 'danger'" size="small">
                        {{ Number(summary.status) === 1 ? '正常' : '禁用' }}
                    </el-tag>
                </p>
            </div>
            <div class="head-actions">
                <el-button @click="loginAs">登录客户中心</el-button>
                <el-button @click="goOrders">为客户下单</el-button>
                <el-button type="success" @click="rechargeVisible = true">创建充值账单</el-button>
                <el-button :type="Number(summary.status) === 1 ? 'danger' : 'primary'" @click="toggleStatus">
                    {{ Number(summary.status) === 1 ? '禁用账户' : '启用账户' }}
                </el-button>
            </div>
        </div>

        <el-tabs v-model="activeTab" @tab-change="onTabChange">
            <el-tab-pane label="客户摘要" name="abstract" />
            <el-tab-pane label="个人资料" name="person" />
            <el-tab-pane label="产品/服务" name="services" />
            <el-tab-pane label="账单" name="invoices" />
            <el-tab-pane label="交易记录" name="transactions" />
            <el-tab-pane label="信用管理" name="credit" />
            <el-tab-pane label="工单" name="tickets" />
            <el-tab-pane label="备注/日志" name="notes" />
        </el-tabs>

        <!-- 客户摘要 -->
        <div v-show="activeTab === 'abstract'">
            <el-row :gutter="14">
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="总收入" :value="formatMoney(cards.total_in)" />
                </el-col>
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="总支出" :value="formatMoney(cards.total_out)" />
                </el-col>
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="未付金额" :value="formatMoney(cards.no_pay)" />
                </el-col>
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="账户余额" :value="formatMoney(cards.credit)" accent />
                </el-col>
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="即将到期" :value="cards.upcoming_pro ?? 0" />
                </el-col>
                <el-col :xs="12" :sm="8" :md="4">
                    <StatCard label="已到期" :value="cards.already_pro ?? 0" />
                </el-col>
            </el-row>

            <el-descriptions :column="3" border>
                <el-descriptions-item label="用户名">{{ summary.username }}</el-descriptions-item>
                <el-descriptions-item label="邮箱">{{ summary.email }}</el-descriptions-item>
                <el-descriptions-item label="手机">{{ summary.phonenumber }}</el-descriptions-item>
                <el-descriptions-item label="QQ">{{ summary.qq }}</el-descriptions-item>
                <el-descriptions-item label="客户分组">{{ summary.group_name }}</el-descriptions-item>
                <el-descriptions-item label="销售">{{ summary.sale_name || summary.user_nickname }}</el-descriptions-item>
                <el-descriptions-item label="注册时间">{{ formatTime(summary.create_time) }}</el-descriptions-item>
                <el-descriptions-item label="最后登录">{{ formatTime(summary.last_login) }}</el-descriptions-item>
                <el-descriptions-item label="最后登录 IP">{{ summary.last_login_ip }}</el-descriptions-item>
                <el-descriptions-item label="信用额">
                    {{ formatMoney(summary.credit_limit_balance) }} / {{ formatMoney(summary.credit_limit) }}
                </el-descriptions-item>
                <el-descriptions-item label="服务数量">{{ summary.host_total ?? 0 }}</el-descriptions-item>
                <el-descriptions-item label="未付账单">{{ unpaid.total ?? 0 }} 笔</el-descriptions-item>
            </el-descriptions>

            <h3 class="block-title">管理员备注</h3>
            <el-input
                v-model="notes"
                type="textarea"
                :rows="3"
                maxlength="500"
                show-word-limit
                placeholder="仅管理员可见"
                @blur="saveNotes"
            />
        </div>

        <!-- 个人资料 -->
        <div v-show="activeTab === 'person'">
            <el-form :model="profile" label-width="120px" class="edit-form">
                <el-row :gutter="12">
                    <el-col :span="12">
                        <el-form-item label="姓名">
                            <el-input v-model="profile.username" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="邮箱">
                            <el-input v-model="profile.email" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="手机">
                            <el-input v-model="profile.phonenumber" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="QQ">
                            <el-input v-model="profile.qq" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="公司">
                            <el-input v-model="profile.companyname" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="所在国家">
                            <el-input v-model="profile.country" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="省/州">
                            <el-input v-model="profile.province" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="地址">
                            <el-input v-model="profile.address1" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="邮编">
                            <el-input v-model="profile.postcode" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="支付方式">
                            <el-select v-model="profile.defaultgateway" clearable>
                                <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="客户分组">
                            <el-select v-model="profile.groupid" clearable>
                                <el-option v-for="g in groups" :key="g.id" :label="g.group_name" :value="g.id" />
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="状态">
                            <el-switch v-model="profile.status" :active-value="1" :inactive-value="0" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="新密码">
                            <el-input v-model="profile.password" type="password" show-password placeholder="留空则不修改" />
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-form-item>
                    <el-button type="primary" :loading="saving" @click="saveProfile">保存资料</el-button>
                </el-form-item>
            </el-form>
        </div>

        <!-- 产品/服务 -->
        <div v-show="activeTab === 'services'">
            <el-table :data="services" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="70" align="center" />
                <el-table-column prop="productname" label="产品名称（主机名）" min-width="200" />
                <el-table-column prop="dedicatedip" label="IP" width="130" />
                <el-table-column label="购买时间" width="160">
                    <template #default="{ row }">{{ formatTime(row.regdate) }}</template>
                </el-table-column>
                <el-table-column label="到期时间" width="160">
                    <template #default="{ row }">{{ formatTime(row.nextduedate) }}</template>
                </el-table-column>
                <el-table-column prop="billingcycle" label="周期" width="100" />
                <el-table-column prop="amount" label="价格" width="100" align="right" />
                <el-table-column label="状态" width="110" align="center">
                    <template #default="{ row }">
                        <el-tag :type="statusTagType(row.domainstatus)" size="small">
                            {{ row.domainstatus_zh || row.domainstatus }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="90">
                    <template #default="{ row }">
                        <el-button link type="primary" size="small" @click="$router.push(`/services/${row.id}`)">
                            管理
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </div>

        <!-- 账单 -->
        <div v-show="activeTab === 'invoices'">
            <el-table :data="invoices" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="账单" width="100" align="center" />
                <el-table-column label="生成日" width="160">
                    <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                </el-table-column>
                <el-table-column label="支付日" width="160">
                    <template #default="{ row }">{{ formatTime(row.paid_time) }}</template>
                </el-table-column>
                <el-table-column label="逾期日" width="160">
                    <template #default="{ row }">{{ formatTime(row.due_time) }}</template>
                </el-table-column>
                <el-table-column prop="total" label="总计" width="110" align="right" />
                <el-table-column prop="status" label="状态" width="100" align="center" />
                <el-table-column label="操作" width="90">
                    <template #default="{ row }">
                        <el-button link type="primary" size="small" @click="$router.push(`/invoices/${row.id}`)">
                            详情
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </div>

        <!-- 交易记录 -->
        <div v-show="activeTab === 'transactions'">
            <el-table :data="transactions" size="small" border empty-text="暂无数据">
                <el-table-column label="时间" width="160">
                    <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                </el-table-column>
                <el-table-column prop="gateway" label="付款方式" width="130" />
                <el-table-column prop="description" label="描述" min-width="200" />
                <el-table-column prop="amount_in" label="收入" width="110" align="right" />
                <el-table-column prop="amount_out" label="支出" width="110" align="right" />
                <el-table-column prop="trans_id" label="流水号" min-width="200" show-overflow-tooltip />
            </el-table>
        </div>

        <!-- 信用管理 -->
        <div v-show="activeTab === 'credit'">
            <el-row :gutter="14">
                <el-col :md="10">
                    <el-form :model="creditLimit" label-width="120px">
                        <el-form-item label="信用额开关">
                            <el-switch v-model="creditLimit.is_open_credit_limit" :active-value="1" :inactive-value="0" />
                        </el-form-item>
                        <el-form-item label="信用额度">
                            <el-input-number v-model="creditLimit.credit_limit" :min="0" :precision="2" :controls="false" />
                        </el-form-item>
                        <el-form-item label="出账日">
                            <el-select v-model="creditLimit.bill_generation_date" clearable>
                                <el-option v-for="day in 31" :key="day" :label="`${day} 日`" :value="day" />
                            </el-select>
                        </el-form-item>
                        <el-form-item label="还款周期">
                            <el-input-number v-model="creditLimit.bill_repayment_period" :min="0" :max="30" />
                        </el-form-item>
                        <el-form-item>
                            <el-button type="primary" :loading="saving" @click="saveCreditLimit">保存信用额</el-button>
                        </el-form-item>
                    </el-form>
                </el-col>
                <el-col :md="14">
                    <h3 class="block-title">信用额变更记录</h3>
                    <el-table :data="creditLogs" size="small" border empty-text="暂无数据">
                        <el-table-column prop="create_time_text" label="操作时间" width="160" />
                        <el-table-column prop="description" label="描述" min-width="180" />
                        <el-table-column prop="amount" label="金额" width="110" align="right" />
                        <el-table-column prop="admin" label="操作人" width="110" />
                        <el-table-column prop="ip" label="操作IP" width="130" />
                    </el-table>
                </el-col>
            </el-row>
        </div>

        <!-- 工单 -->
        <div v-show="activeTab === 'tickets'">
            <el-table :data="tickets" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="80" align="center" />
                <el-table-column prop="title" label="标题" min-width="200" />
                <el-table-column prop="status_title" label="状态" width="110" />
                <el-table-column prop="department_name" label="部门" width="130" />
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
        </div>

        <!-- 备注 / 日志 -->
        <div v-show="activeTab === 'notes'">
            <el-form label-width="120px" class="edit-form">
                <el-form-item label="添加备注">
                    <el-input v-model="newNote" type="textarea" :rows="2" placeholder="记录一条跟进备注" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="addNote">提交备注</el-button>
                </el-form-item>
            </el-form>
            <el-table :data="logs" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="80" align="center" />
                <el-table-column label="时间" width="170">
                    <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                </el-table-column>
                <el-table-column prop="description" label="描述" min-width="240" />
                <el-table-column prop="user" label="操作人" width="120" />
                <el-table-column prop="ipaddr" label="IP" width="140" />
            </el-table>
        </div>

        <el-dialog v-model="rechargeVisible" title="创建充值账单" width="420px">
            <el-form label-width="100px">
                <el-form-item label="充值金额">
                    <el-input-number v-model="rechargeAmount" :min="0.01" :precision="2" :controls="false" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="rechargeVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitRecharge">创建</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';

import StatCard from '../components/StatCard.vue';
import client from '../api/client';
import { formatMoney, formatTime, statusTagType } from '../utils/format';

const route = useRoute();
const router = useRouter();
const uid = computed(() => route.params.id);

const loading = ref(false);
const saving = ref(false);
const activeTab = ref('abstract');

const summary = ref({});
const cards = ref({});
const unpaid = ref({});
const services = ref([]);
const invoices = ref([]);
const transactions = ref([]);
const tickets = ref([]);
const logs = ref([]);
const creditLogs = ref([]);
const gateways = ref([]);
const groups = ref([]);

const notes = ref('');
const newNote = ref('');
const rechargeVisible = ref(false);
const rechargeAmount = ref(100);

const profile = reactive({
    username: '',
    email: '',
    phonenumber: '',
    qq: '',
    companyname: '',
    country: '',
    province: '',
    address1: '',
    postcode: '',
    defaultgateway: '',
    groupid: '',
    status: 1,
    password: '',
});

const creditLimit = reactive({
    is_open_credit_limit: 0,
    credit_limit: 0,
    bill_generation_date: '',
    bill_repayment_period: '',
});

const loadBase = async () => {
    loading.value = true;

    try {
        const [user, profileResponse, summaryResponse] = await Promise.all([
            client.get('get_user', { params: { id: uid.value } }),
            client.get(`profile/${uid.value}`),
            client.get('summary', { params: { client_id: uid.value } }),
        ]);

        summary.value = user.data || {};

        const profileData = profileResponse.data || {};
        const source = profileData.client || profileData;
        Object.keys(profile).forEach((key) => {
            if (source[key] !== undefined && source[key] !== null) {
                profile[key] = source[key];
            }
        });
        notes.value = source.notes || '';
        groups.value = profileData.client_groups || groups.value;
        gateways.value = profileData.gateways || gateways.value;

        const summaryData = summaryResponse.data || {};
        summary.value = { ...summary.value, ...(summaryData.client || summaryData) };
        cards.value = summaryData.cards || summaryData;
        unpaid.value = summaryData.unpaid || {};
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const loadServices = async () => {
    try {
        const response = await client.get('clients_services', { params: { uid: uid.value } });
        services.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadInvoices = async () => {
    try {
        const response = await client.get('user_invoice', { params: { uid: uid.value, page: 1, limit: 50 } });
        invoices.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadTransactions = async () => {
    try {
        const response = await client.get('accounts', { params: { uid: uid.value, page: 1, limit: 50 } });
        transactions.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadTickets = async () => {
    try {
        const response = await client.get('client_ticket', { params: { uid: uid.value, page: 1, limit: 50 } });
        tickets.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadLogs = async () => {
    try {
        const response = await client.get('log_record', { params: { uid: uid.value, page: 1, limit: 50 } });
        logs.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadCreditLimit = async () => {
    try {
        const response = await client.get('credit_limit', { params: { uid: uid.value } });
        const data = response.data || {};
        Object.keys(creditLimit).forEach((key) => {
            if (data[key] !== undefined && data[key] !== null) {
                creditLimit[key] = data[key];
            }
        });

        const logResponse = await client.get('credit_limit/log', { params: { uid: uid.value, page: 1, limit: 50 } });
        creditLogs.value = logResponse.data.list || [];
    } catch {
        // A client without a credit line simply has nothing to show here.
    }
};

const onTabChange = (name) => {
    const loaders = {
        services: loadServices,
        invoices: loadInvoices,
        transactions: loadTransactions,
        tickets: loadTickets,
        notes: loadLogs,
        credit: loadCreditLimit,
    };

    if (loaders[name]) {
        loaders[name]();
    }
};

const saveProfile = async () => {
    saving.value = true;

    try {
        await client.post('profile_post', { id: uid.value, ...profile });
        ElMessage.success('保存成功');
        profile.password = '';
        loadBase();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const saveNotes = async () => {
    try {
        await client.post('post_client_notes', { uid: uid.value, notes: notes.value });
        ElMessage.success('备注已保存');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const addNote = async () => {
    if (!newNote.value.trim()) {
        ElMessage.warning('请输入备注内容');
        return;
    }

    try {
        await client.post('add_remark_log', { uid: uid.value, des: newNote.value });
        newNote.value = '';
        ElMessage.success('提交成功');
        loadLogs();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const saveCreditLimit = async () => {
    saving.value = true;

    try {
        await client.put('credit_limit', { uid: uid.value, ...creditLimit });
        ElMessage.success('保存成功');
        loadCreditLimit();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const toggleStatus = async () => {
    const target = Number(summary.value.status) === 1 ? 0 : 1;

    try {
        await ElMessageBox.confirm(`确定${target === 1 ? '启用' : '禁用'}该客户？`, '提示', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`close_client/${uid.value}`, { params: { status: target } });
        ElMessage.success('操作成功');
        loadBase();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loginAs = async () => {
    try {
        const response = await client.get(`login_by_user/${uid.value}`);
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

const goOrders = () => router.push({ path: '/orders', query: { uid: uid.value } });

const submitRecharge = async () => {
    saving.value = true;

    try {
        await client.post(`add_recharge_invoice/${uid.value}`, { amount: rechargeAmount.value });
        ElMessage.success('充值账单已创建');
        rechargeVisible.value = false;
        loadInvoices();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    await loadBase();

    try {
        const [gatewayList, groupList] = await Promise.all([
            client.get('common/get_getways'),
            client.get('common/get_client_groups'),
        ]);
        gateways.value = gatewayList.data || [];
        groups.value = groupList.data || [];
    } catch {
        // Optional dictionaries.
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

.block-title {
    font-size: 14px;
    margin: 18px 0 10px;
}

.edit-form {
    max-width: 900px;
    margin-top: 8px;
}
</style>
