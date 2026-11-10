<script setup lang="ts">
import { onMounted, ref } from 'vue';
import AdminZoomBatches from './AdminZoomBatches.vue';
defineProps<{ canManageIntegration: boolean }>();

type Assignment = { zoom_url: string; is_active: boolean; updated_at: string } | null;
type StudentRow = { id: number; name: string; pseudocode: string; zoom_assignment: Assignment; active_batch: { id: number; name: string; session: { id: number; topic: string; scheduled_at: string; recurrence: Record<string, unknown> | null } | null; registration_status: string; join_url: string | null; registration_error: string | null } | null };
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const credentialsConfigured = ref(false), credentialsUpdatedAt = ref<string | null>(null);
const students = ref<StudentRow[]>([]);
const search = ref(''), status = ref('all'), page = ref(1), lastPage = ref(1), total = ref(0);
const loading = ref(false), savingCredentials = ref(false), error = ref(''), notice = ref('');
const testingConnection = ref(false);
const refreshingToken = ref(false);
const diagnostic = ref<Record<string, any> | null>(null);
async function readResponse(response: Response) {
  const text = await response.text();
  let data: Record<string, any> = {};
  try { data = text ? JSON.parse(text) : {}; } catch { /* Keep server HTML out of the interface. */ }
  if (!response.ok) throw new Error(typeof data.message === 'string' ? data.message : `The request could not be completed (${response.status}).`);
  return data;
}
async function load() {
  loading.value = true; error.value = '';
  try {
    const query = new URLSearchParams({ page: String(page.value), status: status.value });
    if (search.value.trim()) query.set('search', search.value.trim());
    const response = await fetch(`/api/admin/zoom?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await readResponse(response);
    credentialsConfigured.value = data.credentials.configured;
    credentialsUpdatedAt.value = data.credentials.updated_at;
    students.value = data.students.data;
    total.value = data.students.total;
    page.value = data.students.current_page;
    lastPage.value = data.students.last_page;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
async function saveCredentials(event: Event) {
  const form = event.currentTarget as HTMLFormElement;
  savingCredentials.value = true; error.value = ''; notice.value = '';
  try {
    // Keep credential values in the native form only; do not bind them to Vue state or read them back.
    const response = await fetch('/api/admin/zoom/credentials', {
      method: 'POST', credentials: 'same-origin', body: new FormData(form),
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    const data = await readResponse(response);
    credentialsConfigured.value = data.credentials.configured;
    credentialsUpdatedAt.value = data.credentials.updated_at;
    form.reset();
    notice.value = data.message;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { savingCredentials.value = false; }
}
async function deleteCredentials() {
  savingCredentials.value = true; error.value = ''; notice.value = '';
  try {
    const response = await fetch('/api/admin/zoom/credentials', { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
    const data = await readResponse(response); credentialsConfigured.value = false; credentialsUpdatedAt.value = null; notice.value = data.message;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { savingCredentials.value = false; }
}
async function testConnection() {
  testingConnection.value = true; error.value = ''; notice.value = ''; diagnostic.value = null;
  try {
    const response = await fetch('/api/admin/zoom/diagnostics', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
    const data = await response.json().catch(() => ({}));
    diagnostic.value = data;
    if (!response.ok && !data.error) error.value = data.message || `Diagnostics failed (${response.status}).`;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { testingConnection.value = false; }
}
async function refreshToken() {
  refreshingToken.value = true; error.value = ''; notice.value = ''; diagnostic.value = null;
  try {
    const response = await fetch('/api/admin/zoom/refresh-token', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(`${data.message || 'Token refresh failed.'}${data.http_status ? ` (Zoom HTTP ${data.http_status})` : ''}`);
    notice.value = data.message;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { refreshingToken.value = false; }
}
function filterChanged() { page.value = 1; void load(); }
let searchTimer: ReturnType<typeof setTimeout>;
function searchChanged() { clearTimeout(searchTimer); page.value = 1; searchTimer = setTimeout(load, 250); }
function formatDate(value: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : 'Not configured'; }
function recurrenceName(value: Record<string, unknown> | null) { return ({ daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' } as Record<string, string>)[String(value?.frequency)] || 'One-time'; }
onMounted(() => { void load(); });
</script>

<template>
  <div class="admin-zoom-page">
    <details v-if="canManageIntegration" class="zoom-settings-panel zoom-section-collapse">
      <summary><strong>Zoom integration</strong><span>Credentials, connection checks, and integration controls</span></summary>
      <div class="category-editor-heading"><div><p class="eyebrow">ZOOM INTEGRATION</p><h2>Zoom settings</h2></div><span class="category-state" :class="credentialsConfigured ? 'available' : 'unavailable'">{{ credentialsConfigured ? 'Configured' : 'Not configured' }}</span></div>
      <p class="zoom-settings-copy">Add the Zoom Server-to-Server OAuth account credentials. Values are encrypted on the server and are never returned to this page. Leave fields blank when updating to keep their current values.</p>
      <form class="zoom-credentials-form" @submit.prevent="saveCredentials">
        <label>Account ID<input name="account_id" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom account ID'"></label>
        <label>Client ID<input name="client_id" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom client ID'"></label>
        <label>Client secret<input name="client_secret" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom client secret'"></label>
        <div class="zoom-credentials-footer"><span>Last updated: {{ formatDate(credentialsUpdatedAt) }}</span><div><button v-if="credentialsConfigured" type="button" class="secondary-button" :disabled="refreshingToken" @click="refreshToken">{{ refreshingToken ? 'Refreshing…' : 'Refresh OAuth token' }}</button><button v-if="credentialsConfigured" type="button" class="secondary-button" :disabled="testingConnection" @click="testConnection">{{ testingConnection ? 'Checking…' : 'Run diagnostics' }}</button><button v-if="credentialsConfigured" type="button" class="secondary-button" :disabled="savingCredentials" @click="deleteCredentials">Disable Zoom integration</button><button class="primary" :disabled="savingCredentials">{{ savingCredentials ? 'Saving…' : credentialsConfigured ? 'Update credentials' : 'Save credentials' }}</button></div></div>
      </form>
      <div v-if="diagnostic" class="zoom-diagnostic-results" role="status"><h3>Zoom connection diagnostics</h3><p>Credentials: <strong>{{ diagnostic.credentials_configured ? 'Configured' : 'Missing' }}</strong></p><p>Fresh OAuth token: <strong>{{ diagnostic.token_obtained ? 'Obtained' : 'Failed' }}</strong></p><p>Zoom API connection: <strong>{{ diagnostic.api_connected ? `Connected${diagnostic.account ? ` as ${diagnostic.account}` : ''}` : 'Failed' }}</strong></p><p>Required scopes: <strong>Not independently verified</strong> · {{ diagnostic.scope_message }}</p><p v-if="diagnostic.error" class="student-error">{{ diagnostic.error }}<span v-if="diagnostic.http_status"> (HTTP {{ diagnostic.http_status }})</span></p></div>
    </details>

    <AdminZoomBatches />

    <details class="zoom-assignments-panel zoom-section-collapse" open>
      <summary><strong>Student Zoom assignments</strong><span>Review student access and batch links</span></summary>
      <div class="category-editor-heading"><div><p class="eyebrow">STUDENT ACCESS</p><h2>Zoom assignments <span class="category-total">{{ total }}</span></h2></div></div>
      <p v-if="notice" class="category-notice" role="status">{{ notice }}</p><p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div class="zoom-assignment-filters"><label>Search students<input v-model="search" type="search" placeholder="Name or pseudocode" @input="searchChanged"></label><label>Assignment status<select v-model="status" @change="filterChanged"><option value="all">All students</option><option value="batch">Active batch</option><option value="unassigned">No active batch</option><option value="failed">Failed registration</option></select></label></div>
      <div v-if="loading" class="category-empty-state">Loading student assignments…</div><div v-else-if="!students.length" class="category-empty-state">No students match these filters.</div>
      <div v-else class="zoom-assignment-list">
        <article v-for="student in students" :key="student.id" class="zoom-assignment-card">
          <div class="zoom-student-identity"><strong>{{ student.name }}</strong><span>{{ student.pseudocode }}</span></div>
          <div v-if="student.active_batch" class="zoom-assignment-card-actions"><div><strong>{{ student.active_batch.name }}</strong><small>{{ student.active_batch.session?.topic || 'No meeting created' }} · {{ formatDate(student.active_batch.session?.scheduled_at || null) }} · {{ recurrenceName(student.active_batch.session?.recurrence || null) }}</small></div><span class="category-state" :class="student.active_batch.registration_status === 'registered' ? 'available' : student.active_batch.registration_status === 'failed' ? 'unavailable' : 'archived'">{{ student.active_batch.registration_status }}</span><a v-if="student.active_batch.join_url" :href="student.active_batch.join_url" target="_blank" rel="noopener noreferrer">Individual join link ↗</a><small v-if="student.active_batch.registration_error" class="zoom-registration-error">{{ student.active_batch.registration_error }}</small><a :href="`/admin/zoom?batch=${student.active_batch.id}`">Batch details</a></div>
          <div v-else class="zoom-assignment-card-actions"><span class="category-state archived">No active batch</span><span v-if="student.zoom_assignment?.zoom_url">Legacy manual link retained</span></div>
        </article>
      </div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage" @click="page++; load()">Next</button></div>
    </details>
  </div>
</template>
