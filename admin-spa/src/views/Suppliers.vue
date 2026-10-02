<template>
    <div class="page-card">
        <el-alert
            type="info"
            :closable="false"
            title="提供财务系统之间的互相代理，在此添加API接口信息即可成为代理商。"
            style="margin-bottom: 14px"
        />

        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
        >
            <template #toolbar>
                <el-input v-model="query.keywords" placeholder="名称/接口地址" clearable style="width: 210px" @keyup.enter="search" />

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="refreshAllStatus">刷新全部状态</el-button>
                <el-button type="success" @click="openForm()">添加供应商</el-button>
            </template>

            <el-table-column label="名称" min-width="160">
                <template #default="{ row }">
                    <el-link type="primary" @click="openManagement(row)">{{ row.name }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="type_zh" label="类型" width="110">
                <template #default="{ row }">{{ row.type_zh || typeLabel(row.type) }}</template>
            </el-table-column>
            <el-table-column prop="hostname" label="接口地址" min-width="180" />
            <el-table-column label="可售/已设置商品" width="180" align="center">
                <template #default="{ row }">
                    <el-tooltip content="可售：上游购物车接口中商品数量的总数 / 已设置：系统中使用该接口对接的商品数量">
                        <span>{{ row.product_num ?? 0 }} / {{ row.set_product_num ?? 0 }}</span>
                    </el-tooltip>
                </template>
            </el-table-column>
            <el-table-column label="产品数量(正常/总)" width="170" align="center">
                <template #default="{ row }">{{ row.active_host_num ?? 0 }} / {{ row.host_num ?? 0 }}</template>
            </el-table-column>
            <el-table-column label="状态" width="120" align="center">
                <template #default="{ row }">
                    <el-tooltip :content="row.desc || (Number(row.status) === 1 ? '链接成功' : '链接失败')">
                        <el-tag
                            :type="Number(row.status) === 1 ? 'success' : 'danger'"
                            size="small"
                            class="clickable"
                            @click="refreshStatus(row)"
                        >
                            {{ Number(row.status) === 1 ? '链接成功' : '链接失败' }}
                        </el-tag>
                    </el-tooltip>
                </template>
            </el-table-column>
            <el-table-column label="余额" width="130" align="center">
                <template #default="{ row }">
                    <span :class="{ low: Number(row.credit) < 100 }">{{ row.currency_prefix || '' }}{{ formatMoney(row.credit) }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="des" label="描述" min-width="180" show-overflow-tooltip />
            <el-table-column label="管理" width="260" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openForm(row)">编辑</el-button>
                    <el-button link type="primary" size="small" @click="openManagement(row)">管理</el-button>
                    <el-button link type="warning" size="small" @click="resetPassword(row)">重置密钥</el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>

        <!-- 供应商编辑 -->
        <el-dialog v-model="formVisible" :title="form.id ? '编辑供应商' : '添加供应商'" width="620px">
            <el-form :model="form" label-width="150px">
                <el-form-item label="接口类型">
                    <el-select v-model="form.type" :disabled="Boolean(form.id)" @change="changeType">
                        <el-option label="手动" value="manual" />
                        <el-option label="智简魔方" value="zjmf_api" />
                        <el-option label="v10" value="v10" />
                    </el-select>
                </el-form-item>
                <el-form-item label="名称">
                    <el-input v-model="form.name" placeholder="建议填写上游的公司名称" />
                </el-form-item>
                <el-form-item v-if="form.type === 'manual'" label="联系方式">
                    <el-input v-model="form.contact_way" />
                </el-form-item>
                <template v-if="form.type !== 'manual'">
                    <el-form-item label="接口地址(IP或者域名)">
                        <el-input v-model="form.hostname" placeholder="上游魔方财务系统的访问地址或ip" />
                    </el-form-item>
                    <el-form-item label="用户名">
                        <el-input v-model="form.username" placeholder="您在上游注册的账号，手机/邮箱" />
                    </el-form-item>
                    <el-form-item label="API密钥">
                        <el-input v-model="form.password" show-password placeholder="上游客户中心-安全中心-API 查看密钥" />
                    </el-form-item>
                </template>
                <el-form-item label="工单传递">
                    <el-switch v-model="form.ticket_open" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="自动更新">
                    <el-switch v-model="form.auto_update" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="form.des" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="formVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitForm">保存</el-button>
            </template>
        </el-dialog>

        <!-- 供应商管理 -->
        <el-drawer v-model="manageVisible" :title="`供应商管理 - ${current.name || ''}`" size="900px">
            <el-tabs v-model="manageTab" @tab-change="loadManageTab">
                <el-tab-pane label="上下游概览" name="summary">
                    <el-row :gutter="14">
                        <el-col :xs="12" :md="6">
                            <StatCard label="上游商品" :value="summary.product_num ?? 0" />
                        </el-col>
                        <el-col :xs="12" :md="6">
                            <StatCard label="已开通产品" :value="summary.host_num ?? 0" />
                        </el-col>
                        <el-col :xs="12" :md="6">
                            <StatCard label="订单数量" :value="summary.order_num ?? 0" />
                        </el-col>
                        <el-col :xs="12" :md="6">
                            <StatCard label="上游余额" :value="formatMoney(summary.credit)" accent />
                        </el-col>
                    </el-row>
                </el-tab-pane>

                <el-tab-pane label="上游商品" name="products">
                    <el-table :data="upstreamProducts" size="small" border empty-text="暂无数据">
                        <el-table-column prop="id" label="ID" width="80" align="center" />
                        <el-table-column prop="name" label="商品名称" min-width="200" />
                        <el-table-column prop="billingcycle_zh" label="定价" width="140" align="center" />
                        <el-table-column prop="stock" label="供应商库存" width="120" align="center" />
                        <el-table-column prop="price" label="售价" width="110" align="right" />
                    </el-table>
                </el-tab-pane>

                <el-tab-pane label="上游订单" name="orders">
                    <el-table :data="upstreamOrders" size="small" border empty-text="暂无数据">
                        <el-table-column prop="id" label="ID" width="70" align="center" />
                        <el-table-column prop="username" label="客户名" width="130" />
                        <el-table-column prop="hosts" label="产品" min-width="180" />
                        <el-table-column prop="amount" label="金额" width="110" align="right" />
                        <el-table-column prop="status" label="状态" width="110" align="center" />
                        <el-table-column label="下单时间" width="160">
                            <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                        </el-table-column>
                    </el-table>
                </el-tab-pane>

                <el-tab-pane label="上游产品/主机" name="hosts">
                    <el-table :data="upstreamHosts" size="small" border empty-text="暂无数据">
                        <el-table-column prop="id" label="ID" width="70" align="center" />
                        <el-table-column prop="name" label="产品名称(主机名)" min-width="190" />
                        <el-table-column prop="username" label="客户" width="130" />
                        <el-table-column prop="dedicatedip" label="IP" width="130" />
                        <el-table-column prop="amount" label="续费价格" width="120" align="right" />
                        <el-table-column prop="domainstatus_zh" label="状态" width="120" align="center" />
                      </el-table>
                </el-tab-pane>
            </el-tabs>
        </el-drawer>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import StatCard from '../components/StatCard.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);

const formVisible = ref(false);
const manageVisible = ref(false);
const manageTab = ref('summary');

const current = ref({});
const summary = ref({});
const upstreamProducts = ref([]);
const upstreamOrders = ref([]);
const upstreamHosts = ref([]);

const query = reactive({ page: 1, limit: 20, orderby: 'id', sort: 'desc', keywords: '' });

const form = reactive({
    id: 0,
    name: '',
    hostname: '',
    username: '',
    password: '',
    des: '',
    type: 'manual',
    contact_way: '',
    ticket_open: 0,
    auto_update: 0,
});

const typeLabel = (type) => {
    const map = { manual: '手动', zjmf_api: '智简魔方', v10: 'v10', whmcs: 'WHMCS' };

    return map[type] || type;
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('zjmf_finance_api', { params: { ...query } });
        const payload = pickList(response.data);

        rows.value = payload.list;
        total.value = payload.total;
        await loadCredits();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

// Row balances are a second call per supplier in the original panel; they are
// fetched here for the visible page so the 余额 column is filled in.
const loadCredits = async () => {
    await Promise.all(
        rows.value.map(async (row) => {
            try {
                const response = await client.get('zjmf_finance_api/upstreamcredit', { params: { id: row.id } });
                row.credit = response.data.credit;
                row.currency_prefix = (response.data.currency && response.data.currency.prefix) || '';
            } catch {
                // A supplier that cannot be reached simply has no balance.
            }
        }),
    );
};

const search = () => {
    query.page = 1;
    load();
};

const refreshStatus = async (row) => {
    try {
        const response = await client.get(`zjmf_finance_api/${row.id}/status`);
        row.status = response.data.status;
        row.desc = response.data.desc;
        ElMessage.success(response.data.desc || '已刷新');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const refreshAllStatus = async () => {
    for (const row of rows.value) {
        await refreshStatus(row);
    }
};

const openForm = async (row = null) => {
    const source = row
        ? await client.get(`zjmf_finance_api/${row.id}`).then((response) => response.data || row).catch(() => row)
        : {};

    Object.assign(form, {
        id: source.id || 0,
        name: source.name || '',
        hostname: source.hostname || '',
        username: source.username || '',
        password: source.password || '',
        des: source.des || '',
        type: source.type || 'manual',
        contact_way: source.contact_way || '',
        ticket_open: Number(source.ticket_open || 0),
        auto_update: Number(source.auto_update || 0),
    });

    formVisible.value = true;
};

const changeType = () => {
    form.hostname = '';
    form.username = '';
    form.password = '';
    form.contact_way = '';
};

const submitForm = async () => {
    saving.value = true;

    try {
        if (form.id) {
            await client.put('zjmf_finance_api', { ...form });
        } else {
            await client.post('zjmf_finance_api', { ...form });
        }

        ElMessage.success('保存成功');
        formVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const resetPassword = async (row) => {
    try {
        await ElMessageBox.confirm('确定重置该供应商的API密钥？重置后需要在上游重新获取。', '重置确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('zjmf_finance_api/reset', { id: row.id });
        ElMessage.success('重置成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除供应商「${row.name}」？该操作不可恢复。`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`zjmf_finance_api/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openManagement = async (row) => {
    current.value = row;
    manageTab.value = 'summary';
    manageVisible.value = true;
    await loadManageTab('summary');
};

const loadManageTab = async (name) => {
    const supplierId = current.value.id;

    try {
        if (name === 'summary') {
            const response = await client.get('zjmf_finance_api/downstream_summary', { params: { id: supplierId } });
            summary.value = response.data || {};
        } else if (name === 'products') {
            const response = await client.get('zjmf_finance_api/products', { params: { id: supplierId, page: 1, limit: 50 } });
            upstreamProducts.value = pickList(response.data).list;
        } else if (name === 'orders') {
            const response = await client.get('zjmf_finance_api/order', { params: { id: supplierId, page: 1, limit: 50 } });
            upstreamOrders.value = pickList(response.data).list;
        } else if (name === 'hosts') {
            const response = await client.get('zjmf_finance_api/host', { params: { id: supplierId, page: 1, limit: 50 } });
            upstreamHosts.value = pickList(response.data).list;
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(load);
</script>

<style scoped>
.low {
    color: #dc2626;
}

.clickable {
    cursor: pointer;
}
</style>
