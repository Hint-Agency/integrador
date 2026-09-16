<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    nodes: { type: Array, default: () => [] },
    chain: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const orderedNodes = computed(() => {
    const nodeMap = new Map(props.nodes.map((node) => [node.id, node]));
    const chained = props.chain.map((id) => nodeMap.get(id)).filter(Boolean);

    return chained.length ? chained : [...props.nodes].sort((left, right) => left.depth - right.depth);
});
const selectedId = ref(props.chain[0] ?? props.nodes[0]?.id ?? null);
const selectedNode = computed(() => orderedNodes.value.find((node) => node.id === selectedId.value) ?? orderedNodes.value[0] ?? null);
const platformClass = (type) => `platform-${String(type ?? 'generic').toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
</script>

<template>
    <div class="workflow-layout">
        <section class="workflow-canvas">
            <div class="canvas-head">
                <div>
                    <span>Recorrido del flujo</span>
                    <strong>{{ orderedNodes.length }} eventos encadenados</strong>
                </div>
                <div class="legend"><i /> Activo <i class="off" /> Inactivo</div>
            </div>

            <div v-if="orderedNodes.length" class="node-track">
                <template v-for="(node, index) in orderedNodes" :key="node.id">
                    <button
                        type="button"
                        :class="['workflow-node', { selected: node.id === selectedNode?.id, inactive: !node.active }]"
                        @click="selectedId = node.id"
                    >
                        <span class="node-order">{{ index + 1 }}</span>
                        <span :class="['node-platform', platformClass(node.platform_type)]">{{ node.platform }}</span>
                        <strong>{{ node.name }}</strong>
                        <code>{{ node.method_name ?? node.event_type_label ?? node.event_type_id }}</code>
                        <small><i :class="{ off: !node.active }" /> {{ node.active ? 'Activo' : 'Inactivo' }}</small>
                    </button>
                    <div v-if="index < orderedNodes.length - 1" class="connector" aria-hidden="true"><span>→</span></div>
                </template>
            </div>
            <div v-else class="empty-flow">No hay eventos encadenados para mostrar.</div>
        </section>

        <aside v-if="selectedNode" class="node-inspector">
            <div class="inspector-head">
                <div><span>Evento seleccionado</span><strong>#{{ selectedNode.id }}</strong></div>
                <span :class="['status', { inactive: !selectedNode.active }]">{{ selectedNode.active ? 'Activo' : 'Inactivo' }}</span>
            </div>
            <div class="inspector-name">{{ selectedNode.name }}</div>
            <dl>
                <div><dt>Plataforma</dt><dd>{{ selectedNode.platform ?? 'n/d' }}</dd></div>
                <div><dt>Tipo</dt><dd>{{ selectedNode.event_type_label ?? selectedNode.event_type_id ?? 'n/d' }}</dd></div>
                <div><dt>Método</dt><dd><code>{{ selectedNode.method_name ?? 'n/d' }}</code></dd></div>
                <div v-if="selectedNode.subscription_type"><dt>Suscripción</dt><dd><code>{{ selectedNode.subscription_type }}</code></dd></div>
                <div v-if="selectedNode.schedule_expression"><dt>Programación</dt><dd><code>{{ selectedNode.schedule_expression }}</code></dd></div>
                <div v-if="selectedNode.endpoint"><dt>Endpoint</dt><dd><code>{{ selectedNode.endpoint }}</code></dd></div>
                <div><dt>Siguiente evento</dt><dd>{{ selectedNode.to_event_id ? `#${selectedNode.to_event_id}` : 'Fin del flujo' }}</dd></div>
            </dl>
            <div v-if="canManage" class="inspector-actions">
                <Link class="primary-action" :href="`/admin/events/${selectedNode.id}/edit`">Editar evento</Link>
                <Link :href="`/admin/events/${selectedNode.id}/relationships`">Mapeos</Link>
                <Link :href="`/admin/events/${selectedNode.id}/triggers`">Disparadores</Link>
                <Link href="/admin/records">Registros</Link>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.workflow-layout{display:grid;grid-template-columns:minmax(0,1fr)270px;gap:14px}.workflow-canvas,.node-inspector{border:1px solid #dce5ea;border-radius:14px;background:#fff}.workflow-canvas{min-width:0;min-height:430px;padding:16px;background-color:#fbfcfd;background-image:radial-gradient(#d5dfe5 .8px,transparent .8px);background-size:18px 18px}.canvas-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:58px}.canvas-head>div:first-child{display:grid;gap:3px}.canvas-head span{color:#7a8994;font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.canvas-head strong{color:#263945;font-size:13px}.legend{display:flex;align-items:center;gap:6px;color:#788691;font-size:9px}.legend i,.workflow-node small i{display:inline-block;width:6px;height:6px;border-radius:50%;background:#17866a}.legend i.off,.workflow-node small i.off{background:#bd6565}.node-track{display:flex;align-items:center;overflow-x:auto;padding:16px 8px 30px;scrollbar-width:thin}.workflow-node{position:relative;display:grid;align-content:start;gap:8px;flex:1 0 160px;max-width:205px;min-height:150px;border:1px solid #d6e0e6;border-radius:11px;background:#fff;padding:15px 12px 12px;text-align:left;box-shadow:0 8px 20px rgba(25,42,53,.07);cursor:pointer;transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}.workflow-node:hover{transform:translateY(-2px);border-color:#8bb2cf}.workflow-node.selected{border:2px solid #2878b7;box-shadow:0 0 0 4px rgba(40,120,183,.11),0 10px 25px rgba(25,42,53,.1)}.workflow-node.inactive{opacity:.65}.node-order{position:absolute;top:-12px;left:12px;display:grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#192a35;color:#fff;font-size:9px;font-weight:800;box-shadow:0 3px 7px rgba(20,34,43,.2)}.node-platform{overflow:hidden;color:#3b759f;font-size:8px;font-weight:800;letter-spacing:.08em;text-overflow:ellipsis;text-transform:uppercase;white-space:nowrap}.platform-hubspot{color:#c55e2e}.platform-odoo{color:#9b466f}.platform-generic{color:#347865}.workflow-node strong{color:#1e303c;font-size:12px;line-height:1.3}.workflow-node code{overflow:hidden;border:1px solid #e0e7eb;border-radius:6px;background:#f4f7f8;color:#61717c;padding:6px;font-size:8px;text-overflow:ellipsis;white-space:nowrap}.workflow-node small{display:flex;align-items:center;gap:5px;color:#7c8992;font-size:9px}.connector{position:relative;flex:0 0 42px;height:2px;background:#4f93c5}.connector span{position:absolute;right:-3px;top:-10px;color:#4f93c5;font-size:16px}.node-inspector{align-self:start;padding:16px;box-shadow:0 10px 28px rgba(25,42,53,.06)}.inspector-head{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e1e8ec;padding-bottom:12px}.inspector-head>div{display:grid;gap:2px}.inspector-head span{color:#7a8994;font-size:8px;letter-spacing:.08em;text-transform:uppercase}.inspector-head strong{color:#20333f;font-size:13px}.status{border:1px solid rgba(23,134,106,.2);border-radius:999px;background:rgba(23,134,106,.08);color:#147158!important;padding:4px 7px;font-size:8px!important;font-weight:800}.status.inactive{border-color:rgba(189,101,101,.22);background:rgba(189,101,101,.08);color:#a04b4b!important}.inspector-name{margin:14px 0;color:#1a2c38;font-size:15px;font-weight:700;line-height:1.3}.node-inspector dl{margin:0}.node-inspector dl div{display:grid;gap:4px;border-bottom:1px solid #e6ecef;padding:10px 0}.node-inspector dt{color:#81909a;font-size:8px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.node-inspector dd{margin:0;color:#344650;font-size:10px;overflow-wrap:anywhere}.node-inspector dd code{font-size:9px}.inspector-actions{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:14px}.inspector-actions a{border:1px solid #d6e0e6;border-radius:7px;background:#f5f8f9;color:#344650;padding:7px;text-align:center;text-decoration:none;font-size:9px;font-weight:700}.inspector-actions a.primary-action{border-color:#2878b7;background:#2878b7;color:#fff}.empty-flow{display:grid;place-items:center;min-height:280px;color:#7b8993;font-size:12px}
@media(max-width:900px){.workflow-layout{grid-template-columns:1fr}.node-inspector{position:static}.canvas-head{margin-bottom:38px}}@media(max-width:600px){.workflow-canvas{min-height:auto;padding:12px}.node-track{align-items:stretch;flex-direction:column;overflow:visible;padding:16px 5px}.workflow-node{flex:auto;width:100%;max-width:none;min-height:130px}.connector{width:2px;height:28px;flex:0 0 28px;margin:0 auto}.connector span{right:-6px;top:10px;transform:rotate(90deg)}.inspector-actions{grid-template-columns:1fr}.legend{display:none}}
</style>
