<script setup>
import ClientTabs from '@/Components/ClientTabs.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    mode: { type: String, required: true },
    client: { type: Object, required: true },
    connection: { type: Object, default: null },
});

const isEdit = computed(() => props.mode === 'edit');
const showHubspotCredentials = ref(!isEdit.value || !props.connection?.has_webhook_secret || !props.connection?.has_credentials);
const showTrebleCredentials = ref(!isEdit.value || !props.connection?.has_credentials);
const isHubspot = computed(() => form.platform_type === 'hubspot');
const isTreble = computed(() => form.platform_type === 'treble');
const defaultHubspotProperties = [
    'firstname',
    'lastname',
    'phone',
    'campus_de_interes',
    'nivel_escolar_de_interes',
    'plantilla_de_whatsapp',
];
const form = useForm({
    name: props.connection?.name ?? '',
    slug: props.connection?.slug ?? '',
    platform_type: props.connection?.platform_type ?? 'hubspot',
    base_url: props.connection?.base_url ?? '',
    signature_header: props.connection?.signature_header ?? '',
    webhook_secret: '',
    access_token: '',
    api_key: '',
    username: '',
    password: '',
    contact_properties: (props.connection?.settings?.contact_properties ?? defaultHubspotProperties).map((property) => ({
        value: String(property ?? ''),
    })),
    send_path: props.connection?.settings?.send_path ?? '',
    http_method: props.connection?.settings?.http_method ?? 'POST',
    auth_mode: props.connection?.settings?.auth_mode ?? 'authorization_header',
    api_key_header: props.connection?.settings?.api_key_header ?? 'X-API-Key',
    country_code_default: props.connection?.settings?.country_code_default ?? '52',
    headers_text: JSON.stringify(props.connection?.settings?.headers ?? {}, null, 2),
    timeout_seconds: props.connection?.settings?.timeout_seconds ?? 20,
    active: props.connection?.active ?? true,
});
const copyFeedback = ref('');

const parseJsonField = (field, fallback) => {
    try {
        form.clearErrors(field);
        return JSON.parse(form[field] || fallback);
    } catch (error) {
        form.setError(field, 'JSON inválido');
        return null;
    }
};

const enableHubspotCredentialsEdit = () => {
    showHubspotCredentials.value = true;
};

const disableHubspotCredentialsEdit = () => {
    showHubspotCredentials.value = false;
    form.webhook_secret = '';
    form.access_token = '';
};

const rotateTrebleWebhookSecret = () => {
    if (!isEdit.value || !props.connection?.id) {
        return;
    }

    router.post(`/admin/clients/${props.client.id}/connections/${props.connection.id}/rotate-webhook-secret`, {}, {
        preserveScroll: true,
    });
};

const revokeTrebleWebhookSecret = () => {
    if (!isEdit.value || !props.connection?.id) {
        return;
    }

    router.post(`/admin/clients/${props.client.id}/connections/${props.connection.id}/revoke-webhook-secret`, {}, {
        preserveScroll: true,
    });
};

const enableTrebleCredentialsEdit = () => {
    showTrebleCredentials.value = true;
};

const disableTrebleCredentialsEdit = () => {
    showTrebleCredentials.value = false;
    form.api_key = '';
    form.username = '';
    form.password = '';
};

const fallbackCopyText = (value) => {
    const helper = document.createElement('textarea');
    helper.value = value;
    helper.setAttribute('readonly', 'readonly');
    helper.style.position = 'absolute';
    helper.style.left = '-9999px';
    document.body.appendChild(helper);
    helper.select();
    document.execCommand('copy');
    document.body.removeChild(helper);
};

const copyText = async (value, label) => {
    if (!value) {
        return;
    }

    try {
        if (navigator?.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
        } else {
            fallbackCopyText(value);
        }
    } catch (error) {
        fallbackCopyText(value);
    }

    copyFeedback.value = `${label} copiado`;
    window.setTimeout(() => {
        copyFeedback.value = '';
    }, 1800);
};

const submit = () => {
    const headers = parseJsonField('headers_text', '{}');

    if (headers === null) {
        return;
    }

    const contactProperties = form.contact_properties
        .map((property) => String(property?.value ?? '').trim())
        .filter(Boolean);

    const payload = {
        name: form.name,
        slug: form.slug || null,
        platform_type: form.platform_type,
        base_url: form.base_url || null,
        signature_header: form.signature_header || null,
            webhook_secret: form.webhook_secret || null,
        active: !!form.active,
        credentials: {
            access_token: form.access_token || undefined,
            api_key: form.api_key || undefined,
            username: form.username || undefined,
            password: form.password || undefined,
        },
        settings: {
            contact_properties: contactProperties,
            send_path: form.send_path || null,
            http_method: form.http_method,
            auth_mode: form.auth_mode || null,
            api_key_header: form.api_key_header || null,
            country_code_default: form.country_code_default || '52',
            headers,
            timeout_seconds: Number(form.timeout_seconds || 20),
        },
    };

    form.transform(() => payload);

    if (isEdit.value) {
        form.transform(() => ({
            ...payload,
            _method: 'put',
        }));
        form.post(`/admin/clients/${props.client.id}/connections/${props.connection.id}`);
        return;
    }

    form.post(`/admin/clients/${props.client.id}/connections`);
};

const addContactProperty = () => {
    form.contact_properties.push({ value: '' });
};

const removeContactProperty = (index) => {
    if (form.contact_properties.length === 1) {
        form.contact_properties[0].value = '';
        return;
    }

    form.contact_properties.splice(index, 1);
};
</script>

<template>
    <AdminLayout :title="isEdit ? 'Editar conexión' : 'Nueva conexión'">
        <ClientTabs :client="client" />
        <form class="form" @submit.prevent="submit">
            <div v-if="Object.keys(form.errors).length" class="error-summary">
                <strong>No pudimos guardar la conexión.</strong>
                <span>Revisa los campos marcados e intenta de nuevo.</span>
            </div>
            <div class="grid">
                <label><span>Nombre</span><input v-model="form.name" type="text" required><small v-if="form.errors.name" class="field-error">{{ form.errors.name }}</small></label>
                <label><span>Slug</span><input v-model="form.slug" type="text"><small v-if="form.errors.slug" class="field-error">{{ form.errors.slug }}</small></label>
                <label><span>Tipo de plataforma</span><select v-model="form.platform_type"><option value="hubspot">HubSpot</option><option value="treble">Treble</option></select><small v-if="form.errors.platform_type" class="field-error">{{ form.errors.platform_type }}</small></label>
            </div>

            <section v-if="isHubspot" class="section">
                <header>
                    <h2>HubSpot</h2>
                    <p>Webhook, token y propiedades del contacto a consultar.</p>
                </header>
                <div class="grid">
                    <label><span>Base URL</span><input v-model="form.base_url" type="url" placeholder="https://api.hubapi.com"><small v-if="form.errors.base_url" class="field-error">{{ form.errors.base_url }}</small></label>
                    <label><span>Header de firma</span><input v-model="form.signature_header" type="text" placeholder="x-signature"><small v-if="form.errors.signature_header" class="field-error">{{ form.errors.signature_header }}</small></label>
                    <label><span>Timeout (segundos)</span><input v-model="form.timeout_seconds" type="number" min="1"></label>
                    <label class="toggle-field">
                        <span>Estado</span>
                        <button
                            type="button"
                            class="toggle"
                            :class="{ on: form.active }"
                            :aria-pressed="form.active ? 'true' : 'false'"
                            @click="form.active = !form.active"
                        >
                            <span class="track">
                                <span class="thumb" />
                            </span>
                            <span class="toggle-label">{{ form.active ? 'Activa' : 'Inactiva' }}</span>
                        </button>
                    </label>
                </div>
                <div v-if="isEdit && (connection?.has_webhook_secret || connection?.has_credentials)" class="credentials-bar">
                    <div class="credentials-copy">
                        <strong>Credenciales</strong>
                        <small class="hint ok">Ya configuradas. Ábrelas solo si necesitas reemplazarlas.</small>
                    </div>
                    <button
                        type="button"
                        class="ghost-button"
                        @click="showHubspotCredentials ? disableHubspotCredentialsEdit() : enableHubspotCredentialsEdit()"
                    >
                        {{ showHubspotCredentials ? 'Conservar actuales' : 'Actualizar credenciales' }}
                    </button>
                </div>
                <div v-if="showHubspotCredentials" class="grid">
                    <label>
                        <span>Webhook secret</span>
                        <input
                            v-model="form.webhook_secret"
                            type="password"
                            :placeholder="isEdit ? 'solo para reemplazar' : 'Webhook secret'"
                        >
                    </label>
                    <label>
                        <span>Access token</span>
                        <input
                            v-model="form.access_token"
                            type="password"
                            :placeholder="isEdit ? 'solo para reemplazar' : 'HubSpot token'"
                        >
                    </label>
                </div>
                <div class="repeater-block">
                    <div class="repeater-header">
                        <div>
                            <span class="repeater-title">Propiedades HubSpot a leer</span>
                            <small class="hint">Se consultarán en cada lectura del contacto antes de resolver la regla.</small>
                        </div>
                        <button type="button" class="ghost-button" @click="addContactProperty">Agregar propiedad</button>
                    </div>
                    <div class="repeater-list">
                        <div v-for="(property, index) in form.contact_properties" :key="`hubspot-property-${index}`" class="repeater-row">
                            <label class="repeater-input">
                                <span>Propiedad {{ index + 1 }}</span>
                                <input v-model="property.value" type="text" placeholder="plantilla_de_whatsapp">
                            </label>
                            <button type="button" class="ghost-button danger-button" @click="removeContactProperty(index)">Quitar</button>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="isTreble" class="section">
                <header>
                    <h2>Treble</h2>
                    <p>Endpoint, autenticación y payload configurable.</p>
                </header>
                <div v-if="isEdit && connection?.status_webhook_url" class="credentials-bar">
                    <div class="credentials-copy">
                        <strong>Webhook de estados</strong>
                        <small class="hint ok">{{ connection.status_webhook_url }}</small>
                    </div>
                </div>
                <div class="grid">
                    <label><span>Base URL</span><input v-model="form.base_url" type="url" required placeholder="https://main.treble.ai"><small v-if="form.errors.base_url" class="field-error">{{ form.errors.base_url }}</small></label>
                    <label><span>Endpoint de despliegue</span><input v-model="form.send_path" type="text" placeholder="/deployment/api/poll/{poll_id}"><small v-if="form.errors['settings.send_path']" class="field-error">{{ form.errors['settings.send_path'] }}</small></label>
                    <label><span>HTTP method</span><select v-model="form.http_method"><option value="POST">POST</option></select></label>
                    <label><span>Auth mode</span><select v-model="form.auth_mode"><option value="authorization_header">Authorization header</option><option value="bearer_api_key">Bearer API key</option><option value="header_api_key">Header API key</option><option value="basic_auth">Basic auth</option></select></label>
                    <label v-if="form.auth_mode === 'header_api_key'"><span>Header API key</span><input v-model="form.api_key_header" type="text"></label>
                    <label v-if="(form.auth_mode === 'authorization_header' || form.auth_mode === 'bearer_api_key' || form.auth_mode === 'header_api_key') && showTrebleCredentials">
                        <span>API key</span>
                        <input
                            v-model="form.api_key"
                            type="password"
                            :placeholder="isEdit ? 'solo para reemplazar' : 'Treble API key'"
                        >
                        <small v-if="form.errors['credentials.api_key']" class="field-error">{{ form.errors['credentials.api_key'] }}</small>
                    </label>
                    <label v-if="form.auth_mode === 'basic_auth' && showTrebleCredentials">
                        <span>Username</span>
                        <input v-model="form.username" type="text">
                    </label>
                    <label v-if="form.auth_mode === 'basic_auth' && showTrebleCredentials">
                        <span>Password</span>
                        <input
                            v-model="form.password"
                            type="password"
                            :placeholder="isEdit ? 'solo para reemplazar' : 'Password'"
                        >
                    </label>
                    <label><span>Country code default</span><input v-model="form.country_code_default" type="text" placeholder="52"></label>
                    <label><span>Timeout (segundos)</span><input v-model="form.timeout_seconds" type="number" min="1"></label>
                    <label class="toggle-field">
                        <span>Estado</span>
                        <button
                            type="button"
                            class="toggle"
                            :class="{ on: form.active }"
                            :aria-pressed="form.active ? 'true' : 'false'"
                            @click="form.active = !form.active"
                        >
                            <span class="track">
                                <span class="thumb" />
                            </span>
                            <span class="toggle-label">{{ form.active ? 'Activa' : 'Inactiva' }}</span>
                        </button>
                    </label>
                </div>
                <div class="credentials-bar">
                    <div class="credentials-copy">
                        <strong>Webhook secret</strong>
                        <small v-if="connection?.has_webhook_secret" class="hint ok">Ya configurado y generado automáticamente desde la API key.</small>
                        <small v-else class="hint">Se generará automáticamente al guardar una API key.</small>
                    </div>
                    <div class="inline-actions" v-if="isEdit">
                        <button
                            v-if="connection?.has_webhook_secret"
                            type="button"
                            class="ghost-button"
                            @click="revokeTrebleWebhookSecret"
                        >
                            Revocar
                        </button>
                        <button
                            type="button"
                            class="ghost-button"
                            @click="rotateTrebleWebhookSecret"
                        >
                            {{ connection?.has_webhook_secret ? 'Regenerar' : 'Generar ahora' }}
                        </button>
                    </div>
                </div>
                <div v-if="isEdit && connection?.has_webhook_secret" class="grid">
                    <label class="aligned-copy-field">
                        <span>Header de firma</span>
                        <div class="copy-field">
                            <input v-model="form.signature_header" type="text" placeholder="X-Treble-Webhook-Secret">
                            <button type="button" class="ghost-button" @click="copyText(form.signature_header || 'X-Treble-Webhook-Secret', 'Header')">Copiar</button>
                        </div>
                        <small class="hint">Treble debe enviar este header con el webhook secret actual.</small>
                        <small v-if="form.errors.signature_header" class="field-error">{{ form.errors.signature_header }}</small>
                    </label>
                    <label class="aligned-copy-field">
                        <span>Webhook secret actual</span>
                        <div class="copy-field">
                            <input :value="connection?.revealed_webhook_secret || ''" type="text" readonly>
                            <button type="button" class="ghost-button" @click="copyText(connection?.revealed_webhook_secret || '', 'Webhook secret')">Copiar</button>
                        </div>
                        <small class="hint placeholder-hint" aria-hidden="true">&nbsp;</small>
                    </label>
                </div>
                <p v-if="copyFeedback" class="copy-feedback">{{ copyFeedback }}</p>
                <div v-if="isEdit && connection?.has_credentials" class="credentials-bar">
                    <div class="credentials-copy">
                        <strong>Credenciales de API</strong>
                        <small class="hint ok">Ya configuradas. Ábrelas solo si necesitas reemplazarlas.</small>
                    </div>
                    <button
                        type="button"
                        class="ghost-button"
                        @click="showTrebleCredentials ? disableTrebleCredentialsEdit() : enableTrebleCredentialsEdit()"
                    >
                        {{ showTrebleCredentials ? 'Conservar actuales' : 'Actualizar credenciales' }}
                    </button>
                </div>
                <label><span>Headers JSON</span><textarea v-model="form.headers_text" rows="8" placeholder='{"X-Workspace":"abc"}'></textarea></label>
            </section>

            <div class="actions">
                <Link :href="`/admin/clients/${client.id}/connections`">Cancelar</Link>
                <button type="submit" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.form { display: grid; gap: 14px; }
.section { display: grid; gap: 14px; padding: 14px; border: 1px solid #e2e8f0; border-radius: 8px; }
.section header { display: grid; gap: 4px; }
.section h2, .section p { margin: 0; }
.section p { color: #64748b; font-size: 14px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
label { display: grid; gap: 6px; }
span { color: #334155; font-size: 14px; }
.hint { font-size: 12px; color: #64748b; }
.hint.ok { color: #047857; }
.credentials-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
}
.credentials-copy {
    display: grid;
    gap: 2px;
}
.inline-actions {
    display: inline-flex;
    gap: 8px;
}
.copy-field {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
    align-items: center;
}
.aligned-copy-field {
    align-content: start;
}
.copy-feedback {
    margin: 0;
    font-size: 12px;
    color: #047857;
}
.placeholder-hint {
    visibility: hidden;
}
.error-summary {
    display: grid;
    gap: 4px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    background: #fef2f2;
    color: #991b1b;
    padding: 10px 12px;
}
.field-error {
    font-size: 12px;
    color: #b91c1c;
}
.repeater-block {
    display: grid;
    gap: 12px;
}
.repeater-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}
.repeater-title {
    display: block;
    font-size: 14px;
    font-weight: 600;
    color: #0f172a;
}
.repeater-list {
    display: grid;
    gap: 10px;
}
.repeater-row {
    display: flex;
    align-items: end;
    gap: 10px;
}
.repeater-input {
    flex: 1;
}
.danger-button {
    color: #b91c1c;
    border-color: #fecaca;
}
.ghost-button {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 6px 10px;
    background: #fff;
    color: #334155;
    font-size: 12px;
    cursor: pointer;
}
input, select, textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
.toggle-field { display: grid; gap: 8px; align-content: start; }
.toggle {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    width: fit-content;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 10px;
    background: #fff;
    color: #334155;
    cursor: pointer;
}
.toggle .track {
    position: relative;
    width: 40px;
    height: 22px;
    border-radius: 999px;
    background: #cbd5e1;
    transition: background 0.16s ease;
}
.toggle .thumb {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.24);
    transition: transform 0.16s ease;
}
.toggle.on {
    border-color: #93c5fd;
    background: #eff6ff;
}
.toggle.on .track {
    background: #2563eb;
}
.toggle.on .thumb {
    transform: translateX(18px);
}
.toggle-label {
    font-size: 14px;
    color: #334155;
}
.actions { display: flex; gap: 10px; }
.actions a, .actions button { border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 14px; background: #fff; color: #334155; text-decoration: none; }
.actions button { background: #2563eb; border-color: #2563eb; color: #fff; cursor: pointer; }
@media (max-width: 768px) {
    .repeater-header,
    .repeater-row {
        flex-direction: column;
        align-items: stretch;
    }
}
</style>
