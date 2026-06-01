<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LightboxFormModal from '@/Components/LightboxFormModal.vue';
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    config: { type: Object, default: null },
});

const isEdit = computed(() => props.mode === 'edit');

const formatValue = (value) => JSON.stringify(value ?? {}, null, 2);

const form = useForm({
    key: props.config?.key ?? '',
    value_text: formatValue(props.config?.value),
    description: props.config?.description ?? '',
    is_encrypted: props.config?.is_encrypted ?? false,
});

const parseValue = () => {
    try {
        return JSON.parse(form.value_text || '{}');
    } catch (error) {
        return null;
    }
};

const submit = () => {
    const parsed = parseValue();
    if (parsed === null) {
        alert('`value` debe ser JSON válido.');
        return;
    }

    form.transform((data) => ({
        key: data.key,
        value: parsed,
        description: data.description,
        is_encrypted: !!data.is_encrypted,
    }));

    if (isEdit.value) {
        form.put(`/admin/configs/${props.config.id}`);
        return;
    }

    form.post('/admin/configs');
};
</script>

<template>
    <AdminLayout title="Configuración">
        <LightboxFormModal :title="isEdit ? `Editar configuración #${props.config?.id}` : 'Crear configuración'" close-href="/admin/configs">
            <form class="lightbox-form" @submit.prevent="submit">
                <div class="lightbox-grid">
                    <label class="lightbox-field">
                        <span class="lightbox-label">Clave de configuración</span>
                        <input v-model="form.key" class="lightbox-input" type="text" placeholder="Clave de configuración" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Descripción</span>
                        <input v-model="form.description" class="lightbox-input" type="text" placeholder="Descripción">
                    </label>
                </div>

                <label class="lightbox-field">
                    <span class="lightbox-label">Valor JSON</span>
                    <textarea v-model="form.value_text" class="lightbox-textarea" rows="8" placeholder='{"key":"value"}' />
                </label>
                <label class="lightbox-check-inline"><input v-model="form.is_encrypted" type="checkbox"> Valor cifrado</label>

                <div class="lightbox-actions">
                    <button class="lightbox-submit" type="submit" :disabled="form.processing">{{ isEdit ? 'Guardar cambios' : 'Crear configuración' }}</button>
                    <Link class="lightbox-link" href="/admin/configs">Cancelar</Link>
                </div>
            </form>
        </LightboxFormModal>
    </AdminLayout>
</template>
