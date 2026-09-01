<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
});

const page = usePage();
const currentUrl = page.url ?? '';

const links = [
    { href: `/admin/clients/${props.client.id}/connections`, label: 'Conexiones' },
    { href: `/admin/clients/${props.client.id}/owners`, label: 'Propietarios' },
    { href: `/admin/clients/${props.client.id}/templates`, label: 'Plantillas Treble' },
    { href: `/admin/clients/${props.client.id}/flows`, label: 'Flujos' },
    { href: `/admin/clients/${props.client.id}/records`, label: 'Records' },
];
</script>

<template>
    <div class="tabs">
        <div class="title">
            <strong>{{ client.name }}</strong>
            <span>{{ client.slug }}</span>
        </div>
        <div class="links">
            <Link
                v-for="link in links"
                :key="link.href"
                :href="link.href"
                :class="{ active: currentUrl.startsWith(link.href) }"
            >
                {{ link.label }}
            </Link>
        </div>
    </div>
</template>

<style scoped>
.tabs {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}

.title {
    display: grid;
    gap: 2px;
}

.title span {
    color: #64748b;
    font-size: 13px;
}

.links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.links a {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    color: #334155;
    text-decoration: none;
}

.links a.active {
    border-color: #2563eb;
    color: #1d4ed8;
    background: #eff6ff;
}
</style>
