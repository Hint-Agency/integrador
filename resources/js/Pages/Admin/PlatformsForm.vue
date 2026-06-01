<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    mode: { type: String, required: true },
    platform: { type: Object, default: null },
});

const isEdit = computed(() => props.mode === 'edit');

const credentials = props.platform?.credentials ?? {};
const settings = props.platform?.settings ?? {};
const genericServiceDriver = settings.service_driver ?? credentials.service_driver ?? '';
const settingsJsonError = ref('');

const stringifyJson = (value) => JSON.stringify(value ?? {}, null, 2);

const form = useForm({
    name: props.platform?.name ?? '',
    slug: props.platform?.slug ?? '',
    type: props.platform?.type ?? 'hubspot',

    hubspot_api_token: credentials.access_token ?? credentials.api_token ?? '',

    odoo_username: credentials.username ?? '',
    odoo_password: credentials.password ?? '',
    odoo_database: credentials.database ?? '',
    odoo_url: settings.url ?? '',

    netsuite_account: credentials.account ?? '',
    netsuite_consumer_key: credentials.consumer_key ?? '',
    netsuite_consumer_secret: credentials.consumer_secret ?? '',
    netsuite_token_id: credentials.token_id ?? credentials.certificate_id ?? '',
    netsuite_token_secret: credentials.token_secret ?? '',
    netsuite_private_key: credentials.private_key ?? '',

    generic_service_driver: genericServiceDriver,
    generic_auth_mode: settings.auth_mode ?? '',
    generic_api_key: credentials.api_key ?? '',
    generic_basic_user: credentials.username ?? credentials.basic_user ?? '',
    generic_basic_password: credentials.password ?? credentials.basic_password ?? '',
    generic_oauth_client_id: credentials.client_id ?? credentials.oauth_client_id ?? '',
    generic_oauth_client_secret: credentials.client_secret ?? credentials.oauth_client_secret ?? '',
    generic_oauth_token_url: settings.token_url ?? settings.oauth_token_url ?? '',
    azure_sql_connection_string: '',
    azure_sql_host: settings.host ?? credentials.host ?? '',
    azure_sql_port: settings.port ?? credentials.port ?? '1433',
    azure_sql_database: settings.database ?? credentials.database ?? '',
    azure_sql_username: credentials.username ?? '',
    azure_sql_password: credentials.password ?? '',
    azure_sql_encrypt: settings.encrypt ?? true,
    azure_sql_trust_server_certificate: settings.trust_server_certificate ?? false,
    azure_sql_login_timeout: settings.login_timeout ?? 30,

    signature: props.platform?.signature ?? (props.platform?.type === 'odoo' ? 'x-odoo-signature' : ''),
    secret_key: props.platform?.secret_key ?? '',
    webhook_validation_mode: settings.webhook?.validation_mode
        ?? settings.webhook?.validation
        ?? settings.webhook_signature_mode
        ?? (props.platform?.type === 'odoo' ? 'shared_token' : 'hmac_sha256'),
    webhook_allow_token_in_query: settings.webhook?.allow_token_in_query ?? false,
    api_url: settings.base_url ?? '',
    settings_text: stringifyJson(settings),
    active: props.platform?.active ?? true,
});

const requiredFields = computed(() => {
    const required = ['name', 'type'];

    if (form.type === 'hubspot') {
        required.push('hubspot_api_token');
    }

    if (form.type === 'odoo') {
        required.push('odoo_username', 'odoo_password', 'odoo_database');
    }

    if (form.type === 'netsuite') {
        required.push(
            'netsuite_account',
            'netsuite_consumer_key',
            'netsuite_consumer_secret',
            'netsuite_token_id',
            'netsuite_token_secret',
            'netsuite_private_key'
        );
    }

    if (form.type === 'generic') {
        required.push('generic_service_driver');

        if (form.generic_service_driver === 'azure_sql') {
            required.push('azure_sql_host', 'azure_sql_database', 'azure_sql_username', 'azure_sql_password');
        } else {
            required.push('api_url');
        }

        if (form.generic_service_driver !== 'azure_sql' && form.generic_auth_mode === 'bearer_api_key') {
            required.push('generic_api_key');
        }

        if (form.generic_service_driver !== 'azure_sql' && form.generic_auth_mode === 'basic_auth') {
            required.push('generic_basic_user', 'generic_basic_password');
        }

        if (form.generic_service_driver !== 'azure_sql' && form.generic_auth_mode === 'oauth2_client_credentials') {
            required.push('generic_oauth_client_id', 'generic_oauth_client_secret', 'generic_oauth_token_url');
        }
    }

    return required;
});

const isGenericBearer = computed(() => form.type === 'generic' && form.generic_auth_mode === 'bearer_api_key');
const isGenericBasic = computed(() => form.type === 'generic' && form.generic_auth_mode === 'basic_auth');
const isGenericOAuth = computed(() => form.type === 'generic' && form.generic_auth_mode === 'oauth2_client_credentials');
const isAzureSqlDriver = computed(() => form.type === 'generic' && form.generic_service_driver === 'azure_sql');

const missingRequiredCount = computed(() => requiredFields.value
    .filter((field) => {
        const value = form[field];
        return value === null || value === undefined || String(value).trim() === '';
    }).length);

const canTestConnection = computed(() => isEdit.value && !form.processing);
const webhookSecretLabel = computed(() => form.webhook_validation_mode === 'shared_token' ? 'Token compartido' : 'Llave secreta');
const webhookSecretPlaceholder = computed(() => form.webhook_validation_mode === 'shared_token' ? 'Ingresa el token compartido del webhook' : 'Ingresa la llave secreta');

const compactObject = (obj) => Object.fromEntries(Object.entries(obj)
    .filter(([_, value]) => {
        if (value === null || value === undefined) return false;
        if (typeof value === 'string') return value.trim() !== '';
        return true;
    }));

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const deepMerge = (base, override) => {
    const merged = { ...base };

    Object.entries(override).forEach(([key, value]) => {
        if (isPlainObject(value) && isPlainObject(merged[key])) {
            merged[key] = deepMerge(merged[key], value);
            return;
        }

        merged[key] = value;
    });

    return merged;
};

const parseBooleanString = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim().toLowerCase();

    if (['true', '1', 'yes'].includes(normalized)) {
        return true;
    }

    if (['false', '0', 'no'].includes(normalized)) {
        return false;
    }

    return null;
};

const splitConnectionSegment = (segment) => {
    const separatorIndex = segment.indexOf('=');

    if (separatorIndex === -1) {
        return null;
    }

    const key = segment.slice(0, separatorIndex).trim();
    const value = segment.slice(separatorIndex + 1).trim();

    if (!key) {
        return null;
    }

    return [key, value];
};

const parseAzureSqlConnectionString = () => {
    const raw = String(form.azure_sql_connection_string || '').trim();

    if (!raw) {
        return;
    }

    const entries = raw
        .split(';')
        .map((segment) => segment.trim())
        .filter(Boolean)
        .map(splitConnectionSegment)
        .filter(Boolean);

    const values = Object.fromEntries(entries.map(([key, value]) => [key.toLowerCase(), value]));

    const dataSource = values['data source'] ?? values.server ?? values.address ?? values.addr ?? values['network address'];
    const database = values['initial catalog'] ?? values.database;
    const username = values['user id'] ?? values.uid ?? values.username;
    const password = values.password ?? values.pwd;
    const loginTimeout = values['connect timeout'] ?? values['login timeout'];
    const encrypt = values.encrypt;
    const trustServerCertificate = values['trust server certificate'];

    if (database) {
        form.azure_sql_database = database;
    }

    if (username) {
        form.azure_sql_username = username;
    }

    if (password) {
        form.azure_sql_password = password;
    }

    if (loginTimeout && !Number.isNaN(Number(loginTimeout))) {
        form.azure_sql_login_timeout = Number(loginTimeout);
    }

    const parsedEncrypt = parseBooleanString(encrypt);
    if (parsedEncrypt !== null) {
        form.azure_sql_encrypt = parsedEncrypt;
    }

    const parsedTrustServerCertificate = parseBooleanString(trustServerCertificate);
    if (parsedTrustServerCertificate !== null) {
        form.azure_sql_trust_server_certificate = parsedTrustServerCertificate;
    }

    if (dataSource) {
        const normalizedDataSource = dataSource.replace(/^tcp:/i, '').trim();
        const lastCommaIndex = normalizedDataSource.lastIndexOf(',');

        if (lastCommaIndex !== -1) {
            form.azure_sql_host = normalizedDataSource.slice(0, lastCommaIndex).trim();
            form.azure_sql_port = normalizedDataSource.slice(lastCommaIndex + 1).trim();
        } else {
            form.azure_sql_host = normalizedDataSource;
        }
    }
};

const buildCredentials = () => {
    if (form.type === 'hubspot') {
        return compactObject({
            access_token: form.hubspot_api_token,
        });
    }

    if (form.type === 'odoo') {
        return compactObject({
            username: form.odoo_username,
            password: form.odoo_password,
            database: form.odoo_database,
        });
    }

    if (form.type === 'netsuite') {
        return compactObject({
            account: form.netsuite_account,
            consumer_key: form.netsuite_consumer_key,
            consumer_secret: form.netsuite_consumer_secret,
            token_id: form.netsuite_token_id,
            token_secret: form.netsuite_token_secret,
            private_key: form.netsuite_private_key,
        });
    }

    if (form.type === 'generic' && form.generic_service_driver === 'azure_sql') {
        return compactObject({
            service_driver: 'azure_sql',
            username: form.azure_sql_username,
            password: form.azure_sql_password,
        });
    }

    if (form.generic_auth_mode === 'bearer_api_key') {
        return compactObject({
            api_key: form.generic_api_key,
        });
    }

    if (form.generic_auth_mode === 'basic_auth') {
        return compactObject({
            username: form.generic_basic_user,
            password: form.generic_basic_password,
        });
    }

    if (form.generic_auth_mode === 'oauth2_client_credentials') {
        return compactObject({
            client_id: form.generic_oauth_client_id,
            client_secret: form.generic_oauth_client_secret,
        });
    }

    return {};
};

const buildSettings = () => {
    const common = compactObject({
        base_url: form.api_url,
        webhook: compactObject({
            validation_mode: form.webhook_validation_mode,
            allow_token_in_query: form.webhook_validation_mode === 'shared_token'
                ? !!form.webhook_allow_token_in_query
                : null,
        }),
    });

    if (form.type === 'odoo') {
        return {
            ...common,
            ...compactObject({
                url: form.odoo_url || form.api_url,
            }),
        };
    }

    if (form.type === 'generic') {
        return {
            ...common,
            ...compactObject({
                service_driver: form.generic_service_driver,
                auth_mode: form.generic_auth_mode,
                token_url: form.generic_oauth_token_url,
                host: form.azure_sql_host,
                port: form.azure_sql_port,
                database: form.azure_sql_database,
                encrypt: form.generic_service_driver === 'azure_sql' ? !!form.azure_sql_encrypt : null,
                trust_server_certificate: form.generic_service_driver === 'azure_sql' ? !!form.azure_sql_trust_server_certificate : null,
                login_timeout: form.generic_service_driver === 'azure_sql' ? Number(form.azure_sql_login_timeout || 30) : null,
            }),
        };
    }

    return common;
};

watch(() => form.type, (type) => {
    if (type === 'odoo' && !form.signature) {
        form.signature = 'x-odoo-signature';
    }

    if (type === 'odoo' && form.webhook_validation_mode === 'hmac_sha256') {
        form.webhook_validation_mode = 'shared_token';
    }
});

const parseAdvancedSettings = () => {
    const raw = String(form.settings_text || '').trim();

    if (!raw) {
        return {};
    }

    const parsed = JSON.parse(raw);

    if (!isPlainObject(parsed)) {
        throw new Error('Settings JSON debe ser un objeto.');
    }

    return parsed;
};

const testConnection = () => {
    if (!isEdit.value) {
        return;
    }

    router.post(`/admin/platforms/${props.platform.id}/test-connection`, {}, {
        preserveScroll: true,
    });
};

const generateToken = () => {
    if (form.secret_key && !window.confirm('Ya existe un token configurado. ¿Desea reemplazarlo por uno nuevo?')) {
        return;
    }

    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    form.secret_key = Array.from(bytes)
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

    window.alert('Token generado. Guarde la plataforma para aplicar el cambio.');
};

const revokeToken = () => {
    if (!form.secret_key) {
        window.alert('No hay token configurado para revocar.');
        return;
    }

    if (!window.confirm('¿Desea revocar el token actual? Los webhooks dejarán de validar hasta guardar un nuevo token.')) {
        return;
    }

    form.secret_key = '';
    window.alert('Token revocado. Guarde la plataforma para aplicar el cambio.');
};

const copyToken = async () => {
    if (!form.secret_key) {
        window.alert('No hay token para copiar.');
        return;
    }

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(form.secret_key);
        } else {
            throw new Error('API del portapapeles no disponible.');
        }

        window.alert('Token copiado al portapapeles.');
    } catch (error) {
        const textarea = document.createElement('textarea');
        textarea.value = form.secret_key;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '-9999px';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            const copied = document.execCommand('copy');
            window.alert(copied ? 'Token copiado al portapapeles.' : 'No se pudo copiar el token desde el navegador.');
        } finally {
            document.body.removeChild(textarea);
        }
    }
};

const submit = () => {
    settingsJsonError.value = '';

    let advancedSettings = {};

    try {
        advancedSettings = parseAdvancedSettings();
    } catch (error) {
        settingsJsonError.value = error.message || 'Settings JSON debe ser válido.';
        return;
    }

    const payload = {
        name: form.name,
        slug: form.slug || null,
        type: form.type,
        signature: form.signature || null,
        secret_key: form.secret_key || null,
        active: !!form.active,
        credentials: buildCredentials(),
        settings: deepMerge(advancedSettings, buildSettings()),
    };

    form.transform(() => payload);

    if (isEdit.value) {
        form.put(`/admin/platforms/${props.platform.id}`);
        return;
    }

    form.post('/admin/platforms');
};
</script>

<template>
    <AdminLayout title="Plataformas">
        <form class="platform-page" @submit.prevent="submit">
            <header class="head-actions">
                <p class="subtitle">Configura una integración de plataforma</p>
                <div class="actions">
                    <span v-if="missingRequiredCount > 0" class="warning">Completa los campos requeridos</span>
                    <button type="button" class="secondary" :disabled="!canTestConnection" @click="testConnection">
                        Probar conexión
                    </button>
                    <button type="submit" class="primary" :disabled="form.processing">Guardar plataforma</button>
                </div>
            </header>

            <section class="block">
                <header>
                    <h2>Información básica</h2>
                    <p>Nombre y tipo de plataforma</p>
                </header>
                <div class="grid two">
                    <label>
                        <span>Nombre de plataforma</span>
                        <input v-model="form.name" type="text" placeholder="Ingresa el nombre de la plataforma" required>
                    </label>
                    <label>
                        <span>Tipo de plataforma</span>
                        <select v-model="form.type" required>
                            <option value="hubspot">HubSpot</option>
                            <option value="odoo">Odoo</option>
                            <option value="netsuite">NetSuite</option>
                            <option value="generic">Genérica</option>
                        </select>
                    </label>
                    <label>
                        <span>Slug</span>
                        <input v-model="form.slug" type="text" placeholder="platform-slug">
                    </label>
                </div>
            </section>

            <section v-if="form.type === 'hubspot'" class="block">
                <header>
                    <h2>Configuración HubSpot</h2>
                    <p>Credenciales API y autenticación</p>
                </header>
                <div class="grid one">
                    <label>
                        <span>Token API</span>
                        <input v-model="form.hubspot_api_token" type="text" placeholder="Ingresa el token API de HubSpot" required>
                    </label>
                </div>
            </section>

            <section v-if="form.type === 'odoo'" class="block">
                <header>
                    <h2>Configuración Odoo</h2>
                    <p>Credenciales de base de datos y conexión</p>
                </header>
                <div class="grid two">
                    <label>
                        <span>Usuario</span>
                        <input v-model="form.odoo_username" type="text" placeholder="Ingresa el usuario Odoo" required>
                    </label>
                    <label>
                        <span>Contraseña</span>
                        <input v-model="form.odoo_password" type="password" placeholder="Ingresa la contraseña Odoo" required>
                    </label>
                    <label>
                        <span>Base de datos</span>
                        <input v-model="form.odoo_database" type="text" placeholder="Ingresa el nombre de la base" required>
                    </label>
                    <label>
                        <span>Odoo URL</span>
                        <input v-model="form.odoo_url" type="url" placeholder="https://odoo.example.com">
                    </label>
                </div>
            </section>

            <section v-if="form.type === 'netsuite'" class="block">
                <header>
                    <h2>Configuración NetSuite</h2>
                    <p>Credenciales OAuth y certificado</p>
                </header>
                <div class="grid two">
                    <label>
                        <span>Account ID</span>
                        <input v-model="form.netsuite_account" type="text" placeholder="e.g. 1234567" required>
                    </label>
                    <label>
                        <span>Consumer Key</span>
                        <input v-model="form.netsuite_consumer_key" type="text" placeholder="Ingresa el consumer key" required>
                    </label>
                    <label>
                        <span>Consumer Secret</span>
                        <input v-model="form.netsuite_consumer_secret" type="password" placeholder="Ingresa el consumer secret" required>
                    </label>
                    <label>
                        <span>ID de token</span>
                        <input v-model="form.netsuite_token_id" type="text" placeholder="Ingresa el ID de token" required>
                    </label>
                    <label>
                        <span>Token Secret</span>
                        <input v-model="form.netsuite_token_secret" type="password" placeholder="Ingresa el token secret" required>
                    </label>
                </div>
                <div class="grid one">
                    <label>
                        <span>Llave privada</span>
                        <textarea v-model="form.netsuite_private_key" rows="4" placeholder="Pega aquí la llave privada" required />
                    </label>
                </div>
            </section>

            <section v-if="form.type === 'generic'" class="block">
                <header>
                    <h2>Configuración genérica</h2>
                    <p>Autenticación y parámetros de integración</p>
                </header>
                <div class="grid two">
                    <label>
                        <span>Driver de servicio</span>
                        <select v-model="form.generic_service_driver">
                            <option value="">Selecciona un driver</option>
                            <option value="azure_sql">azure_sql</option>
                            <option value="aspel">aspel</option>
                            <option value="generic_http">generic_http</option>
                        </select>
                    </label>
                    <label>
                        <span>Modo de autenticación</span>
                        <select v-model="form.generic_auth_mode" :disabled="isAzureSqlDriver">
                            <option value="">Selecciona modo de autenticación</option>
                            <option value="bearer_api_key">bearer_api_key</option>
                            <option value="basic_auth">basic_auth</option>
                            <option value="oauth2_client_credentials">oauth2_client_credentials</option>
                        </select>
                    </label>
                    <div v-if="isAzureSqlDriver" class="field-group full">
                        <label>
                            <span>Cadena de conexión</span>
                            <textarea
                                v-model="form.azure_sql_connection_string"
                                rows="3"
                                placeholder="Data Source=tcp:sql-crm-maco.database.windows.net,1433;Initial Catalog=DB-CRM;User ID=sqladmin;Password=...;Encrypt=True;Trust Server Certificate=False;Connect Timeout=30;"
                            />
                        </label>
                        <div class="inline-actions">
                            <button type="button" class="secondary small" @click="parseAzureSqlConnectionString">
                                Parsear cadena de conexión
                            </button>
                            <p class="helper">
                                Pega la cadena completa y convertimos sus valores a los campos de abajo. No guardamos la cadena cruda.
                            </p>
                        </div>
                    </div>
                    <label v-if="isAzureSqlDriver">
                        <span>SQL Host</span>
                        <input v-model="form.azure_sql_host" type="text" placeholder="sql-crm-maco.database.windows.net">
                    </label>
                    <label v-if="isAzureSqlDriver">
                        <span>SQL Port</span>
                        <input v-model="form.azure_sql_port" type="text" placeholder="1433">
                    </label>
                    <label v-if="isAzureSqlDriver">
                        <span>Base de datos</span>
                        <input v-model="form.azure_sql_database" type="text" placeholder="DB-CRM">
                    </label>
                    <label v-if="isAzureSqlDriver">
                        <span>Usuario</span>
                        <input v-model="form.azure_sql_username" type="text" placeholder="sqladmin">
                    </label>
                    <label v-if="isAzureSqlDriver">
                        <span>Contraseña</span>
                        <input v-model="form.azure_sql_password" type="password" placeholder="Ingresa la contraseña SQL">
                    </label>
                    <label v-if="isAzureSqlDriver">
                        <span>Timeout de login</span>
                        <input v-model="form.azure_sql_login_timeout" type="number" min="1" placeholder="30">
                    </label>
                    <label v-if="isAzureSqlDriver" class="check">
                        <input v-model="form.azure_sql_encrypt" type="checkbox">
                        <span>Cifrar conexión</span>
                    </label>
                    <label v-if="isAzureSqlDriver" class="check">
                        <input v-model="form.azure_sql_trust_server_certificate" type="checkbox">
                        <span>Confiar en certificado del servidor</span>
                    </label>
                    <label v-if="isGenericBearer && !isAzureSqlDriver">
                        <span>API Key</span>
                        <input v-model="form.generic_api_key" type="text" placeholder="Ingresa la API key">
                    </label>
                    <label v-if="isGenericBasic && !isAzureSqlDriver">
                        <span>Usuario básico</span>
                        <input v-model="form.generic_basic_user" type="text" placeholder="Ingresa el usuario básico">
                    </label>
                    <label v-if="isGenericBasic && !isAzureSqlDriver">
                        <span>Contraseña básica</span>
                        <input v-model="form.generic_basic_password" type="password" placeholder="Ingresa la contraseña básica">
                    </label>
                    <label v-if="isGenericOAuth && !isAzureSqlDriver">
                        <span>OAuth Client ID</span>
                        <input v-model="form.generic_oauth_client_id" type="text" placeholder="Ingresa el OAuth client ID">
                    </label>
                    <label v-if="isGenericOAuth && !isAzureSqlDriver">
                        <span>OAuth Client Secret</span>
                        <input v-model="form.generic_oauth_client_secret" type="password" placeholder="Ingresa el OAuth client secret">
                    </label>
                    <label v-if="isGenericOAuth && !isAzureSqlDriver">
                        <span>OAuth Token URL</span>
                        <input v-model="form.generic_oauth_token_url" type="url" placeholder="https://oauth.example.com/token">
                    </label>
                </div>
            </section>

            <section class="block">
                <header>
                    <h2>Seguridad y API</h2>
                    <p>Firma de webhooks y configuración API</p>
                </header>
                <div class="grid two">
                    <label>
                        <span>Firma del webhook</span>
                        <input v-model="form.signature" type="text" placeholder="Ingresa el header de firma del webhook">
                    </label>
                    <label>
                        <span>Modo de validación</span>
                        <select v-model="form.webhook_validation_mode">
                            <option value="hmac_sha256">HMAC SHA-256</option>
                            <option value="shared_token">Token compartido</option>
                        </select>
                    </label>
                    <label>
                        <span>{{ webhookSecretLabel }}</span>
                        <input v-model="form.secret_key" type="password" :placeholder="webhookSecretPlaceholder">
                    </label>
                    <label v-if="form.webhook_validation_mode === 'shared_token'" class="check full">
                        <input v-model="form.webhook_allow_token_in_query" type="checkbox">
                        <span>Permitir token compartido en URL/body para plataformas que no pueden enviar headers</span>
                    </label>
                    <div class="token-actions full">
                        <button type="button" class="secondary small" @click="generateToken">
                            Generar token
                        </button>
                        <button type="button" class="secondary small" :disabled="!form.secret_key" @click="copyToken">
                            Copiar token
                        </button>
                        <button type="button" class="secondary danger small" :disabled="!form.secret_key" @click="revokeToken">
                            Revocar token
                        </button>
                    </div>
                    <label class="full">
                        <span>API URL</span>
                        <input v-model="form.api_url" type="url" placeholder="https://api.example.com" :disabled="isAzureSqlDriver">
                    </label>
                    <label class="check full">
                        <input v-model="form.active" type="checkbox">
                        <span>Plataforma activa</span>
                    </label>
                </div>
            </section>

            <section class="block">
                <header>
                    <h2>Configuración avanzada</h2>
                    <p>Parámetros no sensibles guardados en platforms.settings</p>
                </header>
                <div class="grid one">
                    <label>
                        <span>Settings JSON</span>
                        <textarea
                            v-model="form.settings_text"
                            rows="12"
                            spellcheck="false"
                            placeholder='{"odoo":{"catalogs":{"taxes":{}},"defaults":{"company_id":1}}}'
                        />
                    </label>
                    <p v-if="settingsJsonError" class="field-error">{{ settingsJsonError }}</p>
                    <p class="helper">
                        Use este campo para catálogos, defaults y opciones por plataforma. No guarde secretos aquí; las credenciales van en el bloque de credenciales.
                    </p>
                </div>
            </section>

            <footer class="bottom-actions">
                <Link class="secondary link" href="/admin/platforms">Cancelar</Link>
                <button type="submit" class="primary" :disabled="form.processing">Guardar plataforma</button>
            </footer>
        </form>
    </AdminLayout>
</template>

<style scoped>
.platform-page{
    display:grid;
    gap:16px;
}

.head-actions{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.subtitle{
    margin:0;
    color:#64748b;
    font-size:16px;
}

.actions{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}

.warning{
    color:#f97316;
    font-size:13px;
}

.field-group{
    display:grid;
    gap:8px;
}

.inline-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.helper{
    margin:0;
    color:#64748b;
    font-size:12px;
}

.field-error{
    margin:0;
    color:#dc2626;
    font-size:13px;
}

.block{
    background:#ffffff;
    border:1px solid #dbe4ef;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 8px 20px rgba(15, 23, 42, 0.05);
}

.block > header{
    padding:16px 20px;
    border-bottom:1px solid #e2e8f0;
}

.block h2{
    margin:0;
    color:#0f172a;
    font-size:24px;
    font-family:'Barlow Condensed', sans-serif;
}

.block p{
    margin:4px 0 0;
    color:#64748b;
    font-size:14px;
}

.grid{
    padding:18px 20px;
    display:grid;
    gap:12px;
}

.grid.one{grid-template-columns:1fr;}
.grid.two{grid-template-columns:repeat(2, minmax(0, 1fr));}

label{
    display:grid;
    gap:6px;
}

label span{
    color:#334155;
    font-size:14px;
    font-weight:500;
}

input,
select,
textarea{
    width:100%;
    border:1px solid #cbd5e1;
    border-radius:8px;
    background:#f8fafc;
    color:#0f172a;
    font-size:14px;
    padding:10px 12px;
}

textarea{resize:vertical;}

input::placeholder,
textarea::placeholder{color:#94a3b8;}

.full{grid-column:1 / -1;}

.check{
    display:flex;
    align-items:center;
    gap:8px;
}

.check input{width:16px;height:16px;}

.token-actions{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
}

.primary,
.secondary{
    border-radius:8px;
    padding:10px 14px;
    font-size:14px;
    cursor:pointer;
    text-decoration:none;
}

.primary{
    border:0;
    background:#1d4ed8;
    color:#f8fafc;
}

.secondary{
    border:1px solid #cbd5e1;
    background:#f8fafc;
    color:#334155;
}

.secondary.danger{
    border-color:#fecaca;
    color:#b91c1c;
    background:#fff7f7;
}

button:disabled{opacity:.55;cursor:not-allowed;}

.bottom-actions{
    display:flex;
    justify-content:flex-end;
    gap:10px;
}

@media (max-width: 980px){
    .subtitle{font-size:15px}
    .grid.two{grid-template-columns:1fr;}
    .block h2{font-size:24px}
    .block p{font-size:14px}
    label span{font-size:14px}
    input,select,textarea{font-size:14px}
}
</style>
