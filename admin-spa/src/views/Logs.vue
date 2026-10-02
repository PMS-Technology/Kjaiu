<template>
    <div class="page-card">
        <el-tabs v-model="activeTab" @tab-change="search">
            <el-tab-pane v-for="tab in tabs" :key="tab.value" :label="tab.label" :name="tab.value" />
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
                <el-input v-model="query.keywords" placeholder="描述/用户名" clearable style="width: 200px" @keyup.enter="search" />
                <el-input v-model="query.ip" placeholder="IP地址" clearable style="width: 150px" @keyup.enter="search" />
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
                <el-button type="danger" @click="cleanup">清理日志</el-button>
            </template>

            <el-table-column prop="id" label="ID" width="80" align="center" />
            <el-table-column label="时间" width="175" align="center">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column prop="new_desc" label="描述" min-width="300" show-overflow-tooltip>
                <template #default="{ row }">{{ row.new_desc || row.description }}</template>
            </el-table-column>
            <el-table-column prop="username" label="用户名" width="140" />
            <el-table-column label="IP" width="150">
                <template #default="{ row }">{{ row.ip || row.ipaddr }}</template>
            </el-table-column>
        </DataTable>

        <el-dialog v-model="cleanupVisible" title="清理日志" width="460px">
            <el-form label-width="120px">
                <el-form-item label="保留天数">
                    <el-input-number v-model="cleanupDays" :min="1" :max="3650" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="cleanupVisible = false">取消</el-button>
                <el-button type="danger" :loading="saving" @click="submitCleanup">确定清理</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatTime, pickList, rangeToSeconds } from '../utils/format';

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const activeTab = ref('systemlog');
const timeRange = ref([]);
const cleanupVisible = ref(false);
const cleanupDays = ref(90);

const tabs = [
    { label: '系统日志', value: 'systemlog' },
    { label: '管理员日志', value: 'adminlog' },
    { label: '登录日志', value: 'userlog' },
    { label: 'API日志', value: 'api_log' },
    { label: '邮件日志', value: 'emaillog' },
    { label: '短信日志', value: 'smslog' },
    { label: '定时任务日志', value: 'cronsystemlog' },
    { label: '站内信日志', value: 'systemmessagelog' },
];

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'DESC',
    keywords: '',
    ip: '',
});

const load = async () => {
    loading.value = true;

    try {
        const [start, end] = rangeToSeconds(timeRange.value);
        const response = await client.get(`log_record/${activeTab.value}`, {
            params: { ...query, start_time: start, end_time: end },
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
    Object.assign(query, { page: 1, limit: query.limit, order: 'id', sort: 'DESC', keywords: '', ip: '' });
    timeRange.value = [];
    load();
};

const cleanup = () => {
    cleanupVisible.value = true;
};

const submitCleanup = async () => {
    try {
        await ElMessageBox.confirm(`确定删除 ${cleanupDays.value} 天前的日志？该操作不可恢复。`, '清理确认', {
            type: 'warning',
        });
    } catch {
        return;
    }

    saving.value = true;

    try {
        await client.delete('log_record/delete_log', { params: { days: cleanupDays.value, type: activeTab.value } });
        ElMessage.success('清理成功');
        cleanupVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(load);
</script>
