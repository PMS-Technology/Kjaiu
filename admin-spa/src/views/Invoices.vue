<template>
    <div class="page-card">
        <el-tabs v-model="activeStatus" @tab-change="search">
            <el-tab-pane label="全部" name="all" />
            <el-tab-pane label="已支付" name="Paid" />
            <el-tab-pane label="未支付" name="Unpaid" />
            <el-tab-pane label="已取消" name="Cancelled" />
        </el-tabs>

        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
            @selection-change="(value) => (selection = value)"
        >
            <template #toolbar>
                <el-input v-model="query.invoice_id" placeholder="账单号" clearable style="width: 130px" @keyup.enter="search" />
                <el-autocomplete
                    v-model="query.username"
                    :fetch-suggestions="fetchClients"
                    placeholder="客户"
                    clearable
                    style="width: 170px"
                    @select="search"
                />
                <el-select v-model="query.payment" placeholder="付款方式" clearable style="width: 150px" @change="search">
                    <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                </el-select>
                <el-select v-model="query.type" placeholder="账单类型" clearable style="width: 140px" @change="search">
                    <el-option label="产品" value="product" />
                    <el-option label="充值" value="recharge" />
                    <el-option label="续费" value="renew" />
                </el-select>
                <el-date-picker
                    v-model="createRange"
                    type="datetimerange"
                    value-format="x"
                    start-placeholder="生成日"
                    end-placeholder="生成日"
                    style="width: 320px"
                    @change="search"
                />
                <el-date-picker
                    v-model="dueRange"
                    type="datetimerange"
                    value-format="x"
                    start-placeholder="逾期日"
                    end-placeholder="逾期日"
                    style="width: 320px"
                    @change="search"
                />

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button :disabled="selection.length === 0" @click="markPaid">标记已支付</el-button>
                <el-button :disabled="selection.length === 0" @click="markUnpaid">标记未支付</el-button>
                <el-button :disabled="selection.length === 0" type="danger" @click="markCancelled">取消账单</el-button>
            </template>

            <el-table-column type="selection" width="55" />
            <el-table-column prop="id" label="账单" width="105" align="center">
                <template #default="{ row }">
                    <el-link type="primary" @click="$router.push(`/invoices/${row.id}`)">{{ row.id }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="username" label="客户名" min-width="130" />
            <el-table-column label="账单生成日" width="160">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="账单支付日" width="160">
                <template #default="{ row }">{{ formatTime(row.paid_time) }}</template>
            </el-table-column>
            <el-table-column label="账单逾期日" width="160">
                <template #default="{ row }">{{ formatTime(row.due_time) }}</template>
            </el-table-column>
            <el-table-column prop="total" label="总计" width="110" align="right">
                <template #default="{ row }">{{ formatMoney(row.total) }}</template>
            </el-table-column>
            <el-table-column prop="payment" label="付款方式" width="130" />
            <el-table-column prop="sale_name" label="销售" width="120" />
            <el-table-column label="状态" width="100" align="center">
                <template #default="{ row }">
                    <el-tag :type="statusTagType(row.status)" size="small">{{ row.status_zh || row.status }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="type" label="账单类型" width="110" align="center" />
            <el-table-column label="操作" width="200" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="$router.push(`/invoices/${row.id}`)">详情</el-button>
                    <el-button link type="primary" size="small" @click="duplicate(row)">复制</el-button>
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
import { formatMoney, formatTime, pickList, rangeToSeconds, statusTagType } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const activeStatus = ref('all');
const selection = ref([]);
const gateways = ref([]);
const createRange = ref([]);
const dueRange = ref([]);

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    username: '',
    uid: '',
    payment: '',
    type: '',
    sale_id: '',
    invoice_id: '',
});

const load = async () => {
    loading.value = true;

    try {
        const [createStart, createEnd] = rangeToSeconds(createRange.value);
        const [dueStart, dueEnd] = rangeToSeconds(dueRange.value);

        const response = await client.get('invoice/index', {
            params: {
                ...query,
                status: activeStatus.value,
                create_time_start: createStart,
                create_time_end: createEnd,
                due_time_start: dueStart,
                due_time_end: dueEnd,
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
        username: '',
        uid: '',
        payment: '',
        type: '',
        sale_id: '',
        invoice_id: '',
    });
    createRange.value = [];
    dueRange.value = [];
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

const markStatus = async (endpoint, label) => {
    try {
        await ElMessageBox.confirm(`确定将选中的 ${selection.value.length} 张账单标记为${label}？`, '操作确认', {
            type: 'warning',
        });
    } catch {
        return;
    }

    try {
        await client.get(endpoint, { params: { id: selection.value.map((row) => row.id).join(',') } });
        ElMessage.success('操作成功');
        selection.value = [];
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const markPaid = () => markStatus('invoice/paid', '已支付');
const markUnpaid = () => markStatus('invoice/unpaid', '未支付');
const markCancelled = () => markStatus('invoice/cancelled', '已取消');

const duplicate = async (row) => {
    try {
        await client.get('invoice/duplicate', { params: { id: row.id } });
        ElMessage.success('复制成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该账单？该操作不可恢复。', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete('invoice/delete', { params: { id: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const response = await client.get('invoice/search_page');
        gateways.value = response.data.gateways || [];
    } catch {
        // Optional dictionary.
    }

    load();
});
</script>
