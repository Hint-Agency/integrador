import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

const pageElement = document.querySelector('script[data-page="app"]');

if (!pageElement?.textContent) {
    throw new Error('The initial Inertia page payload is missing.');
}

const initialPage = JSON.parse(pageElement.textContent);

createInertiaApp({
    page: initialPage,
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
