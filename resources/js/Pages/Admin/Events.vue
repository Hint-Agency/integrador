<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import IconAction from '@/Components/IconAction.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    events: { type: Object, required: true },
});

const pipelineRows = computed(() => (props.events.data ?? []).map((event) => ({
    id: event.id,
    name: event.name,
    next: event.to_event?.name ?? 'FIN',
    platform: event.platform?.name ?? 'n/a',
})));
const showPipeline = ref(false);

const remove = (id) => router.delete(`/admin/events/${id}`, { preserveScroll: true });
const executeNow = (id) => router.post(`/admin/events/${id}/execute-now`, {}, { preserveScroll: true });
</script>

<template>
    <AdminLayout title="Eventos">
        <div v-if="$page.props.flash?.success" class="flash success">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="flash error">{{ $page.props.flash.error }}</div>

        <div class="toolbar">
            <Link class="primary" href="/admin/events/create">Crear evento</Link>
        </div>

        <div class="flow-map">
            <button
                type="button"
                class="flow-toggle"
                :aria-expanded="showPipeline"
                @click="showPipeline = !showPipeline"
            >
                <span>Relaciones de eventos (pipeline)</span>
                <strong>{{ showPipeline ? 'Ocultar' : 'Mostrar' }}</strong>
            </button>
            <div v-if="showPipeline" class="flow-lines">
                <div v-for="row in pipelineRows" :key="`flow-${row.id}`" class="flow-line">
                    <span class="badge">{{ row.platform }}</span>
                    <strong>{{ row.name }}</strong>
                    <span class="arrow">→</span>
                    <span>{{ row.next }}</span>
                </div>
            </div>
        </div>

        <div class="summary">
            <p>Total: {{ props.events.total }}</p>
            <p>Página: {{ props.events.current_page }} / {{ props.events.last_page }}</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Tipo de evento</th>
                        <th>Plataforma</th>
                        <th>Siguiente</th>
                        <th>Activo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="event in props.events.data" :key="event.id">
                        <td>{{ event.id }}</td>
                        <td>{{ event.name }}</td>
                        <td>{{ event.type }}</td>
                        <td>{{ event.event_type_label ?? event.event_type_id }}</td>
                        <td>{{ event.platform?.name ?? 'n/a' }}</td>
                        <td>{{ event.to_event?.name ?? 'FIN' }}</td>
                        <td>{{ event.active ? 'Sí' : 'No' }}</td>
                        <td class="actions-cell">
                            <IconAction as="link" icon="flow" label="Ver flujo" :href="`/events/${event.id}`" />
                            <IconAction as="link" icon="trigger" label="Configurar disparadores" :href="`/admin/events/${event.id}/triggers`" />
                            <IconAction as="link" icon="mapping" label="Editar mapeos" :href="`/admin/events/${event.id}/relationships`" />
                            <IconAction as="link" icon="edit" label="Editar evento" :href="`/admin/events/${event.id}/edit`" />
                            <IconAction
                                v-if="event.type === 'schedule'"
                                icon="play"
                                label="Ejecutar ahora"
                                variant="success"
                                @click="executeNow(event.id)"
                            />
                            <IconAction icon="delete" label="Eliminar evento" variant="danger" @click="remove(event.id)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationNav :links="props.events.links ?? []" />
    </AdminLayout>
</template>

<style scoped>
.toolbar{display:flex;justify-content:flex-end;margin-bottom:10px}
.summary{display:flex;gap:18px;margin:8px 0;color:#475569;font-size:13px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;font-size:13px}
th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#475569}
.actions-cell{white-space:nowrap}
.flow-map{border:1px solid #dbe4ef;border-radius:12px;background:#fff;padding:12px;margin-bottom:12px}
.flow-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;border:0;background:transparent;color:#0f172a;padding:0;cursor:pointer;text-align:left}
.flow-toggle span{font-size:16px;font-weight:700}
.flow-toggle strong{border:1px solid #cbd5e1;border-radius:999px;background:#f8fafc;color:#334155;font-size:12px;padding:5px 9px}
.flow-lines{display:grid;gap:8px}
.flow-toggle + .flow-lines{margin-top:10px}
.flow-line{display:flex;align-items:center;gap:8px;border:1px solid #e2e8f0;border-radius:10px;padding:8px 10px;background:#f8fafc}
.badge{font-size:10px;border:1px solid #cbd5e1;border-radius:999px;padding:2px 6px;color:#334155;background:#fff}
.arrow{color:#64748b}
.primary,.secondary{border:1px solid #1d4ed8;background:#1d4ed8;color:#fff;border-radius:8px;padding:8px 12px;cursor:pointer;text-decoration:none}
.secondary{border-color:#cbd5e1;background:#f8fafc;color:#334155}
.compact{margin-right:6px;padding:6px 8px}
.danger{border:1px solid #fecaca;background:#fee2e2;color:#991b1b;border-radius:8px;padding:6px 8px;cursor:pointer}
.flash{border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px}
.flash.success{background:#dcfce7;color:#166534}
.flash.error{background:#fee2e2;color:#991b1b}
</style>
