<template>
    <div class="page-card">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="配置项组列表" name="groups">
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
                            v-model="query.keywords"
                            placeholder="组名"
                            clearable
                            style="width: 180px"
                            @keyup.enter="search"
                        />
                        <span class="spacer" />
                        <el-button type="primary" @click="search">搜索</el-button>
                        <el-button type="success" @click="openGroup()">新增配置项组</el-button>
                        <el-button @click="openDuplicate">复制配置项组</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="name" label="组名" min-width="200" />
                    <el-table-column prop="description" label="描述" min-width="240" />
                    <el-table-column prop="products" label="产品" min-width="200">
                        <template #default="{ row }">
                            <span v-if="!row.products">未指定</span>
                            <el-tag v-else v-for="product in parseProducts(row.products)" :key="product" size="small" class="tag">
                                {{ product }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="200">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openGroup(row)">编辑</el-button>
                            <el-button link type="primary" size="small" @click="openOptions(row)">配置项</el-button>
                            <el-button link type="danger" size="small" @click="removeGroup(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane v-if="currentGroup" label="配置项编辑" name="options">
                <div class="group-head">
                    <h3>{{ currentGroup.name }}</h3>
                    <el-button @click="activeTab = 'groups'">返回列表</el-button>
                </div>

                <el-form :model="groupForm" label-width="120px" class="edit-form">
                    <el-form-item label="组名">
                        <el-input v-model="groupForm.name" />
                    </el-form-item>
                    <el-form-item label="描述">
                        <el-input v-model="groupForm.description" type="textarea" :rows="2" />
                    </el-form-item>
                    <el-form-item label="指定产品">
                        <el-select v-model="groupForm.pids" multiple filterable style="width: 100%">
                            <el-option v-for="product in products" :key="product.id" :label="product.name" :value="product.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" :loading="saving" @click="saveGroup">保存组信息</el-button>
                    </el-form-item>
                </el-form>

                <h3 class="block-title">配置项</h3>
                <el-table :data="options" size="small" border empty-text="暂无数据">
                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="gid" label="分组" width="80" align="center" />
                    <el-table-column prop="option_name" label="配置项名称" min-width="180" />
                    <el-table-column prop="option_type" label="配置项类型" width="140" />
                    <el-table-column prop="order" label="排序" width="80" align="center" />
                    <el-table-column label="是否隐藏" width="100" align="center">
                        <template #default="{ row }">{{ Number(row.hidden) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="允许升降级" width="110" align="center">
                        <template #default="{ row }">{{ Number(row.upgrade) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="应用优惠码" width="110" align="center">
                        <template #default="{ row }">{{ Number(row.is_discount) === 1 ? '是' : '否' }}</template>
                    </el-table-column>
                    <el-table-column label="操作" width="170">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openOption(row)">编辑</el-button>
                            <el-button link type="primary" size="small" @click="openSubDialog(row)">子项</el-button>
                            <el-button link type="danger" size="small" @click="removeOption(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>

                <el-button type="success" style="margin-top: 12px" @click="openOption()">新增配置项</el-button>
            </el-tab-pane>
        </el-tabs>

        <!-- 组编辑 -->
        <el-dialog v-model="groupVisible" :title="groupForm.id ? '编辑配置项组' : '新增配置项组'" width="560px">
            <el-form :model="groupForm" label-width="110px">
                <el-form-item label="组名">
                    <el-input v-model="groupForm.name" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="groupForm.description" type="textarea" :rows="2" />
                </el-form-item>
                <el-form-item label="指定产品">
                    <el-select v-model="groupForm.pids" multiple filterable style="width: 100%">
                        <el-option v-for="product in products" :key="product.id" :label="product.name" :value="product.id" />
                    </el-select>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="groupVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="saveGroup">保存</el-button>
            </template>
        </el-dialog>

        <!-- 复制组 -->
        <el-dialog v-model="duplicateVisible" title="复制配置项组" width="480px">
            <el-form :model="duplicateForm" label-width="110px">
                <el-form-item label="配置项组">
                    <el-select v-model="duplicateForm.gid" filterable>
                        <el-option v-for="row in rows" :key="row.id" :label="row.name" :value="row.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="新的组名">
                    <el-input v-model="duplicateForm.newname" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="duplicateVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitDuplicate">确定</el-button>
            </template>
        </el-dialog>

        <!-- 配置项编辑 -->
        <el-dialog v-model="optionVisible" :title="optionForm.id ? '编辑配置项' : '新增配置项'" width="640px">
            <el-form :model="optionForm" label-width="130px">
                <el-form-item label="配置项名称">
                    <el-input v-model="optionForm.option_name" />
                </el-form-item>
                <el-form-item label="配置项类型">
                    <el-select v-model="optionForm.option_type">
                        <el-option v-for="(label, value) in optionTypes" :key="value" :label="label" :value="Number(value)" />
                    </el-select>
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="optionForm.order" :min="0" />
                </el-form-item>
                <el-form-item label="是否隐藏">
                    <el-switch v-model="optionForm.hidden" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="允许升降级">
                    <el-switch v-model="optionForm.upgrade" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="应用优惠码">
                    <el-switch v-model="optionForm.is_discount" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="客户分组折扣">
                    <el-switch v-model="optionForm.is_rebate" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item v-if="[1, 2].includes(Number(optionForm.option_type))" label="数量最小值">
                    <el-input-number v-model="optionForm.qty_minimum" :min="0" />
                </el-form-item>
                <el-form-item v-if="[1, 2].includes(Number(optionForm.option_type))" label="数量最大值">
                    <el-input-number v-model="optionForm.qty_maximum" :min="0" />
                </el-form-item>
                <el-form-item label="备注">
                    <el-input v-model="optionForm.notes" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="optionVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="saveOption">保存</el-button>
            </template>
        </el-dialog>

        <!-- 子项 -->
        <el-dialog v-model="subVisible" :title="`子项 - ${currentOption.option_name || ''}`" width="760px">
            <el-table :data="subOptions" size="small" border empty-text="暂无数据">
                <el-table-column prop="id" label="ID" width="70" align="center" />
                <el-table-column prop="option_name" label="子项名称" min-width="160" />
                <el-table-column prop="sort_order" label="排序" width="80" align="center" />
                <el-table-column prop="qty_minimum" label="最小数量" width="100" align="center" />
                <el-table-column prop="qty_maximum" label="最大数量" width="100" align="center" />
                <el-table-column label="操作" width="90">
                    <template #default="{ row }">
                        <el-button link type="danger" size="small" @click="removeSubOption(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>

            <h3 class="block-title">新增子项</h3>
            <el-form :model="subForm" label-width="110px">
                <el-row :gutter="12">
                    <el-col :span="12">
                        <el-form-item label="子项名称">
                            <el-input v-model="subForm.option_name" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="排序">
                            <el-input-number v-model="subForm.sort_order" :min="0" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="最小数量">
                            <el-input-number v-model="subForm.qty_minimum" :min="0" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="最大数量">
                            <el-input-number v-model="subForm.qty_maximum" :min="0" />
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-form-item>
                    <el-button type="primary" @click="addSubOption">添加子项</el-button>
                </el-form-item>
            </el-form>
        </el-dialog>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { pickList } from '../utils/format';

const activeTab = ref('groups');
const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const saving = ref(false);
const products = ref([]);
const groups = ref([]);

const currentGroup = ref(null);
const options = ref([]);
const currentOption = ref({});
const subOptions = ref([]);

const groupVisible = ref(false);
const duplicateVisible = ref(false);
const optionVisible = ref(false);
const subVisible = ref(false);

const query = reactive({ page: 1, limit: 20, order: 'id', sort: 'DESC', keywords: '' });

const optionTypes = {
    1: '下拉选项',
    2: '单选',
    3: '复选框',
    4: '数量输入',
    5: '滑块',
};

const groupForm = reactive({ id: '', name: '', description: '', pids: [] });
const duplicateForm = reactive({ gid: '', newname: '' });
const subForm = reactive({ option_name: '', sort_order: 0, qty_minimum: 0, qty_maximum: 0 });

const optionForm = reactive({
    id: '',
    gid: '',
    option_name: '',
    option_type: 1,
    order: 0,
    hidden: 0,
    upgrade: 0,
    is_discount: 0,
    is_rebate: 0,
    qty_minimum: 0,
    qty_maximum: 0,
    notes: '',
});

const parseProducts = (value) => {
    if (Array.isArray(value)) {
        return value;
    }

    try {
        const parsed = JSON.parse(value);

        return Array.isArray(parsed) ? parsed : [value];
    } catch {
        return String(value)
            .split(',')
            .filter((item) => item.length > 0);
    }
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('options/groups_list', { params: { ...query } });
        const data = response.data || {};
        const payload = pickList(data);

        rows.value = payload.list;
        total.value = payload.total;
        products.value = data.products || products.value;
        groups.value = payload.list;
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

const openGroup = async (row = null) => {
    Object.assign(groupForm, {
        id: row ? row.id : '',
        name: row ? row.name : '',
        description: row ? row.description : '',
        pids: row && row.pids ? (Array.isArray(row.pids) ? row.pids : String(row.pids).split(',').map(Number)) : [],
    });
    groupVisible.value = true;
};

const saveGroup = async () => {
    saving.value = true;

    try {
        const endpoint = activeTab.value === 'options' ? 'options/edit_groups_post' : 'options/create_groups_post';
        await client.post(endpoint, { ...groupForm, pids: groupForm.pids.join(',') });
        ElMessage.success('保存成功');
        groupVisible.value = false;
        load();

        if (activeTab.value === 'options') {
            loadGroupDetail(groupForm.id);
        }
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const openDuplicate = () => {
    duplicateForm.gid = '';
    duplicateForm.newname = '';
    duplicateVisible.value = true;
};

const submitDuplicate = async () => {
    saving.value = true;

    try {
        await client.post('options/duplicate_groups_post', { ...duplicateForm });
        ElMessage.success('复制成功');
        duplicateVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeGroup = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除配置项组「${row.name}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`options/delete_groups/${row.id}`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const loadGroupDetail = async (gid) => {
    try {
        const response = await client.get(`options/edit_groups/${gid}`);
        const data = response.data || {};

        currentGroup.value = data.group || { id: gid, name: data.name };
        Object.assign(groupForm, {
            id: gid,
            name: data.name || '',
            description: data.description || '',
            pids: data.pids ? (Array.isArray(data.pids) ? data.pids : String(data.pids).split(',').map(Number)) : [],
        });
        options.value = data.options || [];
        products.value = data.products || products.value;
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openOptions = async (row) => {
    currentGroup.value = row;
    await loadGroupDetail(row.id);
    activeTab.value = 'options';
};

const openOption = (row = null) => {
    Object.assign(optionForm, {
        id: row ? row.id : '',
        gid: currentGroup.value ? currentGroup.value.id : '',
        option_name: row ? row.option_name : '',
        option_type: row ? Number(row.option_type) : 1,
        order: row ? row.order : 0,
        hidden: row ? Number(row.hidden) : 0,
        upgrade: row ? Number(row.upgrade) : 0,
        is_discount: row ? Number(row.is_discount) : 0,
        is_rebate: row ? Number(row.is_rebate) : 0,
        qty_minimum: row ? row.qty_minimum : 0,
        qty_maximum: row ? row.qty_maximum : 0,
        notes: row ? row.notes : '',
    });
    optionVisible.value = true;
};

const saveOption = async () => {
    saving.value = true;

    try {
        await client.post('options/edit_config_post', { ...optionForm });
        ElMessage.success('保存成功');
        optionVisible.value = false;
        loadGroupDetail(optionForm.gid);
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeOption = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该配置项？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`options/delete_options/${row.id}`);
        ElMessage.success('删除成功');
        loadGroupDetail(currentGroup.value.id);
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openSubDialog = async (row) => {
    currentOption.value = row;
    subForm.option_name = '';
    subForm.sort_order = 0;
    subForm.qty_minimum = 0;
    subForm.qty_maximum = 0;

    try {
        const response = await client.get(`options/edit_config/${row.id}`);
        subOptions.value = response.data.sub_options || response.data.sub || [];
    } catch (error) {
        subOptions.value = [];
    }

    subVisible.value = true;
};

const addSubOption = async () => {
    if (!subForm.option_name.trim()) {
        ElMessage.warning('请输入子项名称');
        return;
    }

    try {
        await client.post('options/add_options', { cid: currentOption.value.id, ...subForm });
        ElMessage.success('添加成功');
        openSubDialog(currentOption.value);
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeSubOption = async (row) => {
    try {
        await client.get(`options/delete_sub_options/${row.id}`);
        ElMessage.success('删除成功');
        openSubDialog(currentOption.value);
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const response = await client.get('common/get_product_list', { params: { type: 'all' } });
        products.value = response.data.list || response.data || [];
    } catch {
        // The list endpoint also returns the product dictionary.
    }

    load();
});
</script>

<style scoped>
.tag {
    margin-right: 4px;
}

.group-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.group-head h3 {
    margin: 0;
    font-size: 16px;
}

.edit-form {
    max-width: 800px;
}

.block-title {
    font-size: 14px;
    margin: 18px 0 10px;
}
</style>
