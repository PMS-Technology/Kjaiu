import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import zhCn from 'element-plus/es/locale/lang/zh-cn';
import 'element-plus/dist/index.css';

import App from './App.vue';
import router from './router';
import { client } from './api/client';

const app = createApp(App);

app.use(router);
app.use(ElementPlus, { locale: zhCn });
app.provide('http', client);
app.mount('#admin-app');
