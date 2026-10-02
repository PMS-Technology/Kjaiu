import { createRouter, createWebHashHistory } from 'vue-router';

import Layout from '../components/Layout.vue';
import Login from '../views/Login.vue';
import Dashboard from '../views/Dashboard.vue';

// Routes are lazy-loaded so the initial bundle stays small; names match the
// administrator menu entries defined in the layout.
const routes = [
    { path: '/login', name: 'login', component: Login, meta: { public: true } },
    {
        path: '/',
        component: Layout,
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard', name: 'dashboard', component: Dashboard, meta: { title: '控制台' } },

            { path: 'customers', name: 'customers', component: () => import('../views/Customers.vue'), meta: { title: '客户列表' } },
            { path: 'customers/:id', name: 'customer-detail', component: () => import('../views/CustomerDetail.vue'), meta: { title: '客户详情' } },

            { path: 'orders', name: 'orders', component: () => import('../views/Orders.vue'), meta: { title: '产品订单' } },
            { path: 'services', name: 'services', component: () => import('../views/Services.vue'), meta: { title: '业务列表' } },
            { path: 'services/:id', name: 'service-detail', component: () => import('../views/ServiceDetail.vue'), meta: { title: '业务详情' } },
            { path: 'cancel-requests', name: 'cancel-requests', component: () => import('../views/CancelRequests.vue'), meta: { title: '产品暂停请求' } },

            { path: 'invoices', name: 'invoices', component: () => import('../views/Invoices.vue'), meta: { title: '账单管理' } },
            { path: 'invoices/:id', name: 'invoice-detail', component: () => import('../views/InvoiceDetail.vue'), meta: { title: '账单详情' } },
            { path: 'transactions', name: 'transactions', component: () => import('../views/Transactions.vue'), meta: { title: '交易流水' } },

            { path: 'tickets', name: 'tickets', component: () => import('../views/Tickets.vue'), meta: { title: '工单列表' } },
            { path: 'tickets/:id', name: 'ticket-detail', component: () => import('../views/TicketDetail.vue'), meta: { title: '工单详情' } },

            { path: 'products', name: 'products', component: () => import('../views/Products.vue'), meta: { title: '商品管理' } },
            { path: 'products/:id', name: 'product-edit', component: () => import('../views/ProductEdit.vue'), meta: { title: '编辑商品' } },
            { path: 'config-options', name: 'config-options', component: () => import('../views/ConfigOptions.vue'), meta: { title: '全局可配置项' } },
            { path: 'servers', name: 'servers', component: () => import('../views/Servers.vue'), meta: { title: '通用接口' } },

            { path: 'suppliers', name: 'suppliers', component: () => import('../views/Suppliers.vue'), meta: { title: '供应商管理' } },
            { path: 'supplier-products', name: 'supplier-products', component: () => import('../views/SupplierProducts.vue'), meta: { title: '上游商品管理' } },
            { path: 'task-queue', name: 'task-queue', component: () => import('../views/TaskQueue.vue'), meta: { title: '任务队列' } },
            { path: 'downstream', name: 'downstream', component: () => import('../views/Downstream.vue'), meta: { title: '下游管理' } },

            { path: 'settings', name: 'settings', component: () => import('../views/Settings.vue'), meta: { title: '常规设置' } },
            { path: 'logs', name: 'logs', component: () => import('../views/Logs.vue'), meta: { title: '系统日志' } },
            { path: 'admins', name: 'admins', component: () => import('../views/Admins.vue'), meta: { title: '员工管理' } },
        ],
    },
];

export default createRouter({
    history: createWebHashHistory(),
    routes,
});
