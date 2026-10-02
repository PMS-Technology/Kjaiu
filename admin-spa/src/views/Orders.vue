<template>
    <div class="page-card">
        <el-tabs v-model="activeTab" @tab-change="search">
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
                <el-input v-model="query.id" placeholder="订单ID" clearable style="width: 120px" @keyup.enter="search" />
                <el-autocomplete
                    v-model="query.username"
                    :fetch-suggestions="fetchClients"
                    placeholder="客户"
                    clearable
                    style="width: 180px"
                    @select="search"
                />
                <el-select v-model="query.pay_status" placeholder="付款状态" clearable style="width: 130px" @change="search">
                    <el-option label="已付款" value="Paid" />
                    <el-option label="未付款" value="Unpaid" />
                </el-select>
                <el-select v-model="query.status" placeholder="状态" clearable style="width: 140px" @change="search">
                    <el-option v-for="option in statusOptions" :key="option.value" :label="option.label" :value="option.value" />
                </el-select>
                <el-select v-model="query.payment" placeholder="付款方式" clearable style="width: 150px" @change="search">
                    <el-option v-for="gateway in gateways" :key="gateway.name" :label="gateway.title" :value="gateway.name" />
                </el-select>
                <el-input v-model="query.amount" placeholder="金额" clearable style="width: 110px" @keyup.enter="search" />
                <el-date-picker
                    v-model="timeRange"
                    type="datetimerange"
                    value-format="x"
                    start-placeholder="下单开始"
                    end-placeholder="下单结束"
                    style="width: 340px"
                    @change="search"
                />

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button type="success" @click="goAddOrder">为客户下单</el-button>
            </template>

            <el-table-column prop="id" label="ID" width="70" align="center" />
            <el-table-column prop="username" label="客户名" min-width="120" />
            <el-table-column prop="order_notes" label="客户备注" min-width="140" show-overflow-tooltip />
            <el-table-column prop="hosts" label="产品" min-width="160" show-overflow-tooltip />
            <el-table-column prop="dedicatedip" label="IP" width="130" />
            <el-table-column label="下单时间" width="160" align="center">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column prop="amount" label="金额" width="110" align="right">
                <template #default="{ row }">{{ formatMoney(row.amount) }}</template>
            </el-table-column>
            <el-table-column label="付款状态/付款方式" width="170">
                <template #default="{ row }">
                    <el-tag :type="row.pay_status === 'Paid' ? 'success' : 'warning'" size="small">
                        {{ row.pay_status_zh || row.pay_status }}
                    </el-tag>
                    <span class="gateway">{{ row.payment_zh || row.payment }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="status" label="状态" width="110" align="center">
                <template #default="{ row }">
                    <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_zh || row.status }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="sum" label="提成/销售" width="140">
                <template #default="{ row }">{{ formatMoney(row.sum) }} / {{ row.user_nickname }}</template>
            </el-table-column>
            <el-table-column label="操作" width="220" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openDetail(row)">详情</el-button>
                    <el-button
                        v-if="row.status === 'Unpaid' || row.status === 'Pending'"
                        link
                        type="success"
                        size="small"
                        @click="accept(row)"
                    >
                        审核通过
                    </el-button>
                    <el-button link type="warning" size="small" @click="cancel(row)">取消</el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>

        <el-drawer v-model="detailVisible" title="订单详情" size="720px">
            <el-descriptions v-if="detail" :column="2" border>
                <el-descriptions-item label="订单号">{{ detail.ordernum || detail.id }}</el-descriptions-item>
                <el-descriptions-item label="客户">{{ detail.username }}</el-descriptions-item>
                <el-descriptions-item label="金额">{{ formatMoney(detail.amount) }}</el-descriptions-item>
                <el-descriptions-item label="付款方式">{{ detail.payment }}</el-descriptions-item>
                <el-descriptions-item label="付款状态">{{ detail.pay_status }}</el-descriptions-item>
                <el-descriptions-item label="状态">{{ detail.status }}</el-descriptions-item>
                <el-descriptions-item label="下单时间">{{ formatTime(detail.create_time) }}</el-descriptions-item>
                <el-descriptions-item label="支付时间">{{ formatTime(detail.pay_time) }}</el-descriptions-item>
                <el-descriptions-item label="优惠码">{{ detail.promo_code }}</el-descriptions-item>
                <el-descriptions-item label="备注">{{ detail.notes }}</el-descriptions-item>
            </el-descriptions>

            <h3 class="block-title">订单项目</h3>
            <el-table :data="detailItems" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="70" align="center" />
                <el-table-column prop="product_name" label="商品" min-width="180" />
                <el-table-column prop="billingcycle" label="周期" width="100" />
                <el-table-column prop="amount" label="金额" width="110" align="right">
                    <template #default="{ row }">{{ formatMoney(row.amount) }}</template>
                </el-table-column>
                <el-table-column prop="domainstatus" label="状态" width="110" />
            </el-table>
        </el-drawer>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList, rangeToSeconds, statusTagType } from '../utils/format';

const route = useRoute();
const router = useRouter();

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const activeTab = ref('all');
const timeRange = ref([]);
const gateways = ref([]);
const detailVisible = ref(false);
const detail = ref(null);
const detailItems = ref([]);

const tabs = ref([
    { label: '全部', value: 'all' },
    { label: '待付款', value: 'Unpaid' },
    { label: '已付款', value: 'Paid' },
    { label: '已完成', value: 'Completed' },
    { label: '已取消', value: 'Cancelled' },
]);

const statusOptions = [
    { label: '待处理', value: 'Pending' },
    { label: '已激活', value: 'Active' },
    { label: '已完成', value: 'Completed' },
    { label: '已取消', value: 'Cancelled' },
];

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    id: '',
    username: '',
    uid: route.query.uid || '',
    sale_id: '',
    pay_status: '',
    status: '',
    amount: '',
    payment: '',
});

const load = async () => {
    loading.value = true;

    try {
        const [start, end] = rangeToSeconds(timeRange.value);
        const response = await client.get('order/search', {
            params: {
                ...query,
                status: activeTab.value === 'all' ? query.status : activeTab.value,
                start_time: start,
                end_time: end,
            },
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
        id: '',
        username: '',
        uid: '',
        sale_id: '',
        pay_status: '',
        status: '',
        amount: '',
        payment: '',
    });
    timeRange.value = [];
    activeTab.value = 'all';
    load();
};

const fetchClients = async (keyword, callback) => {
    if (!keyword) {
        callback([]);
        return;
    }

    try {
        const response = await client.get('order/getclients', { params: { username: keyword } });
        const list = response.data || [];
        callback(list.map((item) => ({ value: item.username, ...item })));
    } catch {
        callback([]);
    }
};

const openDetail = async (row) => {
    try {
        const response = await client.get(`orders/${row.id}`);
        const data = response.data || {};
        detail.value = data.order || data;
        detailItems.value = data.items || data.hosts || [];
        detailVisible.value = true;
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const accept = async (row) => {
    try {
        await ElMessageBox.confirm('确定审核通过该订单？审核后将开通相关业务。', '审核确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get('order/check', { params: { id: row.id } });
        ElMessage.success('操作成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const cancel = async (row) => {
    try {
        await ElMessageBox.confirm('确定取消该订单？', '取消确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get('order/cancel', { params: { id: row.id } });
        ElMessage.success('已取消');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该订单？该操作不可恢复。', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete('orders/delete', { params: { id: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const goAddOrder = () => router.push({ path: '/orders', query: { id: query.uid } });

onMounted(async () => {
    try {
        const response = await client.get('order/search_page');
        const data = response.data || {};

        if (Array.isArray(data.tabs) && data.tabs.length > 0) {
            tabs.value = data.tabs;
        }
        gateways.value = data.gateways || [];
    } catch {
        // The default tabs above remain usable without the dictionary.
    }

    load();
});
</script>

<style scoped>
.gateway {
    margin-left: 6px;
    color: #6b7280;
    font-size: 12px;
}

.block-title {
    font-size: 14px;
    margin: 18px 0 10px;
}
</style>
