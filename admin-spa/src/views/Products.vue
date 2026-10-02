<template>
    <div class="page-card" v-loading="loading">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="商品列表" name="products">
                <div class="toolbar">
                    <el-select v-model="filter.gid" placeholder="商品组" clearable style="width: 170px" @change="load">
                        <el-option v-for="group in groups" :key="group.id" :label="group.name" :value="group.id" />
                    </el-select>
                    <el-select v-model="filter.type" placeholder="商品类型" clearable style="width: 150px" @change="load">
                        <el-option v-for="(label, value) in productTypes" :key="value" :label="label" :value="value" />
                    </el-select>
                    <el-input v-model="filter.keywords" placeholder="商品名称" clearable style="width: 180px" @keyup.enter="load" />

                    <span class="spacer" />

                    <el-button type="primary" @click="load">搜索</el-button>
                    <el-button type="success" @click="openCreateProduct">新增商品</el-button>
                    <el-button @click="copyVisible = true">复制商品</el-button>
                </div>

                <DataTable
                    ref="tableRef"
                    v-model:page="query.page"
                    v-model:limit="query.limit"
                    :rows="rows"
                    :total="total"
                    :loading="tableLoading"
                    row-key="id"
                    @pagination="load"
                    @sort-change="onSortChange"
                >
                    <el-table-column prop="id" width="40" align="center">
                        <template #default>
                            <el-icon class="drag-handle"><Rank /></el-icon>
                        </template>
                    </el-table-column>
                    <el-table-column prop="name" label="商品名称" min-width="200">
                        <template #default="{ row }">
                            <el-link type="primary" @click="$router.push(`/products/${row.id}`)">{{ row.name }}</el-link>
                        </template>
                    </el-table-column>
                    <el-table-column prop="type_zh" label="类型" width="120" />
                    <el-table-column prop="pay_type_zh" label="定价" width="100" align="center" />
                    <el-table-column prop="qty" label="库存" width="100" align="center">
                        <template #default="{ row }">
                            <el-input-number
                                v-if="editingStock === row.id"
                                v-model="stockValue"
                                size="small"
                                :min="0"
                                :controls="false"
                                @blur="saveStock(row)"
                            />
                            <span v-else class="stock" @click="editStock(row)">{{ row.qty }}</span>
                        </template>
                    </el-table-column>
                    <el-table-column prop="count" label="已开通/总数量" width="140" align="center">
                        <template #default="{ row }">{{ row.active_count ?? 0 }} / {{ row.host_count ?? 0 }}</template>
                    </el-table-column>
                    <el-table-column label="自动开通" width="200" align="center">
                        <template #default="{ row }">
                            <el-tag v-if="row.auto_setup === 'on'" size="small" type="success">付款后自动开通</el-tag>
                            <el-tag v-else-if="row.auto_setup === 'payment'" size="small" type="warning">支付后开通</el-tag>
                            <el-tag v-else-if="row.auto_setup === 'order'" size="small" type="info">下单后开通</el-tag>
                            <el-tag v-else size="small" type="info">手动开通</el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="200" fixed="right">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="$router.push(`/products/${row.id}`)">
                                编辑
                            </el-button>
                            <el-button link type="primary" size="small" @click="move(row, 'up')">上移</el-button>
                            <el-button link type="primary" size="small" @click="move(row, 'down')">下移</el-button>
                            <el-button link type="danger" size="small" @click="removeProduct(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane label="商品组" name="groups">
                <el-row :gutter="16">
                    <el-col :md="9">
                        <div class="panel-title">
                            一级分组
                            <el-button link type="primary" size="small" @click="openGroupDialog(1)">新增</el-button>
                        </div>
                        <el-table :data="firstGroups" size="small" border empty-text="暂无数据">
                            <el-table-column prop="name" label="名称" min-width="140" />
                            <el-table-column label="隐藏" width="70" align="center">
                                <template #default="{ row }">{{ Number(row.hidden) === 1 ? '是' : '否' }}</template>
                            </el-table-column>
                            <el-table-column label="操作" width="170">
                                <template #default="{ row }">
                                    <el-button link type="primary" size="small" @click="moveGroup(row, 1, 'up')">上移</el-button>
                                    <el-button link type="primary" size="small" @click="moveGroup(row, 1, 'down')">下移</el-button>
                                    <el-button link type="primary" size="small" @click="openGroupDialog(1, row)">编辑</el-button>
                                    <el-button link type="danger" size="small" @click="removeGroup(row, 1)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                    </el-col>

                    <el-col :md="15">
                        <div class="panel-title">
                            分组
                            <el-button link type="primary" size="small" @click="openGroupDialog(2)">新增</el-button>
                        </div>
                        <el-table :data="groups" size="small" border empty-text="暂无数据">
                            <el-table-column prop="id" label="ID" width="60" align="center" />
                            <el-table-column prop="name" label="商品组名称" min-width="140" />
                            <el-table-column prop="headline" label="标题" min-width="130" />
                            <el-table-column prop="alias" label="访问别名" min-width="120" />
                            <el-table-column label="隐藏" width="70" align="center">
                                <template #default="{ row }">{{ Number(row.hidden) === 1 ? '是' : '否' }}</template>
                            </el-table-column>
                            <el-table-column label="操作" width="200">
                                <template #default="{ row }">
                                    <el-button link type="primary" size="small" @click="moveGroup(row, 2, 'up')">上移</el-button>
                                    <el-button link type="primary" size="small" @click="moveGroup(row, 2, 'down')">下移</el-button>
                                    <el-button link type="primary" size="small" @click="openGroupDialog(2, row)">编辑</el-button>
                                    <el-button link type="danger" size="small" @click="removeGroup(row, 2)">删除</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                    </el-col>
                </el-row>
            </el-tab-pane>

            <el-tab-pane label="同步商品" name="sync">
                <el-alert
                    type="info"
                    :closable="false"
                    title="同步上游商品信息会按上游最新的价格与配置重整本地商品，请谨慎操作。"
                    style="margin-bottom: 14px"
                />
                <el-button type="primary" :loading="syncing" @click="syncProducts">开始同步</el-button>

                <el-table :data="syncResults" size="small" border style="margin-top: 14px" empty-text="暂无数据">
                    <el-table-column prop="name" label="商品名称" min-width="200" />
                    <el-table-column prop="type" label="类型" width="150" />
                    <el-table-column prop="msg" label="同步结果" min-width="240" />
                </el-table>
            </el-tab-pane>
        </el-tabs>

        <!-- 新增商品 -->
        <el-dialog v-model="productVisible" title="新增商品" width="560px">
            <el-form :model="productForm" label-width="140px">
                <el-form-item label="商品名称">
                    <el-input v-model="productForm.productname" />
                </el-form-item>
                <el-form-item label="商品类型">
                    <el-select v-model="productForm.type">
                        <el-option v-for="(label, value) in productTypes" :key="value" :label="label" :value="value" />
                    </el-select>
                </el-form-item>
                <el-form-item label="商品组">
                    <el-select v-model="productForm.gid" filterable>
                        <el-option v-for="group in groups" :key="group.id" :label="group.name" :value="group.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="会员中心导航分类">
                    <el-select v-model="productForm.ptype" clearable>
                        <el-option v-for="item in navTypes" :key="item.id" :label="item.name" :value="item.id" />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="productVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitProduct">保存</el-button>
            </template>
        </el-dialog>

        <!-- 复制商品 -->
        <el-dialog v-model="copyVisible" title="复制商品" width="520px">
            <el-form :model="copyForm" label-width="120px">
                <el-form-item label="现有的商品">
                    <el-select v-model="copyForm.existingproduct" filterable>
                        <el-option v-for="item in allProducts" :key="item.id" :label="item.name" :value="item.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="新商品名称">
                    <el-input v-model="copyForm.newproductname" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="copyVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitCopy">保存</el-button>
            </template>
        </el-dialog>

        <!-- 分组编辑 -->
        <el-dialog v-model="groupVisible" :title="groupForm.id ? '编辑分组' : '新增分组'" width="640px">
            <el-form :model="groupForm" label-width="140px">
                <el-form-item label="分组类型">
                    <el-radio-group v-model="groupForm.createType" :disabled="Boolean(groupForm.id)">
                        <el-radio :value="1">一级分组</el-radio>
                        <el-radio :value="2">分组</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item v-if="groupForm.createType === 2" label="一级分组">
                    <el-select v-model="groupForm.gid" clearable>
                        <el-option v-for="group in firstGroups" :key="group.id" :label="group.name" :value="group.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="商品组名称">
                    <el-input v-model="groupForm.name" />
                </el-form-item>
                <template v-if="groupForm.createType === 2">
                    <el-form-item label="商品组标题">
                        <el-input v-model="groupForm.headline" />
                    </el-form-item>
                    <el-form-item label="商品组标语">
                        <el-input v-model="groupForm.tagline" />
                    </el-form-item>
                    <el-form-item label="访问别名">
                        <el-input v-model="groupForm.alias" @blur="verifyAlias" />
                    </el-form-item>
                    <el-form-item label="订购表格模板">
                        <el-select v-model="groupForm.tpl_type" clearable>
                            <el-option label="默认" value="default" />
                            <el-option label="紧凑" value="compact" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="订购表单模板">
                        <el-input v-model="groupForm.order_frm_tpl" />
                    </el-form-item>
                </template>
                <el-form-item label="是否隐藏">
                    <el-switch v-model="groupForm.hidden" :active-value="1" :inactive-value="0" />
                </el-form-item>

                <el-form-item label="自定义字段">
                    <div class="custom-fields">
                        <div v-for="(field, index) in groupForm.customfields" :key="index" class="custom-field-row">
                            <el-input v-model="field.name" placeholder="名称" />
                            <el-input v-model="field.value" placeholder="值" />
                            <el-button link type="danger" @click="groupForm.customfields.splice(index, 1)">删除</el-button>
                        </div>
                        <el-button link type="primary" @click="groupForm.customfields.push({ name: '', value: '' })">
                            添加字段
                        </el-button>
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="groupVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitGroup">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, nextTick } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Rank } from '@element-plus/icons-vue';
import Sortable from 'sortablejs';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { formatMoney, pickList } from '../utils/format';

const activeTab = ref('products');
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const tableLoading = ref(false);
const saving = ref(false);
const syncing = ref(false);
const syncResults = ref([]);
const groups = ref([]);
const firstGroups = ref([]);
const allProducts = ref([]);
const navTypes = ref([]);

const editingStock = ref('');
const stockValue = ref(0);
const tableRef = ref(null);

const productVisible = ref(false);
const copyVisible = ref(false);
const groupVisible = ref(false);

const productTypes = ref({
    hostingaccount: '虚拟主机',
    server: '独立服务器',
    cloud: '云服务器',
    dcimcloud: '魔方云',
    dcim: '魔方DCIM',
    software: '软件产品',
    cdn: 'CDN',
    other: '其他服务',
});

const query = reactive({ page: 1, limit: 20, orderby: 'order', sort: 'ASC' });
const filter = reactive({ gid: '', type: '', keywords: '' });

const productForm = reactive({ productname: '', type: 'cloud', gid: '', ptype: '' });
const copyForm = reactive({ existingproduct: '', newproductname: '' });

const groupForm = reactive({
    id: '',
    createType: 2,
    gid: '',
    name: '',
    headline: '',
    tagline: '',
    alias: '',
    tpl_type: '',
    order_frm_tpl: '',
    hidden: 0,
    customfields: [],
});

const load = async () => {
    loading.value = true;
    tableLoading.value = true;

    try {
        const response = await client.get('product_list_page', {
            params: { ...query, ...filter },
        });
        const data = response.data || {};
        const payload = pickList(data);

        rows.value = payload.list;
        total.value = payload.total;
        groups.value = data.product_group || groups.value;
        firstGroups.value = data.first_group || firstGroups.value;
        allProducts.value = data.all_product || [];
        navTypes.value = data.ptype || [];
        if (data.type) {
            productTypes.value = data.type;
        }
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
        tableLoading.value = false;
    }

    await nextTick();
    initSortable();
};

// Drag-order mirrors the original `update_productsort` endpoint; the table rows
// are re-ordered in place and the whole id sequence is posted.
let sortableInstance = null;

const initSortable = () => {
    if (sortableInstance || !tableRef.value) {
        return;
    }

    const tbody = tableRef.value.$el.querySelector('.el-table__body-wrapper tbody');

    if (!tbody) {
        return;
    }

    sortableInstance = Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: async ({ oldIndex, newIndex }) => {
            const moved = rows.value.splice(oldIndex, 1)[0];
            rows.value.splice(newIndex, 0, moved);

            try {
                await client.post('update_productsort', { id: rows.value.map((row) => row.id) });
                ElMessage.success('排序已保存');
            } catch (error) {
                ElMessage.error(error.message);
            }
        },
    });
};

const onSortChange = ({ prop, order }) => {
    query.orderby = prop || 'order';
    query.sort = order === 'descending' ? 'DESC' : 'ASC';
    load();
};

const editStock = (row) => {
    editingStock.value = row.id;
    stockValue.value = Number(row.qty || 0);
};

const saveStock = async (row) => {
    try {
        await client.post('edit_stock', { id: row.id, qty: stockValue.value });
        row.qty = stockValue.value;
        ElMessage.success('库存已更新');
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        editingStock.value = '';
    }
};

const move = async (row, direction) => {
    const index = rows.value.findIndex((item) => item.id === row.id);
    const target = direction === 'up' ? index - 1 : index + 1;

    if (target < 0 || target >= rows.value.length) {
        return;
    }

    const list = [...rows.value];
    [list[index], list[target]] = [list[target], list[index]];
    rows.value = list;

    try {
        await client.post('update_productsort', { id: rows.value.map((item) => item.id) });
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openCreateProduct = () => {
    Object.assign(productForm, { productname: '', type: 'cloud', gid: '', ptype: '' });
    productVisible.value = true;
};

const submitProduct = async () => {
    if (!productForm.productname.trim()) {
        ElMessage.warning('请输入商品名称');
        return;
    }

    saving.value = true;

    try {
        await client.post('create_product', { ...productForm });
        ElMessage.success('添加成功');
        productVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const submitCopy = async () => {
    saving.value = true;

    try {
        await client.post('product_duplicate', { ...copyForm });
        ElMessage.success('复制成功');
        copyVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeProduct = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除商品「${row.name}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get('del_product', { params: { id: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openGroupDialog = async (type, row = null) => {
    Object.assign(groupForm, {
        id: row ? row.id : '',
        createType: type,
        gid: row ? row.gid || '' : '',
        name: row ? row.name : '',
        headline: row ? row.headline : '',
        tagline: row ? row.tagline : '',
        alias: row ? row.alias : '',
        tpl_type: row ? row.tpl_type : '',
        order_frm_tpl: row ? row.order_frm_tpl : '',
        hidden: row ? Number(row.hidden) : 0,
        customfields: [],
    });

    if (row) {
        try {
            const request = type === 1
                ? client.get('edit_product_first_group_page', { params: { id: row.id } })
                : client.get('edit_product_group_page', { params: { id: row.id } });
            const response = await request;
            const data = response.data || {};
            groupForm.customfields = data.customfields || [];
        } catch {
            // Editing still works with the row values.
        }
    }

    groupVisible.value = true;
};

const verifyAlias = async () => {
    if (!groupForm.alias) {
        return;
    }

    try {
        const response = await client.post('check_product_as', { alias: groupForm.alias, id: groupForm.id });
        if (response.data && response.data.exists) {
            ElMessage.warning('该访问别名已被占用');
        }
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitGroup = async () => {
    saving.value = true;

    try {
        const endpoint = groupForm.createType === 1 ? 'save_product_first_group' : 'save_product_group';
        await client.post(endpoint, { ...groupForm });
        ElMessage.success('保存成功');
        groupVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const moveGroup = async (row, type, direction) => {
    const list = type === 1 ? firstGroups.value : groups.value;
    const index = list.findIndex((item) => item.id === row.id);
    const target = direction === 'up' ? index - 1 : index + 1;

    if (target < 0 || target >= list.length) {
        return;
    }

    [list[index], list[target]] = [list[target], list[index]];

    const endpoint = type === 1 ? 'update_firstgroupsort' : 'update_groupsort';

    try {
        await client.post(endpoint, { id: list.map((item) => item.id) });
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeGroup = async (row, type) => {
    try {
        await ElMessageBox.confirm(`确定删除分组「${row.name}」？该操作不可恢复。`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        const endpoint = type === 1 ? 'del_product_first_group' : 'del_product_group';
        await client.get(endpoint, { params: { id: row.id } });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const syncProducts = async () => {
    syncing.value = true;

    try {
        const response = await client.post('product/sync_product_info', { all: 1 });
        syncResults.value = response.data.list || [];
        ElMessage.success('同步完成');
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        syncing.value = false;
    }
};

onMounted(load);
</script>

<style scoped>
.drag-handle {
    cursor: move;
    color: #9ca3af;
}

.stock {
    cursor: pointer;
    border-bottom: 1px dashed #9ca3af;
}

.panel-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 10px;
}

.custom-field-row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 8px;
}

.custom-fields {
    width: 100%;
}
</style>
