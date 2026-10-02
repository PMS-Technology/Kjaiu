<template>
    <div class="page-card">
        <el-row :gutter="14">
            <el-col :xs="12" :md="6">
                <StatCard label="总收入" :value="formatMoney(totals.amount_in)" accent />
            </el-col>
            <el-col :xs="12" :md="6">
                <StatCard label="总支出" :value="formatMoney(totals.amount_out)" />
            </el-col>
            <el-col :xs="12" :md="6">
                <StatCard label="总结余" :value="formatMoney(totals.surplus)" />
            </el-col>
            <el-col :xs="12" :md="6">
                <StatCard label="币种" :value="totals.code || 'CNY'" />
            </el-col>
        </el-row>

        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
        >
            <template #toolbar>
                <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px" @change="search">
                    <el-option label="收入" value="income" />
                    <el-option label="支出" value="expense" />
                    <el-option label="退款" value="refund" />
                </el-select>
                <el-autocomplete
                    v-model="query.username"
                    :fetch-suggestions="fetchClients"
                    placeholder="客户名"
                    clearable
                    style="width: 170px"
                    @select="search"
                />
                <el-input v-model="query.description" placeholder="描述" clearable style="width: 160px" @keyup.enter="search" />
                <el-input
                    v-model="query.trans_id"
                    placeholder="付款流水号"
                    clearable
                    style="width: 180px"
                    @keyup.enter="search"
                />
                <el-select v-model="query.gateway" placeholder="支付方式" clearable style="width: 150px" @change="search">
                    <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                </el-select>
                <el-input v-model="query.amount" placeholder="金额" clearable style="width: 110px" @keyup.enter="search" />
                <el-date-picker
                    v-model="timeRange"
                    type="datetimerange"
                    value-format="x"
                    start-placeholder="开始时间"
                    end-placeholder="结束时间"
                    style="width: 340px"
                    @change="search"
                />

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button type="success" @click="openCreate">添加流水</el-button>
            </template>

            <el-table-column prop="username" label="客户名" min-width="130" />
            <el-table-column label="时间" width="170" align="center">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column prop="gateway" label="付款方式" width="140" />
            <el-table-column prop="description" label="描述" min-width="200" show-overflow-tooltip />
            <el-table-column prop="amount_in" label="金额" width="110" align="right">
                <template #default="{ row }">{{ formatMoney(row.amount_in) }}</template>
            </el-table-column>
            <el-table-column prop="sale_name" label="销售" width="110" />
            <el-table-column prop="trans_id" label="流水号" min-width="200" show-overflow-tooltip />
            <el-table-column prop="type_zh" label="类型" width="90" align="center" />
            <el-table-column label="操作" width="160" fixed="right">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>

        <el-dialog v-model="formVisible" :title="form.id ? '编辑流水' : '添加流水'" width="560px" destroy-on-close>
            <el-form :model="form" label-width="120px">
                <el-form-item label="客户">
                    <el-autocomplete
                        v-model="form.username"
                        :fetch-suggestions="fetchClients"
                        placeholder="输入客户名"
                        style="width: 100%"
                        @select="onClientSelect"
                    />
                </el-form-item>
                <el-form-item label="金额">
                    <el-input-number v-model="form.amount_in" :precision="2" :controls="false" />
                </el-form-item>
                <el-form-item label="付款方式">
                    <el-select v-model="form.gateway" clearable>
                        <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                    </el-select>
                </el-form-item>
                <el-form-item label="流水号">
                    <el-input v-model="form.trans_id" />
                </el-form-item>
                <el-form-item label="支付时间">
                    <el-date-picker v-model="form.pay_time" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="form.description" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="formVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import StatCard from '../components/StatCard.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList, rangeToSeconds, toSeconds } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const timeRange = ref([]);
const gateways = ref([]);
const totals = ref({});
const formVisible = ref(false);

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    show: '',
    description: '',
    amount: '',
    trans_id: '',
    gateway: '',
    sale_id: '',
    uid: '',
    type: '',
    username: '',
});

const form = reactive({
    id: '',
    uid: '',
    username: '',
    amount_in: 0,
    gateway: '',
    trans_id: '',
    pay_time: '',
    description: '',
});

const load = async () => {
    loading.value = true;

    try {
        const [start, end] = rangeToSeconds(timeRange.value);
        const response = await client.get('accounts', {
            params: { ...query, start_time: start, end_time: end },
        });
        const payload = pickList(response.data);

        rows.value = payload.list;
        total.value = payload.total;
        totals.value = response.data.info || response.data.summary || {};
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
        show: '',
        description: '',
        amount: '',
        trans_id: '',
        gateway: '',
        sale_id: '',
        uid: '',
        type: '',
        username: '',
    });
    timeRange.value = [];
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

const openCreate = async () => {
    Object.assign(form, { id: '', uid: '', username: '', amount_in: 0, gateway: '', trans_id: '', pay_time: '', description: '' });
    formVisible.value = true;

    try {
        await client.get('accounts/create');
    } catch {
        // The form metadata is optional.
    }
};

const openEdit = async (row) => {
    try {
        const response = await client.get(`accounts/${row.id}`);
        const data = response.data || row;
        Object.assign(form, {
            id: data.id,
            uid: data.uid,
            username: data.username,
            amount_in: Number(data.amount_in || 0),
            gateway: data.gateway,
            trans_id: data.trans_id,
            pay_time: data.pay_time ? data.pay_time * 1000 : '',
            description: data.description,
        });
        formVisible.value = true;
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const onClientSelect = (item) => {
    form.uid = item.uid || item.id;
};

const submit = async () => {
    saving.value = true;

    try {
        const payload = { ...form, pay_time: toSeconds(form.pay_time) };

        if (form.id) {
            await client.put(`accounts/${form.id}`, payload);
        } else {
            await client.post('accounts', payload);
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

const remove = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该流水记录？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`accounts/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const response = await client.get('search_page');
        gateways.value = response.data.list || response.data || [];
    } catch {
        // Optional dictionary.
    }

    load();
});
</script>
