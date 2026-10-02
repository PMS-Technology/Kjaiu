<template>
    <div>
        <el-row :gutter="14">
            <el-col v-for="stat in stats" :key="stat.key" :xs="12" :sm="8" :md="6" :lg="4">
                <div class="page-card stat">
                    <div class="stat-label">{{ stat.label }}</div>
                    <div class="stat-value">{{ stat.value }}</div>
                </div>
            </el-col>
        </el-row>

        <el-row :gutter="14" class="row-gap">
            <el-col :md="12">
                <div class="page-card">
                    <h3>最近订单</h3>
                    <el-table :data="recentOrders" size="small" empty-text="暂无数据">
                        <el-table-column prop="ordernum" label="订单号" min-width="150" />
                        <el-table-column prop="username" label="客户" min-width="110" />
                        <el-table-column prop="amount" label="金额" width="100" />
                        <el-table-column prop="status" label="状态" width="100" />
                        <el-table-column prop="create_time_text" label="下单时间" min-width="150" />
                    </el-table>
                </div>
            </el-col>

            <el-col :md="12">
                <div class="page-card">
                    <h3>待处理工单</h3>
                    <el-table :data="recentTickets" size="small" empty-text="暂无数据">
                        <el-table-column prop="title" label="标题" min-width="180" />
                        <el-table-column prop="department" label="部门" width="120" />
                        <el-table-column prop="status_text" label="状态" width="100" />
                        <el-table-column prop="create_time_text" label="提交时间" min-width="150" />
                    </el-table>
                </div>
            </el-col>
        </el-row>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';

import client from '../api/client';

const stats = ref([]);
const recentOrders = ref([]);
const recentTickets = ref([]);

onMounted(async () => {
    try {
        const response = await client.get('index/ad_index');
        const data = response.data || {};

        stats.value = [
            { key: 'clients', label: '客户总数', value: data.client_count ?? 0 },
            { key: 'orders', label: '订单总数', value: data.order_count ?? 0 },
            { key: 'hosts', label: '业务总数', value: data.host_count ?? 0 },
            { key: 'invoices', label: '未付账单', value: data.unpaid_invoice_count ?? 0 },
            { key: 'income', label: '今日收入', value: data.today_income ?? '0.00' },
            { key: 'tickets', label: '待处理工单', value: data.pending_ticket_count ?? 0 },
        ];

        recentOrders.value = data.recent_orders || [];
        recentTickets.value = data.recent_tickets || [];
    } catch {
        // An empty dashboard is preferable to a blocking error on first load.
    }
});
</script>

<style scoped>
.stat {
    margin-bottom: 14px;
}

.stat-label {
    font-size: 13px;
    color: #6b7280;
}

.stat-value {
    font-size: 22px;
    font-weight: 600;
    margin-top: 6px;
}

.row-gap {
    margin-top: 6px;
}

h3 {
    margin: 0 0 12px;
    font-size: 15px;
}
</style>
