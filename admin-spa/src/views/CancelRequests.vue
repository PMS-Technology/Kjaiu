<template>
    <div class="page-card">
        <DataTable
            v-model:page="query.page"
            v-model:limit="query.limit"
            :rows="rows"
            :total="total"
            :loading="loading"
            @pagination="load"
        >
            <template #toolbar>
                <el-input
                    v-model="query.username"
                    placeholder="客户姓名"
                    clearable
                    style="width: 160px"
                    @keyup.enter="search"
                />
                <el-select v-model="query.status" placeholder="执行状态" clearable style="width: 140px" @change="search">
                    <el-option label="待处理" :value="0" />
                    <el-option label="已处理" :value="1" />
                </el-select>
                <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px" @change="search">
                    <el-option label="立即" value="Immediate" />
                    <el-option label="到期" value="End of Billing Period" />
                </el-select>

                <span class="spacer" />

                <el-button type="primary" @click="search">搜索</el-button>
                <el-button @click="reset">重置</el-button>
                <el-button @click="reasonVisible = true">取消原因</el-button>
            </template>

            <el-table-column prop="username" label="姓名" min-width="130" />
            <el-table-column prop="domain" label="产品" min-width="220" show-overflow-tooltip />
            <el-table-column prop="dedicatedip" label="IP" width="130" />
            <el-table-column prop="type" label="类型（立即、到期）" width="170">
                <template #default="{ row }">
                    {{ row.type === 'Immediate' ? '立即' : row.type === 'End of Billing Period' ? '到期' : row.type }}
                </template>
            </el-table-column>
            <el-table-column prop="reason" label="原因" min-width="200" show-overflow-tooltip />
            <el-table-column label="请求时间" width="180">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="删除时间" width="180">
                <template #default="{ row }">{{ formatTime(row.nextduedate) }}</template>
            </el-table-column>
            <el-table-column prop="domainstatus" label="产品状态" width="120" />
            <el-table-column label="执行状态" width="110" align="center">
                <template #default="{ row }">
                    <el-tag :type="Number(row.cancel_status) === 1 ? 'success' : 'warning'" size="small">
                        {{ Number(row.cancel_status) === 1 ? '已处理' : '待处理' }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="160" fixed="right">
                <template #default="{ row }">
                    <el-button
                        v-if="Number(row.cancel_status) !== 1"
                        link
                        type="primary"
                        size="small"
                        @click="review(row, 1)"
                    >
                        同意
                    </el-button>
                    <el-button
                        v-if="Number(row.cancel_status) !== 1"
                        link
                        type="warning"
                        size="small"
                        @click="review(row, 0)"
                    >
                        拒绝
                    </el-button>
                    <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                </template>
            </el-table-column>
        </DataTable>

        <el-dialog v-model="reasonVisible" title="暂停/取消原因" width="560px">
            <el-form label-width="90px">
                <el-form-item label="新增原因">
                    <el-input v-model="newReason" placeholder="例如：客户主动申请" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="addReason">添加</el-button>
                </el-form-item>
            </el-form>

            <el-table :data="reasons" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="70" align="center" />
                <el-table-column prop="reason" label="原因" min-width="200" />
                <el-table-column label="操作" width="90">
                    <template #default="{ row }">
                        <el-button link type="danger" size="small" @click="removeReason(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-dialog>
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
const reasons = ref([]);
const reasonVisible = ref(false);
const newReason = ref('');

const query = reactive({
    page: 1,
    limit: 20,
    order: 'id',
    sort: 'desc',
    username: '',
    status: '',
    type: '',
});

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('request_cancel_list', { params: { ...query } });
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
    Object.assign(query, { page: 1, limit: query.limit, order: 'id', sort: 'desc', username: '', status: '', type: '' });
    load();
};

const review = async (row, status) => {
    const verb = status === 1 ? '同意' : '拒绝';

    try {
        await ElMessageBox.confirm(`确定${verb}「${row.domain}」的暂停请求？`, '处理确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('request_cancel_reason_post', { id: row.id, status });
        ElMessage.success('处理成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该请求记录？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`request_cancel_list/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadReasons = async () => {
    try {
        const response = await client.get('request_cancel_reason');
        reasons.value = response.data.list || response.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const addReason = async () => {
    if (!newReason.value.trim()) {
        ElMessage.warning('请输入原因');
        return;
    }

    try {
        await client.post('request_cancel_reason', { reason: newReason.value });
        newReason.value = '';
        ElMessage.success('添加成功');
        loadReasons();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeReason = async (row) => {
    try {
        await client.delete(`request_cancel_reason/${row.id}`);
        ElMessage.success('删除成功');
        loadReasons();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(() => {
    load();
    loadReasons();
});
</script>
