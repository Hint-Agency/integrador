<script setup>
defineProps({
    node: {
        type: Object,
        required: true,
    },
    highlight: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <div
        :class="[
            'rounded-lg border px-4 py-3 shadow-sm transition',
            highlight ? 'border-blue-500 bg-blue-50' : 'border-slate-200 bg-white',
        ]"
    >
        <div class="text-sm font-semibold text-slate-900">
            {{ node.name }}
        </div>
        <div class="mt-1 text-xs text-slate-500">
            Plataforma: {{ node.platform_type ?? 'n/d' }}
        </div>
        <div class="text-xs text-slate-500">
            Tipo: {{ node.type ?? 'n/d' }}
        </div>
        <div
            class="mt-2 text-xs font-semibold"
            :class="node.active ? 'text-emerald-600' : 'text-rose-600'"
        >
            {{ node.active ? 'Activo' : 'Inactivo' }}
        </div>
    </div>
</template>
