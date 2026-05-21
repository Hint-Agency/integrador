<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mode: { type: String, required: true },
    client: { type: Object, required: true },
    template: { type: Object, default: null },
});

const isEdit = computed(() => props.mode === 'edit');

const mapEntries = (config) => {
    const source = config && typeof config === 'object' ? config : {};

    const entries = Object.entries(source)
        .map(([key, value]) => ({
            key,
            value: typeof value === 'string' ? value : JSON.stringify(value ?? ''),
        }))
        .filter((item) => item.key !== '');

    return entries.length > 0 ? entries : [
        { key: 'name', value: '{{contact.firstname}}' },
        { key: 'campus', value: '{{contact.campus_de_interes}}' },
    ];
};

const form = useForm({
    name: props.template?.name ?? '',
    external_template_id: props.template?.external_template_id ?? '',
    request_template_items: mapEntries(props.template?.request_template),
    active: props.template?.active ?? true,
});

const addRequestTemplateItem = () => {
    form.request_template_items.push({ key: '', value: '' });
};

const removeRequestTemplateItem = (index) => {
    form.request_template_items.splice(index, 1);
};

const normalizeRequestTemplate = () => form.request_template_items.reduce((carry, item) => {
    const key = String(item?.key ?? '').trim();

    if (key !== '') {
        carry[key] = String(item?.value ?? '').trim();
    }

    return carry;
}, {});

const submit = () => {
    const requestTemplate = normalizeRequestTemplate();

    form.clearErrors('request_template');

    if (Object.keys(requestTemplate).length === 0) {
        form.setError('request_template', 'Agrega al menos una variable para la plantilla.');
        return;
    }

    form.transform(() => ({
        name: form.name,
        external_template_id: form.external_template_id,
        payload_mapping: requestTemplate,
        request_template: requestTemplate,
        active: !!form.active,
    }));

    if (isEdit.value) {
        form.put(`/admin/clients/${props.client.id}/templates/${props.template.id}`);
        return;
    }

    form.post(`/admin/clients/${props.client.id}/templates`);
};
</script>

<template>
    <AdminLayout :title="isEdit ? 'Editar plantilla' : 'Nueva plantilla'">
        <ClientTabs :client="client" />
        <form class="form" @submit.prevent="submit">
            <div class="grid">
                <label><span>Nombre</span><input v-model="form.name" type="text" required></label>
                <label><span>Poll ID / ID externo</span><input v-model="form.external_template_id" type="text" required></label>
            </div>

            <section class="section">
                <header class="section-head">
                    <div>
                        <h2>Request template</h2>
                        <p>Estas variables se enviarán a Treble como items de <code>user_session_keys</code>.</p>
                    </div>
                    <button type="button" class="ghost-button" @click="addRequestTemplateItem">Agregar variable</button>
                </header>

                <div class="stack">
                    <div v-for="(item, index) in form.request_template_items" :key="`request-template-${index}`" class="repeat-row">
                        <label>
                            <span>Llave</span>
                            <input v-model="item.key" type="text" placeholder="campus">
                        </label>
                        <label class="repeat-value">
                            <span>Valor</span>
                            <input v-model="item.value" type="text" :placeholder="'{{contact.campus_de_interes}}'">
                        </label>
                        <button type="button" class="ghost-button danger" @click="removeRequestTemplateItem(index)">Quitar</button>
                    </div>
                </div>

                <small class="hint">
                    Placeholders útiles:
                    <code v-pre>{{contact.firstname}}</code>,
                    <code v-pre>{{contact.lastname}}</code>,
                    <code v-pre>{{contact.phone}}</code>,
                    <code v-pre>{{contact.campus_de_interes}}</code>,
                    <code v-pre>{{contact.nivel_escolar_de_interes}}</code>,
                    <code v-pre>{{template.external_template_id}}</code>.
                </small>
                <small v-if="form.errors.request_template" class="field-error">{{ form.errors.request_template }}</small>
            </section>

            <label class="checkbox"><input v-model="form.active" type="checkbox"><span>Activa</span></label>
            <div class="actions">
                <Link :href="`/admin/clients/${client.id}/templates`">Cancelar</Link>
                <button type="submit" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.form { display: grid; gap: 14px; max-width: 920px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.section { display: grid; gap: 12px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
.section-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.section-head h2, .section-head p { margin: 0; }
.section-head p { color: #64748b; font-size: 14px; }
.stack { display: grid; gap: 10px; }
.repeat-row { display: grid; grid-template-columns: minmax(160px, 220px) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
.repeat-value { min-width: 0; }
label { display: grid; gap: 6px; }
span { color: #334155; font-size: 14px; }
input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.checkbox { display: flex; align-items: center; gap: 10px; }
.hint { font-size: 12px; color: #64748b; }
.field-error { font-size: 12px; color: #b91c1c; }
.actions { display: flex; gap: 10px; }
.actions a, .actions button, .ghost-button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; cursor: pointer; }
.ghost-button { cursor: pointer; }
.ghost-button.danger { color: #b91c1c; border-color: #fecaca; }
</style>
