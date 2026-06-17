import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});

// Fix for Inertia Laravel 3.x rendering data-page in script tag instead of div
document.addEventListener('DOMContentLoaded', function() {
    const appDiv = document.getElementById('app');
    if (appDiv && !appDiv.dataset.page) {
        const scriptTag = document.querySelector('script[data-page]');
        if (scriptTag && scriptTag.textContent) {
            try {
                appDiv.dataset.page = scriptTag.textContent;
            } catch (e) {
                console.error('Failed to move data-page from script to div', e);
            }
        }
    }
});
