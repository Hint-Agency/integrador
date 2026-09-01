<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mode: { type: String, required: true },
    client: { type: Object, required: true },
    owner: { type: Object, default: null },
});

const isEdit = computed(() => props.mode === 'edit');
const form = useForm({
    name: props.owner?.name ?? '',
    external_owner_id: props.owner?.external_owner_id ?? '',
    email: props.owner?.email ?? '',
    active: props.owner?.active ?? true,
});

const submit = () => {
    form.transform(() => ({
        name: form.name.trim(),
        external_owner_id: form.external_owner_id.trim(),
        email: form.email.trim() || null,
        active: !!form.active,
    }));

    if (isEdit.value) {
        form.put(`/admin/clients/${props.client.id}/owners/${props.owner.id}`);
        return;
    }

    form.post(`/admin/clients/${props.client.id}/owners`);
};
</script>

<template>
    <AdminLayout :title="isEdit ? 'Editar propietario' : 'Nuevo propietario'">
        <ClientTabs :client="client" />
        <form class="form" @submit.prevent="submit">
            <div class="grid">
                <label>
                    <span>Nombre</span>
                    <input v-model="form.name" type="text" required placeholder="Ana López">
                    <small v-if="form.errors.name">{{ form.errors.name }}</small>
                </label>
                <label>
                    <span>ID de propietario en HubSpot</span>
                    <input v-model="form.external_owner_id" type="text" required placeholder="12345678">
                    <small v-if="form.errors.external_owner_id">{{ form.errors.external_owner_id }}</small>
                </label>
                <label>
                    <span>Correo</span>
                    <input v-model="form.email" type="email" placeholder="asesor@colegio.com">
                    <small v-if="form.errors.email">{{ form.errors.email }}</small>
                </label>
            </div>

            <label class="checkbox"><input v-model="form.active" type="checkbox"><span>Activo</span></label>
            <div class="actions">
                <Link :href="`/admin/clients/${client.id}/owners`">Cancelar</Link>
                <button type="submit" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.form { display: grid; gap: 16px; max-width: 920px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
label { display: grid; gap: 6px; }
span { color: #334155; font-size: 14px; }
input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
small { color: #b91c1c; }
.checkbox { display: flex; align-items: center; gap: 10px; }
.checkbox input { width: auto; }
.actions { display: flex; gap: 10px; }
.actions a, .actions button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; cursor: pointer; }
</style>
