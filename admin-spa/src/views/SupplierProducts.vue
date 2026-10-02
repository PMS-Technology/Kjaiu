<template>
    <div class="page-card">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="上游商品列表" name="list">
                <DataTable
                    v-model:page="query.page"
                    v-model:limit="query.limit"
                    :rows="rows"
                    :total="total"
                    :loading="loading"
                    @pagination="load"
                >
                    <template #toolbar>
                        <el-select v-model="query.zjmf_finance_api_id" placeholder="供应商" clearable style="width: 180px" @change="search">
                            <el-option v-for="supplier in suppliers" :key="supplier.id" :label="supplier.name" :value="supplier.id" />
                        </el-select>
                        <el-input v-model="query.keywords" placeholder="商品名称" clearable style="width: 180px" @keyup.enter="search" />

                        <span class="spacer" />

                        <el-button type="primary" @click="search">搜索</el-button>
                        <el-button type="success" @click="openImport">导入商品</el-button>
                    </template>

                    <el-table-column prop="name" label="商品名称" min-width="200" />
                    <el-table-column prop="billingcycle_zh" label="定价" width="120" align="center" />
                    <el-table-column prop="supplier_name" label="供应商" width="150" align="center" />
                    <el-table-column label="已开通/总数量" width="150" align="center">
                        <template #default="{ row }">{{ row.active_count ?? 0 }} / {{ row.host_count ?? 0 }}</template>
                    </el-table-column>
                    <el-table-column label="售价" width="110" align="right">
                        <template #default="{ row }">{{ formatMoney(row.price) }}</template>
                    </el-table-column>
                    <el-table-column label="利润" width="110" align="right">
                        <template #default="{ row }">
                            <span :class="Number(row.credit) < 0 ? 'loss' : 'profit'">{{ formatMoney(row.credit) }}</span>
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="180" fixed="right">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="$router.push(`/products/${row.id}`)">
                                编辑
                            </el-button>
                            <el-button link type="primary" size="small" @click="refresh(row)">同步</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane label="上游主机" name="hosts">
                <DataTable
                    v-model:page="hostQuery.page"
                    v-model:limit="hostQuery.limit"
                    :rows="hosts"
                    :total="hostTotal"
                    :loading="hostLoading"
                    @pagination="loadHosts"
                >
                    <template #toolbar>
                        <el-select v-model="hostQuery.type" placeholder="来源" clearable style="width: 150px" @change="searchHosts">
                            <el-option label="手动" value="manual" />
                            <el-option label="接口" value="api" />
                        </el-select>
                        <span class="spacer" />
                        <el-button type="primary" @click="searchHosts">搜索</el-button>
                        <el-button type="success" @click="openManualHost()">添加上游主机</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="80" align="center" />
                    <el-table-column prop="name" label="产品名称(主机名)" min-width="190" />
                    <el-table-column prop="server_name" label="供应商" width="120" align="center" />
                    <el-table-column prop="username" label="客户" width="140" />
                    <el-table-column prop="dedicatedip" label="IP" width="130" align="center" />
                    <el-table-column prop="type_zh" label="类型" width="110" align="center" />
                    <el-table-column label="购买时间" width="150" align="center">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column label="到期时间" width="150" align="center">
                        <template #default="{ row }">{{ formatTime(row.nextduedate) }}</template>
                    </el-table-column>
                    <el-table-column prop="amount" label="续费价格" width="120" align="center" />
                    <el-table-column prop="credit" label="利润" width="120" align="center" />
                    <el-table-column prop="saler" label="销售" width="100" align="center" />
                    <el-table-column prop="domainstatus_zh" label="状态" width="120" align="center" />
                    <el-table-column label="操作" width="90">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openManualHost(row)">编辑</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>
        </el-tabs>

        <!-- 导入上游商品 -->
        <el-dialog v-model="importVisible" title="导入商品" width="680px">
            <el-form ref="importFormRef" :model="form" :rules="rules" label-width="140px">
                <el-form-item label="选择本地分组" prop="grouping">
                    <el-select v-model="form.grouping" filterable style="width: 100%">
                        <el-option v-for="group in groups" :key="group.id" :label="group.name" :value="group.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="选择上游" prop="upperReaches">
                    <el-select v-model="form.upperReaches" filterable style="width: 100%" @change="upperReachesChange">
                        <el-option v-for="supplier in suppliers" :key="supplier.id" :label="supplier.name" :value="supplier.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="请选择上游商品" prop="commodity">
                    <el-select
                        v-model="form.commodity"
                        multiple
                        collapse-tags
                        filterable
                        style="width: 100%"
                        :loading="loadingProducts"
                    >
                        <el-option
                            v-for="product in commodityList"
                            :key="product.id"
                            :label="product.name"
                            :value="product.id"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="利润百分比(%)" prop="percentage">
                    <el-input-number v-model="form.percentage" :min="1" :max="100" />
                    <span class="hint">最终提交的服务端加价系数为 {{ Number(form.percentage || 0) + 100 }}</span>
                </el-form-item>
                <el-form-item label="会员中心导航分类">
                    <el-select v-model="form.classification" clearable style="width: 100%">
                        <el-option v-for="item in navTypes" :key="item.id" :label="item.name" :value="item.id" />
                    </el-select>
                </el-form-item>
                <el-form-item v-if="rateNeeded" label="汇率">
                    <el-input-number v-model="rate" :precision="4" :controls="false" />
                    <span class="hint">上游币种为 {{ upstreamCurrency }}，本地币种为 {{ localCurrency }}</span>
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="importVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitImport">导入</el-button>
            </template>
        </el-dialog>

        <!-- 手动上游主机 -->
        <el-dialog v-model="hostVisible" :title="hostForm.id ? '编辑上游主机' : '添加上游主机'" width="560px">
            <el-form :model="hostForm" label-width="130px">
                <el-form-item label="供应商到期时间">
                    <el-date-picker v-model="hostForm.regate" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
                <el-form-item label="续费价格">
                    <el-input-number v-model="hostForm.amount" :precision="2" :controls="false" />
                </el-form-item>
                <el-form-item label="周期">
                    <el-select v-model="hostForm.billingcycle">
                        <el-option v-for="cycle in cycles" :key="cycle.value" :label="cycle.label" :value="cycle.value" />
                    </el-select>
                </el-form-item>
                <el-form-item label="IP">
                    <el-input v-model="hostForm.dedicatedip" />
                </el-form-item>
                <el-form-item label="附加IP">
                    <el-input v-model="hostForm.assignedips" type="textarea" :rows="2" />
                </el-form-item>
                <el-form-item label="开通时间">
                    <el-date-picker v-model="hostForm.create_time" type="datetime" value-format="x" style="width: 100%" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="hostVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitHost">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatMoney, formatTime, pickList, toSeconds } from '../utils/format';

const activeTab = ref('list');
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const hosts = ref([]);
const hostTotal = ref(0);
const hostLoading = ref(false);
const loadingProducts = ref(false);

const suppliers = ref([]);
const groups = ref([]);
const navTypes = ref([]);
const commodityList = ref([]);
const rateNeeded = ref(false);
const upstreamCurrency = ref('');
const localCurrency = ref('');
const rate = ref(1);

const importVisible = ref(false);
const hostVisible = ref(false);
const importFormRef = ref(null);

const query = reactive({ page: 1, limit: 20, orderby: 'id', sort: 'DESC', keywords: '', zjmf_finance_api_id: '' });
const hostQuery = reactive({ page: 1, limit: 20, type: '', keywords: '' });

const form = reactive({
    grouping: '',
    upperReaches: '',
    commodity: [],
    percentage: 10,
    classification: '',
});

const hostForm = reactive({
    id: '',
    regate: '',
    amount: 0,
    billingcycle: 'monthly',
    dedicatedip: '',
    assignedips: '',
    create_time: '',
});

const cycles = [
    { label: '月', value: 'monthly' },
    { label: '季', value: 'quarterly' },
    { label: '半年', value: 'semiannually' },
    { label: '年', value: 'annually' },
    { label: '两年', value: 'biennially' },
    { label: '三年', value: 'triennially' },
];

const rules = {
    grouping: [{ required: true, message: '请选择本地分组', trigger: 'change' }],
    upperReaches: [{ required: true, message: '请选择上游', trigger: 'change' }],
    commodity: [{ required: true, message: '请选择上游商品', trigger: 'change' }],
    percentage: [
        { required: true, message: '请输入利润百分比', trigger: 'blur' },
        { pattern: /^([1-9]|[1-9]\d|100)$/, message: '请输入 1-100 之间的整数', trigger: 'blur' },
    ],
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('product_list_page', {
            params: { ...query, is_upstream: 1 },
        });
        const data = response.data || {};
        const payload = pickList(data);

        rows.value = payload.list;
        total.value = payload.total;
        groups.value = data.product_group || groups.value;
        navTypes.value = data.ptype || navTypes.value;
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

const loadHosts = async () => {
    hostLoading.value = true;

    try {
        const response = await client.get('zjmf_finance_api/manualhost', { params: { ...hostQuery } });
        const payload = pickList(response.data);

        hosts.value = payload.list;
        hostTotal.value = payload.total;
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        hostLoading.value = false;
    }
};

const searchHosts = () => {
    hostQuery.page = 1;
    loadHosts();
};

const openImport = () => {
    Object.assign(form, { grouping: '', upperReaches: '', commodity: [], percentage: 10, classification: '' });
    commodityList.value = [];
    rateNeeded.value = false;
    importVisible.value = true;
};

const upperReachesChange = async (supplierId) => {
    form.commodity = [];
    commodityList.value = [];
    rateNeeded.value = false;
    loadingProducts.value = true;

    try {
        const response = await client.get('get_upstream_products', { params: { id: supplierId } });
        const data = response.data || {};

        commodityList.value = data.data || data.list || [];
        upstreamCurrency.value = (data.upstream_currency && data.upstream_currency.code) || data.upstream_currency || '';
        localCurrency.value = (data.currency && data.currency.code) || data.currency || '';
        rate.value = Number(data.rate || 1);
        rateNeeded.value = Boolean(upstreamCurrency.value && localCurrency.value && upstreamCurrency.value !== localCurrency.value);
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loadingProducts.value = false;
    }
};

const refresh = async (row) => {
    try {
        await client.post('product/sync_product_info', { id: row.id });
        ElMessage.success('同步成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitImport = async () => {
    const valid = await importFormRef.value.validate().catch(() => false);

    if (!valid) {
        return;
    }

    saving.value = true;

    try {
        // The import endpoint is multipart/form-data with bracket keys.
        const payload = new FormData();

        payload.append('gid', form.grouping);
        payload.append('ptype', form.classification);
        payload.append('zjmf_finance_api_id', form.upperReaches);
        payload.append('upstream_price_value', String(Number(form.percentage) + 100));

        if (rateNeeded.value) {
            payload.append('rate', String(rate.value));
        }

        form.commodity.forEach((id) => {
            const product = commodityList.value.find((item) => item.id === id);
            payload.append(`type[${id}]`, (product && product.module) || '');
            payload.append(`productnames[${id}]`, (product && product.name) || '');
        });

        await client.post('zjmf_finance_api/inputproduct', payload, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        ElMessage.success('导入成功');
        importVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openManualHost = (row = null) => {
    Object.assign(hostForm, {
        id: row ? row.id : '',
        regate: row && row.nextduedate ? row.nextduedate * 1000 : '',
        amount: row ? Number(row.amount || 0) : 0,
        billingcycle: row ? row.billingcycle : 'monthly',
        dedicatedip: row ? row.dedicatedip : '',
        assignedips: row ? row.assignedips : '',
        create_time: row && row.create_time ? row.create_time * 1000 : '',
    });
    hostVisible.value = true;
};

const submitHost = async () => {
    saving.value = true;

    try {
        await client.post('zjmf_finance_api/manualhost', {
            ...hostForm,
            regate: toSeconds(hostForm.regate),
            create_time: toSeconds(hostForm.create_time),
        });
        ElMessage.success('保存成功');
        hostVisible.value = false;
        loadHosts();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

onMounted(async () => {
    try {
        const response = await client.get('zjmf_finance_api', { params: { page: 1, limit: 200 } });
        suppliers.value = pickList(response.data).list;
    } catch {
        // The supplier list is optional for the local product tab.
    }

    load();
    loadHosts();
});
</script>

<style scoped>
.hint {
    margin-left: 10px;
    color: #9ca3af;
    font-size: 12px;
}

.profit {
    color: #16a34a;
}

.loss {
    color: #dc2626;
}
</style>
