import { createRouter, createWebHistory } from 'vue-router';
import PrivacyPage from '../modules/privacy/pages/PrivacyPage.vue';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/app', redirect: '/app/privacy' },
        { path: '/app/privacy', component: PrivacyPage },
    ],
});
