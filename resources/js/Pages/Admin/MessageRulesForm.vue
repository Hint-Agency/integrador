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

const operators = [
    { value: 'equals', label: 'Es igual a' },
    { value: 'not_equals', label: 'Es distinto de' },
    { value: 'contains', label: 'Contiene' },
    { value: 'not_contains', label: 'No contiene' },
    { value: 'in', label: 'Está en la lista' },
    { value: 'not_in', label: 'No está en la lista' },
    { value: 'is_empty', label: 'Está vacío' },
    { value: 'is_not_empty', label: 'No está vacío' },
];

const createEmptyRule = () => ({
    property: '',
    operator: 'equals',
    value: '',
});

const createEmptyGroup = () => ({
    match: 'all',
    rules: [createEmptyRule()],
});

const toConditionBuilder = (value) => {
    if (value && typeof value === 'object' && Array.isArray(value.groups) && value.groups.length > 0) {
        return {
            match: value.match === 'any' ? 'any' : 'all',
            groups: value.groups.map((group) => ({
                match: group?.match === 'any' ? 'any' : 'all',
                rules: Array.isArray(group?.rules) && group.rules.length > 0
                    ? group.rules.map((rule) => ({
                        property: String(rule?.property ?? ''),
                        operator: String(rule?.operator ?? 'equals'),
                        value: String(rule?.value ?? ''),
                    }))
                    : [createEmptyRule()],
            })),
        };
    }

    if (Array.isArray(value) && value.length > 0) {
        return {
            match: 'all',
            groups: [{
                match: 'all',
                rules: value.map((rule) => ({
                    property: String(rule?.property ?? ''),
                    operator: String(rule?.operator ?? 'equals'),
                    value: String(rule?.value ?? ''),
                })),
            }],
        };
    }

    if (value && typeof value === 'object' && Object.keys(value).length > 0) {
        return {
            match: 'all',
            groups: [{
                match: 'all',
                rules: Object.entries(value).map(([property, conditionValue]) => ({
                    property,
                    operator: 'equals',
                    value: typeof conditionValue === 'string' ? conditionValue : JSON.stringify(conditionValue ?? ''),
                })),
            }],
        };
    }

    return {
        match: 'all',
        groups: [createEmptyGroup()],
    };
};

const form = useForm({
    treble_template_id: props.rule?.treble_template_id ?? props.templates[0]?.id ?? '',
    name: props.rule?.name ?? '',
    priority: props.rule?.priority ?? 100,
    trigger_property: props.rule?.trigger_property ?? 'plantilla_de_whatsapp',
    trigger_value: props.rule?.trigger_value ?? '',
    conditions: toConditionBuilder(props.rule?.condition_builder ?? props.rule?.conditions ?? []),
    active: props.rule?.active ?? true,
});

const addGroup = () => {
    form.conditions.groups.push(createEmptyGroup());
};

const removeGroup = (groupIndex) => {
    form.conditions.groups.splice(groupIndex, 1);
    if (form.conditions.groups.length === 0) {
        form.conditions.groups.push(createEmptyGroup());
    }
};

const addRule = (groupIndex) => {
    form.conditions.groups[groupIndex].rules.push(createEmptyRule());
};

const removeRule = (groupIndex, ruleIndex) => {
    const group = form.conditions.groups[groupIndex];
    group.rules.splice(ruleIndex, 1);

    if (group.rules.length === 0) {
        group.rules.push(createEmptyRule());
    }
};

const normalizeConditions = () => ({
    match: form.conditions.match === 'any' ? 'any' : 'all',
    groups: form.conditions.groups
        .map((group) => ({
            match: group.match === 'any' ? 'any' : 'all',
            rules: group.rules
                .map((rule) => ({
                    property: String(rule?.property ?? '').trim(),
                    operator: String(rule?.operator ?? 'equals').trim() || 'equals',
                    value: String(rule?.value ?? '').trim(),
                }))
                .filter((rule) => rule.property !== '')
                .map((rule) => ({
                    ...rule,
                    value: ['is_empty', 'is_not_empty'].includes(rule.operator) ? null : rule.value,
                })),
        }))
        .filter((group) => group.rules.length > 0),
});

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
                        <p>Este par decide qué reglas compiten cuando cambia una propiedad en HubSpot.</p>
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
                        <p>Aquí ya puedes combinar grupos. La regla completa puede exigir que se cumplan todos los grupos o que baste con uno.</p>
                    </div>
                    <button type="button" class="ghost-button" @click="addGroup">Agregar grupo</button>
                </header>

                <div class="section-topbar">
                    <label class="compact-field">
                        <span>Relación entre grupos</span>
                        <select v-model="form.conditions.match">
                            <option value="all">Todos los grupos (AND)</option>
                            <option value="any">Cualquier grupo (OR)</option>
                        </select>
                    </label>
                </div>

                <div class="group-stack">
                    <article v-for="(group, groupIndex) in form.conditions.groups" :key="`group-${groupIndex}`" class="group-card">
                        <header class="group-head">
                            <div>
                                <strong>Grupo {{ groupIndex + 1 }}</strong>
                                <p>Define cómo se evalúan las condiciones dentro de este grupo.</p>
                            </div>
                            <div class="group-actions">
                                <label class="compact-field">
                                    <span>Relación interna</span>
                                    <select v-model="group.match">
                                        <option value="all">Todas (AND)</option>
                                        <option value="any">Cualquiera (OR)</option>
                                    </select>
                                </label>
                                <button type="button" class="ghost-button danger" @click="removeGroup(groupIndex)">Quitar grupo</button>
                            </div>
                        </header>

                        <div class="stack">
                            <div v-for="(rule, ruleIndex) in group.rules" :key="`group-${groupIndex}-rule-${ruleIndex}`" class="repeat-row">
                                <label>
                                    <span>Propiedad</span>
                                    <input v-model="rule.property" type="text" placeholder="campus_de_interes">
                                </label>
                                <label>
                                    <span>Operador</span>
                                    <select v-model="rule.operator">
                                        <option v-for="operator in operators" :key="operator.value" :value="operator.value">{{ operator.label }}</option>
                                    </select>
                                </label>
                                <label class="repeat-value">
                                    <span>Valor esperado</span>
                                    <input
                                        v-model="rule.value"
                                        type="text"
                                        :disabled="['is_empty', 'is_not_empty'].includes(rule.operator)"
                                        :placeholder="rule.operator === 'in' || rule.operator === 'not_in' ? 'Maternal, Prematernal' : 'La Paz'"
                                    >
                                </label>
                                <button type="button" class="ghost-button danger" @click="removeRule(groupIndex, ruleIndex)">Quitar</button>
                            </div>
                        </div>

                        <div class="group-footer">
                            <button type="button" class="ghost-button" @click="addRule(groupIndex)">Agregar condición</button>
                        </div>
                    </article>
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
.section-topbar { display: flex; justify-content: flex-start; }
.group-stack, .stack { display: grid; gap: 12px; }
.group-card { display: grid; gap: 12px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; background: #f8fafc; }
.group-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
.group-head p { margin: 4px 0 0; color: #64748b; font-size: 13px; }
.group-actions { display: flex; gap: 10px; align-items: end; }
.group-footer { display: flex; justify-content: flex-start; }
.repeat-row { display: grid; grid-template-columns: minmax(170px, 220px) minmax(170px, 220px) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
.repeat-value { min-width: 0; }
.compact-field { display: grid; gap: 6px; min-width: 180px; }
label { display: grid; gap: 6px; }
span { color: #334155; font-size: 14px; }
input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.checkbox { display: flex; align-items: center; gap: 10px; }
.actions { display: flex; gap: 10px; }
.actions a, .actions button, .ghost-button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; cursor: pointer; }
.ghost-button { cursor: pointer; }
.ghost-button.danger { color: #b91c1c; border-color: #fecaca; }
@media (max-width: 960px) {
    .group-head, .group-actions { flex-direction: column; align-items: stretch; }
    .repeat-row { grid-template-columns: 1fr; }
}
</style>
