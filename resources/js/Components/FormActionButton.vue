<script setup>
defineProps({
    icon: {
        type: String,
        default: 'add',
    },
    label: {
        type: String,
        required: true,
    },
    variant: {
        type: String,
        default: 'neutral',
    },
});

defineEmits(['click']);

const paths = {
    add: 'M12 5v14M5 12h14',
    delete: 'M5 7h14M10 11v6M14 11v6M7 7l1 13h8l1-13M9 7l1-3h4l1 3',
};
</script>

<template>
    <button
        type="button"
        :class="['form-action-button', `form-action-button--${variant}`]"
        :aria-label="label"
        @click="$emit('click', $event)"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path :d="paths[icon] ?? paths.add" />
        </svg>
        <span>{{ label }}</span>
    </button>
</template>

<style scoped>
.form-action-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 38px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    background: #fff;
    color: #334155;
    cursor: pointer;
    font: inherit;
    white-space: nowrap;
}

.form-action-button:hover {
    border-color: #94a3b8;
    background: #f8fafc;
}

.form-action-button:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
}

.form-action-button svg {
    width: 17px;
    height: 17px;
    flex: 0 0 auto;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.form-action-button--danger {
    border-color: #fecaca;
    color: #b91c1c;
}

.form-action-button--danger:hover {
    border-color: #fca5a5;
    background: #fff1f2;
}
</style>
