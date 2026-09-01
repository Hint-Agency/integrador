<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    mode: { type: String, required: true },
    client: { type: Object, required: true },
    flow: { type: Object, default: null },
    owners: { type: Array, required: true },
    messageRules: { type: Array, required: true },
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

const emptyCondition = () => ({ property: '', operator: 'equals', value: '' });
const emptyGroup = () => ({ match: 'all', rules: [emptyCondition()] });
const initialConditions = props.flow?.condition_builder ?? { match: 'all', groups: [emptyGroup()] };

const form = useForm({
    name: props.flow?.name ?? '',
    priority: props.flow?.priority ?? 100,
    trigger_property: props.flow?.trigger_property ?? 'campus_de_interes',
    trigger_value: props.flow?.trigger_value ?? '',
    conditions: {
        match: initialConditions.match === 'any' ? 'any' : 'all',
        groups: Array.isArray(initialConditions.groups) && initialConditions.groups.length
            ? initialConditions.groups.map((group) => ({
                match: group.match === 'any' ? 'any' : 'all',
                rules: group.rules.map((condition) => ({
                    property: String(condition.property ?? ''),
                    operator: String(condition.operator ?? 'equals'),
                    value: String(condition.value ?? ''),
                })),
            }))
            : [emptyGroup()],
    },
    owner_assignment_enabled: props.flow?.owner_assignment_enabled ?? true,
    owner_property: props.flow?.owner_property ?? 'hubspot_owner_id',
    owner_selection_strategy: props.flow?.owner_selection_strategy ?? 'random',
    existing_owner_behavior: props.flow?.existing_owner_behavior ?? 'stop',
    owner_ids: Array.isArray(props.flow?.owner_ids) ? props.flow.owner_ids.map(Number) : [],
    continue_to_treble: props.flow?.continue_to_treble ?? true,
    active: props.flow?.active ?? true,
});

const addGroup = () => form.conditions.groups.push(emptyGroup());
const removeGroup = (groupIndex) => {
    form.conditions.groups.splice(groupIndex, 1);
    if (!form.conditions.groups.length) form.conditions.groups.push(emptyGroup());
};
const addCondition = (groupIndex) => form.conditions.groups[groupIndex].rules.push(emptyCondition());
const removeCondition = (groupIndex, conditionIndex) => {
    const group = form.conditions.groups[groupIndex];
    group.rules.splice(conditionIndex, 1);
    if (!group.rules.length) group.rules.push(emptyCondition());
};

const normalizeConditions = () => ({
    match: form.conditions.match === 'any' ? 'any' : 'all',
    groups: form.conditions.groups
        .map((group) => ({
            match: group.match === 'any' ? 'any' : 'all',
            rules: group.rules
                .map((condition) => ({
                    property: String(condition.property ?? '').trim(),
                    operator: String(condition.operator ?? 'equals'),
                    value: ['is_empty', 'is_not_empty'].includes(condition.operator)
                        ? null
                        : String(condition.value ?? '').trim(),
                }))
                .filter((condition) => condition.property !== ''),
        }))
        .filter((group) => group.rules.length),
});

const submit = () => {
    form.transform(() => ({
        name: form.name.trim(),
        priority: Number(form.priority),
        trigger_property: form.trigger_property.trim(),
        trigger_value: form.trigger_value.trim() || null,
        conditions: normalizeConditions(),
        owner_assignment_enabled: !!form.owner_assignment_enabled,
        owner_property: form.owner_assignment_enabled ? (form.owner_property.trim() || 'hubspot_owner_id') : null,
        owner_selection_strategy: form.owner_assignment_enabled ? 'random' : null,
        existing_owner_behavior: form.owner_assignment_enabled ? form.existing_owner_behavior : null,
        owner_ids: form.owner_assignment_enabled ? form.owner_ids.map(Number) : [],
        continue_to_treble: !!form.continue_to_treble,
        active: !!form.active,
    }));

    if (isEdit.value) {
        form.put(`/admin/clients/${props.client.id}/flows/${props.flow.id}`);
        return;
    }

    form.post(`/admin/clients/${props.client.id}/flows`);
};

const destroyRule = (rule) => {
    if (confirm(`Eliminar la regla Treble ${rule.name}?`)) {
        router.delete(`/admin/clients/${props.client.id}/flows/${props.flow.id}/rules/${rule.id}`);
    }
};
</script>

<template>
    <AdminLayout :title="isEdit ? 'Configurar flujo' : 'Nuevo flujo'">
        <ClientTabs :client="client" />

        <form class="form" @submit.prevent="submit">
            <section class="intro">
                <div>
                    <h2>{{ isEdit ? flow.name : 'Nuevo flujo de seguimiento' }}</h2>
                    <p>Los pasos se ejecutan de arriba hacia abajo. Treble nunca continúa si HubSpot no confirma la asignación.</p>
                </div>
                <label class="switch-field">
                    <span>Activo</span>
                    <input v-model="form.active" type="checkbox">
                    <i aria-hidden="true"></i>
                </label>
            </section>

            <section class="section">
                <header><span class="node trigger">Inicio</span><div><h3>Disparador HubSpot</h3><p>Define qué cambio inicia este flujo.</p></div></header>
                <div class="grid three">
                    <label><span>Nombre del flujo</span><input v-model="form.name" type="text" required></label>
                    <label><span>Propiedad que cambia</span><input v-model="form.trigger_property" type="text" required placeholder="campus_de_interes"></label>
                    <label><span>Valor que inicia el flujo</span><input v-model="form.trigger_value" type="text" placeholder="Cancún"></label>
                    <label><span>Prioridad</span><input v-model="form.priority" type="number" min="0"></label>
                </div>
            </section>

            <div class="connector success">Después de validar el disparador</div>

            <section :class="['section', { muted: !form.owner_assignment_enabled }]">
                <header class="section-head">
                    <div class="step-heading"><span class="node">1</span><div><h3>Asignar propietario en HubSpot</h3><p>Actívalo cuando este flujo deba garantizar un asesor antes de Treble.</p></div></div>
                    <label class="switch-field"><span>{{ form.owner_assignment_enabled ? 'Habilitado' : 'Omitido' }}</span><input v-model="form.owner_assignment_enabled" type="checkbox"><i aria-hidden="true"></i></label>
                </header>

                <template v-if="form.owner_assignment_enabled">
                    <div class="mandatory-guard">
                        <strong>Los contactos asignados nunca se reasignan</strong>
                        <p v-if="form.existing_owner_behavior === 'continue'">Si <code>{{ form.owner_property || 'hubspot_owner_id' }}</code> ya tiene valor, se conserva y el flujo continúa al siguiente paso.</p>
                        <p v-else>Si <code>{{ form.owner_property || 'hubspot_owner_id' }}</code> ya tiene valor, el flujo se detiene antes de Treble.</p>
                    </div>

                    <div class="section-head">
                        <div><strong>Propietarios elegibles</strong><p>Se elegirá uno al azar entre los propietarios activos seleccionados.</p></div>
                        <Link class="ghost-button" :href="`/admin/clients/${client.id}/owners/create`">Dar de alta propietario</Link>
                    </div>

                    <div v-if="owners.length" class="owner-grid">
                        <label v-for="owner in owners" :key="owner.id" class="owner-option">
                            <input v-model="form.owner_ids" type="checkbox" :value="owner.id">
                            <span><strong>{{ owner.name }}</strong><small>{{ owner.external_owner_id }} · {{ owner.email || 'Sin correo' }}</small></span>
                        </label>
                    </div>
                    <p v-else class="field-error">Primero registra al menos un propietario activo.</p>
                    <p v-if="form.errors.owner_ids" class="field-error">{{ form.errors.owner_ids }}</p>

                    <div class="grid">
                        <label><span>Propiedad owner en HubSpot</span><input v-model="form.owner_property" type="text" required></label>
                        <label><span>Estrategia</span><select v-model="form.owner_selection_strategy" disabled><option value="random">Aleatoria</option></select></label>
                        <label>
                            <span>Si el contacto ya tiene propietario</span>
                            <select v-model="form.existing_owner_behavior">
                                <option value="stop">Conservar y detener el flujo</option>
                                <option value="continue">Conservar y continuar a Treble</option>
                            </select>
                        </label>
                    </div>
                </template>

                <div v-else class="skip-policy"><strong>Asignación omitida</strong><p>El flujo no consultará ni modificará el propietario. Si las condiciones coinciden, continuará directamente al paso Treble.</p></div>

                <div class="condition-heading">
                    <div><strong>Condiciones del flujo</strong><p>Agrega filtros como campus, lista o fuente original. Se evaluarán antes de ejecutar cualquier paso activo.</p></div>
                    <button type="button" class="ghost-button" @click="addGroup">Agregar grupo</button>
                </div>

                <label class="compact-field"><span>Relación entre grupos</span><select v-model="form.conditions.match"><option value="all">Todos (AND)</option><option value="any">Cualquiera (OR)</option></select></label>

                <div class="group-stack">
                    <div v-for="(group, groupIndex) in form.conditions.groups" :key="`group-${groupIndex}`" class="condition-group">
                        <div class="group-head">
                            <strong>Grupo {{ groupIndex + 1 }}</strong>
                            <div class="group-actions"><select v-model="group.match"><option value="all">Todas (AND)</option><option value="any">Cualquiera (OR)</option></select><button type="button" class="ghost-button danger" @click="removeGroup(groupIndex)">Quitar grupo</button></div>
                        </div>
                        <div class="condition-stack">
                            <div v-for="(condition, conditionIndex) in group.rules" :key="`condition-${groupIndex}-${conditionIndex}`" class="condition-row">
                                <label>
                                    <span>Propiedad</span>
                                    <input v-model="condition.property" type="text" placeholder="hs_analytics_source">
                                    <small v-if="form.errors[`conditions.groups.${groupIndex}.rules.${conditionIndex}.property`]" class="field-error">{{ form.errors[`conditions.groups.${groupIndex}.rules.${conditionIndex}.property`] }}</small>
                                </label>
                                <label><span>Operador</span><select v-model="condition.operator"><option v-for="operator in operators" :key="operator.value" :value="operator.value">{{ operator.label }}</option></select></label>
                                <label><span>Valor</span><input v-model="condition.value" type="text" :disabled="['is_empty', 'is_not_empty'].includes(condition.operator)" :placeholder="['in', 'not_in'].includes(condition.operator) ? 'X, Y, Z' : 'Valor'"></label>
                                <button type="button" class="ghost-button danger" @click="removeCondition(groupIndex, conditionIndex)">Quitar</button>
                            </div>
                        </div>
                        <button type="button" class="ghost-button add-condition" @click="addCondition(groupIndex)">Agregar condición</button>
                    </div>
                </div>

                <div v-if="form.owner_assignment_enabled" class="failure-policy"><strong>Si falla la asignación</strong><p>Detener el flujo, registrar el error e intentar crear una nota en HubSpot. El paso 2 no se ejecuta.</p></div>
            </section>

            <div class="connector success">{{ form.owner_assignment_enabled ? 'Solo cuando HubSpot confirma la asignación' : 'Continúa sin modificar el propietario' }}</div>

            <section :class="['section', { muted: !form.continue_to_treble }]">
                <header class="section-head">
                    <div class="step-heading"><span class="node">2</span><div><h3>Resolver regla y enviar por Treble</h3><p>Las reglas compiten por prioridad y se ejecuta la primera coincidencia.</p></div></div>
                    <label class="switch-field"><span>{{ form.continue_to_treble ? 'Habilitado' : 'Omitido' }}</span><input v-model="form.continue_to_treble" type="checkbox"><i aria-hidden="true"></i></label>
                </header>

                <template v-if="form.continue_to_treble">
                    <div v-if="isEdit" class="rules-block">
                        <div class="section-head">
                            <div><strong>Reglas Treble del flujo</strong><p>Cada regla elige una plantilla y agrega condiciones posteriores a la asignación.</p></div>
                            <Link class="ghost-button primary-outline" :href="`/admin/clients/${client.id}/flows/${flow.id}/rules/create`">Nueva regla Treble</Link>
                        </div>

                        <div v-if="messageRules.length" class="rule-list">
                            <div v-for="rule in messageRules" :key="rule.id" class="rule-row">
                                <span class="priority">P {{ rule.priority }}</span>
                                <div><strong>{{ rule.name }}</strong><small>{{ rule.condition_count }} condición(es) · {{ rule.treble_template?.name || 'Sin plantilla' }} · {{ rule.active ? 'Activa' : 'Inactiva' }}</small></div>
                                <div class="rule-actions"><Link :href="`/admin/clients/${client.id}/flows/${flow.id}/rules/${rule.id}/edit`">Editar</Link><button type="button" @click="destroyRule(rule)">Eliminar</button></div>
                            </div>
                        </div>
                        <p v-else class="empty-rules">No hay reglas Treble. La asignación funcionará, pero el flujo terminará con warning hasta que agregues una regla.</p>
                    </div>
                    <div v-else class="save-first"><strong>Primero guarda el flujo.</strong><p>Después podrás agregar sus reglas Treble sin mezclar las condiciones de asignación con las de mensajería.</p></div>
                </template>
            </section>

            <div class="actions">
                <Link :href="`/admin/clients/${client.id}/flows`">Volver a flujos</Link>
                <button type="submit" :disabled="form.processing || (form.owner_assignment_enabled && owners.length === 0)">{{ isEdit ? 'Guardar cambios' : 'Guardar y configurar Treble' }}</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.form { display: grid; gap: 0; }
.intro, .section-head, .step-heading, .condition-heading, .group-head, .group-actions, .actions, .rule-actions { display: flex; align-items: center; gap: 12px; }
.intro, .section-head, .condition-heading, .group-head { justify-content: space-between; }
.intro { margin-bottom: 16px; }
.intro h2, .intro p, .section h3, .section header p, .condition-heading p, .section-head p, .failure-policy p, .save-first p { margin: 0; }
.intro p, .section header p, .condition-heading p, .section-head p, .save-first p { color: #64748b; font-size: 14px; }
.intro p, .section header p { margin-top: 4px; }
.section { display: grid; gap: 14px; border: 1px solid #dbe2e8; border-radius: 8px; padding: 16px; background: #fff; }
.section.muted { background: #f8fafc; color: #64748b; }
.section > header { display: flex; align-items: center; gap: 12px; }
.node { flex: 0 0 auto; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 7px; background: #2563eb; color: #fff; font-weight: 700; font-size: 13px; }
.node.trigger { width: auto; padding: 0 9px; background: #0f766e; }
.step-badge { border-radius: 999px; padding: 5px 9px; background: #eff6ff; color: #1d4ed8; font-size: 12px; }
.connector { margin-left: 17px; min-height: 38px; padding: 11px 0 9px 31px; border-left: 2px solid #86efac; color: #15803d; font-size: 12px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.grid.three { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
label { display: grid; gap: 6px; }
label > span { color: #334155; font-size: 14px; }
input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.switch-field { display: flex; align-items: center; gap: 9px; cursor: pointer; }
.switch-field input { position: absolute; opacity: 0; pointer-events: none; }
.switch-field i { position: relative; width: 40px; height: 22px; border-radius: 999px; background: #cbd5e1; transition: background .15s ease; }
.switch-field i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s ease; }
.switch-field input:checked + i { background: #16a34a; }
.switch-field input:checked + i::after { transform: translateX(18px); }
.owner-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
.owner-option { display: grid; grid-template-columns: auto 1fr; align-items: start; gap: 10px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 11px; }
.owner-option input { width: auto; margin-top: 3px; }
.owner-option span, .owner-option small { display: block; }
.owner-option small { margin-top: 3px; color: #64748b; }
.mandatory-guard, .failure-policy, .save-first, .empty-rules, .skip-policy { border-left: 4px solid #2563eb; background: #eff6ff; padding: 11px 12px; }
.mandatory-guard p, .failure-policy p { margin: 4px 0 0; color: #334155; font-size: 14px; }
.skip-policy { border-left-color: #64748b; background: #f1f5f9; }
.skip-policy p { margin: 4px 0 0; color: #475569; font-size: 14px; }
.failure-policy { border-left-color: #dc2626; background: #fef2f2; }
.failure-policy p { color: #7f1d1d; }
.condition-heading { margin-top: 4px; }
.compact-field { max-width: 260px; }
.group-stack, .condition-stack, .rules-block, .rule-list { display: grid; gap: 12px; }
.condition-group { display: grid; gap: 12px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; background: #f8fafc; }
.condition-row { display: grid; grid-template-columns: minmax(160px, 1fr) minmax(180px, 220px) minmax(180px, 1fr) auto; gap: 10px; align-items: end; }
.add-condition { justify-self: start; }
.rule-row { display: grid; grid-template-columns: 54px minmax(0, 1fr) auto; gap: 12px; align-items: center; border-bottom: 1px solid #e2e8f0; padding: 10px 0; }
.rule-row:last-child { border-bottom: 0; }
.priority { color: #64748b; font-size: 12px; }
.rule-row small { display: block; margin-top: 3px; color: #64748b; }
.rule-actions a, .rule-actions button { border: 0; background: transparent; color: #2563eb; text-decoration: none; cursor: pointer; padding: 4px; }
.rule-actions button { color: #b91c1c; }
.empty-rules { margin: 0; border-left-color: #d97706; background: #fffbeb; color: #92400e; font-size: 14px; }
.save-first p { margin-top: 4px; }
.field-error { margin: 0; color: #b91c1c; font-size: 14px; }
.actions { margin-top: 16px; }
.actions a, .actions button, .ghost-button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; cursor: pointer; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; }
.actions button:disabled { opacity: .55; cursor: not-allowed; }
.ghost-button.danger { color: #b91c1c; border-color: #fecaca; }
.ghost-button.primary-outline { color: #1d4ed8; border-color: #93c5fd; }
code { color: #1d4ed8; }
@media (max-width: 960px) {
    .intro, .section-head, .condition-heading, .group-head, .group-actions { align-items: stretch; flex-direction: column; }
    .condition-row, .rule-row { grid-template-columns: 1fr; }
    .rule-actions { justify-content: flex-start; }
}
</style>
