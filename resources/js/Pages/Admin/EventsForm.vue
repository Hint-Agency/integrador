<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LightboxFormModal from '@/Components/LightboxFormModal.vue';
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    event: { type: Object, default: null },
    platforms: { type: Array, default: () => [] },
    event_options: { type: Array, default: () => [] },
    event_type_groups: { type: Array, default: () => [] },
    platform_method_options: { type: Object, default: () => ({}) },
});

const isEdit = computed(() => props.mode === 'edit');

const formatJsonText = (value, fallback = '') => {
    if (value === null || value === undefined) {
        return fallback;
    }

    if (Array.isArray(value) && value.length === 0) {
        return fallback;
    }

    if (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0) {
        return fallback;
    }

    if (typeof value === 'object') {
        return JSON.stringify(value, null, 2);
    }

    return String(value);
};

const defaultHttpFields = () => ({
    http_method: 'POST',
    http_base_url: '',
    http_path: '',
    http_auth_mode: '',
    http_timeout_seconds: 30,
    http_headers_text: '',
    http_query_text: '',
    http_auth_config_text: '',
    http_retry_policy_text: '',
    http_idempotency_config_text: '',
    http_allowlist_domains_text: '',
    http_active: true,
});

const form = useForm({
    platform_id: props.event?.platform?.id ?? '',
    to_event_id: props.event?.to_event?.id ?? '',
    name: props.event?.name ?? '',
    event_type_id: props.event?.event_type_id ?? '',
    type: props.event?.type ?? 'webhook',
    subscription_type: props.event?.subscription_type ?? '',
    method_name: props.event?.method_name ?? '',
    endpoint_api: props.event?.endpoint_api ?? '',
    schedule_expression: props.event?.schedule_expression ?? '',
    command_sql: props.event?.command_sql ?? '',
    enable_update_hubdb: props.event?.enable_update_hubdb ?? false,
    hubdb_table_id: props.event?.hubdb_table_id ?? '',
    active: props.event?.active ?? true,
    payload_mapping_text: formatJsonText(props.event?.payload_mapping, ''),
    meta_text: formatJsonText(props.event?.meta, ''),
    ...defaultHttpFields(),
});

const hasInitialHttpConfig = !!props.event?.http_config;
const enableGenericConfig = ref(hasInitialHttpConfig);
const genericPanelOpen = ref(hasInitialHttpConfig);
const hasInitialEventAuthOverride = !!(
    props.event?.http_config?.auth_mode
    || (props.event?.http_config?.auth_config_json && Object.keys(props.event.http_config.auth_config_json).length > 0)
);
const useEventAuthOverride = ref(hasInitialEventAuthOverride);

if (hasInitialHttpConfig) {
    const cfg = props.event.http_config;
    form.http_method = cfg.method ?? 'POST';
    form.http_base_url = cfg.base_url ?? '';
    form.http_path = cfg.path ?? '';
    form.http_auth_mode = cfg.auth_mode ?? '';
    form.http_timeout_seconds = cfg.timeout_seconds ?? 30;
    form.http_headers_text = formatJsonText(cfg.headers_json, '');
    form.http_query_text = formatJsonText(cfg.query_json, '');
    form.http_auth_config_text = formatJsonText(cfg.auth_config_json, '');
    form.http_retry_policy_text = formatJsonText(cfg.retry_policy_json, '');
    form.http_idempotency_config_text = formatJsonText(cfg.idempotency_config_json, '');
    form.http_allowlist_domains_text = formatJsonText(cfg.allowlist_domains_json, '');
    form.http_active = cfg.active ?? true;
}

const selectedPlatform = computed(() => props.platforms.find((platform) => Number(platform.id) === Number(form.platform_id)) ?? null);
const selectedPlatformType = computed(() => selectedPlatform.value?.type ?? null);
const isGenericPlatform = computed(() => selectedPlatformType.value === 'generic');
const selectedPlatformMethodOptions = computed(() => props.platform_method_options?.[String(form.platform_id)] ?? []);
const methodNameExistsInOptions = computed(() => selectedPlatformMethodOptions.value.some((option) => option.value === form.method_name));
const shouldUseMethodSelect = computed(() => selectedPlatformMethodOptions.value.length > 0 || !!form.method_name);
const methodOptionsForSelect = computed(() => {
    if (!form.method_name || methodNameExistsInOptions.value) {
        return selectedPlatformMethodOptions.value;
    }

    return [
        { value: form.method_name, label: `${form.method_name} (legacy/manual)` },
        ...selectedPlatformMethodOptions.value,
    ];
});

const filteredEventTypeGroups = computed(() => {
    const platformType = selectedPlatformType.value;

    return (props.event_type_groups ?? [])
        .map((group) => ({
            ...group,
            options: (group.options ?? []).filter((option) => {
                if (!platformType) {
                    return true;
                }

                const platforms = option.platforms ?? [];
                return platforms.includes('*') || platforms.includes(platformType);
            }),
        }))
        .filter((group) => (group.options ?? []).length > 0);
});

const suggestedEventTypeValues = computed(() => filteredEventTypeGroups.value.flatMap((group) => group.options.map((option) => option.value)));
const hasSuggestedEventType = computed(() => suggestedEventTypeValues.value.includes(form.event_type_id));

const payloadMappingPlaceholder = `{
  "properties.dealname": "name",
  "properties.amount": "total",
  "properties.pipeline": "sales_pipeline"
}`;

const metaPlaceholder = `{
  "object_type": "deal",
  "source": "hubspot",
  "notes": "Optional execution metadata"
}`;

const genericConfigHelp = 'Disponible solo para plataformas generic. Define HTTP, auth, retries e idempotencia del request saliente.';

const safeParse = (raw, fallback = {}) => {
    try {
        if (!raw || raw === '') {
            return fallback;
        }

        const parsed = JSON.parse(raw);
        return parsed ?? fallback;
    } catch (error) {
        return null;
    }
};

const parseArrayJson = (raw) => {
    const parsed = safeParse(raw, []);
    if (parsed === null) {
        return null;
    }

    return Array.isArray(parsed) ? parsed : [];
};

const buildHttpConfig = () => {
    if (!isGenericPlatform.value || !enableGenericConfig.value) {
        return null;
    }

    const headers = safeParse(form.http_headers_text, {});
    const query = safeParse(form.http_query_text, {});
    const authConfig = useEventAuthOverride.value
        ? safeParse(form.http_auth_config_text, {})
        : {};
    const retryPolicy = safeParse(form.http_retry_policy_text, {});
    const idempotencyConfig = safeParse(form.http_idempotency_config_text, {});
    const allowlistDomains = parseArrayJson(form.http_allowlist_domains_text);

    if ([headers, query, authConfig, retryPolicy, idempotencyConfig, allowlistDomains].includes(null)) {
        return null;
    }

    return {
        method: form.http_method || 'POST',
        base_url: form.http_base_url || null,
        path: form.http_path || null,
        headers_json: headers,
        query_json: query,
        auth_mode: useEventAuthOverride.value ? (form.http_auth_mode || null) : null,
        auth_config_json: authConfig,
        timeout_seconds: Number(form.http_timeout_seconds || 30),
        retry_policy_json: retryPolicy,
        idempotency_config_json: idempotencyConfig,
        allowlist_domains_json: allowlistDomains,
        active: !!form.http_active,
    };
};

const submit = () => {
    const payloadMapping = safeParse(form.payload_mapping_text);
    const meta = safeParse(form.meta_text);
    const httpConfig = buildHttpConfig();

    if (payloadMapping === null || meta === null || (enableGenericConfig.value && isGenericPlatform.value && httpConfig === null)) {
        alert('Revisa JSON en payload mapping, meta o configuración HTTP genérica.');
        return;
    }

    form.transform((data) => ({
        platform_id: data.platform_id,
        to_event_id: data.to_event_id || null,
        name: data.name,
        event_type_id: data.event_type_id,
        type: data.type,
        subscription_type: data.subscription_type || null,
        method_name: data.method_name || null,
        endpoint_api: data.endpoint_api || null,
        schedule_expression: data.type === 'schedule' ? (data.schedule_expression || null) : null,
        command_sql: data.type === 'schedule' ? (data.command_sql || null) : null,
        enable_update_hubdb: data.type === 'schedule' ? !!data.enable_update_hubdb : false,
        hubdb_table_id: data.type === 'schedule' && data.enable_update_hubdb ? Number(data.hubdb_table_id || 0) || null : null,
        active: !!data.active,
        payload_mapping: payloadMapping,
        meta,
        http_config: httpConfig,
    }));

    if (isEdit.value) {
        form.put(`/admin/events/${props.event.id}`);
        return;
    }

    form.post('/admin/events');
};

const toggleGenericConfigPanel = () => {
    if (!isGenericPlatform.value) {
        return;
    }

    if (!enableGenericConfig.value) {
        enableGenericConfig.value = true;
        genericPanelOpen.value = true;
        return;
    }

    genericPanelOpen.value = !genericPanelOpen.value;
};

watch(isGenericPlatform, (value) => {
    if (value) {
        enableGenericConfig.value = true;
        genericPanelOpen.value = true;
        return;
    }

    enableGenericConfig.value = false;
    genericPanelOpen.value = false;
});
</script>

<template>
    <AdminLayout title="Events">
        <LightboxFormModal :title="isEdit ? `Edit event #${props.event?.id}` : 'Create event'" close-href="/admin/events">
            <form class="lightbox-form" @submit.prevent="submit">
                <div class="lightbox-grid">
                    <label class="lightbox-field">
                        <span class="lightbox-label">Platform</span>
                        <select v-model="form.platform_id" class="lightbox-select" required>
                            <option value="" disabled>Platform</option>
                            <option v-for="platform in props.platforms" :key="platform.id" :value="platform.id">{{ platform.name }} ({{ platform.type }})</option>
                        </select>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Next Event</span>
                        <select v-model="form.to_event_id" class="lightbox-select">
                            <option value="">No next event</option>
                            <option v-for="option in props.event_options" :key="option.id" :value="option.id">{{ option.name }}</option>
                        </select>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Name</span>
                        <input v-model="form.name" class="lightbox-input" type="text" placeholder="Event Name" required>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Suggested Event Type</span>
                        <select
                            class="lightbox-select"
                            :value="hasSuggestedEventType ? form.event_type_id : ''"
                            @change="form.event_type_id = $event.target.value || form.event_type_id"
                        >
                            <option value="">Suggested event type</option>
                            <optgroup
                                v-for="group in filteredEventTypeGroups"
                                :key="group.label"
                                :label="group.label"
                            >
                                <option
                                    v-for="option in group.options"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </optgroup>
                        </select>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Execution Type</span>
                        <select v-model="form.type" class="lightbox-select" required>
                            <option value="webhook">webhook</option>
                            <option value="schedule">schedule</option>
                        </select>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Event Type ID</span>
                        <input v-model="form.event_type_id" class="lightbox-input" type="text" placeholder="event_type_id" required>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Subscription Type</span>
                        <input v-model="form.subscription_type" class="lightbox-input" type="text" placeholder="deal.propertyChange">
                        <span class="lightbox-help">Clave que llega en el webhook y permite encontrar este evento.</span>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Method Name</span>
                        <select v-if="shouldUseMethodSelect" v-model="form.method_name" class="lightbox-select">
                            <option value="">No method</option>
                            <option
                                v-for="option in methodOptionsForSelect"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                        <input v-else v-model="form.method_name" class="lightbox-input" type="text" placeholder="objectPropertyChange">
                        <span class="lightbox-help">
                            Método del servicio que ejecutará la lógica principal del evento.
                            <template v-if="!selectedPlatformMethodOptions.length">
                                No hay métodos sugeridos para esta plataforma; se habilita captura manual como fallback.
                            </template>
                        </span>
                    </label>

                    <label class="lightbox-field">
                        <span class="lightbox-label">Endpoint API</span>
                        <input v-model="form.endpoint_api" class="lightbox-input" type="text" placeholder="/crm/v3/objects/deals">
                        <span class="lightbox-help">Útil para eventos genéricos o para guardar metadata operativa del endpoint.</span>
                    </label>
                </div>

                <p v-if="form.event_type_id && !hasSuggestedEventType" class="field-note">
                    Valor manual o legado. Se conserva compatibilidad aunque no aparezca en el enum sugerido.
                </p>

                <div v-if="form.type === 'schedule'" class="lightbox-block">
                    <p class="lightbox-block-title">Scheduled Event Settings</p>
                    <div class="lightbox-grid">
                        <label class="lightbox-field">
                            <span class="lightbox-label">Schedule Expression</span>
                            <input v-model="form.schedule_expression" class="lightbox-input" type="text" placeholder="0 * * * *">
                        </label>
                        <label class="lightbox-field">
                            <span class="lightbox-label">HubDB Table ID</span>
                            <input v-model="form.hubdb_table_id" class="lightbox-input" type="number" placeholder="hubdb_table_id">
                        </label>
                    </div>
                    <label class="lightbox-field">
                        <span class="lightbox-label">Command SQL</span>
                        <textarea v-model="form.command_sql" class="lightbox-textarea" rows="3" placeholder="select * from deals where synced = 0" />
                    </label>
                    <label class="lightbox-check-inline"><input v-model="form.enable_update_hubdb" type="checkbox"> enable_update_hubdb</label>
                </div>

                <div v-if="isGenericPlatform" class="toggle-row">
                    <label class="lightbox-check-inline">
                        <input v-model="enableGenericConfig" type="checkbox">
                        Generic platform configuration
                    </label>
                    <button type="button" class="lightbox-link toggle-button" @click="toggleGenericConfigPanel">
                        {{ genericPanelOpen ? 'Hide config' : 'Show config' }}
                    </button>
                </div>

                <div v-if="enableGenericConfig && isGenericPlatform && genericPanelOpen" class="lightbox-block">
                    <p class="lightbox-block-title">Generic HTTP Configuration</p>
                    <p class="lightbox-help">{{ genericConfigHelp }}</p>

                    <div class="lightbox-grid">
                        <label class="lightbox-field">
                            <span class="lightbox-label">HTTP Method</span>
                            <select v-model="form.http_method" class="lightbox-select">
                                <option>GET</option>
                                <option>POST</option>
                                <option>PUT</option>
                                <option>PATCH</option>
                                <option>DELETE</option>
                            </select>
                        </label>

                        <label class="lightbox-field">
                            <span class="lightbox-label">Base URL</span>
                            <input v-model="form.http_base_url" class="lightbox-input" type="url" placeholder="https://api.example.com">
                        </label>

                        <label class="lightbox-field">
                            <span class="lightbox-label">Path</span>
                            <input v-model="form.http_path" class="lightbox-input" type="text" placeholder="/v1/orders/sync">
                        </label>

                        <label class="lightbox-check-inline">
                            <input v-model="useEventAuthOverride" type="checkbox">
                            Override platform auth for this event
                        </label>

                        <label class="lightbox-field">
                            <span class="lightbox-label">Auth Mode</span>
                            <select v-model="form.http_auth_mode" class="lightbox-select" :disabled="!useEventAuthOverride">
                                <option value="">Auth mode (optional)</option>
                                <option value="bearer_api_key">bearer_api_key</option>
                                <option value="basic_auth">basic_auth</option>
                                <option value="oauth2_client_credentials">oauth2_client_credentials</option>
                            </select>
                            <span class="lightbox-help">
                                {{ useEventAuthOverride ? 'Auth propio del evento.' : 'Usando auth de la plataforma.' }}
                            </span>
                        </label>

                        <label class="lightbox-field">
                            <span class="lightbox-label">Timeout Seconds</span>
                            <input v-model="form.http_timeout_seconds" class="lightbox-input" type="number" min="1" max="120" placeholder="30">
                        </label>

                        <label class="lightbox-check-inline"><input v-model="form.http_active" type="checkbox"> HTTP active</label>
                    </div>

                    <div class="json-stack">
                        <label class="lightbox-field">
                            <p class="field-label">Headers JSON</p>
                            <textarea v-model="form.http_headers_text" class="lightbox-textarea" rows="2" placeholder='{"x-tenant-id":"abc"}' />
                        </label>

                        <label class="lightbox-field">
                            <p class="field-label">Query JSON</p>
                            <textarea v-model="form.http_query_text" class="lightbox-textarea" rows="2" placeholder='{"source":"integrador"}' />
                        </label>

                        <label class="lightbox-field">
                            <p class="field-label">Auth config JSON</p>
                            <textarea
                                v-model="form.http_auth_config_text"
                                class="lightbox-textarea"
                                rows="2"
                                placeholder='{"api_key_env":"GENERIC_API_KEY"}'
                                :disabled="!useEventAuthOverride"
                            />
                            <p class="lightbox-help">
                                {{ useEventAuthOverride ? 'Opcional: override fino por evento.' : 'Se ignora mientras el override esté desactivado.' }}
                            </p>
                        </label>

                        <label class="lightbox-field">
                            <p class="field-label">Retry policy JSON</p>
                            <textarea v-model="form.http_retry_policy_text" class="lightbox-textarea" rows="2" placeholder='{"max_attempts":3}' />
                        </label>

                        <label class="lightbox-field">
                            <p class="field-label">Idempotency JSON</p>
                            <textarea v-model="form.http_idempotency_config_text" class="lightbox-textarea" rows="2" placeholder='{"enabled":true,"ttl_hours":24}' />
                        </label>

                        <label class="lightbox-field">
                            <p class="field-label">Allowlist Domains JSON</p>
                            <textarea v-model="form.http_allowlist_domains_text" class="lightbox-textarea" rows="2" placeholder='["api.example.com"]' />
                        </label>
                    </div>
                </div>

                <div class="json-stack">
                    <label class="lightbox-field">
                        <p class="field-label">Payload Mapping JSON</p>
                        <textarea v-model="form.payload_mapping_text" class="lightbox-textarea" rows="4" :placeholder="payloadMappingPlaceholder" />
                        <p class="lightbox-help">Opcional. Déjalo vacío si este evento no necesita remapear campos del payload.</p>
                    </label>

                    <label class="lightbox-field">
                        <p class="field-label">Meta JSON</p>
                        <textarea v-model="form.meta_text" class="lightbox-textarea" rows="4" :placeholder="metaPlaceholder" />
                        <p class="lightbox-help">Opcional. Guarda metadata auxiliar del evento sin mezclarla con el payload de negocio.</p>
                    </label>
                </div>

                <label class="lightbox-check-inline"><input v-model="form.active" type="checkbox"> Active</label>

                <div class="lightbox-actions">
                    <button class="lightbox-submit" type="submit" :disabled="form.processing">{{ isEdit ? 'Save changes' : 'Create event' }}</button>
                    <Link class="lightbox-link" href="/admin/events">Cancel</Link>
                </div>
            </form>
        </LightboxFormModal>
    </AdminLayout>
</template>

<style scoped>
.toggle-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
}

.toggle-button{
    cursor:pointer;
}

.json-stack{
    display:grid;
    gap:10px;
}

.field-label{
    margin:0;
    font-size:12px;
    color:#334155;
    font-weight:600;
}

.field-note{
    margin:0;
    font-size:12px;
    color:#64748b;
}
</style>
