<template>
    <div class="page-card">
        <el-tabs v-model="activeTab" @tab-change="onTabChange">
            <el-tab-pane label="接口列表" name="servers">
                <DataTable
                    v-model:page="serverQuery.page"
                    v-model:limit="serverQuery.limit"
                    :rows="servers"
                    :total="serverTotal"
                    :loading="loading"
                    @pagination="loadServers"
                >
                    <template #toolbar>
                        <el-input
                            v-model="serverQuery.search"
                            placeholder="接口名称/IP"
                            clearable
                            style="width: 190px"
                            @keyup.enter="searchServers"
                        />
                        <el-select v-model="serverQuery.gid" placeholder="接口分组" clearable style="width: 160px" @change="searchServers">
                            <el-option v-for="group in serverGroups" :key="group.id" :label="group.name" :value="group.id" />
                        </el-select>

                        <span class="spacer" />

                        <el-button type="primary" @click="searchServers">搜索</el-button>
                        <el-button type="success" @click="openServer()">添加接口</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column label="状态" width="60" align="center">
                        <template #default="{ row }">
                            <el-tag :type="Number(row.disabled) === 1 ? 'danger' : 'success'" size="small" effect="dark">
                                {{ Number(row.disabled) === 1 ? '停' : '启' }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column prop="name" label="接口名称" min-width="160" />
                    <el-table-column prop="type" label="服务器模块" width="160" />
                    <el-table-column prop="gname" label="接口分组名" width="150" />
                    <el-table-column prop="ip_address" label="IP地址" width="150" />
                    <el-table-column prop="open_num" label="已开通/容量" width="140" align="center">
                        <template #default="{ row }">{{ row.open_num ?? 0 }} / {{ row.max_accounts ?? '不限' }}</template>
                    </el-table-column>
                    <el-table-column label="操作" width="240" fixed="right">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="testLink(row)">测试连接</el-button>
                            <el-button link type="primary" size="small" @click="openServer(row)">编辑</el-button>
                            <el-button link type="danger" size="small" @click="removeServer(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>

            <el-tab-pane label="接口分组" name="groups">
                <DataTable
                    v-model:page="groupQuery.page"
                    v-model:limit="groupQuery.limit"
                    :rows="serverGroups"
                    :total="groupTotal"
                    :loading="loading"
                    @pagination="loadGroups"
                >
                    <template #toolbar>
                        <span class="spacer" />
                        <el-button type="primary" @click="loadGroups">刷新</el-button>
                        <el-button type="success" @click="openGroup()">添加分组</el-button>
                    </template>

                    <el-table-column prop="id" label="ID" width="70" align="center" />
                    <el-table-column prop="name" label="接口分组名" min-width="180" />
                    <el-table-column prop="type" label="模块类型" width="160" />
                    <el-table-column label="分配方式" width="140">
                        <template #default="{ row }">
                            {{ Number(row.mode) === 1 ? '平均分配' : '顺序分配' }}
                        </template>
                    </el-table-column>
                    <el-table-column prop="capacity" label="容量" width="100" align="center" />
                    <el-table-column label="操作" width="180">
                        <template #default="{ row }">
                            <el-button link type="primary" size="small" @click="openGroup(row)">编辑</el-button>
                            <el-button link type="danger" size="small" @click="removeGroup(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </DataTable>
            </el-tab-pane>
        </el-tabs>

        <!-- 接口编辑 -->
        <el-dialog v-model="serverVisible" :title="serverForm.id ? '编辑接口' : '添加接口'" width="640px">
            <el-form :model="serverForm" label-width="120px">
                <el-form-item label="名称">
                    <el-input v-model="serverForm.name" />
                </el-form-item>
                <el-form-item label="IP地址">
                    <el-input v-model="serverForm.ip_address" />
                </el-form-item>
                <el-form-item label="服务器模块">
                    <el-select v-model="serverForm.type" filterable @change="loadModuleConfig">
                        <el-option v-for="module in moduleTypes" :key="module.value" :label="module.name" :value="module.value" />
                    </el-select>
                </el-form-item>
                <el-form-item label="接口容量">
                    <el-input-number v-model="serverForm.max_accounts" :min="0" />
                </el-form-item>
                <el-form-item label="主机名">
                    <el-input v-model="serverForm.hostname" />
                </el-form-item>
                <el-form-item label="接口分组名">
                    <el-select v-model="serverForm.gid" clearable>
                        <el-option v-for="group in serverGroups" :key="group.id" :label="group.name" :value="group.id" />
                    </el-select>
                </el-form-item>

                <el-divider content-position="left">模块配置</el-divider>
                <el-form-item v-for="field in moduleConfig" :key="field.name" :label="field.title || field.name">
                    <el-select v-if="field.type === 'select'" v-model="moduleConfigValues[field.name]">
                        <el-option v-for="(label, value) in field.options" :key="value" :label="label" :value="value" />
                    </el-select>
                    <el-input
                        v-else
                        v-model="moduleConfigValues[field.name]"
                        :type="field.type === 'password' ? 'password' : 'text'"
                        show-password
                    />
                </el-form-item>
            </el-form>

            <template #footer>
                <el-button @click="serverVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitServer">保存</el-button>
            </template>
        </el-dialog>

        <!-- 分组编辑 -->
        <el-dialog v-model="groupVisible" :title="groupFormData.id ? '编辑接口分组' : '添加接口分组'" width="640px">
            <el-form :model="groupFormData" label-width="120px">
                <el-form-item label="接口分组名">
                    <el-input v-model="groupFormData.group_name" />
                </el-form-item>
                <el-form-item label="分配方式">
                    <el-radio-group v-model="groupFormData.mode">
                        <el-radio :value="1">平均分配</el-radio>
                        <el-radio :value="2">顺序分配</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="选择空闲接口">
                    <el-transfer
                        v-model="groupFormData.sid"
                        :data="transferData"
                        :titles="['空闲接口', '已选接口']"
                    />
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
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';

import DataTable from '../components/DataTable.vue';
import client from '../api/client';
import { pickList } from '../utils/format';

const activeTab = ref('servers');
const loading = ref(false);
const saving = ref(false);

const servers = ref([]);
const serverTotal = ref(0);
const serverGroups = ref([]);
const groupTotal = ref(0);
const moduleTypes = ref([]);
const moduleConfig = ref([]);
const moduleConfigValues = reactive({});
const transferData = ref([]);

const serverVisible = ref(false);
const groupVisible = ref(false);

const serverQuery = reactive({ page: 1, limit: 20, search: '', gid: '' });
const groupQuery = reactive({ page: 1, limit: 20 });

const serverForm = reactive({
    id: '',
    name: '',
    ip_address: '',
    type: '',
    max_accounts: 0,
    hostname: '',
    gid: '',
});

const groupFormData = reactive({ id: '', group_name: '', mode: 1, sid: [] });

const loadServers = async () => {
    loading.value = true;

    try {
        const response = await client.get('servers_list', { params: { ...serverQuery } });
        const data = response.data || {};
        const payload = pickList(data);

        servers.value = payload.list;
        serverTotal.value = payload.total;
        moduleTypes.value = data.interface_type || moduleTypes.value;
        serverGroups.value = data.groups || serverGroups.value;
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const searchServers = () => {
    serverQuery.page = 1;
    loadServers();
};

const loadGroups = async () => {
    loading.value = true;

    try {
        const response = await client.get('groups_list', { params: { ...groupQuery } });
        const payload = pickList(response.data);

        serverGroups.value = payload.list;
        groupTotal.value = payload.total;
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const onTabChange = (name) => {
    if (name === 'groups') {
        loadGroups();
    } else {
        loadServers();
    }
};

const loadModuleConfig = async () => {
    if (!serverForm.type) {
        moduleConfig.value = [];
        return;
    }

    try {
        const response = await client.post('get_modules_group', { type: serverForm.type });
        moduleConfig.value = response.data.config || response.data || [];
        moduleConfig.value.forEach((field) => {
            moduleConfigValues[field.name] = field.default ?? '';
        });
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openServer = async (row = null) => {
    Object.assign(serverForm, {
        id: row ? row.id : '',
        name: row ? row.name : '',
        ip_address: row ? row.ip_address : '',
        type: row ? row.type : '',
        max_accounts: row ? row.max_accounts : 0,
        hostname: row ? row.hostname : '',
        gid: row ? row.gid : '',
    });

    Object.keys(moduleConfigValues).forEach((key) => delete moduleConfigValues[key]);
    moduleConfig.value = [];

    try {
        const response = row
            ? await client.get(`edit_servers/${row.id}`)
            : await client.get('servers_add');
        const data = response.data || {};

        moduleConfig.value = data.config || [];
        moduleConfig.value.forEach((field) => {
            moduleConfigValues[field.name] = field.value ?? field.default ?? '';
        });

        if (data.interface_type) {
            moduleTypes.value = data.interface_type;
        }
        if (data.groups) {
            serverGroups.value = data.groups;
        }
        if (data.data) {
            Object.assign(serverForm, { ...serverForm, ...data.data, id: row ? row.id : '' });
        }
    } catch (error) {
        ElMessage.error(error.message);
    }

    serverVisible.value = true;
};

const submitServer = async () => {
    saving.value = true;

    try {
        const payload = { data: { ...serverForm }, config: { ...moduleConfigValues } };

        if (serverForm.id) {
            await client.post('edit_servers_post', payload);
        } else {
            await client.post('servers_add_post', payload);
        }

        ElMessage.success('保存成功');
        serverVisible.value = false;
        loadServers();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const testLink = async (row) => {
    try {
        const response = await client.get(`server_test_link/${row.id}`);
        const message = response.data.msg || response.msg || '链接成功';
        ElMessage.success(message);
        loadServers();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const removeServer = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除接口「${row.name}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`delete_servers/${row.id}`);
        ElMessage.success('删除成功');
        loadServers();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const openGroup = async (row = null) => {
    Object.assign(groupFormData, {
        id: row ? row.id : '',
        group_name: row ? row.name : '',
        mode: row ? Number(row.mode) || 1 : 1,
        sid: [],
    });

    try {
        const response = row
            ? await client.get(`edit_server_groups/${row.id}`)
            : await client.get('create_groups');
        const data = response.data || {};

        transferData.value = (data.servers || data.server_list || []).map((server) => ({
            key: server.id,
            label: server.name,
            disabled: false,
        }));
        if (Array.isArray(data.sid)) {
            groupFormData.sid = data.sid;
        }
    } catch (error) {
        ElMessage.error(error.message);
    }

    groupVisible.value = true;
};

const submitGroup = async () => {
    saving.value = true;

    try {
        const endpoint = groupFormData.id ? 'edit_server_groups_post' : 'create_groups_post';
        await client.post(endpoint, { ...groupFormData, sid: groupFormData.sid.join(',') });
        ElMessage.success('保存成功');
        groupVisible.value = false;
        loadGroups();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const removeGroup = async (row) => {
    try {
        await ElMessageBox.confirm(`确定删除接口分组「${row.name}」？`, '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.get(`delete_server_groups/${row.id}`);
        ElMessage.success('删除成功');
        loadGroups();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

onMounted(() => {
    loadServers();
    loadGroups();
});
</script>
