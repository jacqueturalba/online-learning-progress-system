<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';

type Provider = { key: string; name: string; capabilities: string[]; credential_fields: { key: string; label: string; secret: boolean }[]; models: Record<string, string[]> };
type Integration = { id: number; provider: string; label: string; endpoint: string | null; is_enabled: boolean; credential_fields: { key: string; configured: boolean; masked: string | null }[]; last_tested_at: string | null; last_test_status: string | null; assignments: { service: string; model: string }[] };
type Assignment = { service: string; model: string; target_language: string | null; integration_id: number; integration_label: string | null; provider: string | null; is_available: boolean };
const providers = ref<Provider[]>([]), integrations = ref<Integration[]>([]), assignments = ref<Assignment[]>([]);
const selectedProvider = computed(() => providers.value.find(provider => provider.key === form.provider) ?? null);
const loading = ref(false), saving = ref(false), testingId = ref<number | null>(null), busyId = ref<number | null>(null), error = ref(''), notice = ref('');
const form = reactive<{ id: number | null; provider: string; label: string; endpoint: string; credentials: Record<string, string> }>({ id: null, provider: 'openai', label: '', endpoint: '', credentials: {} });
const serviceForms = reactive<Record<string, { integration_id: string; model: string; target_language: string }>>({
  transcription: { integration_id: '', model: '', target_language: '' },
  translation: { integration_id: '', model: '', target_language: 'Filipino' },
});
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

async function call(url: string, method = 'GET', body?: unknown) {
  const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'The request could not be completed.');
  return data;
}
async function load() {
  loading.value = true; error.value = '';
  try {
    const data = await call('/api/admin/ai-integrations');
    providers.value = data.providers; integrations.value = data.integrations; assignments.value = data.assignments;
    for (const service of ['transcription', 'translation']) {
      const assignment = assignments.value.find(item => item.service === service);
      serviceForms[service] = { integration_id: assignment ? String(assignment.integration_id) : '', model: assignment?.model || '', target_language: assignment?.target_language || (service === 'translation' ? 'Filipino' : '') };
    }
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function resetForm() { form.id = null; form.provider = providers.value[0]?.key || 'openai'; form.label = ''; form.endpoint = ''; form.credentials = {}; }
function edit(integration: Integration) { form.id = integration.id; form.provider = integration.provider; form.label = integration.label; form.endpoint = integration.endpoint || ''; form.credentials = {}; error.value = ''; notice.value = ''; }
async function save() {
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const url = form.id ? `/api/admin/ai-integrations/${form.id}` : '/api/admin/ai-integrations';
    await call(url, form.id ? 'PUT' : 'POST', { provider: form.provider, label: form.label, endpoint: form.endpoint || null, credentials: form.credentials });
    notice.value = form.id ? 'Integration settings saved.' : 'Provider account added. Enable it after testing the connection.';
    resetForm(); await load();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function testConnection(integration: Integration) {
  testingId.value = integration.id; error.value = ''; notice.value = '';
  try { const result = await call(`/api/admin/ai-integrations/${integration.id}/test`, 'POST', {}); notice.value = result.message; await load(); }
  catch (caught) { error.value = (caught as Error).message; await load(); }
  finally { testingId.value = null; }
}
async function setEnabled(integration: Integration) {
  busyId.value = integration.id; error.value = ''; notice.value = '';
  try { const result = await call(`/api/admin/ai-integrations/${integration.id}/status`, 'PATCH', { is_enabled: !integration.is_enabled }); notice.value = result.message; await load(); }
  catch (caught) { error.value = (caught as Error).message; }
  finally { busyId.value = null; }
}
async function remove(integration: Integration) {
  if (!window.confirm(`Remove ${integration.label}? This is only allowed after service assignments and active jobs are cleared.`)) return;
  busyId.value = integration.id; error.value = ''; notice.value = '';
  try { const result = await call(`/api/admin/ai-integrations/${integration.id}`, 'DELETE'); notice.value = result.message; if (form.id === integration.id) resetForm(); await load(); }
  catch (caught) { error.value = (caught as Error).message; }
  finally { busyId.value = null; }
}
const eligibleIntegrations = (service: string) => integrations.value.filter(integration => integration.is_enabled && providers.value.find(provider => provider.key === integration.provider)?.capabilities.includes(service));
const selectedServiceProvider = (service: string) => providers.value.find(provider => provider.key === integrations.value.find(item => String(item.id) === serviceForms[service].integration_id)?.provider);
async function saveAssignment(service: string) {
  error.value = ''; notice.value = '';
  try {
    const data: Record<string, string | number> = { integration_id: Number(serviceForms[service].integration_id), model: serviceForms[service].model };
    if (service === 'translation') data.target_language = serviceForms[service].target_language;
    const result = await call(`/api/admin/ai-services/${service}`, 'PUT', data); notice.value = result.message; await load();
  } catch (caught) { error.value = (caught as Error).message; }
}
function serviceChanged(service: string) { serviceForms[service].model = ''; }
onMounted(() => void load());
</script>

<template>
  <section class="ai-settings" aria-labelledby="ai-settings-title">
    <header class="ai-settings-heading"><div><p class="eyebrow">INTEGRATIONS</p><h2 id="ai-settings-title">AI provider accounts</h2><p>Connect provider accounts and select which account and model handles each AI service.</p></div><button class="secondary-button" @click="resetForm">Add account</button></header>
    <p v-if="error" class="student-error" role="alert">{{ error }}</p><p v-if="notice" class="student-success" role="status">{{ notice }}</p>
    <div class="ai-settings-grid">
      <form class="ai-integration-form" @submit.prevent="save">
        <h3>{{ form.id ? 'Edit provider account' : 'Add provider account' }}</h3>
        <label>Provider<select v-model="form.provider" :disabled="Boolean(form.id)" required><option v-for="provider in providers" :key="provider.key" :value="provider.key">{{ provider.name }}</option></select></label>
        <label>Account label<input v-model="form.label" maxlength="120" placeholder="Production account" required></label>
        <label>API endpoint <span class="ai-optional">Optional; uses provider default when blank</span><input v-model="form.endpoint" type="url" maxlength="2048" placeholder="https://api.openai.com/v1"></label>
        <label v-for="field in selectedProvider?.credential_fields || []" :key="field.key">{{ field.label }}<input v-model="form.credentials[field.key]" type="password" autocomplete="new-password" :placeholder="form.id ? 'Leave blank to keep the saved credential' : 'Enter provider API key'" :required="!form.id"></label>
        <p class="ai-help">Credentials are encrypted on the server. Saved keys are never returned; enter a new key only when replacing one.</p>
        <div class="ai-form-actions"><button class="primary" :disabled="saving || loading">{{ saving ? 'Saving…' : form.id ? 'Save changes' : 'Add account' }}</button><button v-if="form.id" type="button" class="secondary-button" @click="resetForm">Cancel edit</button></div>
      </form>

      <section class="ai-integrations-list" aria-label="Configured provider accounts">
        <h3>Configured accounts</h3><p v-if="loading">Loading provider accounts…</p><p v-else-if="!integrations.length" class="ai-empty">No AI provider accounts are configured.</p>
        <article v-for="integration in integrations" :key="integration.id" class="ai-account-card">
          <div class="ai-account-heading"><div><strong>{{ integration.label }}</strong><span>{{ providers.find(provider => provider.key === integration.provider)?.name || integration.provider }}</span></div><span class="ai-status" :class="integration.is_enabled ? 'enabled' : 'disabled'">{{ integration.is_enabled ? 'Enabled' : 'Disabled' }}</span></div>
          <p class="ai-endpoint">{{ integration.endpoint || 'Provider default endpoint' }}</p>
          <p class="ai-key-state">{{ integration.credential_fields.some(field => field.configured) ? 'API key saved · ••••••••' : 'No API key saved' }}</p>
          <small v-if="integration.last_tested_at">Last connection test: {{ integration.last_test_status }} · {{ new Date(integration.last_tested_at).toLocaleString() }}</small>
          <div class="ai-account-actions"><button class="secondary-button" :disabled="testingId === integration.id" @click="testConnection(integration)">{{ testingId === integration.id ? 'Testing…' : 'Test connection' }}</button><button class="secondary-button" @click="edit(integration)">Edit</button><button class="secondary-button" :disabled="busyId === integration.id" @click="setEnabled(integration)">{{ integration.is_enabled ? 'Disable' : 'Enable' }}</button><button class="danger-button" :disabled="busyId === integration.id" @click="remove(integration)">Remove</button></div>
        </article>
      </section>
    </div>

    <section class="ai-service-assignments" aria-labelledby="ai-service-title"><h3 id="ai-service-title">Service assignments</h3><p>Assign an enabled account and a model supported for that capability.</p>
      <form v-for="service in ['transcription', 'translation']" :key="service" class="ai-assignment-row" @submit.prevent="saveAssignment(service)">
        <div><strong>{{ service === 'transcription' ? 'Speech transcription' : 'Text translation' }}</strong><small>{{ assignments.find(item => item.service === service)?.is_available ? 'Currently assigned' : 'Not assigned or assigned account is unavailable' }}</small></div>
        <label>Provider account<select v-model="serviceForms[service].integration_id" required @change="serviceChanged(service)"><option value="">Choose account</option><option v-for="integration in eligibleIntegrations(service)" :key="integration.id" :value="String(integration.id)">{{ integration.label }} · {{ integration.provider }}</option></select></label>
        <label>Model<select v-model="serviceForms[service].model" required :disabled="!serviceForms[service].integration_id"><option value="">Choose supported model</option><option v-for="model in selectedServiceProvider(service)?.models[service] || []" :key="model" :value="model">{{ model }}</option></select></label>
        <label v-if="service === 'translation'">Target language<input v-model="serviceForms[service].target_language" maxlength="35" placeholder="Filipino" required></label>
        <button class="primary" :disabled="!serviceForms[service].integration_id">Save assignment</button>
      </form>
    </section>
  </section>
</template>

<style scoped>
.ai-settings{display:grid;gap:18px}.ai-settings-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.ai-settings-heading h2{margin:0;color:var(--text-primary);font-size:21px}.ai-settings-heading p:not(.eyebrow),.ai-service-assignments>p{color:var(--text-secondary);font-size:13px;line-height:1.55}.ai-settings-grid{display:grid;grid-template-columns:minmax(250px,.85fr) minmax(0,1.15fr);gap:16px;align-items:start}.ai-integration-form,.ai-integrations-list,.ai-service-assignments{display:grid;gap:12px;padding:18px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}.ai-integration-form h3,.ai-integrations-list h3,.ai-service-assignments h3{margin:0;color:var(--text-primary);font-size:16px}.ai-integration-form label,.ai-assignment-row label{display:grid;gap:6px;color:var(--text-primary);font-size:12px;font-weight:600}.ai-integration-form input,.ai-integration-form select,.ai-assignment-row input,.ai-assignment-row select{width:100%;min-height:38px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text-primary);font:inherit}.ai-optional,.ai-help,.ai-integrations-list>p,.ai-account-card small,.ai-assignment-row small{color:var(--text-secondary);font-size:11px;font-weight:400;line-height:1.55}.ai-form-actions,.ai-account-actions{display:flex;flex-wrap:wrap;gap:8px}.ai-account-card{display:grid;gap:7px;padding:13px;border:1px solid var(--border);border-radius:8px}.ai-account-heading{display:flex;justify-content:space-between;gap:12px;align-items:center}.ai-account-heading>div{display:grid;gap:2px}.ai-account-heading strong{color:var(--text-primary);font-size:14px}.ai-account-heading span,.ai-endpoint,.ai-key-state{color:var(--text-secondary);font-size:11px}.ai-endpoint,.ai-key-state{margin:0;overflow-wrap:anywhere}.ai-status{padding:4px 8px;border-radius:20px;font-size:11px}.ai-status.enabled{background:#e7f5ee;color:#08774d}.ai-status.disabled{background:#edf1f4;color:#52616d}.ai-account-actions button{min-height:32px;padding:6px 9px;font-size:11px}.danger-button{border:1px solid #e4b8b0;border-radius:6px;background:#fff7f5;color:#922f21;font-weight:600;cursor:pointer}.ai-empty{padding:12px;border:1px dashed var(--border);border-radius:8px}.ai-service-assignments{gap:10px}.ai-service-assignments>p{margin:0}.ai-assignment-row{display:grid;grid-template-columns:minmax(120px,1fr) repeat(2,minmax(150px,1.1fr)) minmax(140px,1fr) auto;align-items:end;gap:10px;padding:13px 0;border-top:1px solid var(--border)}.ai-assignment-row>div{display:grid;gap:4px;align-self:center}.ai-assignment-row>div strong{font-size:13px;color:var(--text-primary)}.ai-assignment-row button{min-height:38px;white-space:nowrap}.settings-tabs{display:flex;gap:8px;margin-bottom:14px;border-bottom:1px solid var(--border)}.settings-tabs button{padding:10px 14px;border:0;border-bottom:2px solid transparent;background:transparent;color:var(--text-secondary);font:inherit;cursor:pointer}.settings-tabs button.active{border-color:var(--brand-blue);color:var(--brand-blue);font-weight:700}
@media(max-width:960px){.ai-settings-grid{grid-template-columns:1fr}.ai-assignment-row{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-assignment-row>div{grid-column:1/-1}}
@media(max-width:600px){.ai-settings-heading{display:grid}.ai-settings-heading button{width:100%}.ai-integration-form,.ai-integrations-list,.ai-service-assignments{padding:14px}.ai-assignment-row{grid-template-columns:1fr}.ai-assignment-row>div{grid-column:auto}.ai-assignment-row button{width:100%}.ai-account-actions>*{flex:1}}
</style>
