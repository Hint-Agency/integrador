<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import IconAction from '@/Components/IconAction.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    events: { type: Object, required: true },
    total_events: { type: Number, default: 0 },
});

const flowNodes = (event) => [...(event.flow?.nodes ?? [])].sort((left, right) => left.depth - right.depth);
const activeNodeCount = (event) => flowNodes(event).filter((node) => node.active).length;
const flowDirection = (event) => {
    const platforms = [...new Set(flowNodes(event).map((node) => node.platform).filter(Boolean))];

    return platforms.length > 1 ? platforms.join(' → ') : (platforms[0] ?? 'Sin plataforma');
};
const platformClass = (type) => `platform-${String(type ?? 'generic').toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
const remove = (id) => router.delete(`/admin/events/${id}`, { preserveScroll: true });
const executeNow = (id) => router.post(`/admin/events/${id}/execute-now`, {}, { preserveScroll: true });
</script>

<template>
    <AdminLayout title="Flujos de eventos">
        <div v-if="$page.props.flash?.success" class="flash success">{{ $page.props.flash.success }}</div>
        <div v-if="$page.props.flash?.error" class="flash error">{{ $page.props.flash.error }}</div>

        <div class="page-intro">
            <div>
                <p class="eyebrow">Orquestación</p>
                <h2>Eventos detonantes</h2>
                <p class="intro-copy">Cada tarjeta representa el inicio de un flujo y muestra su recorrido completo.</p>
            </div>
            <Link class="primary" href="/admin/events/create">Crear evento</Link>
        </div>

        <div class="flow-summary">
            <div><strong>{{ props.events.total }}</strong><span>Flujos configurados</span></div>
            <div><strong>{{ props.total_events }}</strong><span>Eventos totales</span></div>
            <div><strong>{{ props.events.current_page }} / {{ props.events.last_page }}</strong><span>Página actual</span></div>
        </div>

        <div v-if="props.events.data?.length" class="flow-grid">
            <article v-for="event in props.events.data" :key="event.id" class="flow-card">
                <header class="card-head">
                    <div class="card-title">
                        <span class="flow-id">FLUJO #{{ event.id }}</span>
                        <h3>{{ event.name }}</h3>
                        <p class="trigger-line">
                            <span :class="['state-dot', { inactive: !event.active }]" />
                            {{ event.trigger_summary }}
                        </p>
                    </div>
                    <span :class="['status-pill', { inactive: !event.active }]">
                        {{ event.active ? 'Activo' : 'Inactivo' }}
                    </span>
                </header>

                <div class="chain" :aria-label="`Flujo de ${event.name}`">
                    <template v-for="(node, index) in flowNodes(event)" :key="node.id">
                        <div :class="['mini-node', { root: index === 0, inactive: !node.active }]">
                            <span :class="['platform-mark', platformClass(node.platform_type)]">{{ node.platform }}</span>
                            <strong>{{ node.name }}</strong>
                            <small>#{{ node.id }} · {{ node.method_name ?? node.event_type_label ?? node.event_type_id }}</small>
                        </div>
                        <span v-if="index < flowNodes(event).length - 1" class="chain-arrow" aria-hidden="true">→</span>
                    </template>
                </div>

                <footer class="card-foot">
                    <div class="flow-meta">
                        <strong>{{ flowNodes(event).length }} eventos</strong>
                        <span>{{ activeNodeCount(event) }} activos</span>
                        <span>{{ flowDirection(event) }}</span>
                    </div>
                    <div class="card-actions">
                        <IconAction as="link" icon="flow" label="Abrir workflow" :href="`/events/${event.id}`" />
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
                    </div>
                </footer>
            </article>
        </div>

        <div v-else class="empty-state">
            <strong>No hay flujos configurados</strong>
            <p>Crea un evento sin predecesor para comenzar un nuevo flujo.</p>
        </div>

        <PaginationNav :links="props.events.links ?? []" />
    </AdminLayout>
</template>

<style scoped>
.page-intro{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding:4px 2px 18px}.eyebrow{margin:0 0 4px;color:#1d6db0;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.page-intro h2{margin:0;color:#14232f;font-family:'Barlow Condensed',sans-serif;font-size:30px;letter-spacing:.02em}.intro-copy{margin:4px 0 0;color:#64717d;font-size:13px}.primary{border:1px solid #1d6db0;background:#1d6db0;color:#fff;border-radius:9px;padding:9px 13px;font-size:13px;font-weight:700;text-decoration:none;box-shadow:0 7px 18px rgba(29,109,176,.18)}
.flow-summary{display:flex;gap:28px;border-top:1px solid #e0e7ec;border-bottom:1px solid #e0e7ec;padding:12px 2px;margin-bottom:16px}.flow-summary div{display:grid;gap:1px}.flow-summary strong{color:#1a2b37;font-size:15px}.flow-summary span{color:#788591;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.flow-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.flow-card{min-width:0;border:1px solid #dbe4ea;border-radius:14px;background:#fff;padding:15px;box-shadow:0 9px 26px rgba(23,39,51,.055);transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease}.flow-card:hover{border-color:#9ebdd6;transform:translateY(-2px);box-shadow:0 13px 30px rgba(23,39,51,.09)}.card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.card-title{min-width:0}.flow-id{color:#7a8995;font-size:9px;font-weight:800;letter-spacing:.1em}.card-title h3{margin:5px 0 6px;color:#152631;font-size:15px;line-height:1.25}.trigger-line{display:flex;align-items:center;gap:7px;margin:0;color:#657582;font-size:10px}.state-dot{width:7px;height:7px;border-radius:50%;background:#18866b;box-shadow:0 0 0 3px rgba(24,134,107,.12);flex:0 0 auto}.state-dot.inactive{background:#c65d5d;box-shadow:0 0 0 3px rgba(198,93,93,.12)}.status-pill{flex:0 0 auto;border:1px solid rgba(24,134,107,.2);border-radius:999px;background:rgba(24,134,107,.09);color:#13725c;padding:4px 7px;font-size:9px;font-weight:800;text-transform:uppercase}.status-pill.inactive{border-color:rgba(198,93,93,.22);background:rgba(198,93,93,.08);color:#a34545}
.chain{display:flex;align-items:stretch;gap:6px;overflow-x:auto;margin:14px 0;padding:10px;border:1px solid #e1e8ed;border-radius:10px;background:linear-gradient(135deg,#f7f9fa,#f1f5f7);scrollbar-width:thin}.mini-node{display:grid;align-content:start;gap:4px;flex:1 0 128px;min-width:128px;max-width:172px;border:1px solid #d8e1e7;border-radius:8px;background:#fff;padding:8px;box-shadow:0 3px 9px rgba(23,39,51,.04)}.mini-node.root{border-color:#73a6cf;box-shadow:inset 3px 0 #2878b7}.mini-node.inactive{opacity:.58}.platform-mark{width:max-content;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#356e9b;font-size:8px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.platform-odoo{color:#9b466f}.platform-hubspot{color:#cb612c}.platform-generic{color:#397a68}.mini-node strong{overflow:hidden;color:#20323f;font-size:10px;line-height:1.25}.mini-node small{overflow:hidden;color:#7b8994;font:8px/1.25 ui-monospace,SFMono-Regular,Menlo,monospace;text-overflow:ellipsis;white-space:nowrap}.chain-arrow{align-self:center;color:#7d9db4;font-size:15px}.card-foot{display:flex;align-items:flex-end;justify-content:space-between;gap:12px}.flow-meta{display:flex;flex-wrap:wrap;gap:5px 10px;color:#7a8791;font-size:9px}.flow-meta strong{color:#3c4d59}.card-actions{display:flex;align-items:center;justify-content:flex-end;gap:3px;white-space:nowrap}.empty-state{display:grid;place-items:center;min-height:260px;border:1px dashed #cbd7df;border-radius:13px;background:#f8fafb;color:#40515e;text-align:center}.empty-state p{margin:4px 0 0;color:#7a8791;font-size:12px}.flash{border-radius:10px;padding:8px 12px;margin-bottom:10px;font-size:13px}.flash.success{background:#dcfce7;color:#166534}.flash.error{background:#fee2e2;color:#991b1b}
@media(max-width:1050px){.flow-grid{grid-template-columns:1fr}}@media(max-width:640px){.page-intro{align-items:stretch;flex-direction:column}.primary{text-align:center}.flow-summary{gap:14px;justify-content:space-between}.card-foot{align-items:flex-start;flex-direction:column}.card-actions{justify-content:flex-start}.flow-card{padding:12px}}
</style>
