<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
    flows: { type: Array, required: true },
});

const destroyFlow = (flow) => {
    if (confirm(`Eliminar el flujo ${flow.name}?`)) {
        router.delete(`/admin/clients/${props.client.id}/flows/${flow.id}`);
    }
};
</script>

<template>
    <AdminLayout title="Flujos de automatización">
        <ClientTabs :client="client" />

        <div class="page-head">
            <div>
                <h2>Flujos</h2>
                <p>HubSpot ejecuta los pasos en orden. Un error de asignación detiene el envío a Treble.</p>
            </div>
            <Link class="primary" :href="`/admin/clients/${client.id}/flows/create`">Nuevo flujo</Link>
        </div>

        <div v-if="flows.length" class="flow-list">
            <article v-for="flow in flows" :key="flow.id" class="flow-item">
                <header class="flow-head">
                    <div>
                        <div class="title-line">
                            <strong>{{ flow.name }}</strong>
                            <span :class="['state', { inactive: !flow.active }]">{{ flow.active ? 'Activo' : 'Inactivo' }}</span>
                        </div>
                        <p>Cuando <code>{{ flow.trigger_property }}</code> = <code>{{ flow.trigger_value || '(vacío)' }}</code></p>
                        <small>Prioridad {{ flow.priority }} · {{ flow.condition_count }} condición(es)</small>
                    </div>
                    <div class="actions">
                        <Link :href="`/admin/clients/${client.id}/flows/${flow.id}/edit`">Configurar</Link>
                        <button type="button" @click="destroyFlow(flow)">Eliminar</button>
                    </div>
                </header>

                <div class="sequence">
                    <div :class="['step', { disabled: !flow.owner_assignment_enabled }]">
                        <span class="step-number">1</span>
                        <div>
                            <strong>{{ flow.owner_assignment_enabled ? 'Asignar propietario' : 'Asignación de propietario omitida' }}</strong>
                            <template v-if="flow.owner_assignment_enabled">
                                <p>{{ flow.owners.length }} asesor(es) · selección aleatoria cuando {{ flow.owner_property }} está vacío</p>
                                <p>Si ya tiene propietario: {{ flow.existing_owner_behavior === 'continue' ? 'conservar y continuar' : 'conservar y detener' }}</p>
                            </template>
                            <p v-else>El flujo continúa sin consultar ni modificar el propietario.</p>
                        </div>
                        <span class="required">{{ flow.owner_assignment_enabled ? 'Habilitado' : 'Omitido' }}</span>
                    </div>

                    <div class="connector"><span>{{ flow.owner_assignment_enabled ? 'Si termina correctamente' : 'Continúa directamente' }}</span></div>

                    <div :class="['step', { disabled: !flow.continue_to_treble }]">
                        <span class="step-number">2</span>
                        <div>
                            <strong>{{ flow.continue_to_treble ? 'Resolver regla y enviar por Treble' : 'Treble desactivado' }}</strong>
                            <p v-if="flow.continue_to_treble">{{ flow.message_rules_count }} regla(s) compiten por prioridad.</p>
                            <p v-else>El flujo finaliza después de los pasos habilitados.</p>
                        </div>
                        <span>{{ flow.continue_to_treble ? 'Condicional' : 'Omitido' }}</span>
                    </div>
                </div>

                <div v-if="flow.owner_assignment_enabled" class="failure"><strong>Si falla el paso 1:</strong> detener, registrar error y crear nota en HubSpot. No enviar a Treble.</div>
            </article>
        </div>

        <div v-else class="empty">
            <strong>Todavía no hay flujos configurados.</strong>
            <p>Primero registra los propietarios y después crea la secuencia de asignación y mensajería.</p>
        </div>
    </AdminLayout>
</template>

<style scoped>
.page-head, .flow-head, .title-line, .actions { display: flex; align-items: center; gap: 12px; }
.page-head, .flow-head { justify-content: space-between; }
.page-head { margin-bottom: 18px; }
.page-head h2, .page-head p, .flow-head p, .step p, .empty p { margin: 0; }
.page-head p, .flow-head p, .flow-head small, .step p, .empty p { color: #64748b; }
.page-head p { margin-top: 4px; }
.flow-list { display: grid; gap: 14px; }
.flow-item { display: grid; gap: 14px; border: 1px solid #dbe2e8; border-radius: 8px; padding: 16px; }
.flow-head p { margin-top: 5px; font-size: 14px; }
.flow-head small { display: block; margin-top: 4px; }
.state, .required { border-radius: 999px; padding: 4px 8px; background: #dcfce7; color: #166534; font-size: 12px; }
.state.inactive { background: #f1f5f9; color: #64748b; }
.sequence { display: grid; gap: 7px; }
.step { display: grid; grid-template-columns: 34px minmax(0, 1fr) auto; gap: 12px; align-items: center; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; }
.step.disabled { background: #f8fafc; color: #64748b; }
.step-number { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 7px; background: #2563eb; color: #fff; font-weight: 700; }
.step p { margin-top: 3px; font-size: 13px; }
.required { background: #eff6ff; color: #1d4ed8; }
.connector { margin-left: 16px; padding: 5px 0 5px 31px; border-left: 2px solid #86efac; color: #15803d; font-size: 12px; }
.failure { border-left: 4px solid #dc2626; background: #fef2f2; padding: 10px 12px; color: #7f1d1d; font-size: 13px; }
.actions a, .actions button, .primary { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; background: #fff; color: #334155; text-decoration: none; cursor: pointer; }
.primary { background: #2563eb; border-color: #2563eb; color: #fff; }
.empty { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 24px; text-align: center; }
.empty p { margin-top: 5px; }
code { color: #1d4ed8; }
@media (max-width: 760px) {
    .page-head, .flow-head { align-items: stretch; flex-direction: column; }
    .actions { justify-content: flex-start; }
    .step { grid-template-columns: 34px minmax(0, 1fr); }
    .step > span:last-child { grid-column: 2; justify-self: start; }
}
</style>
