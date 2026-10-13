<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

defineProps({
    label: {
        type: String,
        default: 'Mostrar ayuda',
    },
});

const open = ref(false);
const root = ref(null);

const toggle = () => {
    open.value = !open.value;
};

const closeFromDocument = (event) => {
    if (event.type === 'keydown' && event.key !== 'Escape') {
        return;
    }

    if (event.type === 'click' && root.value?.contains(event.target)) {
        return;
    }

    open.value = false;
};

onMounted(() => {
    document.addEventListener('click', closeFromDocument);
    document.addEventListener('keydown', closeFromDocument);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', closeFromDocument);
    document.removeEventListener('keydown', closeFromDocument);
});
</script>

<template>
    <span ref="root" class="help-tooltip" :class="{ open }">
        <button
            type="button"
            class="help-trigger"
            :aria-label="label"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="9" />
                <path d="M9.8 9a2.35 2.35 0 1 1 3.25 2.17c-.67.31-1.05.75-1.05 1.58v.25M12 17h.01" />
            </svg>
        </button>
        <span class="help-content" role="tooltip">
            <slot />
        </span>
    </span>
</template>

<style scoped>
.help-tooltip{
    position:relative;
    display:inline-flex;
    align-items:center;
}

.help-trigger{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:24px;
    height:24px;
    padding:0;
    border:0;
    border-radius:999px;
    background:transparent;
    color:#64748b;
    cursor:pointer;
    transition:background .16s ease, color .16s ease;
}

.help-trigger:hover,
.help-trigger:focus-visible,
.open .help-trigger{
    background:#eff6ff;
    color:#2563eb;
}

.help-trigger svg{
    width:17px;
    height:17px;
    fill:none;
    stroke:currentColor;
    stroke-width:1.9;
    stroke-linecap:round;
    stroke-linejoin:round;
}

.help-content{
    position:absolute;
    z-index:40;
    right:0;
    top:calc(100% + 8px);
    width:min(320px, calc(100vw - 56px));
    padding:10px 12px;
    border-radius:10px;
    background:#0f172a;
    color:#fff;
    font-size:12px;
    font-weight:400;
    line-height:1.45;
    text-align:left;
    opacity:0;
    visibility:hidden;
    pointer-events:none;
    transform:translateY(4px);
    transition:opacity .14s ease, transform .14s ease, visibility .14s ease;
    box-shadow:0 14px 28px rgba(15, 23, 42, .24);
}

.help-content::after{
    content:'';
    position:absolute;
    bottom:100%;
    right:7px;
    border:6px solid transparent;
    border-bottom-color:#0f172a;
}

.help-tooltip:hover .help-content,
.help-tooltip:focus-within .help-content,
.help-tooltip.open .help-content{
    opacity:1;
    visibility:visible;
    pointer-events:auto;
    transform:translateY(0);
}

.help-content :deep(p){
    margin:0;
    color:#fff !important;
}

.help-content :deep(*){
    color:inherit !important;
}

.help-content :deep(p + p){
    margin-top:7px;
}
</style>
