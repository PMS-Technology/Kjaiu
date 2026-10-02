<template>
    <div class="page-card">
        <el-tabs v-model="activeDepartment" @tab-change="search">
            <el-tab-pane label="全部" name="all" />
            <el-tab-pane v-for="dept in departments" :key="dept.id" :label="dept.name" :name="String(dept.id)" />
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
                <el-input v-model="query.tid" placeholder="工单编号" clearable style="width: 130px" @keyup.enter="search" />
                <el-input v-model="query.content" placeholder="工单标题/内容" clearable style="width: 190px" @keyup.enter="search" />
                <el-autocomplete
                    v-model="query.username"
                    :fetch-suggestions="fetchClients"
                    placeholder="客户"
                    clearable
                    style="width: 160px"
                    @select="search"
                />
                <el-select v-model="query.status" placeholder="状态" clearable style="width: 140px" @change="search">
                    <el-option v-for="status in statuses" :key="status.id" :label="status.title" :value="status.id" />
                </el-select>
                <el-select v-model="query.priority" placeholder="优先级" clearable style="width: 120px" @change="search">
                    <el-option label="低" value="low" />
                    <el-option label="中" value="medium" />
                    <el-option label="高" value="high" />
                </el-select>

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button
                    :disabled="selection.length === 0"
                    type="success"
                    @click="notifyStatus"
                >
                    刷新客户状态
                </el-button>
                <el-button :disabled="selection.length === 0" @click="merge">合并工单</el-button>
                <el-button :disabled="selection.length === 0" @click="closeTickets">关闭</el-button>
                <el-button :disabled="selection.length === 0" type="danger" @click="deleteTickets">删除</el-button>
            </template>

            <el-table-column type="selection" width="45" />
            <el-table-column prop="id" label="ID" width="80" align="center" />
            <el-table-column label="工单标题" min-width="220">
                <template #default="{ row }">
                    <el-link type="primary" @click="$router.push(`/tickets/${row.id}`)">{{ row.title }}</el-link>
                </template>
            </el-table-column>
            <el-table-column prop="user_name" label="提交人" width="140" />
            <el-table-column prop="status_title" label="状态" width="90" align="center" />
            <el-table-column prop="handle_name" label="处理人" width="90" />
            <el-table-column prop="department_name" label="部门" width="120" />
            <el-table-column label="提交时间" width="160">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="上次回复" width="160">
                <template #default="{ row }">{{ formatTime(row.last_reply_time) }}</template>
            </el-table-column>
        </DataTable>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatTime, pickList } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const selection = ref([]);
const departments = ref([]);
const statuses = ref([]);
const activeDepartment = ref('all');

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    tid: '',
    content: '',
    username: '',
    uid: '',
    status: '',
    priority: '',
    dptid: '',
});

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('list_ticket', {
            params: {
                ...query,
                dptid: activeDepartment.value === 'all' ? query.dptid : activeDepartment.value,
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
        tid: '',
        content: '',
        username: '',
        uid: '',
        status: '',
        priority: '',
        dptid: '',
    });
    activeDepartment.value = 'all';
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

const ids = () => selection.value.map((row) => row.id);

const notifyStatus = async () => {
    try {
        await client.post('tastes/editUserTanstes', { id: ids(), ticket_refresh: 1 });
        ElMessage.success('已刷新');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const merge = async () => {
    if (selection.value.length < 2) {
        ElMessage.warning('请至少选择两张工单');
        return;
    }

    try {
        await ElMessageBox.confirm('确定将选中的工单合并为一张？', '合并确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('merge_ticket', { id: ids() });
        ElMessage.success('合并成功');
        selection.value = [];
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const closeTickets = async () => {
    try {
        await ElMessageBox.confirm(`确定关闭选中的 ${ids().length} 张工单？`, '关闭确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('close_ticket', { id: ids() });
        ElMessage.success('操作成功');
        selection.value = [];
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const deleteTickets = async () => {
    try {
        await ElMessageBox.confirm(`确定删除选中的 ${ids().length} 张工单？该操作不可恢复。`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('delete_ticket', { id: ids() });
        ElMessage.success('删除成功');
        selection.value = [];
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const [departmentList, statusList] = await Promise.all([
            client.get('list_ticket_department'),
            client.get('list_ticket_status'),
        ]);
        departments.value = departmentList.data.list || departmentList.data || [];
        statuses.value = statusList.data.list || statusList.data || [];
    } catch {
        // Dictionaries are optional; the raw list still renders.
    }

    load();
});
</script>
