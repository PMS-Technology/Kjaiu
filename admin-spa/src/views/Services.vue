<template>
    <div class="page-card">
        <el-tabs v-model="activeStatus" @tab-change="search">
            <el-tab-pane v-for="tab in tabs" :key="String(tab.value)" :label="tab.label" :name="String(tab.value)" />
        </el-tabs>

        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
        >
            <template #toolbar>
                <el-autocomplete
                    v-model="query.username"
                    :fetch-suggestions="fetchClients"
                    placeholder="客户"
                    clearable
                    style="width: 170px"
                    @select="search"
                />
                <el-input
                    v-model="query.domain"
                    placeholder="主机名"
                    clearable
                    style="width: 160px"
                    @keyup.enter="search"
                />
                <el-input v-model="query.ip" placeholder="IP" clearable style="width: 140px" @keyup.enter="search" />
                <el-select v-model="query.product_type" placeholder="产品类型" clearable style="width: 140px" @change="search">
                    <el-option v-for="(label, value) in productTypes" :key="value" :label="label" :value="value" />
                </el-select>
                <el-select v-model="query.billingcycle" placeholder="付款周期" clearable style="width: 130px" @change="search">
                    <el-option v-for="cycle in cycles" :key="cycle.value" :label="cycle.label" :value="cycle.value" />
                </el-select>

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
            </template>

            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column label="客户" min-width="150">
                <template #default="{ row }">
                    <el-link type="primary" @click="$router.push(`/customers/${row.uid}`)">{{ row.username }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="productname" label="产品名称（主机名）" min-width="220" show-overflow-tooltip />
            <el-table-column prop="dedicatedip" label="IP" width="130" />
            <el-table-column prop="type_zh" label="类型" width="120" />
            <el-table-column label="购买时间" width="160">
                <template #default="{ row }">{{ formatTime(row.regdate) }}</template>
            </el-table-column>
            <el-table-column label="到期时间" width="160">
                <template #default="{ row }">
                    <span :class="{ overdue: isOverdue(row) }">{{ formatTime(row.nextduedate) }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="billingcycle_zh" label="周期" width="100" />
            <el-table-column prop="amount" label="价格" width="110" align="right">
                <template #default="{ row }">{{ formatMoney(row.amount) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="110" align="center">
                <template #default="{ row }">
                    <el-tag :type="statusTagType(row.domainstatus)" size="small">
                        {{ row.domainstatus_zh || row.domainstatus }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="user_nickname" label="销售" width="110" />
            <el-table-column label="操作" width="220" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="$router.push(`/services/${row.id}`)">管理</el-button>
                    <el-button link type="primary" size="small" @click="loginAs(row)">客户中心</el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList, statusTagType } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const activeStatus = ref('all');
const productTypes = ref({
    hostingaccount: '虚拟主机',
    server: '独立服务器',
    cloud: '云服务器',
    dcimcloud: '魔方云',
    dcim: '魔方DCIM',
    software: '软件产品',
    cdn: 'CDN',
    other: '其他服务',
});

const tabs = ref([
    { label: '全部', value: 'all' },
    { label: '正常', value: 'Active' },
    { label: '暂停', value: 'Suspended' },
    { label: '待开通', value: 'Pending' },
    { label: '已删除', value: 'Deleted' },
    { label: '已取消', value: 'Cancelled' },
]);

const cycles = [
    { label: '一次性', value: 'onetime' },
    { label: '月付', value: 'monthly' },
    { label: '季付', value: 'quarterly' },
    { label: '半年付', value: 'semiannually' },
    { label: '年付', value: 'annually' },
    { label: '两年付', value: 'biennially' },
    { label: '三年付', value: 'triennially' },
    { label: '日付', value: 'day' },
    { label: '小时付', value: 'hour' },
];

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    username: '',
    uid: '',
    domain: '',
    ip: '',
    product_type: '',
    billingcycle: '',
    domainstatus: '',
});

const isOverdue = (row) => Number(row.nextduedate) > 0 && Number(row.nextduedate) * 1000 < Date.now();

const load = async () => {
    loading.value = true;

    try {
        // The business list is served by the advanced-search endpoint, which
        // carries the host joins (client, product, server) in one payload.
        const response = await client.post('searchfornamelist', {
            ...query,
            domainstatus: activeStatus.value === 'all' ? query.domainstatus : activeStatus.value,
            type: 'host',
        });
        const payload = pickList(response.data);

        rows.value = payload.list;
        total.value = payload.total;
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
        uid: '',
        domain: '',
        ip: '',
        product_type: '',
        billingcycle: '',
        domainstatus: '',
    });
    activeStatus.value = 'all';
    load();
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

const loginAs = async (row) => {
    try {
        const response = await client.get(`login_by_user/${row.uid}`);
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

const remove = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除业务「${row.productname}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete('clients_services/host', { params: { hostid: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(load);
</script>

<style scoped>
.overdue {
    color: #dc2626;
}
</style>
