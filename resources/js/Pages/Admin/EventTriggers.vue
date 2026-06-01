<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    event: { type: Object, required: true },
    triggers: { type: Object, required: true },
    supported_operators: { type: Array, default: () => [] },
});

const normalizeGroups = () => {
    const sourceGroups = props.triggers.groups ?? [];

    if (sourceGroups.length === 0) {
        return [{
            id: null,
            name: 'Grupo default',
            operator: 'and',
            active: true,
            conditions: [{
                id: null,
                field: '',
                operator: 'equals',
                value_text: '',
            }],
        }];
    }

    return sourceGroups.map((group) => ({
        id: group.id ?? null,
        name: group.name ?? '',
        operator: group.operator ?? 'and',
        active: !!group.active,
        conditions: (group.conditions ?? []).map((condition) => ({
            id: condition.id ?? null,
            field: condition.field ?? '',
            operator: condition.operator ?? 'equals',
            value_text: condition.value === null || condition.value === undefined ? '' : JSON.stringify(condition.value),
        })),
    }));
};

const form = useForm({
    groups: normalizeGroups(),
});

const addGroup = () => {
    form.groups.push({
        id: null,
        name: `Grupo ${form.groups.length + 1}`,
        operator: 'and',
        active: true,
        conditions: [{
            id: null,
            field: '',
            operator: 'equals',
            value_text: '',
        }],
    });
};

const removeGroup = (groupIndex) => {
    form.groups.splice(groupIndex, 1);
    if (form.groups.length === 0) {
        addGroup();
    }
};

const addCondition = (groupIndex) => {
    form.groups[groupIndex].conditions.push({
        id: null,
        field: '',
        operator: 'equals',
        value_text: '',
    });
};

const removeCondition = (groupIndex, conditionIndex) => {
    form.groups[groupIndex].conditions.splice(conditionIndex, 1);
    if (form.groups[groupIndex].conditions.length === 0) {
        addCondition(groupIndex);
    }
};

const safeValue = (raw) => {
    if (raw === '' || raw === null || raw === undefined) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        return raw;
    }
};

const submit = () => {
    form.transform((data) => ({
        groups: data.groups.map((group) => ({
            id: group.id,
            name: group.name,
            operator: group.operator,
            active: !!group.active,
            conditions: group.conditions.map((condition) => ({
                id: condition.id,
                field: condition.field,
                operator: condition.operator,
                value: safeValue(condition.value_text),
            })),
        })),
    })).put(`/admin/events/${props.event.id}/triggers`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AdminLayout :title="`Disparadores · ${event.name}`">
        <div v-if="$page.props.flash?.success" class="flash success">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="flash error">{{ $page.props.flash.error }}</div>

        <div class="toolbar">
            <Link class="secondary" href="/admin/events">Volver a eventos</Link>
            <button type="button" class="primary" @click="addGroup">Agregar grupo</button>
        </div>

        <form class="triggers-form" @submit.prevent="submit">
            <div class="groups">
                <article v-for="(group, groupIndex) in form.groups" :key="`group-${groupIndex}`" class="group">
                    <header class="group-head">
                        <input v-model="group.name" type="text" placeholder="Nombre del grupo" required>
                        <select v-model="group.operator">
                            <option value="and">y</option>
                            <option value="or">o</option>
                        </select>
                        <label class="check">
                            <input v-model="group.active" type="checkbox">
                            Activo
                        </label>
                        <button type="button" class="danger" @click="removeGroup(groupIndex)">Eliminar grupo</button>
                    </header>

                    <div class="conditions">
                        <div
                            v-for="(condition, conditionIndex) in group.conditions"
                            :key="`condition-${groupIndex}-${conditionIndex}`"
                            class="condition"
                        >
                            <input v-model="condition.field" type="text" placeholder="campo (ej. payload.amount)" required>
                            <select v-model="condition.operator">
                                <option v-for="operator in props.supported_operators" :key="operator" :value="operator">
                                    {{ operator }}
                                </option>
                            </select>
                            <input v-model="condition.value_text" type="text" placeholder='valor (ej. "100" o {"a":1})'>
                            <button type="button" class="danger compact" @click="removeCondition(groupIndex, conditionIndex)">Quitar</button>
                        </div>
                    </div>

                    <button type="button" class="secondary" @click="addCondition(groupIndex)">Agregar condición</button>
                </article>
            </div>

            <div class="actions">
                <button type="submit" class="primary save" :disabled="form.processing">Guardar disparadores</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.toolbar{display:flex;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
.triggers-form{display:grid;gap:12px}
.groups{display:grid;gap:12px}
.group{border:1px solid #dbe4ef;border-radius:12px;padding:12px;background:#fff;box-shadow:0 8px 20px rgba(15,23,42,.05)}
.group-head{display:grid;grid-template-columns:2fr 120px 110px auto;gap:8px;align-items:center}
.group-head input,.group-head select{height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px}
.check{display:flex;align-items:center;gap:6px;font-size:13px;color:#334155}
.conditions{margin-top:10px;display:grid;gap:8px}
.condition{display:grid;grid-template-columns:2fr 180px 2fr auto;gap:8px}
.condition input,.condition select{height:36px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px}
.primary{border:0;border-radius:8px;background:#1d4ed8;color:#fff;padding:8px 12px;cursor:pointer}
.secondary{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #cbd5e1;background:#f8fafc;color:#334155;border-radius:8px;padding:8px 12px;cursor:pointer}
.danger{border:1px solid #fecaca;background:#fee2e2;color:#991b1b;border-radius:8px;padding:8px 10px;cursor:pointer}
.compact{padding:6px 8px}
.actions{display:flex;justify-content:flex-end}
.save{min-width:160px}
.flash{border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px}
.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
@media (max-width: 900px){
  .group-head{grid-template-columns:1fr}
  .condition{grid-template-columns:1fr}
}
</style>
