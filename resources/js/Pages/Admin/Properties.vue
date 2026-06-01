<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import IconAction from '@/Components/IconAction.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    properties: { type: Object, required: true },
    platforms: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    property_types: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const filters = reactive({
    platform_id: props.filters.platform_id ?? '',
    category_id: props.filters.category_id ?? '',
    type: props.filters.type ?? '',
    search: props.filters.search ?? '',
});

const remove = (id) => router.delete(`/admin/properties/${id}`, { preserveScroll: true });

const applyFilters = () => {
    router.get('/admin/properties', {
        platform_id: filters.platform_id || undefined,
        category_id: filters.category_id || undefined,
        type: filters.type || undefined,
        search: filters.search || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const resetFilters = () => {
    filters.platform_id = '';
    filters.category_id = '';
    filters.type = '';
    filters.search = '';
    router.get('/admin/properties', {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};
</script>

<template>
    <AdminLayout title="Propiedades">
        <div v-if="$page.props.flash?.success" class="flash success">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="flash error">{{ $page.props.flash.error }}</div>

        <div class="toolbar">
            <Link class="primary" href="/admin/properties/create">Crear propiedad</Link>
        </div>

        <form class="filters" @submit.prevent="applyFilters">
            <label>
                <span>Buscar</span>
                <input
                    v-model="filters.search"
                    type="search"
                    placeholder="Nombre o key"
                >
            </label>

            <label>
                <span>Plataforma</span>
                <select v-model="filters.platform_id">
                    <option value="">Todas</option>
                    <option v-for="platform in props.platforms" :key="platform.id" :value="String(platform.id)">
                        {{ platform.name }} ({{ platform.type }})
                    </option>
                </select>
            </label>

            <label>
                <span>Tipo</span>
                <select v-model="filters.type">
                    <option value="">Todos</option>
                    <option v-for="type in props.property_types" :key="type" :value="type">
                        {{ type }}
                    </option>
                </select>
            </label>

            <label>
                <span>Categoría</span>
                <select v-model="filters.category_id">
                    <option value="">Todas</option>
                    <option v-for="category in props.categories" :key="category.id" :value="String(category.id)">
                        {{ category.name }}
                    </option>
                </select>
            </label>

            <div class="filter-actions">
                <button type="submit" class="secondary">Aplicar</button>
                <button type="button" class="ghost" @click="resetFilters">Limpiar</button>
            </div>
        </form>

        <div class="summary">
            <p>Total: {{ props.properties.total }}</p>
            <p>Página: {{ props.properties.current_page }} / {{ props.properties.last_page }}</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Clave</th>
                        <th>Tipo</th>
                        <th>Plataforma</th>
                        <th>Categorías</th>
                        <th>Requerida</th>
                        <th>Activa</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="property in props.properties.data" :key="property.id">
                        <td>{{ property.id }}</td>
                        <td>{{ property.name }}</td>
                        <td>{{ property.key }}</td>
                        <td>{{ property.type }}</td>
                        <td>{{ property.platform?.name ?? 'n/a' }}</td>
                        <td>
                            <div v-if="property.categories?.length" class="category-list">
                                <span v-for="category in property.categories" :key="category.id" class="category-badge">
                                    {{ category.name }}
                                </span>
                            </div>
                            <span v-else class="muted">Sin categoría</span>
                        </td>
                        <td>{{ property.required ? 'Sí' : 'No' }}</td>
                        <td>{{ property.active ? 'Sí' : 'No' }}</td>
                        <td class="actions-cell">
                            <IconAction as="link" icon="edit" label="Editar propiedad" :href="`/admin/properties/${property.id}/edit`" />
                            <IconAction icon="delete" label="Eliminar propiedad" variant="danger" @click="remove(property.id)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationNav :links="props.properties.links ?? []" />
    </AdminLayout>
</template>

<style scoped>
.toolbar{display:flex;justify-content:flex-end;margin-bottom:10px}
.filters{display:grid;grid-template-columns:minmax(220px,1fr) minmax(180px,220px) minmax(140px,180px) minmax(170px,220px) auto;gap:12px;align-items:end;border:1px solid #dbe4ef;border-radius:14px;background:#f8fafc;padding:12px;margin-bottom:12px}
.filters label{display:grid;gap:6px}
.filters span{font-size:12px;font-weight:700;color:#475569}
.filters input,.filters select{width:100%;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#0f172a;font-size:14px;padding:9px 11px}
.filter-actions{display:flex;gap:8px;flex-wrap:wrap}
.summary{display:flex;gap:18px;margin:8px 0;color:#475569;font-size:13px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;font-size:13px}
th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#475569}
.actions-cell{white-space:nowrap}
.category-list{display:flex;flex-wrap:wrap;gap:5px}
.category-badge{display:inline-flex;align-items:center;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;font-size:11px;padding:3px 7px}
.muted{color:#94a3b8;font-size:12px}
.primary,.secondary{border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;border-radius:8px;padding:8px 12px;cursor:pointer;text-decoration:none}
.secondary{border-color:#cbd5e1;background:#f8fafc;color:#334155}
.ghost{border:1px solid transparent;background:transparent;color:#475569;border-radius:8px;padding:8px 10px;cursor:pointer}
.ghost:hover{background:#e2e8f0;color:#0f172a}
.compact{margin-right:6px;padding:6px 8px}
.danger{border:1px solid #fecaca;background:#fee2e2;color:#991b1b;border-radius:8px;padding:6px 8px;cursor:pointer}
.flash{border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px}
.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
@media (max-width: 960px){.filters{grid-template-columns:1fr 1fr}.filter-actions{grid-column:1 / -1}}
@media (max-width: 620px){.filters{grid-template-columns:1fr}}
</style>
