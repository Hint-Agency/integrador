<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
    rules: { type: Array, required: true },
});

const destroyRule = (rule) => {
    if (confirm(`Eliminar ${rule.name}?`)) {
        router.delete(`/admin/clients/${props.client.id}/rules/${rule.id}`);
    }
};
</script>

<template>
    <AdminLayout title="Reglas de mensaje">
        <ClientTabs :client="client" />
        <div class="page-head">
            <p>Resolución por prioridad y coincidencia exacta.</p>
            <Link class="primary" :href="`/admin/clients/${client.id}/rules/create`">Nueva regla</Link>
        </div>
        <div class="stack">
            <article v-for="rule in rules" :key="rule.id" class="item">
                <div>
                    <strong>{{ rule.name }}</strong>
                    <p>{{ rule.trigger_property }} = {{ rule.trigger_value || '(vacío)' }}</p>
                    <small>
                        Prioridad {{ rule.priority }} · {{ rule.treble_template?.name || 'Sin plantilla' }}
                        · {{ Array.isArray(rule.conditions_list) ? rule.conditions_list.length : Object.keys(rule.conditions || {}).length }} condición(es)
                    </small>
                </div>
                <div class="actions">
                    <Link :href="`/admin/clients/${client.id}/rules/${rule.id}/edit`">Editar</Link>
                    <button type="button" @click="destroyRule(rule)">Eliminar</button>
                </div>
            </article>
        </div>
    </AdminLayout>
</template>

<style scoped>
.page-head, .item, .actions { display: flex; align-items: center; gap: 12px; }
.page-head { justify-content: space-between; margin-bottom: 16px; }
.stack { display: grid; gap: 12px; }
.item { justify-content: space-between; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
.item p, .item small { margin: 4px 0 0; color: #64748b; display: block; }
.actions a, .actions button, .primary { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; background: #fff; color: #334155; text-decoration: none; }
.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.actions button { cursor: pointer; }
</style>
