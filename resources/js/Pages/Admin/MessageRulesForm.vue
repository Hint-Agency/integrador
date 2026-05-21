<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mode: { type: String, required: true },
    client: { type: Object, required: true },
    rule: { type: Object, default: null },
    templates: { type: Array, required: true },
});

const isEdit = computed(() => props.mode === 'edit');

const toConditionList = (value) => {
    if (Array.isArray(value) && value.length > 0) {
        return value.map((item) => ({
            property: String(item?.property ?? ''),
            value: String(item?.value ?? ''),
        }));
    }

    if (value && typeof value === 'object') {
        const mapped = Object.entries(value).map(([property, conditionValue]) => ({
            property,
            value: typeof conditionValue === 'string' ? conditionValue : JSON.stringify(conditionValue ?? ''),
        }));

        if (mapped.length > 0) {
            return mapped;
        }
    }

    return [{ property: '', value: '' }];
};

const form = useForm({
    treble_template_id: props.rule?.treble_template_id ?? props.templates[0]?.id ?? '',
    name: props.rule?.name ?? '',
    priority: props.rule?.priority ?? 100,
    trigger_property: props.rule?.trigger_property ?? 'plantilla_de_whatsapp',
    trigger_value: props.rule?.trigger_value ?? '',
    conditions: toConditionList(props.rule?.conditions_list ?? props.rule?.conditions ?? []),
    active: props.rule?.active ?? true,
});

const addCondition = () => {
    form.conditions.push({ property: '', value: '' });
};

const removeCondition = (index) => {
    form.conditions.splice(index, 1);
    if (form.conditions.length === 0) {
        form.conditions.push({ property: '', value: '' });
    }
};

const normalizeConditions = () => form.conditions
    .map((item) => ({
        property: String(item?.property ?? '').trim(),
        value: String(item?.value ?? '').trim(),
    }))
    .filter((item) => item.property !== '');

const submit = () => {
    form.clearErrors('conditions');

    form.transform(() => ({
        treble_template_id: Number(form.treble_template_id),
        name: form.name,
        priority: Number(form.priority),
        trigger_property: form.trigger_property.trim(),
        trigger_value: form.trigger_value.trim() || null,
        conditions: normalizeConditions(),
        active: !!form.active,
    }));

    if (isEdit.value) {
        form.put(`/admin/clients/${props.client.id}/rules/${props.rule.id}`);
        return;
    }

    form.post(`/admin/clients/${props.client.id}/rules`);
};
</script>

<template>
    <AdminLayout :title="isEdit ? 'Editar regla' : 'Nueva regla'">
        <ClientTabs :client="client" />
        <form class="form" @submit.prevent="submit">
            <div class="grid">
                <label><span>Nombre</span><input v-model="form.name" type="text" required></label>
                <label><span>Plantilla Treble</span><select v-model="form.treble_template_id"><option v-for="template in templates" :key="template.id" :value="template.id">{{ template.name }} ({{ template.external_template_id }})</option></select></label>
                <label><span>Prioridad</span><input v-model="form.priority" type="number" min="0"></label>
                <label class="checkbox"><input v-model="form.active" type="checkbox"><span>Activa</span></label>
            </div>

            <section class="section">
                <header class="section-head">
                    <div>
                        <h2>Disparador principal</h2>
                        <p>Este par sí se usa para decidir qué reglas compiten cuando cambia una propiedad en HubSpot.</p>
                    </div>
                </header>
                <div class="grid">
                    <label><span>Trigger property</span><input v-model="form.trigger_property" type="text" required placeholder="plantilla_de_whatsapp"></label>
                    <label><span>Trigger value</span><input v-model="form.trigger_value" type="text" placeholder="Bienvenida"></label>
                </div>
            </section>

            <section class="section">
                <header class="section-head">
                    <div>
                        <h2>Condiciones adicionales</h2>
                        <p>Se evalúan después del disparador principal y deben coincidir exactamente con las propiedades del contacto.</p>
                    </div>
                    <button type="button" class="ghost-button" @click="addCondition">Agregar condición</button>
                </header>
                <div class="stack">
                    <div v-for="(condition, index) in form.conditions" :key="`condition-${index}`" class="repeat-row">
                        <label>
                            <span>Propiedad</span>
                            <input v-model="condition.property" type="text" placeholder="campus_de_interes">
                        </label>
                        <label class="repeat-value">
                            <span>Valor esperado</span>
                            <input v-model="condition.value" type="text" placeholder="La Paz">
                        </label>
                        <button type="button" class="ghost-button danger" @click="removeCondition(index)">Quitar</button>
                    </div>
                </div>
            </section>

            <div class="actions">
                <Link :href="`/admin/clients/${client.id}/rules`">Cancelar</Link>
                <button type="submit" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.form { display: grid; gap: 14px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.section { display: grid; gap: 12px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
.section-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.section-head h2, .section-head p { margin: 0; }
.section-head p { color: #64748b; font-size: 14px; }
.stack { display: grid; gap: 10px; }
.repeat-row { display: grid; grid-template-columns: minmax(180px, 240px) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
.repeat-value { min-width: 0; }
label { display: grid; gap: 6px; }
span { color: #334155; font-size: 14px; }
input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.checkbox { display: flex; align-items: center; gap: 10px; }
.actions { display: flex; gap: 10px; }
.actions a, .actions button, .ghost-button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; cursor: pointer; }
.ghost-button { cursor: pointer; }
.ghost-button.danger { color: #b91c1c; border-color: #fecaca; }
</style>
