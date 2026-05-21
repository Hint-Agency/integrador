<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
    templates: { type: Array, required: true },
});

const destroyTemplate = (template) => {
    if (confirm(`Eliminar ${template.name}?`)) {
        router.delete(`/admin/clients/${props.client.id}/templates/${template.id}`);
    }
};
</script>

<template>
    <AdminLayout title="Plantillas Treble">
        <ClientTabs :client="client" />
        <div class="page-head">
            <p>Catalogo de IDs de plantillas y payload configurable.</p>
            <Link class="primary" :href="`/admin/clients/${client.id}/templates/create`">Nueva plantilla</Link>
        </div>
        <div class="stack">
            <article v-for="template in templates" :key="template.id" class="item">
                <div>
                    <strong>{{ template.name }}</strong>
                    <p>ID externo: {{ template.external_template_id }}</p>
                    <small>{{ Object.keys(template.request_template || {}).length }} variable(s) configurada(s)</small>
                </div>
                <div class="actions">
                    <Link :href="`/admin/clients/${client.id}/templates/${template.id}/edit`">Editar</Link>
                    <button type="button" @click="destroyTemplate(template)">Eliminar</button>
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
.item p { margin: 4px 0 0; color: #64748b; }
.actions a, .actions button, .primary { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; background: #fff; color: #334155; text-decoration: none; }
.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.actions button { cursor: pointer; }
</style>
