<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LightboxFormModal from '@/Components/LightboxFormModal.vue';
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    category: { type: Object, default: null },
    properties: { type: Array, default: () => [] },
});

const isEdit = computed(() => props.mode === 'edit');
const propertySearch = ref('');
const platformFilter = ref('');

const form = useForm({
    name: props.category?.name ?? '',
    slug: props.category?.slug ?? '',
    description: props.category?.description ?? '',
    active: props.category?.active ?? true,
    property_ids: props.category?.property_ids ?? [],
});

const platforms = computed(() => {
    const map = new Map();

    props.properties.forEach((property) => {
        if (property.platform) {
            map.set(property.platform.id, property.platform);
        }
    });

    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
});

const normalizedSearch = computed(() => propertySearch.value.trim().toLowerCase());
const selectedIds = computed(() => form.property_ids.map(Number));

const filteredProperties = computed(() => props.properties.filter((property) => {
    const matchesPlatform = !platformFilter.value || String(property.platform_id) === String(platformFilter.value);
    const name = String(property.name ?? '').toLowerCase();
    const key = String(property.key ?? '').toLowerCase();
    const matchesSearch = !normalizedSearch.value
        || name.includes(normalizedSearch.value)
        || key.includes(normalizedSearch.value);

    return matchesPlatform && matchesSearch;
}));

const selectedProperties = computed(() => props.properties.filter((property) => selectedIds.value.includes(Number(property.id))));
const hasActivePropertyFilters = computed(() => normalizedSearch.value !== '' || platformFilter.value !== '');

const isSelected = (propertyId) => selectedIds.value.includes(Number(propertyId));

const toggleProperty = (propertyId) => {
    const normalizedId = Number(propertyId);

    if (isSelected(normalizedId)) {
        form.property_ids = form.property_ids.filter((id) => Number(id) !== normalizedId);
        return;
    }

    form.property_ids = [...form.property_ids, normalizedId];
};

const addProperties = (properties) => {
    form.property_ids = Array.from(new Set([
        ...selectedIds.value,
        ...properties.map((property) => Number(property.id)),
    ]));
};

const removeProperties = (properties) => {
    const idsToRemove = new Set(properties.map((property) => Number(property.id)));
    form.property_ids = selectedIds.value.filter((id) => !idsToRemove.has(id));
};

const addVisibleProperties = () => addProperties(filteredProperties.value);
const addAllProperties = () => addProperties(props.properties);
const removeVisibleProperties = () => removeProperties(filteredProperties.value);

const clearPropertyFilters = () => {
    propertySearch.value = '';
    platformFilter.value = '';
};

const submit = () => {
    if (isEdit.value) {
        form.put(`/admin/categories/${props.category.id}`);
        return;
    }

    form.post('/admin/categories');
};
</script>

<template>
    <AdminLayout title="Categorías">
        <LightboxFormModal :title="isEdit ? `Editar categoría #${props.category?.id}` : 'Crear categoría'" close-href="/admin/categories">
            <form class="lightbox-form" @submit.prevent="submit">
                <div class="lightbox-grid">
                    <label class="lightbox-field">
                        <span class="lightbox-label">Nombre</span>
                        <input v-model="form.name" class="lightbox-input" type="text" placeholder="Nombre de la categoría" required>
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Slug</span>
                        <input v-model="form.slug" class="lightbox-input" type="text" placeholder="Category Slug">
                    </label>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Descripción</span>
                        <input v-model="form.description" class="lightbox-input" type="text" placeholder="Descripción">
                    </label>
                </div>

                <div class="lightbox-block property-picker">
                    <div class="property-picker-head">
                        <div>
                            <p class="lightbox-block-title">Propiedades</p>
                            <p class="property-help">Agrega propiedades usando búsqueda por nombre/key o filtrando por plataforma.</p>
                        </div>
                        <span class="selected-count">{{ form.property_ids.length }} seleccionadas</span>
                    </div>

                    <div class="property-filters">
                        <label>
                            <span>Buscar propiedad</span>
                            <input v-model="propertySearch" type="search" placeholder="Nombre o key">
                        </label>

                        <label>
                            <span>Plataforma</span>
                            <select v-model="platformFilter">
                                <option value="">Todas</option>
                                <option v-for="platform in platforms" :key="platform.id" :value="String(platform.id)">
                                    {{ platform.name }} ({{ platform.type }})
                                </option>
                            </select>
                        </label>

                        <button type="button" class="filter-reset" @click="clearPropertyFilters">Limpiar</button>
                    </div>

                    <div class="bulk-actions">
                        <button
                            v-if="hasActivePropertyFilters"
                            type="button"
                            :disabled="filteredProperties.length === 0"
                            @click="addVisibleProperties"
                        >
                            Agregar visibles ({{ filteredProperties.length }})
                        </button>
                        <button type="button" @click="addAllProperties">
                            Agregar todas ({{ props.properties.length }})
                        </button>
                        <span v-if="hasActivePropertyFilters" class="filter-context">Usando filtros activos</span>
                    </div>

                    <div class="property-picker-grid">
                        <section class="property-list-panel">
                            <div class="panel-title">
                                <strong>Disponibles</strong>
                                <span>{{ filteredProperties.length }} resultados</span>
                            </div>

                            <div v-if="filteredProperties.length" class="property-list">
                                <button
                                    v-for="property in filteredProperties"
                                    :key="property.id"
                                    type="button"
                                    :class="['property-row', { selected: isSelected(property.id) }]"
                                    @click="toggleProperty(property.id)"
                                >
                                    <span class="property-main">
                                        <strong>{{ property.name }}</strong>
                                        <small>{{ property.key }}</small>
                                    </span>
                                    <span class="property-platform">{{ property.platform?.name ?? 'Sin plataforma' }}</span>
                                </button>
                            </div>

                            <p v-else class="empty-state">No hay propiedades que coincidan con los filtros.</p>
                        </section>

                        <section class="property-list-panel">
                            <div class="panel-title">
                                <strong>Seleccionadas</strong>
                                <div class="selected-title-actions">
                                    <button
                                        v-if="selectedProperties.length"
                                        type="button"
                                        class="subtle-danger inline-action"
                                        :disabled="filteredProperties.length === 0"
                                        @click="removeVisibleProperties"
                                    >
                                        Quitar visibles ({{ filteredProperties.length }})
                                    </button>
                                    <span>{{ selectedProperties.length }}</span>
                                </div>
                            </div>

                            <div v-if="selectedProperties.length" class="selected-list">
                                <button
                                    v-for="property in selectedProperties"
                                    :key="`selected-${property.id}`"
                                    type="button"
                                    class="selected-chip"
                                    @click="toggleProperty(property.id)"
                                >
                                    <span>{{ property.name }}</span>
                                    <small>{{ property.key }}</small>
                                </button>
                            </div>

                            <p v-else class="empty-state">Aún no has agregado propiedades.</p>
                        </section>
                    </div>
                </div>

                <label class="lightbox-check-inline"><input v-model="form.active" type="checkbox"> Activa</label>

                <div class="lightbox-actions">
                    <button class="lightbox-submit" type="submit" :disabled="form.processing">{{ isEdit ? 'Guardar cambios' : 'Crear categoría' }}</button>
                    <Link class="lightbox-link" href="/admin/categories">Cancelar</Link>
                </div>
            </form>
        </LightboxFormModal>
    </AdminLayout>
</template>

<style scoped>
.property-picker{
    display:grid;
    gap:10px;
}

.property-picker-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
}

.property-help{
    margin:2px 0 0;
    color:#64748b;
    font-size:12px;
}

.selected-count{
    border:1px solid #bfdbfe;
    border-radius:999px;
    background:#eff6ff;
    color:#1d4ed8;
    font-size:11px;
    font-weight:700;
    padding:4px 8px;
    white-space:nowrap;
}

.property-filters{
    display:grid;
    grid-template-columns:minmax(220px,1fr) minmax(180px,260px) auto;
    gap:8px;
    align-items:end;
}

.property-filters label{
    display:grid;
    gap:4px;
}

.property-filters span{
    color:#475569;
    font-size:11px;
    font-weight:700;
}

.property-filters input,
.property-filters select{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:10px;
    background:#fff;
    color:#0f172a;
    font-size:13px;
    padding:7px 9px;
}

.filter-reset{
    border:1px solid #cbd5e1;
    border-radius:10px;
    background:#f8fafc;
    color:#334155;
    cursor:pointer;
    font-size:13px;
    padding:7px 10px;
}

.bulk-actions{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
}

.bulk-actions button{
    border:1px solid #cbd5e1;
    border-radius:999px;
    background:#fff;
    color:#334155;
    cursor:pointer;
    font-size:12px;
    padding:6px 10px;
}

.bulk-actions button:hover{
    border-color:#93c5fd;
    background:#eff6ff;
    color:#1d4ed8;
}

.bulk-actions button:disabled{
    cursor:not-allowed;
    opacity:.45;
}

.filter-context{
    align-self:center;
    color:#64748b;
    font-size:11px;
}

.subtle-danger{
    border-color:#fecaca;
    color:#b91c1c;
}

.subtle-danger:hover{
    border-color:#fca5a5;
    background:#fff1f2;
    color:#991b1b;
}

.selected-title-actions{
    display:flex;
    align-items:center;
    gap:7px;
}

.inline-action{
    border:1px solid #fecaca;
    border-radius:999px;
    background:#fff;
    cursor:pointer;
    font-size:11px;
    padding:4px 8px;
}

.inline-action:disabled{
    cursor:not-allowed;
    opacity:.45;
}

.property-picker-grid{
    display:grid;
    grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);
    gap:10px;
}

.property-list-panel{
    border:1px solid #dbe4ef;
    border-radius:14px;
    background:#fff;
    overflow:hidden;
}

.panel-title{
    display:flex;
    justify-content:space-between;
    gap:10px;
    border-bottom:1px solid #e2e8f0;
    padding:8px 10px;
    color:#334155;
    font-size:12px;
}

.panel-title span{
    color:#64748b;
}

.property-list{
    display:grid;
    max-height:260px;
    overflow:auto;
}

.property-row{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:10px;
    align-items:center;
    border:0;
    border-bottom:1px solid #eef2f7;
    background:#fff;
    color:#0f172a;
    cursor:pointer;
    padding:7px 10px;
    text-align:left;
}

.property-row:hover,
.property-row.selected{
    background:#f0f7ff;
}

.property-row.selected{
    box-shadow:inset 3px 0 0 #2563eb;
}

.property-main{
    display:grid;
    gap:1px;
    min-width:0;
}

.property-main strong{
    font-size:13px;
}

.property-main strong,
.selected-chip span{
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.property-main small,
.selected-chip small{
    color:#64748b;
    font-size:11px;
}

.property-platform{
    border:1px solid #cbd5e1;
    border-radius:999px;
    color:#475569;
    font-size:10px;
    padding:2px 6px;
    white-space:nowrap;
}

.selected-list{
    display:flex;
    flex-wrap:wrap;
    align-content:flex-start;
    gap:6px;
    max-height:260px;
    overflow:auto;
    padding:10px;
}

.selected-chip{
    display:grid;
    gap:2px;
    max-width:100%;
    border:1px solid #bfdbfe;
    border-radius:12px;
    background:#eff6ff;
    color:#1e3a8a;
    cursor:pointer;
    padding:6px 8px;
    text-align:left;
}

.selected-chip span{
    font-size:12px;
}

.selected-chip:hover{
    border-color:#93c5fd;
    background:#dbeafe;
}

.empty-state{
    margin:0;
    color:#64748b;
    font-size:12px;
    padding:12px;
}

@media (max-width: 920px){
    .property-filters,
    .property-picker-grid{
        grid-template-columns:1fr;
    }
}
</style>
