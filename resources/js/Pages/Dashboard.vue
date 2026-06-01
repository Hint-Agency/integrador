<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <AdminLayout title="Panel">
        <div class="dashboard-grid">
            <section class="hero-card">
                <p class="kicker">Centro de control de integraciones</p>
                <h2>Panel de administración</h2>
                <p>
                    Estado general del sistema de integración, eventos configurados y trazabilidad operativa.
                </p>
            </section>

            <section class="cards" aria-label="Resumen del sistema">
                <article class="card">
                    <span class="label">Eventos</span>
                    <strong>{{ stats.events }}</strong>
                </article>
                <article class="card">
                    <span class="label">Eventos activos</span>
                    <strong>{{ stats.active_events }}</strong>
                </article>
                <article class="card">
                    <span class="label">Plataformas</span>
                    <strong>{{ stats.platforms }}</strong>
                </article>
                <article class="card">
                    <span class="label">Registros</span>
                    <strong>{{ stats.records }}</strong>
                </article>
            </section>

            <section class="quick-panel">
                <div>
                    <p class="kicker">Accesos rápidos</p>
                    <h3>Operación diaria</h3>
                </div>

                <div class="shortcuts">
                    <a href="/admin/events">Eventos</a>
                    <a href="/admin/platforms">Plataformas</a>
                    <a href="/admin/properties">Propiedades</a>
                    <a href="/admin/records">Registros</a>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.dashboard-grid {
    display: grid;
    gap: 18px;
}

.hero-card {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(51, 65, 85, 0.12);
    border-radius: 22px;
    padding: 26px;
    background:
        radial-gradient(circle at 92% 18%, rgba(251, 191, 36, 0.42), transparent 28%),
        linear-gradient(135deg, #fff 0%, #eef6ff 100%);
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.08);
}

.kicker {
    margin: 0 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
}

.hero-card h2,
.quick-panel h3 {
    margin: 0;
    color: #172033;
}

.hero-card h2 {
    font-size: 30px;
}

.hero-card p:last-child {
    max-width: 560px;
    margin: 10px 0 0;
    color: #526174;
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
}

.card {
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 18px;
    padding: 18px;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
}

.label {
    display: block;
    margin-bottom: 8px;
    color: #64748b;
    font-size: 13px;
}

.card strong {
    color: #0f172a;
    font-size: 30px;
}

.quick-panel {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    border: 1px solid rgba(148, 163, 184, 0.28);
    border-radius: 18px;
    padding: 18px;
    background: rgba(255, 255, 255, 0.88);
}

.shortcuts {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.shortcuts a {
    border: 1px solid #d6dee8;
    border-radius: 999px;
    padding: 8px 12px;
    background: #fff;
    color: #334155;
    font-size: 13px;
    text-decoration: none;
}

.shortcuts a:hover {
    border-color: #9db1c9;
    background: #f8fbff;
}

@media (max-width: 720px) {
    .quick-panel {
        align-items: flex-start;
        flex-direction: column;
    }

    .shortcuts {
        justify-content: flex-start;
    }
}
</style>
