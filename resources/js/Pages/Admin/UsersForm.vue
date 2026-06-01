<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LightboxFormModal from '@/Components/LightboxFormModal.vue';
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    user: { type: Object, default: null },
    roles: { type: Array, default: () => [] },
});

const isEdit = computed(() => props.mode === 'edit');

const form = useForm({
    username: props.user?.username ?? '',
    first_name: props.user?.first_name ?? '',
    last_name: props.user?.last_name ?? '',
    email: props.user?.email ?? '',
    password: '',
    role_ids: props.user?.role_ids ?? [],
});

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/users/${props.user.id}`);
        return;
    }

    form.post('/admin/users');
};
</script>

<template>
    <AdminLayout title="Usuarios">
        <LightboxFormModal :title="isEdit ? `Editar usuario #${props.user?.id}` : 'Crear usuario'" close-href="/admin/users">
            <form class="lightbox-form" @submit.prevent="submit">
                <div class="lightbox-grid">
                    <label class="lightbox-field">
                        <span class="lightbox-label">Usuario</span>
                        <input v-model="form.username" class="lightbox-input" type="text" placeholder="Usuario" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Nombre</span>
                        <input v-model="form.first_name" class="lightbox-input" type="text" placeholder="Nombre" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Apellido</span>
                        <input v-model="form.last_name" class="lightbox-input" type="text" placeholder="Apellido" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Email</span>
                        <input v-model="form.email" class="lightbox-input" type="email" placeholder="Email" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">{{ isEdit ? 'Nueva contraseña' : 'Contraseña' }}</span>
                        <input v-model="form.password" class="lightbox-input" type="password" :placeholder="isEdit ? 'Nueva contraseña (opcional)' : 'Contraseña'" :required="!isEdit">
                    </label>
                </div>

                <div class="lightbox-block">
                    <p class="lightbox-block-title">Roles</p>
                    <div class="lightbox-grid">
                        <label v-for="role in props.roles" :key="role.id" class="lightbox-check">
                            <input v-model="form.role_ids" type="checkbox" :value="role.id">
                            {{ role.name }} ({{ role.slug }})
                        </label>
                    </div>
                </div>

                <div class="lightbox-actions">
                    <button class="lightbox-submit" type="submit" :disabled="form.processing">{{ isEdit ? 'Guardar cambios' : 'Crear usuario' }}</button>
                    <Link class="lightbox-link" href="/admin/users">Cancelar</Link>
                </div>
            </form>
        </LightboxFormModal>
    </AdminLayout>
</template>
