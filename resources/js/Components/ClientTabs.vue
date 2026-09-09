<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    client: { type: Object, required: true },
});

const page = usePage();
const currentUrl = computed(() => page.url ?? '');

const links = [
    {
        href: `/admin/clients/${props.client.id}/connections`,
        label: 'Conexiones',
        icon: 'M8 12h8M9 8l-3 4 3 4M15 8l3 4-3 4M5 5h14v14H5z',
    },
    {
        href: `/admin/clients/${props.client.id}/owners`,
        label: 'Propietarios',
        icon: 'M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M17 8h4M19 6v4',
    },
    {
        href: `/admin/clients/${props.client.id}/templates`,
        label: 'Plantillas Treble',
        icon: 'M6 3h9l4 4v14H6zM15 3v5h4M9 12h7M9 16h7',
    },
    {
        href: `/admin/clients/${props.client.id}/flows`,
        label: 'Flujos',
        icon: 'M5 5h5v5H5zM14 14h5v5h-5zM10 7.5h3a3 3 0 0 1 3 3V14M14 16.5h-3a3 3 0 0 1-3-3V10',
    },
    {
        href: `/admin/clients/${props.client.id}/records`,
        label: 'Records',
        icon: 'M6 3h12v18H6zM9 8h6M9 12h6M9 16h4',
    },
];

const isActive = (href) => currentUrl.value === href || currentUrl.value.startsWith(`${href}/`);
</script>

<template>
    <section class="client-navigation">
        <div class="context">
            <span>Cliente</span>
            <div>
                <strong>{{ client.name }}</strong>
                <small>{{ client.slug }}</small>
            </div>
        </div>

        <nav class="subnav" aria-label="Configuración del cliente">
            <Link
                v-for="link in links"
                :key="link.href"
                :href="link.href"
                :class="{ active: isActive(link.href) }"
                :aria-current="isActive(link.href) ? 'page' : undefined"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path :d="link.icon" />
                </svg>
                <span>{{ link.label }}</span>
            </Link>
        </nav>
    </section>
</template>

<style scoped>
.client-navigation {
    display: grid;
    gap: 12px;
    margin-bottom: 20px;
    border-bottom: 1px solid #dbe2e8;
}

.context {
    display: flex;
    align-items: center;
    gap: 10px;
}

.context > span {
    border-radius: 6px;
    padding: 4px 7px;
    background: #ecfdf5;
    color: #047857;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.context > div {
    display: grid;
    gap: 2px;
}

.context small {
    color: #64748b;
    font-size: 12px;
}

.subnav {
    display: flex;
    gap: 4px;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: thin;
}

.subnav a {
    position: relative;
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 44px;
    border: 0;
    border-radius: 8px 8px 0 0;
    padding: 10px 13px 12px;
    color: #475569;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: color 0.16s ease, background 0.16s ease;
}

.subnav a::after {
    content: '';
    position: absolute;
    right: 10px;
    bottom: 0;
    left: 10px;
    height: 3px;
    border-radius: 3px 3px 0 0;
    background: transparent;
}

.subnav a:hover {
    background: #f1f5f9;
    color: #0f172a;
}

.subnav a:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: -2px;
}

.subnav svg {
    width: 18px;
    height: 18px;
    flex: 0 0 auto;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.subnav a.active {
    color: #1d4ed8;
    background: #eff6ff;
}

.subnav a.active::after {
    background: #2563eb;
}

@media (max-width: 680px) {
    .client-navigation {
        margin-right: -18px;
        margin-left: -18px;
    }

    .context {
        padding: 0 18px;
    }

    .subnav {
        padding: 0 10px;
    }
}
</style>
