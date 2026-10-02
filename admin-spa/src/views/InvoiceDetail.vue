<template>
    <div class="page-card" v-loading="loading">
        <div class="detail-head">
            <div>
                <h2>账单 #{{ invoiceId }}</h2>
                <p class="sub">
                    客户：<el-link type="primary" @click="$router.push(`/customers/${invoice.uid}`)">{{ invoice.username }}</el-link>
                    <el-tag :type="statusTagType(invoice.status)" size="small">{{ invoice.status }}</el-tag>
                </p>
            </div>
            <div class="head-actions">
                <el-button @click="openOption">编辑账单</el-button>
                <el-button type="success" @click="openPayment">添加付款</el-button>
                <el-button @click="openRefund">退款</el-button>
                <el-button @click="openNotes">备注</el-button>
                <el-button @click="sendEmail">发送账单邮件</el-button>
                <el-button v-if="invoice.status !== 'Paid'" type="primary" @click="markPaid">标记已支付</el-button>
                <el-button v-if="invoice.status !== 'Cancelled'" type="danger" @click="markCancelled">取消账单</el-button>
            </div>
        </div>

        <el-descriptions :column="3" border>
            <el-descriptions-item label="账单金额">{{ formatMoney(invoice.total) }}</el-descriptions-item>
            <el-descriptions-item label="已支付">{{ formatMoney(invoice.credit) }}</el-descriptions-item>
            <el-descriptions-item label="未支付">{{ formatMoney(surplus) }}</el-descriptions-item>
            <el-descriptions-item label="账单生成日">{{ formatTime(invoice.create_time) }}</el-descriptions-item>
            <el-descriptions-item label="账单逾期日">{{ formatTime(invoice.due_time) }}</el-descriptions-item>
            <el-descriptions-item label="账单支付日">{{ formatTime(invoice.paid_time) }}</el-descriptions-item>
            <el-descriptions-item label="付款方式">{{ invoice.payment }}</el-descriptions-item>
            <el-descriptions-item label="账单类型">{{ invoice.type }}</el-descriptions-item>
            <el-descriptions-item label="备注">{{ invoice.notes }}</el-descriptions-item>
        </el-descriptions>

        <h3 class="block-title">账单项目</h3>
        <el-table :data="items" size="small" border empty-text="暂无数据">
            <el-table-column prop="id" label="ID" width="80" align="center" />
            <el-table-column prop="description" label="描述" min-width="240" />
            <el-table-column prop="amount" label="金额" width="120" align="right">
                <template #default="{ row }">{{ formatMoney(row.amount) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="150">
                <template #default="{ row }">
                    <el-button link type="primary" size="small" @click="openEditItem(row)">编辑</el-button>
                    <el-button link type="danger" size="small" @click="removeItem(row)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>

        <h3 class="block-title">付款记录</h3>
        <el-table :data="payments" size="small" border empty-text="暂无数据">
            <el-table-column prop="pay_time_text" label="时间" width="170" />
            <el-table-column prop="gateway" label="付款方式" width="150" />
            <el-table-column prop="trans_id" label="付款流水号" min-width="200" show-overflow-tooltip />
            <el-table-column prop="amount_in" label="金额" width="120" align="right" />
            <el-table-column label="操作" width="90">
                <template #default="{ row }">
                    <el-button link type="danger" size="small" @click="removePayment(row)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>

        <h3 class="block-title">操作日志</h3>
        <el-table :data="logs" size="small" border empty-text="暂无数据">
            <el-table-column prop="id" label="ID" width="80" align="center" />
            <el-table-column label="时间" width="170">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column prop="new_desc" label="描述" min-width="240" />
            <el-table-column prop="user" label="用户名" width="130" />
            <el-table-column prop="ipaddr" label="IP地址" width="150" />
        </el-table>

        <!-- 添加付款 -->
        <el-dialog v-model="paymentVisible" title="添加付款" width="520px">
            <el-form :model="payForm" label-width="110px">
                <el-form-item label="金额">
                    <el-input-number v-model="payForm.amount" :precision="2" :min="0" :max="surplus" :controls="false" />
                </el-form-item>
                <el-form-item label="时间">
                    <el-date-picker v-model="payForm.pay_time" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
                <el-form-item label="付款方式">
                    <el-select v-model="payForm.gateway" clearable>
                        <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                    </el-select>
                </el-form-item>
                <el-form-item label="付款流水号">
                    <el-input v-model="payForm.trans_id" />
                </el-form-item>
                <el-form-item label="发送邮件">
                    <el-switch v-model="payForm.email" :active-value="1" :inactive-value="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="paymentVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitPayment">确定</el-button>
            </template>
        </el-dialog>

        <!-- 编辑账单 -->
        <el-dialog v-model="optionVisible" title="编辑账单" width="520px">
            <el-form :model="optionForm" label-width="110px">
                <el-form-item label="账单生成日">
                    <el-date-picker v-model="optionForm.create_time" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
                <el-form-item label="账单逾期日">
                    <el-date-picker v-model="optionForm.due_time" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
                <el-form-item label="付款方式">
                    <el-select v-model="optionForm.payment" clearable>
                        <el-option v-for="gw in gateways" :key="gw.name" :label="gw.title" :value="gw.name" />
                    </el-select>
                </el-form-item>
                <el-form-item label="状态">
                    <el-select v-model="optionForm.status">
                        <el-option label="未支付" value="Unpaid" />
                        <el-option label="已支付" value="Paid" />
                        <el-option label="已取消" value="Cancelled" />
                    </el-select>
                </el-form-item>
                <el-form-item label="备注">
                    <el-input v-model="optionForm.notes" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="optionVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitOption">保存</el-button>
            </template>
        </el-dialog>

        <!-- 退款 -->
        <el-dialog v-model="refundVisible" title="退款" width="480px">
            <el-form :model="refundForm" label-width="110px">
                <el-form-item label="退款类型">
                    <el-select v-model="refundForm.type">
                        <el-option label="退回余额" value="credit" />
                        <el-option label="原路退回" value="gateway" />
                    </el-select>
                </el-form-item>
                <el-form-item label="金额">
                    <el-input-number v-model="refundForm.amount" :precision="2" :min="0" :max="diffAmount" :controls="false" />
                </el-form-item>
                <el-form-item label="发送邮件">
                    <el-switch v-model="refundForm.email" :active-value="1" :inactive-value="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="refundVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitRefund">确定</el-button>
            </template>
        </el-dialog>

        <!-- 备注 -->
        <el-dialog v-model="notesVisible" title="账单备注" width="480px">
            <el-input v-model="notesForm.notes" type="textarea" :rows="4" />
            <template #footer>
                <el-button @click="notesVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitNotes">保存</el-button>
            </template>
        </el-dialog>

        <!-- 编辑项目 -->
        <el-dialog v-model="itemVisible" title="编辑账单项目" width="480px">
            <el-form :model="itemForm" label-width="90px">
                <el-form-item label="描述">
                    <el-input v-model="itemForm.description" type="textarea" :rows="2" />
                </el-form-item>
                <el-form-item label="金额">
                    <el-input-number v-model="itemForm.amount" :precision="2" :controls="false" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="itemVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitItem">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';

import client from '../api/client';
import { formatMoney, formatTime, statusTagType, toSeconds } from '../utils/format';

const route = useRoute();
const invoiceId = computed(() => route.params.id);

const loading = ref(false);
const saving = ref(false);
const invoice = ref({});
const items = ref([]);
const payments = ref([]);
const logs = ref([]);
const gateways = ref([]);
const surplus = ref(0);
const diffAmount = ref(0);

const paymentVisible = ref(false);
const optionVisible = ref(false);
const refundVisible = ref(false);
const notesVisible = ref(false);
const itemVisible = ref(false);

const payForm = reactive({ amount: 0, pay_time: '', gateway: '', trans_id: '', email: 0 });
const optionForm = reactive({ create_time: '', due_time: '', payment: '', status: '', notes: '' });
const refundForm = reactive({ type: 'credit', amount: 0, email: 0 });
const notesForm = reactive({ notes: '' });
const itemForm = reactive({ id: '', description: '', amount: 0 });

const load = async () => {
    loading.value = true;

    try {
        const summary = await client.get(`invoice/summary/${invoiceId.value}`);
        const data = summary.data || {};
        invoice.value = data.invoice || data;

        items.value = data.items || [];
        payments.value = data.accounts || data.payments || [];
        surplus.value = Number(data.surplus ?? Math.max(Number(invoice.value.total || 0) - Number(invoice.value.credit || 0), 0));
        diffAmount.value = Number(data.diff_amount ?? invoice.value.credit ?? 0);

        const logResponse = await client.get('invoice/log_list', { params: { invoice_id: invoiceId.value } });
        logs.value = logResponse.data.list || logResponse.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const openPayment = async () => {
    payForm.amount = surplus.value;
    payForm.pay_time = Date.now();
    payForm.gateway = invoice.value.payment || '';
    payForm.trans_id = '';
    payForm.email = 0;

    try {
        const response = await client.get(`invoice/addpay_page/${invoiceId.value}`);
        const data = response.data || {};
        gateways.value = data.gateways || gateways.value;
        if (data.surplus !== undefined) {
            surplus.value = Number(data.surplus);
            payForm.amount = surplus.value;
        }
    } catch {
        // Dialog still works with the locally computed surplus.
    }

    paymentVisible.value = true;
};

const submitPayment = async () => {
    saving.value = true;

    try {
        await client.post('invoice/addpay', {
            invoice_id: invoiceId.value,
            ...payForm,
            pay_time: toSeconds(payForm.pay_time),
        });
        ElMessage.success('添加成功');
        paymentVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openOption = async () => {
    optionForm.create_time = invoice.value.create_time ? invoice.value.create_time * 1000 : '';
    optionForm.due_time = invoice.value.due_time ? invoice.value.due_time * 1000 : '';
    optionForm.payment = invoice.value.payment || '';
    optionForm.status = invoice.value.status || '';
    optionForm.notes = invoice.value.notes || '';

    try {
        const response = await client.get(`invoice/option_page/${invoiceId.value}`);
        const data = response.data || {};
        gateways.value = data.gateways || gateways.value;
    } catch {
        // Fall back to the values already loaded with the invoice.
    }

    optionVisible.value = true;
};

const submitOption = async () => {
    saving.value = true;

    try {
        await client.post('invoice/option', {
            invoice_id: invoiceId.value,
            ...optionForm,
            create_time: toSeconds(optionForm.create_time),
            due_time: toSeconds(optionForm.due_time),
        });
        ElMessage.success('保存成功');
        optionVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openRefund = async () => {
    refundForm.amount = diffAmount.value;

    try {
        const response = await client.get('invoice/refund_page', { params: { invoice_id: invoiceId.value } });
        if (response.data && response.data.diff_amount !== undefined) {
            diffAmount.value = Number(response.data.diff_amount);
            refundForm.amount = diffAmount.value;
        }
    } catch {
        // Keep the locally computed refundable amount.
    }

    refundVisible.value = true;
};

const submitRefund = async () => {
    saving.value = true;

    try {
        await client.post('invoice/refund', { invoice_id: invoiceId.value, ...refundForm });
        ElMessage.success('退款已提交');
        refundVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openNotes = async () => {
    notesForm.notes = invoice.value.notes || '';

    try {
        const response = await client.get('invoice/notes_page', { params: { invoice_id: invoiceId.value } });
        if (response.data && response.data.notes !== undefined) {
            notesForm.notes = response.data.notes;
        }
    } catch {
        // Keep the current note.
    }

    notesVisible.value = true;
};

const submitNotes = async () => {
    saving.value = true;

    try {
        await client.post('invoice/notes', { invoice_id: invoiceId.value, notes: notesForm.notes });
        ElMessage.success('保存成功');
        notesVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openEditItem = (row) => {
    itemForm.id = row.id;
    itemForm.description = row.description;
    itemForm.amount = Number(row.amount || 0);
    itemVisible.value = true;
};

const submitItem = async () => {
    saving.value = true;

    try {
        await client.post('invoice/edit_item', { id: itemForm.id, ...itemForm });
        ElMessage.success('保存成功');
        itemVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeItem = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该账单项目？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete('invoice/delete_item', { params: { id: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removePayment = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该付款记录？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`invoice/delete_account/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const markStatus = async (endpoint, label) => {
    try {
        await ElMessageBox.confirm(`确定${label}该账单？`, '操作确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(endpoint, { params: { id: invoiceId.value } });
        ElMessage.success('操作成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const markPaid = () => markStatus('invoice/paid', '标记已支付');
const markCancelled = () => markStatus('invoice/cancelled', '取消');

const sendEmail = async () => {
    try {
        await client.post('invoice/email', { invoice_id: invoiceId.value });
        ElMessage.success('邮件已发送');
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const [summary, gatewayList] = await Promise.all([
            client.get(`invoice/summary/${invoiceId.value}`),
            client.get('common/get_getways'),
        ]);
        const data = summary.data || {};
        invoice.value = data.invoice || data;
        items.value = data.items || [];
        payments.value = data.accounts || data.payments || [];
        surplus.value = Number(data.surplus ?? Math.max(Number(invoice.value.total || 0) - Number(invoice.value.credit || 0), 0));
        diffAmount.value = Number(data.diff_amount ?? invoice.value.credit ?? 0);
        gateways.value = gatewayList.data || [];

        const logResponse = await client.get('invoice/log_list', { params: { invoice_id: invoiceId.value } });
        logs.value = logResponse.data.list || logResponse.data || [];
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
});
</script>

<style scoped>
.detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 14px;
}

.detail-head h2 {
    margin: 0;
    font-size: 19px;
}

.sub {
    margin: 6px 0 0;
    color: #6b7280;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.head-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.block-title {
    font-size: 14px;
    margin: 20px 0 10px;
}
</style>
