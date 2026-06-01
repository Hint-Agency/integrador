<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LightboxFormModal from '@/Components/LightboxFormModal.vue';
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    role: { type: Object, default: null },
    permissions: { type: Array, default: () => [] },
});

const isEdit = computed(() => props.mode === 'edit');

const form = useForm({
    name: props.role?.name ?? '',
    slug: props.role?.slug ?? '',
    description: props.role?.description ?? '',
    permission_ids: props.role?.permission_ids ?? [],
});

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/roles/${props.role.id}`);
        return;
    }

    form.post('/admin/roles');
};
</script>

<template>
    <AdminLayout title="Roles">
        <LightboxFormModal :title="isEdit ? `Editar rol #${props.role?.id}` : 'Crear rol'" close-href="/admin/roles">
            <form class="lightbox-form" @submit.prevent="submit">
                <div class="lightbox-grid">
                    <label class="lightbox-field">
                        <span class="lightbox-label">Nombre</span>
                        <input v-model="form.name" class="lightbox-input" type="text" placeholder="Nombre del rol" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Slug</span>
                        <input v-model="form.slug" class="lightbox-input" type="text" placeholder="Role Slug">
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Descripción</span>
                        <input v-model="form.description" class="lightbox-input" type="text" placeholder="Descripción">
                    </label>
                </div>

                <div class="lightbox-block">
                    <p class="lightbox-block-title">Permisos</p>
                    <div class="lightbox-grid">
                        <label v-for="permission in props.permissions" :key="permission.id" class="lightbox-check">
                            <input v-model="form.permission_ids" type="checkbox" :value="permission.id">
                            {{ permission.slug }}
                        </label>
                    </div>
                </div>

                <div class="lightbox-actions">
                    <button class="lightbox-submit" type="submit" :disabled="form.processing">{{ isEdit ? 'Guardar cambios' : 'Crear rol' }}</button>
                    <Link class="lightbox-link" href="/admin/roles">Cancelar</Link>
                </div>
            </form>
        </LightboxFormModal>
    </AdminLayout>
</template>
