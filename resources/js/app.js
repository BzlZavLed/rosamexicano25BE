import { createApp } from 'vue';
import App from './spa/App.vue';
import router from './spa/router';

createApp(App).use(router).mount('#app');