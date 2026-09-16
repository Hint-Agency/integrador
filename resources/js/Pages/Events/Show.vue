<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FlowGraph from '@/Components/FlowGraph.vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    event: { type: Object, required: true },
    flow: { type: Object, required: true },
    can_manage_events: { type: Boolean, default: false },
});
</script>

<template>
    <AdminLayout :title="event.name">
        <div class="workflow-page-head">
            <div>
                <Link class="back-link" href="/admin/events">← Volver a flujos</Link>
                <p class="eyebrow">Workflow #{{ flow.root_id }}</p>
                <h2>{{ event.name }}</h2>
                <div class="event-context">
                    <span>{{ event.platform ?? 'Sin plataforma' }}</span>
                    <span>{{ event.event_type_label ?? event.event_type_id ?? 'Sin tipo' }}</span>
                    <span v-if="event.schedule_expression">{{ event.schedule_expression }}</span>
                </div>
            </div>
            <span :class="['root-status', { inactive: !event.active }]">{{ event.active ? 'Flujo activo' : 'Flujo inactivo' }}</span>
        </div>

        <FlowGraph :nodes="flow.nodes" :chain="flow.chain" :can-manage="can_manage_events" />
    </AdminLayout>
</template>

<style scoped>
.workflow-page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:16px;padding:2px}.back-link{display:inline-block;margin-bottom:14px;color:#39759f;font-size:11px;font-weight:700;text-decoration:none}.eyebrow{margin:0 0 3px;color:#2474ad;font-size:9px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.workflow-page-head h2{margin:0;color:#172a36;font-family:'Barlow Condensed',sans-serif;font-size:29px;letter-spacing:.02em}.event-context{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}.event-context span{border:1px solid #dae3e8;border-radius:999px;background:#f5f8f9;color:#62727d;padding:4px 8px;font-size:9px}.root-status{border:1px solid rgba(23,134,106,.2);border-radius:999px;background:rgba(23,134,106,.09);color:#137159;padding:6px 9px;font-size:9px;font-weight:800;text-transform:uppercase}.root-status.inactive{border-color:rgba(192,86,86,.2);background:rgba(192,86,86,.08);color:#a44949}@media(max-width:600px){.workflow-page-head{flex-direction:column}.root-status{align-self:flex-start}}
</style>
