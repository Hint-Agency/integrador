<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    title: {
        type: String,
        required: true,
    },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const currentUrl = computed(() => page.url ?? '');

const links = [
    { href: '/dashboard', label: 'Panel', icon: 'M4 13h6V4H4v9zM14 20h6V4h-6v16zM4 20h6v-3H4v3z' },
    { href: '/admin/events', label: 'Eventos', icon: 'M5 7h14M5 12h14M5 17h14' },
    { href: '/admin/platforms', label: 'Plataformas', icon: 'M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM4 7.5l8 4.5 8-4.5M12 12v9' },
    { href: '/admin/properties', label: 'Propiedades', icon: 'M4 7h16M4 12h10M4 17h16' },
    { href: '/admin/records', label: 'Registros', icon: 'M6 3h12v18H6zM9 7h6M9 11h6M9 15h4' },
    { href: '/admin/users', label: 'Usuarios', icon: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75' },
    { href: '/admin/roles', label: 'Roles', icon: 'M12 2l7 4v6c0 5-3 8-7 10-4-2-7-5-7-10V6l7-4zM9 12l2 2 4-5' },
    { href: '/admin/categories', label: 'Categorías', icon: 'M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z' },
    { href: '/admin/configs', label: 'Configuración', icon: 'M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5zM19.4 15a1.8 1.8 0 0 0 .36 2l.04.04-2 3.46-.06-.02a1.8 1.8 0 0 0-1.96.44l-.04.04-3.48-2 .02-.06a1.8 1.8 0 0 0-.72-1.9H11.5a1.8 1.8 0 0 0-2 .36l-.04.04-3.46-2 .02-.06A1.8 1.8 0 0 0 5.6 13H5.5V9h.1a1.8 1.8 0 0 0 1.62-2.34l-.02-.06 3.46-2 .04.04A1.8 1.8 0 0 0 12.7 5h.06a1.8 1.8 0 0 0 1.96-.44l.04-.04 3.48 2-.02.06A1.8 1.8 0 0 0 18.94 8H19v4h-.1a1.8 1.8 0 0 0-1.5 3z' },
];

const logout = () => router.post('/logout');
const isActive = (href) => currentUrl.value === href || currentUrl.value.startsWith(`${href}/`);
</script>

<template>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand">
                <p>Integrador</p>
                <strong>Panel Admin</strong>
            </div>

            <nav class="nav">
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
                    {{ link.label }}
                </Link>
            </nav>
        </aside>

        <main class="content">
            <header class="header">
                <div>
                    <h1>{{ title }}</h1>
                    <p>Gestión administrativa del sistema de integración</p>
                </div>

                <div class="actions">
                    <span>
                        {{ user?.name ?? user?.email ?? 'Usuario' }}
                    </span>
                    <button type="button" @click="logout">Cerrar sesión</button>
                </div>
            </header>

            <section class="panel">
                <slot />
            </section>
        </main>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Barlow+Condensed:wght@600&display=swap');

.shell {
    min-height: 100vh;
    display: grid;
    grid-template-columns: 260px 1fr;
    font-family: 'DM Sans', sans-serif;
    background:
        radial-gradient(circle at 10% 10%, rgba(247, 201, 120, 0.38), transparent 35%),
        radial-gradient(circle at 90% 20%, rgba(91, 173, 255, 0.34), transparent 34%),
        #f6f8fb;
}

.sidebar {
    background: linear-gradient(180deg, #1d2a35 0%, #0f1720 100%);
    color: #d1dae3;
    padding: 26px 20px;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.brand p {
    margin: 0;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.14em;
    color: #8ba1b5;
}

.brand strong {
    font-family: 'Barlow Condensed', sans-serif;
    letter-spacing: 0.04em;
    font-size: 24px;
}

.nav {
    display: grid;
    gap: 8px;
}

.nav a {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #d6e0ea;
    text-decoration: none;
    border: 1px solid rgba(214, 224, 234, 0.2);
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 14px;
    transition: background 0.16s ease, border-color 0.16s ease, transform 0.16s ease;
}

.nav svg {
    width: 18px;
    height: 18px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.9;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.nav a:hover {
    background: rgba(255, 255, 255, 0.12);
    transform: translateX(2px);
}

.nav a.active {
    border-color: rgba(255, 255, 255, 0.38);
    background: rgba(255, 255, 255, 0.16);
    color: #fff;
}

.content {
    padding: 26px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.header h1 {
    margin: 0;
    font-size: 27px;
}

.header p {
    margin: 4px 0 0;
    color: #5a6675;
}

.actions {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #334155;
}

.actions button {
    border: 0;
    border-radius: 999px;
    background: #1d4ed8;
    color: #fff;
    padding: 9px 13px;
    cursor: pointer;
    font-size: 13px;
}

.panel {
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(148, 163, 184, 0.26);
    border-radius: 16px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.1);
    padding: 18px;
}

.panel:has(.dashboard-grid) {
    background: transparent;
    border: 0;
    box-shadow: none;
    padding: 0;
}

@media (max-width: 900px) {
    .shell {
        grid-template-columns: 1fr;
    }

    .sidebar {
        gap: 14px;
    }

    .content {
        padding: 16px;
    }

    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
}
</style>
