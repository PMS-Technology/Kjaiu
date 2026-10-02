<template>
    <div class="page-card">
        <el-tabs v-model="activeTab">
            <el-tab-pane label="员工列表" name="staff">
                <DataTable
                    v-model:page="query.page"
                    v-model:limit="query.limit"
                    :rows="rows"
                    :total="total"
                    :loading="loading"
                    @pagination="load"
                >
                    <template #toolbar>
                        <el-input v-model="query.keywords" placeholder="用户名/真实姓名" clearable style="width: 190px" @keyup.enter="search" />
                        <span class="spacer" />
                        <el-button type="primary" @click="search">搜索</el-button>
                        <el-button type="success" @click="openForm()">新增员工</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="user_nickname" label="真实名称" min-width="130" />
                    <el-table-column prop="user_email" label="邮件地址" min-width="180" />
                    <el-table-column prop="user_login" label="用户名" min-width="130" />
                    <el-table-column prop="role" label="管理员角色" min-width="140" />
                    <el-table-column prop="dept" label="分配到的部门" min-width="140" />
                    <el-table-column label="是否是销售" width="110" align="center">
                        <template #default="{ row }">
                            <el-tag :type="Number(row.is_sale) === 1 ? 'success' : 'info'" size="small">
                                {{ Number(row.is_sale) === 1 ? '是' : '否' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="销售是否启用" width="120" align="center">
                        <template #default="{ row }">
                            <el-tag :type="Number(row.sale_is_use) === 1 ? 'success' : 'info'" size="small">
                                {{ Number(row.sale_is_use) === 1 ? '是' : '否' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column label="操作" width="180" fixed="right">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openForm(row)">编辑</el-button>
                            <el-button link type="warning" size="small" @click="toggleBan(row)">
                                {{ Number(row.user_status) === 1 ? '禁用' : '启用' }}
                            </el-button>
                            <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane label="分组权限" name="roles">
                <DataTable
                    v-model:page="roleQuery.page"
                    v-model:limit="roleQuery.limit"
                    :rows="roles"
                    :total="roleTotal"
                    :loading="roleLoading"
                    @pagination="loadRoles"
                >
                    <template #toolbar>
                        <span class="spacer" />
                        <el-button type="primary" @click="loadRoles">刷新</el-button>
                        <el-button type="success" @click="openRoleDialog()">新增分组</el-button>
                        <el-button @click="copyVisible = true">复制分组</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="name" label="分组名称" min-width="140" />
                    <el-table-column prop="remark" label="说明" min-width="200" />
                    <el-table-column label="状态" width="80" align="center">
                        <template #default="{ row }">
                            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small">
                                {{ Number(row.status) === 1 ? '正常' : '禁用' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column prop="user_login" label="组成员" min-width="180" />
                    <el-table-column label="操作" width="200">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openRoleDialog(row)">编辑权限</el-button>
                            <el-button link type="danger" size="small" @click="removeRole(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>
        </el-tabs>

        <!-- 员工编辑 -->
        <el-dialog v-model="formVisible" :title="form.id ? '编辑员工' : '新增员工'" width="620px">
            <el-form ref="formRef" :model="form" :rules="rules" label-width="130px">
                <el-form-item label="员工角色">
                    <el-select v-model="form.role" :disabled="form.id === 1" style="width: 100%">
                        <el-option v-for="role in roles" :key="role.id" :label="role.name" :value="role.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="用户名" prop="user_login">
                    <el-input v-model="form.user_login" maxlength="60" />
                </el-form-item>
                <el-form-item label="真实姓名" prop="user_nickname">
                    <el-input v-model="form.user_nickname" maxlength="50" />
                </el-form-item>
                <el-form-item label="邮箱" prop="user_email">
                    <el-input v-model="form.user_email" />
                </el-form-item>
                <el-form-item label="密码" prop="pwd">
                    <el-input v-model="form.pwd" type="password" show-password maxlength="64" placeholder="留空则不修改" />
                </el-form-item>
                <el-form-item label="确认密码" prop="confirmPwd">
                    <el-input v-model="form.confirmPwd" type="password" show-password maxlength="64" />
                </el-form-item>
                <el-form-item label="语言">
                    <el-select v-model="form.language" clearable>
                        <el-option label="简体中文" value="zh-cn" />
                        <el-option label="English" value="en-us" />
                    </el-select>
                </el-form-item>
                <el-form-item label="是否销售">
                    <el-switch v-model="form.is_sale" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="下单时可选">
                    <el-switch v-model="form.sale_is_use" :active-value="1" :inactive-value="0" />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="formVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitForm">保存</el-button>
            </template>
        </el-dialog>

        <!-- 分组权限编辑 -->
        <el-dialog v-model="roleVisible" :title="roleForm.id ? '编辑权限' : '新增分组'" width="720px">
            <el-form :model="roleForm" label-width="130px">
                <el-form-item label="分组名称">
                    <el-input v-model="roleForm.name" />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input v-model="roleForm.remark" type="textarea" :rows="2" />
                </el-form-item>
                <el-form-item label="禁用">
                    <el-switch v-model="roleForm.status" :active-value="0" :inactive-value="1" />
                </el-form-item>
                <el-form-item label="分组用户">
                    <el-select v-model="roleForm.user" multiple filterable style="width: 100%">
                        <el-option v-for="admin in admins" :key="admin.id" :label="admin.user_login" :value="admin.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="权限">
                    <el-input v-model="filterText" placeholder="搜索权限" clearable style="margin-bottom: 8px" />
                    <el-tree
                        ref="treeRef"
                        :data="permissionTree"
                        show-checkbox
                        node-key="id"
                        :props="{ label: 'title', children: 'children' }"
                        :filter-node-method="filterNode"
                        default-expand-all
                        class="perm-tree"
                    />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="roleVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitRole">保存</el-button>
            </template>
        </el-dialog>

        <!-- 复制分组 -->
        <el-dialog v-model="copyVisible" title="复制分组" width="480px">
            <el-form :model="copyForm" label-width="120px">
                <el-form-item label="原分组">
                    <el-select v-model="copyForm.role_id" filterable>
                        <el-option v-for="role in roles" :key="role.id" :label="role.name" :value="role.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="新分组名称">
                    <el-input v-model="copyForm.role_name" />
                </el-form-item>
                <el-form-item label="新分组说明">
                    <el-input v-model="copyForm.role_remark" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="copyVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitCopy">确定</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { nextTick, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { pickList } from '../utils/format';

const activeTab = ref('staff');
const loading = ref(false);
const saving = ref(false);
const roleLoading = ref(false);

const rows = ref([]);
const total = ref(0);
const roles = ref([]);
const roleTotal = ref(0);
const admins = ref([]);
const permissionTree = ref([]);
const filterText = ref('');

const formVisible = ref(false);
const roleVisible = ref(false);
const copyVisible = ref(false);
const formRef = ref(null);
const treeRef = ref(null);

const query = reactive({ page: 1, limit: 20, keywords: '' });
const roleQuery = reactive({ page: 1, limit: 20 });

const form = reactive({
    id: '',
    role: '',
    user_login: '',
    user_nickname: '',
    user_email: '',
    pwd: '',
    confirmPwd: '',
    language: 'zh-cn',
    is_sale: 0,
    sale_is_use: 0,
});

const roleForm = reactive({ id: '', name: '', remark: '', status: 1, user: [], auth: [] });
const copyForm = reactive({ role_id: '', role_name: '', role_remark: '' });

const rules = {
    user_login: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    user_nickname: [{ required: true, message: '请输入真实姓名', trigger: 'blur' }],
    user_email: [
        { required: true, message: '请输入邮箱', trigger: 'blur' },
        { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
    ],
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get('adminuser', { params: { ...query } });
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

const loadRoles = async () => {
    roleLoading.value = true;

    try {
        const response = await client.get('rbac', { params: { ...roleQuery } });
        const payload = pickList(response.data);

        roles.value = payload.list;
        roleTotal.value = payload.total;
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        roleLoading.value = false;
    }
};

const openForm = async (row = null) => {
    Object.assign(form, {
        id: row ? row.id : '',
        role: row ? row.role : '',
        user_login: row ? row.user_login : '',
        user_nickname: row ? row.user_nickname : '',
        user_email: row ? row.user_email : '',
        pwd: '',
        confirmPwd: '',
        language: row ? row.language || 'zh-cn' : 'zh-cn',
        is_sale: row ? Number(row.is_sale || 0) : 0,
        sale_is_use: row ? Number(row.sale_is_use || 0) : 0,
    });

    try {
        const response = row ? await client.get(`adminuser/${row.id}`) : await client.get('create_page');
        const data = response.data || {};

        if (data.roles) {
            roles.value = data.roles;
        }
        if (data.user) {
            Object.assign(form, { ...form, ...data.user, id: row ? row.id : '' });
        }
    } catch (error) {
        ElMessage.error(error.message);
    }

    formVisible.value = true;
};

const submitForm = async () => {
    const valid = await formRef.value.validate().catch(() => false);

    if (!valid) {
        return;
    }

    if (form.pwd && form.pwd !== form.confirmPwd) {
        ElMessage.warning('两次输入的密码不一致');
        return;
    }

    saving.value = true;

    try {
        if (form.id) {
            await client.post('adminuser/update', { ...form });
        } else {
            await client.post('adminuser', { ...form });
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

const toggleBan = async (row) => {
    const endpoint = Number(row.user_status) === 1 ? `ban/${row.id}/` : `cancelBan/${row.id}/`;

    try {
        await client.get(endpoint);
        ElMessage.success('操作成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const remove = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除员工「${row.user_login}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`adminuser/${row.id}/`);
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openRoleDialog = async (row = null) => {
    Object.assign(roleForm, {
        id: row ? row.id : '',
        name: row ? row.name : '',
        remark: row ? row.remark : '',
        status: row ? Number(row.status) : 1,
        user: [],
        auth: [],
    });

    try {
        const response = row
            ? await client.get(`rbac/role_page`, { params: { id: row.id } })
            : await client.get('rbac/role_page');
        const data = response.data || {};

        permissionTree.value = data.rules || data.tree || permissionTree.value;
        roleForm.user = data.user || [];
        if (data.role) {
            roleForm.name = data.role.name;
            roleForm.remark = data.role.remark;
            roleForm.status = Number(data.role.status);
        }

        await nextTick();
        if (Array.isArray(data.auth) && treeRef.value) {
            treeRef.value.setCheckedKeys(data.auth);
        }
    } catch (error) {
        ElMessage.error(error.message);
    }

    roleVisible.value = true;
};

const filterNode = (value, data) => {
    if (!value) {
        return true;
    }

    return String(data.title).includes(value);
};

watch(filterText, (value) => {
    if (treeRef.value) {
        treeRef.value.filter(value);
    }
});

const submitRole = async () => {
    saving.value = true;

    try {
        const auth = treeRef.value ? [...treeRef.value.getCheckedKeys(), ...treeRef.value.getHalfCheckedKeys()] : [];
        const payload = { ...roleForm, auth, auth_role: auth.join(',') };

        if (roleForm.id) {
            await client.post('rbac/edit', payload);
        } else {
            await client.post('rbac', payload);
        }

        ElMessage.success('保存成功');
        roleVisible.value = false;
        loadRoles();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const submitCopy = async () => {
    saving.value = true;

    try {
        await client.post('rbac/copyRole', { ...copyForm });
        ElMessage.success('复制成功');
        copyVisible.value = false;
        loadRoles();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeRole = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除分组「${row.name}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.delete(`rbac/${row.id}/`);
        ElMessage.success('删除成功');
        loadRoles();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(async () => {
    try {
        const response = await client.get('adminuser', { params: { page: 1, limit: 200 } });
        admins.value = pickList(response.data).list;
    } catch {
        // Optional dictionary.
    }

    load();
    loadRoles();
});
</script>

<style scoped>
.perm-tree {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 8px;
    width: 100%;
}
</style>
