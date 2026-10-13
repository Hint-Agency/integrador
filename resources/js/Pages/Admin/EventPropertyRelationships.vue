<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import HelpTooltip from '@/Components/HelpTooltip.vue';
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
    mode: 'property',
    constant_type: 'number',
    constant_value: '0',
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

        const parsed = JSON.parse(form.meta_text);
        return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)
            ? parsed
            : null;
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
    form.mode = relationship.meta?.mode === 'constant' ? 'constant' : 'property';
    form.constant_type = typeof relationship.meta?.value === 'boolean'
        ? 'boolean'
        : typeof relationship.meta?.value === 'number' ? 'number' : 'string';
    form.constant_value = String(relationship.meta?.value ?? '');
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
        alert('`meta` debe ser un objeto JSON válido.');
        return;
    }

    if (form.mode === 'constant') {
        let value = form.constant_value;
        if (form.constant_type === 'number') {
            if (value.trim() === '' || !Number.isFinite(Number(value))) {
                form.setError('constant_value', 'Ingresa un número válido.');
                return;
            }
            value = Number(value);
        } else if (form.constant_type === 'boolean') {
            value = value === 'true';
        }
        meta.mode = 'constant';
        meta.value = value;
    } else {
        delete meta.mode;
        delete meta.value;
    }

    form.transform((data) => ({
        property_id: data.mode === 'constant' ? null : Number(data.property_id),
        related_property_id: Number(data.related_property_id),
        mapping_key: data.mode === 'constant' ? null : data.mapping_key || null,
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

                <div v-else class="mapping-list">
                    <details v-for="relationship in props.relationships" :key="relationship.id" class="mapping-item">
                        <summary class="mapping-summary">
                            <div class="mapping-route">
                                <strong>
                                    {{ relationship.meta?.mode === 'constant' ? `Valor fijo: ${JSON.stringify(relationship.meta.value)}` : relationship.property?.name }}
                                </strong>
                                <span class="route-arrow" aria-hidden="true">→</span>
                                <strong>{{ relationship.related_property?.name }}</strong>
                            </div>
                            <span :class="relationship.active ? 'status active' : 'status inactive'">
                                {{ relationship.active ? 'Activo' : 'Inactivo' }}
                            </span>
                            <span class="summary-chevron" aria-hidden="true">⌄</span>
                        </summary>

                        <div class="mapping-details">
                            <div class="detail-row">
                                <span>Propiedad origen</span>
                                <code v-if="relationship.meta?.mode === 'constant'">
                                    Constante · {{ typeof relationship.meta.value }}
                                </code>
                                <code v-else>
                                    {{ relationship.property?.key }} · {{ relationship.property?.type }}
                                </code>
                            </div>
                            <div class="detail-row">
                                <span>Propiedad destino</span>
                                <code>{{ relationship.related_property?.key }} · {{ relationship.related_property?.type }}</code>
                            </div>
                            <div class="detail-row">
                                <span>{{ relationship.meta?.mode === 'constant' ? 'Valor fijo' : 'Clave de mapeo' }}</span>
                                <code>
                                    {{ relationship.meta?.mode === 'constant' ? JSON.stringify(relationship.meta.value) : relationship.mapping_key || relationship.property?.key || 'auto' }}
                                </code>
                            </div>
                            <div class="detail-actions">
                                <IconAction icon="edit" label="Editar mapeo" @click="startEdit(relationship)" />
                                <IconAction icon="delete" label="Eliminar mapeo" variant="danger" @click="removeRelationship(relationship.id)" />
                            </div>
                        </div>
                    </details>
                </div>
            </section>

            <section class="panel editor">
                <header class="panel-head">
                    <div class="editor-heading">
                        <h2>{{ editingRelationshipId ? 'Editar mapeo' : 'Crear mapeo' }}</h2>
                        <HelpTooltip label="Ayuda para configurar el mapeo">
                            <p>Elige propiedades de entrada y destino.</p>
                            <p>Usa `mapping_key` cuando la ruta del payload sea distinta de la clave origen.</p>
                        </HelpTooltip>
                    </div>
                </header>

                <form class="editor-form" @submit.prevent="submit">
                    <label class="field">
                        <span>Tipo de mapeo</span>
                        <select v-model="form.mode">
                            <option value="property">Desde propiedad</option>
                            <option value="constant">Valor fijo</option>
                        </select>
                    </label>

                    <label v-if="form.mode === 'property'" class="field">
                        <span>Propiedad del payload entrante</span>
                        <select v-model="form.property_id" required>
                            <option value="" disabled>Selecciona propiedad entrante</option>
                            <option v-for="property in props.source_properties" :key="property.id" :value="property.id">
                                {{ property.name }} ({{ property.key }})
                            </option>
                        </select>
                    </label>

                    <template v-if="form.mode === 'constant'">
                        <label class="field">
                            <span>Tipo del valor fijo</span>
                            <select v-model="form.constant_type" @change="form.constant_value = form.constant_type === 'boolean' ? 'false' : form.constant_type === 'number' ? '0' : ''">
                                <option value="number">Número</option>
                                <option value="string">Texto</option>
                                <option value="boolean">Booleano</option>
                            </select>
                        </label>
                        <div class="field">
                            <div class="field-heading">
                                <label for="mapping-constant-value">Valor fijo</label>
                                <HelpTooltip label="Ayuda sobre el valor fijo">
                                    <p>Siempre se enviará este valor a la propiedad destino, aunque el payload contenga otro valor.</p>
                                </HelpTooltip>
                            </div>
                            <select v-if="form.constant_type === 'boolean'" id="mapping-constant-value" v-model="form.constant_value">
                                <option value="false">false</option>
                                <option value="true">true</option>
                            </select>
                            <input v-else id="mapping-constant-value" v-model="form.constant_value" :type="form.constant_type === 'number' ? 'number' : 'text'" step="any" required>
                            <small v-if="form.errors.constant_value || form.errors['meta.value']" class="field-error">{{ form.errors.constant_value || form.errors['meta.value'] }}</small>
                        </div>
                    </template>

                    <label class="field">
                        <span>Propiedad destino</span>
                        <select v-model="form.related_property_id" required>
                            <option value="" disabled>Selecciona propiedad destino</option>
                            <option v-for="property in props.target_properties" :key="property.id" :value="property.id">
                                {{ property.name }} ({{ property.key }})
                            </option>
                        </select>
                    </label>

                    <div v-if="form.mode === 'property'" class="field">
                        <div class="field-heading">
                            <label for="mapping-key">Clave de mapeo</label>
                            <HelpTooltip label="Ayuda sobre la clave de mapeo">
                                <p>Es opcional. Si está vacía, se usa la clave de la propiedad origen.</p>
                                <p>Admite rutas enriquecidas como `raw.properties.hs_terms`, `raw.associations.deals.0.owner.email` o `entity_results.company.target_id`.</p>
                                <p>Para almacenes usa rutas `destination_response.data.*`. Para cotizaciones SAE usa `contact.properties.*`, `quote.properties.*` o `line_item.properties.*`.</p>
                            </HelpTooltip>
                        </div>
                        <input id="mapping-key" v-model="form.mapping_key" type="text" placeholder="raw.associations.deals.0.owner.email">
                    </div>

                    <div class="field">
                        <div class="field-heading">
                            <label for="mapping-meta">Meta JSON</label>
                            <HelpTooltip label="Ayuda sobre Meta JSON">
                                <p>Opcional. Admite transformaciones y valores predeterminados cuando la propiedad origen no existe, es nula o está vacía.</p>
                                <p>En `prepareAspelQuote` usa `{"scope":"header"}` para cabecera y dirección, o `{"scope":"line_item"}` para cada partida.</p>
                            </HelpTooltip>
                        </div>
                        <textarea id="mapping-meta" v-model="form.meta_text" rows="5" placeholder='{"transform":"decimal","default_value":0,"apply_default_when":["missing","null","empty"]}' />
                    </div>

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
    align-items:start;
}

.editor{
    position:sticky;
    top:16px;
    align-self:start;
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

.editor-heading,
.field-heading{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}

.editor-heading h2{
    margin:0;
}

.empty-state{
    padding:18px;
    color:#64748b;
}

.mapping-list{
    display:grid;
    gap:10px;
    padding:14px;
}

.mapping-item{
    overflow:hidden;
    border:1px solid #dbe4ef;
    border-radius:12px;
    background:#fff;
}

.mapping-summary{
    display:grid;
    grid-template-columns:minmax(0, 1fr) auto auto;
    align-items:center;
    gap:12px;
    padding:13px 14px;
    cursor:pointer;
    list-style:none;
}

.mapping-summary::-webkit-details-marker{
    display:none;
}

.mapping-route{
    display:flex;
    align-items:center;
    gap:8px;
    min-width:0;
}

.mapping-route strong{
    min-width:0;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
    color:#0f172a;
    font-size:14px;
}

.route-arrow{
    flex:0 0 auto;
    color:#2563eb;
    font-size:18px;
}

.summary-chevron{
    color:#64748b;
    transition:transform .16s ease;
}

.mapping-item[open] .summary-chevron{
    transform:rotate(180deg);
}

.mapping-details{
    display:grid;
    padding:4px 14px 12px;
    border-top:1px solid #e2e8f0;
    background:#f8fafc;
}

.detail-row{
    display:grid;
    grid-template-columns:minmax(130px, .35fr) minmax(0, 1fr);
    align-items:baseline;
    gap:14px;
    padding:10px 2px;
    border-bottom:1px solid #e2e8f0;
}

.detail-row span{
    color:#64748b;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.06em;
}

.detail-row code{
    overflow-wrap:anywhere;
    color:#334155;
    font-size:12px;
}

.detail-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding-top:10px;
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

.field-heading label{
    font-size:13px;
    font-weight:600;
    color:#334155;
}

.field small{
    color:#64748b;
    font-size:12px;
}

.field .field-error{
    color:#b91c1c;
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

    .editor{
        position:static;
    }
}

@media (max-width: 640px){
    .mapping-summary{
        grid-template-columns:minmax(0, 1fr) auto;
    }

    .mapping-summary .status{
        display:none;
    }

    .mapping-route{
        align-items:flex-start;
        flex-direction:column;
        gap:3px;
    }

    .route-arrow{
        transform:rotate(90deg);
    }

    .detail-row{
        grid-template-columns:1fr;
        gap:4px;
    }
}
</style>
