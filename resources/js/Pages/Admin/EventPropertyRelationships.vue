<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import IconAction from '@/Components/IconAction.vue';
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    event: { type: Object, required: true },
    source_properties: { type: Array, default: () => [] },
    target_properties: { type: Array, default: () => [] },
    relationships: { type: Array, default: () => [] },
    mapping_context: { type: Object, default: () => ({}) },
});

const editingRelationshipId = ref(null);

const blankForm = () => ({
    property_id: '',
    related_property_id: '',
    mapping_key: '',
    meta_text: '',
    active: true,
});

const form = useForm(blankForm());

const sourcePlatformLabel = computed(() => props.mapping_context.source_label ?? props.event.platform?.name ?? 'Payload entrante');
const targetPlatformLabel = computed(() => props.mapping_context.target_label ?? props.event.platform?.name ?? 'Destino del mapeo');
const nextPlatformLabel = computed(() => props.mapping_context.next_label ?? props.event.to_event?.platform?.name ?? null);
const mappingModeLabel = computed(() => {
    if (props.mapping_context.has_upstream && props.event.to_event) {
        return 'Payload entrante → payload del evento actual; el siguiente evento solo da continuidad al flujo.';
    }

    if (props.event.to_event) {
        return 'Payload del evento actual → payload del siguiente evento.';
    }

    return 'Payload del evento actual → payload de la plataforma actual.';
});
const hasRelationships = computed(() => (props.relationships ?? []).length > 0);

const parseMeta = () => {
    try {
        if (!form.meta_text || form.meta_text.trim() === '') {
            return {};
        }

        return JSON.parse(form.meta_text);
    } catch (error) {
        return null;
    }
};

const startCreate = () => {
    editingRelationshipId.value = null;
    form.reset();
    form.clearErrors();
    Object.assign(form, blankForm());
};

const startEdit = (relationship) => {
    editingRelationshipId.value = relationship.id;
    form.property_id = relationship.property_id ?? '';
    form.related_property_id = relationship.related_property_id ?? '';
    form.mapping_key = relationship.mapping_key ?? '';
    form.meta_text = relationship.meta && Object.keys(relationship.meta).length > 0
        ? JSON.stringify(relationship.meta, null, 2)
        : '';
    form.active = !!relationship.active;
    form.clearErrors();
};

const removeRelationship = (relationshipId) => {
    router.delete(`/admin/events/${props.event.id}/relationships/${relationshipId}`, {
        preserveScroll: true,
    });
};

const submit = () => {
    const meta = parseMeta();
    if (meta === null) {
        alert('`meta` debe ser JSON válido.');
        return;
    }

    form.transform((data) => ({
        property_id: Number(data.property_id),
        related_property_id: Number(data.related_property_id),
        mapping_key: data.mapping_key || null,
        active: !!data.active,
        meta,
    }));

    if (editingRelationshipId.value) {
        form.put(`/admin/events/${props.event.id}/relationships/${editingRelationshipId.value}`, {
            preserveScroll: true,
        });
        return;
    }

    form.post(`/admin/events/${props.event.id}/relationships`, {
        preserveScroll: true,
    });
};
</script>

<template>
    <AdminLayout :title="`Mapeo de propiedades · ${props.event.name}`">
        <div v-if="$page.props.flash?.success" class="flash success">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="flash error">{{ $page.props.flash.error }}</div>

        <div class="toolbar">
            <div>
                <h1>Mapeo de propiedades</h1>
                <p>
                    Evento origen: <strong>{{ props.event.name }}</strong>
                    <span class="muted">({{ props.event.event_type_label ?? props.event.event_type_id }})</span>
                </p>
            </div>
            <div class="toolbar-actions">
                <Link class="secondary" href="/admin/events">Volver a eventos</Link>
                <button type="button" class="primary" @click="startCreate">Nuevo mapeo</button>
            </div>
        </div>

        <div class="summary-cards">
            <article class="summary-card">
                <span class="eyebrow">Payload entrante / origen</span>
                <strong>{{ sourcePlatformLabel }}</strong>
                <p>{{ props.source_properties.length }} propiedades origen activas disponibles</p>
            </article>
            <article class="summary-card">
                <span class="eyebrow">Destino del mapeo</span>
                <strong>{{ targetPlatformLabel }}</strong>
                <p>{{ props.target_properties.length }} propiedades destino activas disponibles</p>
            </article>
            <article class="summary-card">
                <span class="eyebrow">Siguiente evento</span>
                <strong>{{ props.event.to_event?.name ?? 'Sin siguiente evento' }}</strong>
                <p>{{ nextPlatformLabel ? `${nextPlatformLabel} · continuidad informativa` : 'Sin write-back o paso posterior configurado' }}</p>
            </article>
            <article class="summary-card">
                <span class="eyebrow">Mapeos configurados</span>
                <strong>{{ props.relationships.length }}</strong>
                <p>{{ mappingModeLabel }}</p>
            </article>
        </div>

        <div class="workspace">
            <section class="panel">
                <header class="panel-head">
                    <div>
                        <h2>Mapeos actuales</h2>
                        <p>Estos mapeos leen el payload entrante y construyen el payload consumido por este evento.</p>
                    </div>
                </header>

                <div v-if="!hasRelationships" class="empty-state">
                    Este evento todavía no tiene mapeos de propiedades configurados.
                </div>

                <div v-else class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Origen</th>
                                <th>Destino</th>
                                <th>Clave de mapeo</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="relationship in props.relationships" :key="relationship.id">
                                <td>
                                    <strong>{{ relationship.property?.name }}</strong>
                                    <div class="cell-meta">{{ relationship.property?.key }} · {{ relationship.property?.type }}</div>
                                </td>
                                <td>
                                    <strong>{{ relationship.related_property?.name }}</strong>
                                    <div class="cell-meta">{{ relationship.related_property?.key }} · {{ relationship.related_property?.type }}</div>
                                </td>
                                <td>{{ relationship.mapping_key || relationship.property?.key || 'auto' }}</td>
                                <td>
                                    <span :class="relationship.active ? 'status active' : 'status inactive'">
                                        {{ relationship.active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="actions">
                                    <IconAction icon="edit" label="Editar mapeo" @click="startEdit(relationship)" />
                                    <IconAction icon="delete" label="Eliminar mapeo" variant="danger" @click="removeRelationship(relationship.id)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel editor">
                <header class="panel-head">
                    <div>
                        <h2>{{ editingRelationshipId ? 'Editar mapeo' : 'Crear mapeo' }}</h2>
                        <p>Elige propiedades de entrada y destino. Usa `mapping_key` cuando la ruta del payload sea distinta de la clave origen.</p>
                    </div>
                </header>

                <form class="editor-form" @submit.prevent="submit">
                    <label class="field">
                        <span>Propiedad del payload entrante</span>
                        <select v-model="form.property_id" required>
                            <option value="" disabled>Selecciona propiedad entrante</option>
                            <option v-for="property in props.source_properties" :key="property.id" :value="property.id">
                                {{ property.name }} ({{ property.key }})
                            </option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Propiedad destino</span>
                        <select v-model="form.related_property_id" required>
                            <option value="" disabled>Selecciona propiedad destino</option>
                            <option v-for="property in props.target_properties" :key="property.id" :value="property.id">
                                {{ property.name }} ({{ property.key }})
                            </option>
                        </select>
                    </label>

                    <label class="field">
                        <span>Clave de mapeo</span>
                        <input v-model="form.mapping_key" type="text" placeholder="raw.associations.deals.0.owner.email">
                        <small>Opcional. Si está vacío, se usa la clave de la propiedad origen. Soporta rutas enriquecidas como hs_terms, raw.properties.hs_terms, raw.associations.deals.0.owner.email o entity_results.company.target_id.</small>
                    </label>

                    <label class="field">
                        <span>Meta JSON</span>
                        <textarea v-model="form.meta_text" rows="5" placeholder='{"transform":"decimal"}' />
                        <small>Opcional. Úsalo para hints de transformación o metadata del mapeo.</small>
                    </label>

                    <label class="check">
                        <input v-model="form.active" type="checkbox">
                        Mapeo activo
                    </label>

                    <div class="form-actions">
                        <button type="submit" class="primary" :disabled="form.processing">
                            {{ editingRelationshipId ? 'Guardar mapeo' : 'Crear mapeo' }}
                        </button>
                        <button type="button" class="secondary" @click="startCreate">Limpiar</button>
                    </div>
                </form>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.toolbar{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    margin-bottom:16px;
}

.toolbar h1{
    margin:0 0 4px;
    font-size:28px;
    color:#0f172a;
}

.toolbar p{
    margin:0;
    color:#475569;
}

.toolbar-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

.summary-cards{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    gap:12px;
    margin-bottom:16px;
}

.summary-card,
.panel{
    background:#fff;
    border:1px solid #dbe4ef;
    border-radius:14px;
    box-shadow:0 8px 20px rgba(15, 23, 42, 0.05);
}

.summary-card{
    padding:16px;
}

.eyebrow{
    display:block;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:#64748b;
    margin-bottom:6px;
}

.summary-card strong{
    display:block;
    font-size:18px;
    color:#0f172a;
}

.summary-card p{
    margin:6px 0 0;
    color:#475569;
    font-size:13px;
}

.workspace{
    display:grid;
    grid-template-columns:minmax(0, 1.4fr) minmax(320px, 0.9fr);
    gap:16px;
}

.panel-head{
    padding:16px 18px;
    border-bottom:1px solid #e2e8f0;
}

.panel-head h2{
    margin:0 0 4px;
    font-size:20px;
    color:#0f172a;
}

.panel-head p{
    margin:0;
    font-size:13px;
    color:#64748b;
}

.empty-state{
    padding:18px;
    color:#64748b;
}

.table-wrap{
    overflow:auto;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,
td{
    padding:12px 14px;
    border-bottom:1px solid #e2e8f0;
    text-align:left;
    vertical-align:top;
}

th{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:#64748b;
}

.cell-meta{
    margin-top:4px;
    font-size:12px;
    color:#64748b;
}

.status{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
}

.status.active{
    background:#dcfce7;
    color:#166534;
}

.status.inactive{
    background:#e2e8f0;
    color:#475569;
}

.editor-form{
    display:grid;
    gap:14px;
    padding:18px;
}

.field{
    display:grid;
    gap:6px;
}

.field span{
    font-size:13px;
    font-weight:600;
    color:#334155;
}

.field small{
    color:#64748b;
    font-size:12px;
}

.field input,
.field select,
.field textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:10px;
    background:#fff;
    color:#0f172a;
    font-size:14px;
    padding:10px 12px;
}

.field textarea{
    resize:vertical;
}

.check{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    color:#334155;
}

.form-actions,
.actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.primary,
.secondary,
.danger{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    padding:9px 12px;
    text-decoration:none;
    cursor:pointer;
}

.primary{
    border:0;
    background:#2563eb;
    color:#fff;
}

.secondary{
    border:1px solid #cbd5e1;
    background:#f8fafc;
    color:#334155;
}

.danger{
    border:1px solid #fecaca;
    background:#fee2e2;
    color:#991b1b;
}

.compact{
    padding:7px 10px;
}

.flash{
    border-radius:10px;
    padding:8px 12px;
    margin-bottom:10px;
    font-size:13px;
}

.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
.muted{color:#64748b}

@media (max-width: 1080px){
    .workspace{
        grid-template-columns:1fr;
    }
}
</style>
