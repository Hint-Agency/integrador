<script setup>
import FlowGraph from '@/Components/FlowGraph.vue';

defineProps({
    event: {
        type: Object,
        required: true,
    },
    flow: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <div class="mx-auto max-w-6xl space-y-8 px-6 py-10">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="text-sm text-slate-500">Detalle del evento</div>
                        <h1 class="mt-1 text-2xl font-semibold text-slate-900">
                            {{ event.name }}
                        </h1>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                            <span class="rounded-full bg-slate-100 px-3 py-1">ID: {{ event.id }}</span>
                            <span class="rounded-full bg-slate-100 px-3 py-1">
                                Plataforma: {{ event.platform_type ?? 'n/d' }}
                            </span>
                            <span class="rounded-full bg-slate-100 px-3 py-1">
                                Tipo: {{ event.type ?? 'n/d' }}
                            </span>
                            <span class="rounded-full bg-slate-100 px-3 py-1">
                                Tipo de evento: {{ event.event_type_label ?? event.event_type_id ?? 'n/d' }}
                            </span>
                        </div>
                    </div>
                    <div
                        class="rounded-full px-4 py-2 text-xs font-semibold"
                        :class="event.active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
                    >
                        {{ event.active ? 'Activo' : 'Inactivo' }}
                    </div>
                </div>

                <div v-if="event.to_event_id" class="mt-4 text-xs text-slate-500">
                    ID del siguiente evento: {{ event.to_event_id }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <FlowGraph :nodes="flow.nodes" :chain="flow.chain" />
            </div>
        </div>
    </div>
</template>
