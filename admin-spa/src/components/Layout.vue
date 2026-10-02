<template>
    <el-container class="layout">
        <el-aside width="212px" class="aside">
            <div class="brand">
                <span class="brand-mark">K</span>
                <span class="brand-name">{{ siteName }}</span>
            </div>

            <el-menu :default-active="activePath" router class="menu" :collapse="false">
                <template v-for="group in menu" :key="group.title">
                    <el-sub-menu v-if="group.children" :index="group.title">
                        <template #title>
                            <el-icon><component :is="group.icon" /></el-icon>
                            <span>{{ group.title }}</span>
                        </template>
                        <el-menu-item v-for="item in group.children" :key="item.path" :index="item.path">
                            {{ item.title }}
                        </el-menu-item>
                    </el-sub-menu>
                    <el-menu-item v-else :index="group.path">
                        <el-icon><component :is="group.icon" /></el-icon>
                        <span>{{ group.title }}</span>
                    </el-menu-item>
                </template>
            </el-menu>
        </el-aside>

        <el-container>
            <el-header class="header">
                <div class="header-title">
                    <h1>{{ pageTitle }}</h1>
                </div>
                <div class="header-actions">
                    <el-button text @click="reload">
                        <el-icon><Refresh /></el-icon>
                    </el-button>
                    <el-dropdown @command="onCommand">
                        <span class="account">
                            {{ adminName }}
                            <el-icon><ArrowDown /></el-icon>
                        </span>
                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item command="logout">退出登录</el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </div>
            </el-header>

            <el-main class="main">
                <router-view v-slot="{ Component }">
                    <component :is="Component" :key="$route.fullPath" />
                </router-view>
            </el-main>
        </el-container>
    </el-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDown, Files, Goods, HomeFilled, List, Money, Refresh, Setting, Tickets, User, Connection } from '@element-plus/icons-vue';

import client from '../api/client';

const route = useRoute();
const router = useRouter();

const siteName = ref('Kjaiu');
const adminName = ref('admin');

const menu = [
    { title: '控制台', path: '/dashboard', icon: HomeFilled },
    {
        title: '客户',
        icon: User,
        children: [
            { title: '客户列表', path: '/customers' },
        ],
    },
    {
        title: '业务',
        icon: List,
        children: [
            { title: '产品订单', path: '/orders' },
            { title: '业务列表', path: '/services' },
            { title: '产品暂停请求', path: '/cancel-requests' },
        ],
    },
    {
        title: '财务',
        icon: Money,
        children: [
            { title: '账单管理', path: '/invoices' },
            { title: '交易流水', path: '/transactions' },
        ],
    },
    {
        title: '工单',
        icon: Tickets,
        children: [{ title: '工单列表', path: '/tickets' }],
    },
    {
        title: '商品设置',
        icon: Goods,
        children: [
            { title: '商品管理', path: '/products' },
            { title: '全局可配置项', path: '/config-options' },
            { title: '通用接口', path: '/servers' },
        ],
    },
    {
        title: '上下游',
        icon: Connection,
        children: [
            { title: '供应商管理', path: '/suppliers' },
            { title: '上游商品管理', path: '/supplier-products' },
            { title: '任务队列', path: '/task-queue' },
            { title: '下游管理', path: '/downstream' },
        ],
    },
    {
        title: '设置',
        icon: Setting,
        children: [
            { title: '常规设置', path: '/settings' },
            { title: '员工管理', path: '/admins' },
            { title: '系统日志', path: '/logs' },
        ],
    },
];

const activePath = computed(() => route.path);
const pageTitle = computed(() => route.meta.title || '控制台');

const reload = () => window.location.reload();

const onCommand = async (command) => {
    if (command === 'logout') {
        await client.get('logout').catch(() => {});
        router.push('/login');
    }
};

onMounted(async () => {
    try {
        const response = await client.get('common');
        siteName.value = response.data.site_name || siteName.value;
        adminName.value = response.data.user_name || adminName.value;
    } catch {
        // The guard on the API surface handles unauthenticated access.
    }
});
</script>

<style scoped>
.layout {
    height: 100vh;
}

.aside {
    background: #1f2937;
    color: #e5e7eb;
    display: flex;
    flex-direction: column;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 20px;
    font-weight: 600;
    color: #fff;
}

.brand-mark {
    display: inline-flex;
    width: 30px;
    height: 30px;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #3b82f6;
    color: #fff;
}

.menu {
    background: transparent;
    border-right: none;
    flex: 1;
}

:deep(.el-menu-item),
:deep(.el-sub-menu__title) {
    color: #cbd5e1;
}

:deep(.el-menu-item.is-active) {
    color: #fff;
    background: #2563eb;
}

:deep(.el-menu-item:hover),
:deep(.el-sub-menu__title:hover) {
    background: #374151;
}

.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
}

.header-title h1 {
    font-size: 17px;
    margin: 0;
    font-weight: 600;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 14px;
}

.account {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #374151;
}

.main {
    background: #f5f7fa;
    padding: 18px;
}
</style>
