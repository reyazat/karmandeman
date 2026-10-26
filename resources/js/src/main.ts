import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';

const el = document.getElementById('app');
if (el) {
    createApp(App).use(createPinia()).use(router).mount(el);
}
