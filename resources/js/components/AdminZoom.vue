<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

type Assignment = { zoom_url: string; is_active: boolean; updated_at: string } | null;
type StudentRow = { id: number; name: string; pseudocode: string; zoom_assignment: Assignment };
type EditRow = { zoom_url: string; is_active: boolean };
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const credentialsConfigured = ref(false), credentialsUpdatedAt = ref<string | null>(null);
const students = ref<StudentRow[]>([]), edits = reactive<Record<number, EditRow>>({});
const search = ref(''), status = ref('all'), page = ref(1), lastPage = ref(1), total = ref(0);
const loading = ref(false), savingCredentials = ref(false), savingStudent = ref<number | null>(null), error = ref(''), notice = ref('');
async function readResponse(response: Response) {
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'The request could not be completed.');
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
    for (const student of students.value) edits[student.id] = { zoom_url: student.zoom_assignment?.zoom_url || '', is_active: student.zoom_assignment?.is_active ?? true };
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
async function saveAssignment(student: StudentRow) {
  savingStudent.value = student.id; error.value = ''; notice.value = '';
  try {
    const response = await fetch(`/api/admin/zoom/assignments/${student.id}`, {
      method: 'PUT', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(edits[student.id]),
    });
    await readResponse(response);
    notice.value = `Zoom assignment saved for ${student.name}.`;
    await load();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { savingStudent.value = null; }
}
function filterChanged() { page.value = 1; void load(); }
let searchTimer: ReturnType<typeof setTimeout>;
function searchChanged() { clearTimeout(searchTimer); page.value = 1; searchTimer = setTimeout(load, 250); }
function formatDate(value: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value)) : 'Not configured'; }
onMounted(() => { void load(); });
</script>

<template>
  <div class="admin-zoom-page">
    <section class="zoom-settings-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">ZOOM INTEGRATION</p><h2>Zoom settings</h2></div><span class="category-state" :class="credentialsConfigured ? 'available' : 'unavailable'">{{ credentialsConfigured ? 'Configured' : 'Not configured' }}</span></div>
      <p class="zoom-settings-copy">Add the Zoom Server-to-Server OAuth account credentials. Values are encrypted on the server and are never returned to this page. Leave fields blank when updating to keep their current values.</p>
      <form class="zoom-credentials-form" @submit.prevent="saveCredentials">
        <label>Account ID<input name="account_id" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom account ID'"></label>
        <label>Client ID<input name="client_id" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom client ID'"></label>
        <label>Client secret<input name="client_secret" type="password" autocomplete="new-password" maxlength="255" :required="!credentialsConfigured" :placeholder="credentialsConfigured ? 'Leave blank to keep current value' : 'Zoom client secret'"></label>
        <div class="zoom-credentials-footer"><span>Last updated: {{ formatDate(credentialsUpdatedAt) }}</span><button class="primary" :disabled="savingCredentials">{{ savingCredentials ? 'Saving…' : credentialsConfigured ? 'Update credentials' : 'Save credentials' }}</button></div>
      </form>
    </section>

    <section class="zoom-assignments-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">STUDENT ACCESS</p><h2>Zoom assignments <span class="category-total">{{ total }}</span></h2></div></div>
      <p v-if="notice" class="category-notice" role="status">{{ notice }}</p><p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div class="zoom-assignment-filters"><label>Search students<input v-model="search" type="search" placeholder="Name or pseudocode" @input="searchChanged"></label><label>Assignment status<select v-model="status" @change="filterChanged"><option value="all">All students</option><option value="assigned">Assigned</option><option value="unassigned">Unassigned</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label></div>
      <div v-if="loading" class="category-empty-state">Loading student assignments…</div><div v-else-if="!students.length" class="category-empty-state">No students match these filters.</div>
      <div v-else class="zoom-assignment-list">
        <article v-for="student in students" :key="student.id" class="zoom-assignment-card">
          <div class="zoom-student-identity"><strong>{{ student.name }}</strong><span>{{ student.pseudocode }}</span></div>
          <label class="zoom-link-field">Zoom link<input v-model.trim="edits[student.id].zoom_url" type="url" placeholder="https://zoom.us/j/…"></label>
          <label class="zoom-status-field">Status<select v-model="edits[student.id].is_active"><option :value="true">Active</option><option :value="false">Inactive</option></select><small v-if="student.zoom_assignment">Updated {{ formatDate(student.zoom_assignment.updated_at) }}</small><small v-else>Not assigned yet</small></label>
          <div class="zoom-assignment-card-actions"><a v-if="student.zoom_assignment" :href="student.zoom_assignment.zoom_url" target="_blank" rel="noopener noreferrer">Open current link</a><button class="primary" :disabled="savingStudent === student.id">{{ savingStudent === student.id ? 'Saving…' : student.zoom_assignment ? 'Save assignment' : 'Assign Zoom link' }}</button><span class="category-state" :class="student.zoom_assignment ? student.zoom_assignment.is_active ? 'available' : 'unavailable' : 'archived'">{{ student.zoom_assignment ? student.zoom_assignment.is_active ? 'Active' : 'Inactive' : 'Unassigned' }}</span></div>
        </article>
      </div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage" @click="page++; load()">Next</button></div>
    </section>
  </div>
</template>
