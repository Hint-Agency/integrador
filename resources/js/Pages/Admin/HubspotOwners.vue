<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
    owners: { type: Array, required: true },
});

const destroyOwner = (owner) => {
    if (confirm(`Eliminar ${owner.name}?`)) {
        router.delete(`/admin/clients/${props.client.id}/owners/${owner.id}`);
    }
};
</script>

<template>
    <AdminLayout title="Propietarios HubSpot">
        <ClientTabs :client="client" />
        <div class="page-head">
            <p>Asesores disponibles para el primer paso de los flujos de este cliente.</p>
            <Link class="primary" :href="`/admin/clients/${client.id}/owners/create`">Nuevo propietario</Link>
        </div>

        <div v-if="owners.length" class="stack">
            <article v-for="owner in owners" :key="owner.id" class="item">
                <div>
                    <strong>{{ owner.name }}</strong>
                    <p>ID HubSpot: {{ owner.external_owner_id }}</p>
                    <small>{{ owner.email || 'Sin correo' }} · {{ owner.automation_flows_count }} flujo(s) · {{ owner.active ? 'Activo' : 'Inactivo' }}</small>
                </div>
                <div class="actions">
                    <Link :href="`/admin/clients/${client.id}/owners/${owner.id}/edit`">Editar</Link>
                    <button type="button" @click="destroyOwner(owner)">Eliminar</button>
                </div>
            </article>
        </div>
        <p v-else class="empty">Todavía no hay propietarios configurados.</p>
    </AdminLayout>
</template>

<style scoped>
.page-head, .item, .actions { display: flex; align-items: center; gap: 12px; }
.page-head { justify-content: space-between; margin-bottom: 16px; }
.stack { display: grid; gap: 12px; }
.item { justify-content: space-between; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
.item p, .item small { display: block; margin: 4px 0 0; color: #64748b; }
.actions a, .actions button, .primary { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; background: #fff; color: #334155; text-decoration: none; }
.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.actions button { cursor: pointer; }
.empty { color: #64748b; }
@media (max-width: 720px) { .page-head, .item { align-items: stretch; flex-direction: column; } }
</style>
