<template>
    <div class="page-card" v-loading="loading">
        <div class="detail-head">
            <div>
                <h2>{{ form.title || '工单详情' }} <span class="uid">#{{ ticketId }}</span></h2>
                <p class="sub">
                    客户：<el-link type="primary" @click="$router.push(`/customers/${form.uid}`)">{{ form.username }}</el-link>
                    <el-tag size="small">{{ statusTitle }}</el-tag>
                    <el-tag v-if="form.priority" size="small" type="warning">{{ form.priority }}</el-tag>
                </p>
            </div>
            <div class="head-actions">
                <el-button @click="receive">接单</el-button>
                <el-button @click="transferVisible = true">转单</el-button>
                <el-button type="danger" @click="closeTicket">关闭工单</el-button>
            </div>
        </div>

        <el-tabs v-model="activeTab">
            <el-tab-pane label="工单会话" name="thread">
                <div class="thread">
                    <div v-for="reply in replies" :key="reply.id" class="thread-item" :class="{ admin: isAdmin(reply) }">
                        <div class="thread-meta">
                            <strong>{{ reply.admin || reply.username || '客户' }}</strong>
                            <span class="time">{{ formatTime(reply.create_time) }}</span>
                        </div>
                        <div class="thread-body" v-html="reply.content"></div>
                        <div v-if="reply.attachment" class="attachments">
                            <el-link
                                v-for="(file, index) in parseAttachments(reply.attachment)"
                                :key="index"
                                type="primary"
                                @click="downloadAttachment(file)"
                            >
                                {{ file.name || file }}
                            </el-link>
                        </div>
                        <div class="thread-actions">
                            <el-button link type="primary" size="small" @click="editReply(reply)">编辑</el-button>
                            <el-button link type="danger" size="small" @click="deleteReply(reply)">删除</el-button>
                        </div>
                    </div>
                    <el-empty v-if="replies.length === 0" description="暂无回复" />
                </div>

                <el-form class="reply-form">
                    <el-form-item>
                        <el-input v-model="replyContent" type="textarea" :rows="5" placeholder="请输入回复内容" />
                    </el-form-item>
                    <el-form-item>
                        <el-select v-model="presetId" placeholder="预定义回复" clearable style="width: 260px" @change="applyPreset">
                            <el-option v-for="preset in presets" :key="preset.id" :label="preset.title" :value="preset.id" />
                        </el-select>
                        <span class="spacer" />
                        <el-button @click="reply(false)" :loading="saving">提交回复</el-button>
                        <el-button type="primary" @click="reply(true)" :loading="saving">回复并关闭</el-button>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="内部备注" name="notes">
                <el-form>
                    <el-form-item>
                        <el-input v-model="noteContent" type="textarea" :rows="4" placeholder="仅管理员可见的备注" />
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" @click="addNote">添加备注</el-button>
                    </el-form-item>
                </el-form>

                <el-table :data="notes" size="small" border empty-text="暂无数据">
                    <el-table-column prop="admin" label="管理员" width="130" />
                    <el-table-column prop="content" label="内容" min-width="240" />
                    <el-table-column label="时间" width="170">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column label="操作" width="90">
                        <template #default="{ row }">
                            <el-button link type="danger" size="small" @click="deleteNote(row)">删除</el-button>
                        </template>
                    </el-table-column>
                </el-table>
            </el-tab-pane>

            <el-tab-pane label="选项" name="options">
                <el-form :model="form" label-width="120px" class="edit-form">
                    <el-form-item label="部门">
                        <el-select v-model="form.dptid" @change="saveTicket">
                            <el-option v-for="dept in departments" :key="dept.id" :label="dept.name" :value="dept.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="客户名">
                        <el-autocomplete
                            v-model="form.username"
                            :fetch-suggestions="fetchClients"
                            style="width: 100%"
                            @select="onClientSelect"
                        />
                    </el-form-item>
                    <el-form-item label="工单标题">
                        <el-input v-model="form.title" />
                    </el-form-item>
                    <el-form-item label="状态">
                        <el-select v-model="form.status" @change="saveTicket">
                            <el-option v-for="status in statuses" :key="status.id" :label="status.title" :value="status.id" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="优先级">
                        <el-select v-model="form.priority" @change="saveTicket">
                            <el-option label="低" value="low" />
                            <el-option label="中" value="medium" />
                            <el-option label="高" value="high" />
                        </el-select>
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" :loading="saving" @click="saveTicket">保存</el-button>
                    </el-form-item>
                </el-form>
            </el-tab-pane>

            <el-tab-pane label="产品信息" name="hosts">
                <h3 class="block-title">关联产品</h3>
                <el-table :data="hosts" size="small" border empty-text="暂无数据">
                    <el-table-column prop="productname" label="产品" min-width="200" />
                    <el-table-column prop="amount" label="金额" width="110" align="right" />
                    <el-table-column prop="billingcycle" label="周期" width="110" />
                    <el-table-column label="购买时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                    </el-table-column>
                    <el-table-column label="到期时间" width="160">
                        <template #default="{ row }">{{ formatTime(row.nextduedate) }}</template>
                    </el-table-column>
                    <el-table-column prop="domainstatus" label="状态" width="110" />
                </el-table>
            </el-tab-pane>
        </el-tabs>

        <el-dialog v-model="transferVisible" title="转单" width="520px">
            <el-form :model="transferForm" label-width="100px">
                <el-form-item label="转移方式">
                    <el-radio-group v-model="transferForm.mode">
                        <el-radio :value="1">指定处理人</el-radio>
                        <el-radio :value="2">转移部门</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item v-if="transferForm.mode === 1" label="指定处理人">
                    <el-select v-model="transferForm.handle" filterable>
                        <el-option v-for="admin in admins" :key="admin.id" :label="admin.user_nickname" :value="admin.id" />
                    </el-select>
                </el-form-item>
                <el-form-item v-else label="处理部门">
                    <el-select v-model="transferForm.dptid">
                        <el-option v-for="dept in departments" :key="dept.id" :label="dept.name" :value="dept.id" />
                    </el-select>
                </el-form-item>
                <el-form-item label="备注">
                    <el-input v-model="transferForm.remarks" type="textarea" :rows="2" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="transferVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitTransfer">确定</el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="replyEditVisible" title="编辑回复" width="560px">
            <el-input v-model="editContent" type="textarea" :rows="6" />
            <template #footer>
                <el-button @click="replyEditVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="submitEditReply">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';

import client from '../api/client';
import { formatTime } from '../utils/format';

const route = useRoute();
const ticketId = computed(() => route.params.id);

const loading = ref(false);
const saving = ref(false);
const activeTab = ref('thread');

const ticket = ref({});
const replies = ref([]);
const notes = ref([]);
const hosts = ref([]);
const departments = ref([]);
const statuses = ref([]);
const presets = ref([]);
const admins = ref([]);

const replyContent = ref('');
const noteContent = ref('');
const presetId = ref('');
const transferVisible = ref(false);
const replyEditVisible = ref(false);
const editContent = ref('');
const editingReplyId = ref('');

const form = reactive({
    dptid: '',
    uid: '',
    username: '',
    title: '',
    status: '',
    priority: '',
});

const transferForm = reactive({ mode: 1, handle: '', dptid: '', remarks: '' });

const statusTitle = computed(() => {
    const found = statuses.value.find((status) => String(status.id) === String(form.status));

    return found ? found.title : form.status;
});

const isAdmin = (reply) => Boolean(reply.admin) || Number(reply.admin_id) > 0;

const parseAttachments = (attachment) => {
    if (!attachment) {
        return [];
    }

    if (Array.isArray(attachment)) {
        return attachment;
    }

    try {
        const parsed = JSON.parse(attachment);

        return Array.isArray(parsed) ? parsed : [attachment];
    } catch {
        return String(attachment)
            .split(',')
            .filter((item) => item.length > 0)
            .map((item) => ({ name: item, path: item }));
    }
};

const load = async () => {
    loading.value = true;

    try {
        const response = await client.get(`list_ticket/${ticketId.value}`);
        const data = response.data || {};

        ticket.value = data.ticket || data;
        replies.value = data.replies || data.reply || [];
        notes.value = data.notes || [];
        hosts.value = data.hosts || data.host || [];

        Object.keys(form).forEach((key) => {
            if (ticket.value[key] !== undefined && ticket.value[key] !== null) {
                form[key] = ticket.value[key];
            }
        });
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        loading.value = false;
    }
};

const applyPreset = (value) => {
    const preset = presets.value.find((item) => item.id === value);

    if (preset) {
        replyContent.value = preset.content;
    }
};

const reply = async (closeAfter) => {
    if (!replyContent.value.trim()) {
        ElMessage.warning('请输入回复内容');
        return;
    }

    saving.value = true;

    try {
        await client.post('reply_ticket', {
            id: ticketId.value,
            content: replyContent.value,
            close: closeAfter ? 1 : 0,
        });
        ElMessage.success('回复成功');
        replyContent.value = '';
        presetId.value = '';
        await load();

        if (closeAfter) {
            form.status = 'Closed';
        }
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const editReply = (row) => {
    editingReplyId.value = row.id;
    editContent.value = row.content;
    replyEditVisible.value = true;
};

const submitEditReply = async () => {
    saving.value = true;

    try {
        await client.post('save_ticket_reply', { id: editingReplyId.value, content: editContent.value });
        ElMessage.success('保存成功');
        replyEditVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const deleteReply = async (row) => {
    try {
        await ElMessageBox.confirm('确定删除该回复？', '删除确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('delete_ticket_reply', { id: row.id });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const addNote = async () => {
    if (!noteContent.value.trim()) {
        ElMessage.warning('请输入备注内容');
        return;
    }

    try {
        await client.post('add_ticket_note', { id: ticketId.value, content: noteContent.value });
        noteContent.value = '';
        ElMessage.success('添加成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const deleteNote = async (row) => {
    try {
        await client.post('delete_ticket_note', { id: row.id });
        ElMessage.success('删除成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const saveTicket = async () => {
    saving.value = true;

    try {
        await client.post('save_ticket', { id: ticketId.value, ...form });
        ElMessage.success('保存成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const receive = async () => {
    try {
        await client.put('ticket_receive', { id: ticketId.value });
        ElMessage.success('接单成功');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const submitTransfer = async () => {
    saving.value = true;

    try {
        await client.put('ticket_transfer', { id: ticketId.value, ...transferForm });
        ElMessage.success('转单成功');
        transferVisible.value = false;
        load();
    } catch (error) {
        ElMessage.error(error.message);
    } finally {
        saving.value = false;
    }
};

const closeTicket = async () => {
    try {
        await ElMessageBox.confirm('确定关闭该工单？', '关闭确认', { type: 'warning' });
    } catch {
        return;
    }

    try {
        await client.post('close_ticket', { id: ticketId.value });
        ElMessage.success('工单已关闭');
        load();
    } catch (error) {
        ElMessage.error(error.message);
    }
};

const downloadAttachment = (file) => {
    const name = typeof file === 'string' ? file : file.path || file.name;

    // The endpoint streams the file itself, so the raw response is used rather
    // than the envelope-unwrapping interceptor output.
    client
        .get('download_ticket_attachment', {
            params: { file: name },
            responseType: 'blob',
            transformResponse: (data) => data,
        })
        .then((response) => {
            const url = window.URL.createObjectURL(new Blob([response.data ?? response]));
            const link = document.createElement('a');
            link.href = url;
            link.download = typeof file === 'string' ? file : file.name || 'attachment';
            link.click();
            window.URL.revokeObjectURL(url);
        })
        .catch((error) => ElMessage.error(error.message));
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

const onClientSelect = (item) => {
    form.uid = item.uid || item.id;
};

onMounted(async () => {
    try {
        const [departmentList, statusList, presetList, adminList] = await Promise.all([
            client.get('list_ticket_department'),
            client.get('list_ticket_status'),
            client.post('search_ticket_prereply', {}),
            client.get('adminuser', { params: { page: 1, limit: 100 } }),
        ]);

        departments.value = departmentList.data.list || departmentList.data || [];
        statuses.value = statusList.data.list || statusList.data || [];
        presets.value = presetList.data.list || presetList.data || [];
        admins.value = adminList.data.list || adminList.data || [];
    } catch {
        // Optional dictionaries.
    }

    await load();
});
</script>

<style scoped>
.detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 12px;
}

.detail-head h2 {
    margin: 0;
    font-size: 19px;
}

.uid {
    color: #9ca3af;
    font-size: 14px;
    font-weight: 400;
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

.thread {
    max-height: 520px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.thread-item {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 12px;
    background: #fff;
}

.thread-item.admin {
    background: #f0f7ff;
    border-color: #bfdbfe;
}

.thread-meta {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #374151;
    margin-bottom: 8px;
}

.thread-meta .time {
    color: #9ca3af;
}

.thread-body {
    font-size: 14px;
    line-height: 1.6;
    word-break: break-word;
}

.thread-actions,
.attachments {
    margin-top: 8px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.reply-form,
.edit-form {
    max-width: 900px;
}

.block-title {
    font-size: 14px;
    margin: 12px 0 10px;
}
</style>
