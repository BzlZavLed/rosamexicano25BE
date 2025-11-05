// src/main.ts
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router/index';
import App from './App.vue';
import '../css/app.css';

createApp(App).use(createPinia()).use(router).mount('#app');
