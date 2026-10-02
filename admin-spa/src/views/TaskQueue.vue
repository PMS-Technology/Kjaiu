<template>
    <div class="page-card">
        <el-tabs v-model="activeTab" @tab-change="search">
            <el-tab-pane label="任务队列" name="queue">
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
                            v-model="query.user"
                            :fetch-suggestions="fetchClients"
                            placeholder="客户"
                            clearable
                            style="width: 160px"
                            @select="search"
                        />
                        <el-input v-model="query.domain" placeholder="产品" clearable style="width: 170px" @keyup.enter="search" />
                        <el-select v-model="query.active_type" placeholder="动作" clearable style="width: 150px" @change="search">
                            <el-option v-for="(label, value) in activeTypes" :key="value" :label="label" :value="value" />
                        </el-select>
                        <el-select v-model="query.status" placeholder="状态" clearable style="width: 130px" @change="search">
                            <el-option label="待执行" :value="0" />
                            <el-option label="已完成" :value="1" />
                            <el-option label="失败" :value="2" />
                        </el-select>

                        <span class="spacer" />

                        <el-button type="primary" @click="search">搜索</el-button>
                        <el-button @click="reset">重置</el-button>
                        <el-button type="warning" :disabled="selection.length === 0" @click="rerunSelection">
                            重新执行
                        </el-button>
                    </template>

                    <el-table-column type="selection" width="45" />
                    <el-table-column prop="user" label="客户" width="180" />
                    <el-table-column prop="domain" label="产品" min-width="180" />
                    <el-table-column prop="active_type_zh" label="动作" width="70" align="center">
                        <template #default="{ row }">{{ row.active_type_zh || activeTypes[row.active_type] || row.active_type }}</template>
                    </el-table-column>
                    <el-table-column prop="description" label="描述" min-width="260" show-overflow-tooltip />
                    <el-table-column prop="from_type_zh" label="来源" width="70" align="center">
                        <template #default="{ row }">{{ row.from_type_zh || row.from_type }}</template>
                    </el-table-column>
                    <el-table-column label="状态" width="60" align="center">
                        <template #default="{ row }">
                            <el-tag :type="taskStatusType(row.status)" size="small" effect="dark">
                                {{ taskStatusLabel(row.status) }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="最后操作时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.last_execute_time) }}</template>
                    </el-table-column>
                    <el-table-column label="操作" width="130">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="rerun(row)">重新执行</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>
        </el-tabs>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatTime, pickList } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const selection = ref([]);
const activeTab = ref('queue');

const activeTypes = {
    1: '开通',
    2: '暂停',
    3: '解除暂停',
    4: '删除',
    5: '续费',
    6: '升降级',
};

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    user: '',
    domain: '',
    active_type: '',
    status: '',
});

const taskStatusType = (status) => {
    switch (Number(status)) {
        case 1:
            return 'success';
        case 2:
            return 'danger';
        default:
            return 'warning';
    }
};

const taskStatusLabel = (status) => {
    switch (Number(status)) {
        case 1:
            return '完成';
        case 2:
            return '失败';
        default:
            return '待执行';
    }
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('run_map/list', { params: { ...query } });
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
        user: '',
        domain: '',
        active_type: '',
        status: '',
    });
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

const rerun = async (row) => {
    try {
        await client.post('run_map/repeat_task', { id: row.id });
        ElMessage.success('已提交重新执行');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const rerunSelection = async () => {
    try {
        await client.post('run_map/repeat_task', { id: selection.value.map((row) => row.id).join(',') });
        ElMessage.success('已提交重新执行');
        selection.value = [];
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(load);
</script>
