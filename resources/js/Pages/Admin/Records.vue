<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import RecordTreeNode from '@/Components/RecordTreeNode.vue';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    records: { type: Object, required: true },
    filters: { type: Object, required: true },
    event_types: { type: Array, default: () => [] },
    status_options: { type: Array, default: () => [] },
    cleanup_stats: { type: Object, default: () => ({}) },
    can_manage_records: { type: Boolean, default: false },
});

const form = reactive({
    status: props.filters.status ?? '',
    event_type: props.filters.event_type ?? '',
});

const cleanupForm = reactive({
    mode: 'older_than',
    older_than_days: 30,
    status: props.filters.status ?? '',
    event_type: props.filters.event_type ?? '',
    keep_warnings_errors: true,
});

const applyFilters = () => {
    router.get('/admin/records', {
        status: form.status || undefined,
        event_type: form.event_type || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    form.status = '';
    form.event_type = '';
    applyFilters();
};

const submitCleanup = () => {
    if (cleanupForm.mode === 'filtered' && !cleanupForm.status && !cleanupForm.event_type) {
        window.alert('Selecciona al menos un filtro para ejecutar una limpieza filtrada.');
        return;
    }

    const messages = {
        all: 'Se eliminarán todos los registros disponibles.',
        filtered: 'Se eliminarán los registros que coincidan con los filtros seleccionados.',
        older_than: `Se eliminarán los registros con más de ${cleanupForm.older_than_days || 30} días.`,
    };

    const keepMessage = cleanupForm.keep_warnings_errors
        ? '\nSe conservarán los registros con advertencia o error.'
        : '';

    if (!window.confirm(`${messages[cleanupForm.mode]}${keepMessage}\n\nEsta acción no se puede deshacer. ¿Continuar?`)) {
        return;
    }

    router.post('/admin/records/cleanup', {
        mode: cleanupForm.mode,
        older_than_days: cleanupForm.mode === 'older_than' ? Number(cleanupForm.older_than_days || 30) : null,
        status: cleanupForm.mode === 'filtered' ? (cleanupForm.status || null) : null,
        event_type: cleanupForm.mode === 'filtered' ? (cleanupForm.event_type || null) : null,
        keep_warnings_errors: cleanupForm.keep_warnings_errors,
    }, {
        preserveScroll: true,
    });
};

const prettyJson = (value) => {
    if (value === null || value === undefined) {
        return 'No hay detalles disponibles';
    }

    if (typeof value === 'object' && Object.keys(value).length === 0) {
        return 'No hay detalles disponibles';
    }

    return JSON.stringify(localizeRecordValue(value), null, 2);
};

const hasObjectValue = (value) => value && typeof value === 'object' && !Array.isArray(value);

const joinList = (value) => {
    if (!Array.isArray(value) || value.length === 0) {
        return 'n/d';
    }

    return value.join(', ');
};

const statusLabels = {
    init: 'Inicial',
    processing: 'Procesando',
    success: 'Correcto',
    warning: 'Advertencia',
    error: 'Error',
};

const messageTranslations = {
    'Webhook not received, platform not found.': 'Webhook no recibido: no se encontró la plataforma.',
    'Webhook not received, missing secret key or signature configuration.': 'Webhook no recibido: falta la llave secreta o la configuración de firma.',
    'Webhook not received, invalid signature.': 'Webhook no recibido: firma inválida.',
    'Webhook received': 'Webhook recibido.',
    'No events found for subscription type': 'No se encontraron eventos para el tipo de suscripción.',
    'Service class not found for event processing.': 'No se encontró la clase de servicio para procesar el evento.',
    'Event method not available for execution.': 'El método del evento no está disponible para ejecución.',
    'Event processed with warnings.': 'Evento procesado con advertencias.',
    'Event processed.': 'Evento procesado.',
    'Event processing failed.': 'Falló el procesamiento del evento.',
    'Odoo account.move ignored because it is not an outgoing invoice.': 'Movimiento account.move de Odoo ignorado porque no es una factura de cliente.',
    'Odoo invoice payload prepared.': 'Payload de factura Odoo preparado.',
    'Odoo invoice payload prepared with warnings.': 'Payload de factura Odoo preparado con advertencias.',
    'Failed to create or update HubSpot invoice object.': 'No se pudo crear o actualizar el objeto de factura en HubSpot.',
    'HubSpot invoice object synchronized.': 'Objeto de factura sincronizado en HubSpot.',
    'HubSpot invoice object synchronized with warnings.': 'Objeto de factura sincronizado en HubSpot con advertencias.',
    'Multiple HubSpot invoice objects matched Odoo invoice id.': 'Más de un objeto de factura HubSpot coincide con el ID de factura Odoo.',
    'Missing Odoo account.move id.': 'Falta el ID account.move de Odoo.',
    'Odoo account.move was not found.': 'No se encontró el account.move en Odoo.',
    'Write-back completed.': 'Write-back completado.',
    'Write-back failed.': 'Falló el write-back.',
    'Write-back skipped because object id or properties are missing.': 'Write-back omitido porque falta el ID del objeto o las propiedades.',
};

const translateMessage = (message) => {
    if (!message) {
        return 'Sin mensaje';
    }

    return messageTranslations[message] ?? message;
};

const statusLabel = (status) => statusLabels[status] ?? status;

const localizeRecordValue = (value) => {
    if (typeof value === 'string') {
        return translateMessage(value);
    }

    if (Array.isArray(value)) {
        return value.map((item) => localizeRecordValue(item));
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([key, item]) => [
            key,
            localizeRecordValue(item),
        ]));
    }

    return value;
};
</script>

<template>
    <AdminLayout title="Registros">
        <form class="filters" @submit.prevent="applyFilters">
            <select v-model="form.status">
                <option value="">Todos los estados</option>
                <option v-for="status in props.status_options" :key="status" :value="status">{{ statusLabel(status) }}</option>
            </select>
            <select v-model="form.event_type">
                <option value="">Todos los tipos</option>
                <option v-for="eventType in props.event_types" :key="eventType" :value="eventType">{{ eventType }}</option>
            </select>
            <button type="submit">Filtrar</button>
            <button type="button" class="secondary" @click="resetFilters">Limpiar</button>
        </form>

        <div class="summary">
            <p>Flujos: {{ props.records.total }}</p>
            <p>Página: {{ props.records.current_page }} / {{ props.records.last_page }}</p>
        </div>

        <details v-if="props.can_manage_records" class="cleanup-panel">
            <summary>Gestionar limpieza de registros</summary>
            <div class="cleanup-body">
                <div class="cleanup-grid">
                    <div class="cleanup-stat">
                        <span>Total en BD</span>
                        <strong>{{ props.cleanup_stats.total ?? 0 }}</strong>
                    </div>
                    <div class="cleanup-stat">
                        <span>Más de 7 días</span>
                        <strong>{{ props.cleanup_stats.older_than_7_days ?? 0 }}</strong>
                    </div>
                    <div class="cleanup-stat">
                        <span>Más de 30 días</span>
                        <strong>{{ props.cleanup_stats.older_than_30_days ?? 0 }}</strong>
                    </div>
                    <div class="cleanup-stat">
                        <span>Más de 90 días</span>
                        <strong>{{ props.cleanup_stats.older_than_90_days ?? 0 }}</strong>
                    </div>
                </div>

                <form class="cleanup-controls" @submit.prevent="submitCleanup">
                    <label>
                        Modo de limpieza
                        <select v-model="cleanupForm.mode">
                            <option value="older_than">Borrar por antigüedad</option>
                            <option value="filtered">Borrar con filtros</option>
                            <option value="all">Borrar todo</option>
                        </select>
                    </label>

                    <label v-if="cleanupForm.mode === 'older_than'">
                        Días a conservar
                        <input v-model.number="cleanupForm.older_than_days" type="number" min="1" max="3650">
                    </label>

                    <template v-if="cleanupForm.mode === 'filtered'">
                        <label>
                            Estado
                            <select v-model="cleanupForm.status">
                                <option value="">Todos los estados</option>
                                <option v-for="status in props.status_options" :key="status" :value="status">
                                    {{ statusLabel(status) }}
                                </option>
                            </select>
                        </label>
                        <label>
                            Tipo de evento
                            <select v-model="cleanupForm.event_type">
                                <option value="">Todos los tipos</option>
                                <option v-for="eventType in props.event_types" :key="eventType" :value="eventType">{{ eventType }}</option>
                            </select>
                        </label>
                    </template>

                    <label class="check">
                        <input v-model="cleanupForm.keep_warnings_errors" type="checkbox">
                        Conservar advertencias y errores
                    </label>

                    <div class="cleanup-actions">
                        <button type="submit" class="danger-button">Ejecutar limpieza</button>
                        <p>La limpieza elimina registros de trazabilidad y libera espacio en la base de datos.</p>
                    </div>
                </form>
            </div>
        </details>

        <div class="list">
            <details v-for="record in props.records.data" :key="record.id" class="item root-record">
                <summary class="record-summary">
                    <div class="summary-main">
                        <h3>#{{ record.id }} · {{ record.event_type }}</h3>
                        <p>{{ translateMessage(record.message) }}</p>
                    </div>
                    <div class="summary-side">
                        <span class="children-badge">{{ record.descendants_count }} pasos</span>
                        <span :class="['status', record.status]">{{ statusLabel(record.status) }}</span>
                    </div>
                </summary>

                <div class="record-body">
                    <div class="meta">
                        <span>evento_id: {{ record.event_id ?? 'n/a' }}</span>
                        <span>registro_padre: {{ record.record_id ?? 'n/a' }}</span>
                        <span>creado: {{ record.created_at ?? 'n/a' }}</span>
                    </div>

                    <details class="record-detail">
                        <summary>Payload de entrada</summary>
                        <pre>{{ prettyJson(record.payload) }}</pre>
                    </details>

                    <details v-if="hasObjectValue(record.details) && record.details.output_payload" class="record-detail">
                        <summary>Payload de salida</summary>
                        <pre>{{ prettyJson(record.details.output_payload) }}</pre>
                    </details>

                    <details v-if="hasObjectValue(record.details) && record.details.hubspot_enrichment" class="record-detail">
                        <summary>Enriquecimiento HubSpot</summary>
                        <div class="enrichment-meta">
                            <p>
                                Propiedades mapeadas solicitadas:
                                <strong>{{ joinList(record.details.hubspot_enrichment.requested_properties) }}</strong>
                            </p>
                            <p>
                                Propiedades obtenidas:
                                <strong>{{ joinList(record.details.hubspot_enrichment.fetched_properties) }}</strong>
                            </p>
                        </div>
                        <pre>{{ prettyJson(record.details.hubspot_enrichment) }}</pre>
                    </details>

                    <details class="record-detail">
                        <summary>Detalles</summary>
                        <pre>{{ prettyJson(record.details) }}</pre>
                    </details>

                    <section class="children-section">
                        <div class="children-title">
                            <h4>Secuencia del flujo</h4>
                            <span>{{ record.descendants_count ?? 0 }} pasos posteriores</span>
                        </div>

                        <p v-if="!record.children?.length" class="empty-children">Este flujo no tiene eventos posteriores registrados.</p>

                        <RecordTreeNode
                            v-for="child in record.children"
                            :key="child.id"
                            :record="child"
                            :level="1"
                            :status-label="statusLabel"
                            :translate-message="translateMessage"
                            :pretty-json="prettyJson"
                            :has-object-value="hasObjectValue"
                            :join-list="joinList"
                        />
                    </section>
                </div>
            </details>
        </div>
        <PaginationNav :links="props.records.links ?? []" />
    </AdminLayout>
</template>

<style scoped>
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.filters select,.filters button{height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;background:#fff}
.filters button{cursor:pointer;background:#1d4ed8;color:#fff;border:0}
.filters .secondary{border:1px solid #cbd5e1;background:#f8fafc;color:#334155}
.summary{display:flex;gap:18px;margin:8px 0;color:#475569;font-size:13px}
.cleanup-panel{border:1px solid #dbe4ef;border-radius:12px;background:#fff;margin:10px 0 14px;padding:10px 12px}
.cleanup-panel>summary{font-weight:700;color:#1f2937}
.cleanup-body{display:grid;gap:12px;margin-top:12px}
.cleanup-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px}
.cleanup-stat{border:1px solid #e2e8f0;border-radius:8px;padding:10px;background:#f8fafc;display:grid;gap:4px}
.cleanup-stat span{font-size:12px;color:#64748b}
.cleanup-stat strong{font-size:18px;color:#0f172a}
.cleanup-controls{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.cleanup-controls label{display:grid;gap:4px;font-size:12px;color:#475569}
.cleanup-controls select,.cleanup-controls input{height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;background:#fff;color:#0f172a}
.cleanup-controls .check{display:flex;align-items:center;gap:8px;height:38px}
.cleanup-controls .check input{height:auto}
.cleanup-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.danger-button{height:38px;border:0;border-radius:8px;padding:0 12px;background:#b91c1c;color:#fff;font-weight:700;cursor:pointer}
.cleanup-actions p{margin:0;color:#64748b;font-size:12px;max-width:420px}
.list{display:grid;gap:10px}
.item{border:1px solid #dbe4ef;border-radius:12px;background:#fff;overflow:hidden}
.root-record{margin-top:0}
.record-summary,.child-summary{display:flex;justify-content:space-between;gap:12px;cursor:pointer;list-style:none}
.record-summary::-webkit-details-marker,.child-summary::-webkit-details-marker{display:none}
.record-summary{padding:12px}
.summary-main{min-width:0}
.summary-side{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.record-body{padding:0 12px 12px}
h3{margin:0 0 4px;font-size:15px;color:#1f2937}
.record-summary p,.child-summary p{margin:0;color:#475569;font-size:13px}
.status{border-radius:999px;padding:4px 8px;font-size:12px;font-weight:600;height:max-content}
.status.init{background:#e2e8f0;color:#334155}
.status.processing{background:#dbeafe;color:#1d4ed8}
.status.success{background:#dcfce7;color:#166534}
.status.warning{background:#fef3c7;color:#78350f}
.status.error{background:#fee2e2;color:#991b1b}
.children-badge{border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700;padding:4px 8px}
.meta{display:flex;flex-wrap:wrap;gap:8px 12px;margin:8px 0;color:#475569;font-size:12px}
details{margin-top:6px}
summary{cursor:pointer;color:#334155;font-size:13px}
.record-detail{border-top:1px solid #eef2f7;padding-top:6px}
.children-section{border-top:1px solid #e2e8f0;margin-top:12px;padding-top:12px;display:grid;gap:8px}
.children-title{display:flex;align-items:center;justify-content:space-between;gap:10px}
.children-title h4{margin:0;font-size:13px;color:#1f2937}
.children-title span,.empty-children{font-size:12px;color:#64748b}
.empty-children{margin:0}
pre{margin:6px 0 0;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;font-size:12px;white-space:pre-wrap}
.enrichment-meta{margin-top:6px;display:grid;gap:4px}
.enrichment-meta p{margin:0;color:#475569;font-size:12px}
.enrichment-meta strong{color:#0f172a}
</style>
