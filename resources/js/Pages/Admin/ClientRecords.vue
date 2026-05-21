<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    client: { type: Object, required: true },
    records: { type: Object, required: true },
});
</script>

<template>
    <AdminLayout title="Records del cliente">
        <ClientTabs :client="client" />
        <div class="stack">
            <article v-for="record in records.data" :key="record.id" class="item">
                <header>
                    <strong>#{{ record.id }} · {{ record.event_type }}</strong>
                    <span :class="record.status">{{ record.status }}</span>
                </header>
                <p>{{ record.message }}</p>
                <div v-if="record.details?.treble_status" class="status-card">
                    <strong>Estado Treble</strong>
                    <div class="status-grid">
                        <span><b>Actual:</b> {{ record.details.treble_status.current || 'n/a' }}</span>
                        <span><b>External ID:</b> {{ record.details.treble_status.external_id || 'n/a' }}</span>
                        <span><b>Cerrado:</b> {{ record.details.treble_status.closed_at || 'n/a' }}</span>
                        <span><b>Callbacks:</b> {{ record.details.treble_status.history?.length || 0 }}</span>
                    </div>
                </div>
                <pre>{{ JSON.stringify(record.details, null, 2) }}</pre>
            </article>
        </div>
    </AdminLayout>
</template>

<style scoped>
.stack { display: grid; gap: 12px; }
.item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
header { display: flex; justify-content: space-between; gap: 12px; }
p { color: #475569; }
.status-card { display: grid; gap: 8px; border: 1px solid #dbeafe; border-radius: 8px; background: #f8fbff; padding: 12px; margin-bottom: 12px; }
.status-grid { display: grid; gap: 6px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); color: #334155; font-size: 13px; }
pre { margin: 0; padding: 12px; border-radius: 8px; background: #0f172a; color: #e2e8f0; overflow: auto; font-size: 12px; }
.success { color: #047857; }
.error { color: #b91c1c; }
.warning { color: #b45309; }
.processing { color: #2563eb; }
</style>
