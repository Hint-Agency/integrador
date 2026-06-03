<script setup>
defineOptions({ name: 'RecordTreeNode' });

const props = defineProps({
    record: { type: Object, required: true },
    level: { type: Number, default: 1 },
    statusLabel: { type: Function, required: true },
    translateMessage: { type: Function, required: true },
    prettyJson: { type: Function, required: true },
    hasObjectValue: { type: Function, required: true },
    joinList: { type: Function, required: true },
});
</script>

<template>
    <details class="tree-record" :style="{ '--level': props.level }">
        <summary class="tree-summary">
            <div class="summary-main">
                <h5>#{{ props.record.id }} · {{ props.record.event_type }}</h5>
                <p>{{ props.translateMessage(props.record.message) }}</p>
            </div>
            <div class="summary-side">
                <span v-if="props.record.descendants_count > 0" class="children-badge">
                    {{ props.record.descendants_count }} pasos
                </span>
                <span :class="['status', props.record.status]">{{ props.statusLabel(props.record.status) }}</span>
            </div>
        </summary>

        <div class="tree-body">
            <div class="meta">
                <span>evento_id: {{ props.record.event_id ?? 'n/a' }}</span>
                <span>registro_padre: {{ props.record.record_id ?? 'n/a' }}</span>
                <span>creado: {{ props.record.created_at ?? 'n/a' }}</span>
            </div>

            <details class="record-detail">
                <summary>Payload de entrada</summary>
                <pre>{{ props.prettyJson(props.record.payload) }}</pre>
            </details>

            <details v-if="props.hasObjectValue(props.record.details) && props.record.details.output_payload" class="record-detail">
                <summary>Payload de salida</summary>
                <pre>{{ props.prettyJson(props.record.details.output_payload) }}</pre>
            </details>

            <details v-if="props.hasObjectValue(props.record.details) && props.record.details.hubspot_enrichment" class="record-detail">
                <summary>Enriquecimiento HubSpot</summary>
                <div class="enrichment-meta">
                    <p>
                        Propiedades mapeadas solicitadas:
                        <strong>{{ props.joinList(props.record.details.hubspot_enrichment.requested_properties) }}</strong>
                    </p>
                    <p>
                        Propiedades obtenidas:
                        <strong>{{ props.joinList(props.record.details.hubspot_enrichment.fetched_properties) }}</strong>
                    </p>
                </div>
                <pre>{{ props.prettyJson(props.record.details.hubspot_enrichment) }}</pre>
            </details>

            <details class="record-detail">
                <summary>Detalles</summary>
                <pre>{{ props.prettyJson(props.record.details) }}</pre>
            </details>

            <div v-if="props.record.children?.length" class="nested-records">
                <RecordTreeNode
                    v-for="child in props.record.children"
                    :key="child.id"
                    :record="child"
                    :level="props.level + 1"
                    :status-label="props.statusLabel"
                    :translate-message="props.translateMessage"
                    :pretty-json="props.prettyJson"
                    :has-object-value="props.hasObjectValue"
                    :join-list="props.joinList"
                />
            </div>
        </div>
    </details>
</template>

<style scoped>
.tree-record{border:1px solid #e2e8f0;border-radius:8px;background:#fff;margin-top:8px}
.tree-summary{display:flex;justify-content:space-between;gap:12px;cursor:pointer;list-style:none;padding:10px;background:#f8fafc}
.tree-summary::-webkit-details-marker{display:none}
.tree-body{padding:0 10px 10px;border-left:3px solid #e0e7ff;margin-left:10px}
.summary-main{min-width:0}
.summary-side{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
h5{margin:0 0 4px;font-size:13px;color:#1f2937}
.tree-summary p{margin:0;color:#475569;font-size:12px}
.status{border-radius:999px;padding:4px 8px;font-size:12px;font-weight:600;height:max-content}
.status.init{background:#e2e8f0;color:#334155}
.status.processing{background:#dbeafe;color:#1d4ed8}
.status.success{background:#dcfce7;color:#166534}
.status.warning{background:#fef3c7;color:#78350f}
.status.error{background:#fee2e2;color:#991b1b}
.children-badge{border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700;padding:4px 8px}
.meta{display:flex;flex-wrap:wrap;gap:8px 12px;margin:8px 0;color:#475569;font-size:12px}
summary{cursor:pointer;color:#334155;font-size:13px}
.record-detail{border-top:1px solid #eef2f7;padding-top:6px;margin-top:6px}
pre{margin:6px 0 0;background:#0f172a;color:#e2e8f0;padding:10px;border-radius:8px;font-size:12px;white-space:pre-wrap}
.enrichment-meta{margin-top:6px;display:grid;gap:4px}
.enrichment-meta p{margin:0;color:#475569;font-size:12px}
.enrichment-meta strong{color:#0f172a}
.nested-records{margin-top:10px;display:grid;gap:8px}
</style>
