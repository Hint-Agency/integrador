<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    records: { type: Object, required: true },
});
</script>

<template>
    <AdminLayout title="Records">
        <div class="stack">
            <article v-for="record in records.data" :key="record.id" class="item">
                <header>
                    <strong>#{{ record.id }} · {{ record.event_type }}</strong>
                    <span>{{ record.client?.name || 'Sin cliente' }}</span>
                    <span :class="record.status">{{ record.status }}</span>
                </header>
                <p>{{ record.message }}</p>
                <small v-if="record.details?.treble_status">
                    Treble: {{ record.details.treble_status.current || 'n/a' }}
                    · external_id {{ record.details.treble_status.external_id || 'n/a' }}
                    · callbacks {{ record.details.treble_status.history?.length || 0 }}
                </small>
            </article>
        </div>
    </AdminLayout>
</template>

<style scoped>
.stack { display: grid; gap: 12px; }
.item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; }
header { display: flex; gap: 12px; flex-wrap: wrap; }
p { color: #475569; margin-bottom: 0; }
small { color: #64748b; display: block; margin-top: 6px; }
.success { color: #047857; }
.error { color: #b91c1c; }
.warning { color: #b45309; }
.processing { color: #2563eb; }
</style>
