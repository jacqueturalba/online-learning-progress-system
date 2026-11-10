<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';

type Session = { id: number; meeting_id: string; topic: string; join_url: string; registration_url: string | null; scheduled_at: string; duration: number | null; recurrence: Record<string, unknown> | null; meeting_type: number; status: string; registration_enabled: boolean; last_synced_at: string | null };
type Registration = { status: 'pending' | 'registered' | 'failed' | 'cancelled' | 'manual'; join_url: string | null; error: string | null; registered_at: string | null };
type Student = { id: number; name: string; email: string; pseudocode: string; membership_id?: number; registration?: Registration | null };
type StudentPage = { data: Student[]; current_page: number; last_page: number; total: number; per_page: number };
type Batch = { id: number; name: string; description: string | null; status: string; capacity: number; starts_at: string | null; ends_at: string | null; enrolled_count: number; students?: StudentPage; session: Session | null };
type BatchChoice = { id: number; name: string; status: string };
type Host = { id: string; name: string; email: string; type: number };
type AttendanceRow = { id: number; occurrence_uuid: string; occurrence_date: string; pseudocode: string | null; participant_name: string | null; student_name: string | null; registrant_id: string | null; joined_at: string | null; left_at: string | null; duration_seconds: number; match_status: string };
type SyncSummary = { meeting_date: string | null; occurrence_id: string; sync_completed_at: string; participants_detected: number; sync_status: string; duration_seconds: number } | null;
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const batches = ref<Batch[]>([]), batch = ref<Batch | null>(null), allStudents = ref<Student[]>([]), hosts = ref<Host[]>([]);
const batchChoices = ref<BatchChoice[]>([]);
const batchPage = ref(1), batchLastPage = ref(1), batchTotal = ref(0);
const newBatch = reactive({ name: '', description: '', capacity: 30, status: 'draft', starts_at: '', ends_at: '' });
const meeting = reactive({ topic: '', description: '', host_id: '', start_time: '', duration: 60, timezone: 'Asia/Singapore', frequency: 'weekly', repeat_interval: 1, weekly_days: [2], end_date: '', registration_enabled: true });
const selectedStudent = ref(''), selectedIds = ref<number[]>([]), loading = ref(false), saving = ref(false), message = ref(''), error = ref('');
const transferTargets = reactive<Record<number, string>>({});
const matchedAttendance = ref<AttendanceRow[]>([]), unmatchedAttendance = ref<AttendanceRow[]>([]), syncingAttendance = ref(false);
const syncSummary = ref<SyncSummary>(null);
const attendanceFrom = ref(''), attendanceTo = ref('');
const availableStudents = computed(() => allStudents.value.filter(student => !batch.value?.students?.data?.some(member => member.id === student.id)));
const selectedCount = computed(() => selectedIds.value.length);
const allSelected = computed(() => !!batch.value?.students?.data?.length && batch.value.students.data.every(student => selectedIds.value.includes(student.id)));
const rosterPage = computed(() => batch.value?.students?.current_page ?? 1), rosterLastPage = computed(() => batch.value?.students?.last_page ?? 1), rosterTotal = computed(() => batch.value?.students?.total ?? 0);
function payloadDates(form: typeof newBatch) {
  return { ...form, starts_at: form.starts_at || null, ends_at: form.ends_at || null };
}
function attendanceDateLimit() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}
async function responseData(response: Response) {
  const text = await response.text();
  let data: Record<string, any> = {};
  try { data = text ? JSON.parse(text) : {}; } catch { /* Server HTML should not be shown as an error message. */ }
  if (!response.ok) {
    const validation = data.errors && Object.values(data.errors).flat()[0];
    const detail = typeof data.message === 'string' ? data.message : typeof validation === 'string' ? validation : `Request failed (${response.status}).`;
    const zoomStatus = data.zoom_http_status ? ` (Zoom HTTP ${data.zoom_http_status}; ${data.category || 'API error'}. Retrieved ${data.meetings_retrieved ?? 0} meetings/occurrences and ${data.participants_retrieved ?? 0} participants; ${data.matched ?? 0} matched, ${data.unmatched ?? 0} unmatched.)` : '';
    throw new Error(detail + zoomStatus);
  }
  return data;
}
async function request(url: string, method = 'GET', body?: unknown) {
  const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
  return responseData(response);
}
async function load(targetPage = batchPage.value) {
  loading.value = true; error.value = '';
  try {
    const [batchData, studentData] = await Promise.all([request(`/api/admin/zoom/batches?page=${targetPage}`), request('/api/admin/zoom/batches/students')]);
    batches.value = batchData.batches.data; batchChoices.value = batchData.batch_choices || []; batchPage.value = batchData.batches.current_page; batchLastPage.value = batchData.batches.last_page; batchTotal.value = batchData.batches.total; allStudents.value = studentData.students;
    if (batch.value) await openBatch(batch.value.id, batch.value.students?.current_page || 1);
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
async function openBatch(id: number, targetRosterPage = 1) {
  try { const data = await request(`/api/admin/zoom/batches/${id}?page=${targetRosterPage}`); const selected = data.batch as Batch; const changedBatch = batch.value?.id !== id; batch.value = selected; if (changedBatch) selectedIds.value = []; history.replaceState(null, '', `/admin/zoom?batch=${id}&student_page=${selected.students?.current_page || 1}`); if (selected.session) await loadAttendance(); else { matchedAttendance.value = []; unmatchedAttendance.value = []; } }
  catch (caught) { error.value = (caught as Error).message; }
}
async function loadAttendance() {
  if (!batch.value?.session) return;
  const data = await request(`/api/admin/zoom/batches/${batch.value.id}/sessions/${batch.value.session.id}/attendance`);
  matchedAttendance.value = data.matched; unmatchedAttendance.value = data.unmatched;
  syncSummary.value = data.latest_occurrence || null;
}
async function syncAttendance() {
  if (!batch.value?.session) return;
  syncingAttendance.value = true; error.value = ''; message.value = '';
  try {
    const data = await request(`/api/admin/zoom/batches/${batch.value.id}/sessions/${batch.value.session.id}/attendance/sync`, 'POST', {
      from: attendanceFrom.value || null, to: attendanceTo.value || null,
    });
    syncSummary.value = data.latest_occurrence || null;
    message.value = data.message;
    await openBatch(batch.value.id);
  } catch (caught) { const responseError = caught as Error & { status?: number }; error.value = responseError.message; }
  finally { syncingAttendance.value = false; }
}
async function saveBatch() {
  saving.value = true; error.value = ''; message.value = '';
  try {
    const editing = !!batch.value;
    const data = await request(editing ? `/api/admin/zoom/batches/${batch.value!.id}` : '/api/admin/zoom/batches', editing ? 'PUT' : 'POST', payloadDates(newBatch));
    batch.value = data.batch; newBatch.name = ''; message.value = editing ? 'Batch saved.' : 'Batch created.'; await loadBatches();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function loadBatches(targetPage = batchPage.value) {
  const data = await request(`/api/admin/zoom/batches?page=${targetPage}`); batches.value = data.batches.data; batchChoices.value = data.batch_choices || []; batchPage.value = data.batches.current_page; batchLastPage.value = data.batches.last_page; batchTotal.value = data.batches.total;
  if (batch.value) await openBatch(batch.value.id, batch.value.students?.current_page || 1);
}
function startNewBatch() {
  batch.value = null; Object.assign(newBatch, { name: '', description: '', capacity: 30, status: 'draft', starts_at: '', ends_at: '' });
}
function editSelected() {
  if (!batch.value) return;
  Object.assign(newBatch, { name: batch.value.name, description: batch.value.description || '', capacity: batch.value.capacity, status: batch.value.status, starts_at: batch.value.starts_at?.slice(0, 16) || '', ends_at: batch.value.ends_at?.slice(0, 16) || '' });
}
async function addStudent() {
  if (!batch.value || !selectedStudent.value) return;
  saving.value = true; error.value = '';
  try { const data = await request(`/api/admin/zoom/batches/${batch.value.id}/students`, 'POST', { student_id: Number(selectedStudent.value) }); batch.value = data.batch; selectedStudent.value = ''; message.value = 'Student added to this batch.'; await loadBatches(); }
  catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function removeStudent(student: Student) {
  if (!batch.value || !student.membership_id) return;
  try { const data = await request(`/api/admin/zoom/batches/${batch.value.id}/students/${student.membership_id}`, 'DELETE'); batch.value = data.batch; message.value = 'Student removed. Their registration history is preserved.'; await loadBatches(); }
  catch (caught) { error.value = (caught as Error).message; }
}
async function transferStudent(student: Student) {
  if (!batch.value || !student.membership_id || !transferTargets[student.id]) return;
  try { const data = await request(`/api/admin/zoom/batches/${batch.value.id}/students/${student.membership_id}/transfer`, 'POST', { target_batch_id: Number(transferTargets[student.id]) }); batch.value = data.batch; message.value = 'Student transferred. Previous batch and meeting history is preserved.'; await loadBatches(); }
  catch (caught) { error.value = (caught as Error).message; }
}
async function loadHosts() {
  try { const data = await request('/api/admin/zoom/batches/hosts'); hosts.value = data.hosts; if (!meeting.host_id && hosts.value.length) meeting.host_id = hosts.value[0].id; }
  catch (caught) { error.value = (caught as Error).message; }
}
async function createMeeting() {
  if (!batch.value) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const data = await request(`/api/admin/zoom/batches/${batch.value.id}/meeting`, 'POST', meeting);
    batch.value = data.batch; message.value = 'Zoom meeting created and associated with this batch.'; await loadBatches();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function register(ids: number[]) {
  if (!batch.value || !ids.length) return;
  saving.value = true; error.value = ''; message.value = '';
  try {
    const data = await request(`/api/admin/zoom/batches/${batch.value.id}/registrations`, 'POST', { student_ids: ids });
    batch.value = data.batch; const failed = data.results.filter((result: { status: string }) => result.status === 'failed').length; const successful = data.results.filter((result: { status: string }) => result.status === 'registered').length;
    message.value = `Registration results: ${successful} successful, ${failed} failed.`;
    await loadBatches();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function copy(url: string) { try { await navigator.clipboard.writeText(url); message.value = 'Individual join link copied.'; } catch { error.value = 'Could not access the clipboard. Open the link and copy it from the browser address bar.'; } }
function toggleStudent(id: number) { selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter(item => item !== id) : [...selectedIds.value, id]; }
function toggleAllStudents() { selectedIds.value = allSelected.value ? selectedIds.value.filter(id => !batch.value?.students?.data.some(student => student.id === id)) : [...new Set([...selectedIds.value, ...(batch.value?.students?.data || []).map(student => student.id)])]; }
function startMeeting() { if (batch.value?.session) window.open(`/api/admin/zoom/batches/${batch.value.id}/start`, '_blank', 'noopener'); }
function dateLabel(value: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Not set'; }
function durationLabel(seconds: number) { return `${Math.floor(seconds / 60)} min ${seconds % 60} sec`; }
function attendanceGroups(rows: AttendanceRow[]) {
  const groups = new Map<string, { key: string; occurrence: string; pseudocode: string; name: string; total: number; sessions: AttendanceRow[] }>();
  for (const row of rows) {
    const key = `${row.occurrence_uuid}|${row.pseudocode || row.student_name || row.participant_name || row.id}`;
    const group = groups.get(key) || { key, occurrence: row.occurrence_date, pseudocode: row.pseudocode || 'No pseudocode', name: row.student_name || row.participant_name || 'Unknown participant', total: 0, sessions: [] };
    group.total += row.duration_seconds; group.sessions.push(row); groups.set(key, group);
  }
  return [...groups.values()];
}
function recurrenceLabel(value: Record<string, unknown> | null) { if (!value || value.frequency === 'one_time') return 'One-time meeting'; const kind = ({ daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' } as Record<string, string>)[String(value.frequency)] || 'Recurring'; return `${kind} · every ${value.repeat_interval || 1} · ${value.timezone || 'Zoom account timezone'} · ends ${value.end_date_time ? dateLabel(String(value.end_date_time)) : 'not set'}`; }
onMounted(async () => { await load(); await loadHosts(); const params = new URLSearchParams(window.location.search); const batchId = Number(params.get('batch')); if (batchId) await openBatch(batchId, Number(params.get('student_page')) || 1); });
</script>

<template>
  <details class="zoom-assignments-panel zoom-batch-panel zoom-section-collapse" open>
    <summary><strong>Batch management</strong><span>Manage Zoom meetings, attendance sync, and student rosters</span></summary>
    <div class="category-editor-heading"><div><p class="eyebrow">COHORT MANAGEMENT</p><h2>Batches <span class="category-total">{{ batches.length }}</span></h2></div><button class="secondary-button" @click="startNewBatch">Create batch</button></div>
    <p class="zoom-settings-copy">Create a separate Zoom meeting arrangement for each student batch. Zoom manages individual registrations and their private join links.</p>
    <p v-if="message" class="category-notice" role="status">{{ message }}</p><p v-if="error" class="student-error" role="alert">{{ error }}</p>
    <div class="zoom-batch-layout">
      <aside class="zoom-batch-list"><div v-if="loading" class="category-empty-state">Loading batches…</div><button v-for="item in batches" :key="item.id" class="zoom-batch-choice" :class="{ selected: batch?.id === item.id }" @click="openBatch(item.id)"><strong>{{ item.name }}</strong><span>{{ item.enrolled_count }}/{{ item.capacity }} students · {{ item.status }}</span><small>{{ item.session ? `Meeting ${item.session.meeting_id}` : 'No Zoom meeting yet' }}</small></button><div v-if="!loading && !batches.length" class="category-empty-state">No batches created yet.</div><div v-if="batchLastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="batchPage <= 1 || loading" @click="load(batchPage - 1)">Previous</button><span>Page {{ batchPage }} of {{ batchLastPage }} · {{ batchTotal }} batches</span><button class="secondary-button" :disabled="batchPage >= batchLastPage || loading" @click="load(batchPage + 1)">Next</button></div></aside>
      <div class="zoom-batch-content">
        <form v-if="!batch || newBatch.name" class="zoom-batch-form" @submit.prevent="saveBatch"><div class="category-editor-heading"><div><p class="eyebrow">{{ batch ? 'EDIT BATCH' : 'NEW BATCH' }}</p><h3>{{ batch ? 'Batch details' : 'Create a batch' }}</h3></div><button v-if="batch" type="button" class="category-action" @click="newBatch.name = ''">Close</button></div>
          <div class="zoom-batch-fields"><label>Batch name<input v-model.trim="newBatch.name" maxlength="180" required></label><label>Capacity (Zoom limit: 4,999 registrants)<input v-model.number="newBatch.capacity" type="number" min="1" max="4999" required></label><label>Status<select v-model="newBatch.status"><option value="draft">Draft</option><option value="active">Active</option><option value="completed">Completed</option><option value="archived">Archived</option></select></label><label>Starts<input v-model="newBatch.starts_at" type="datetime-local"></label><label>Ends<input v-model="newBatch.ends_at" type="datetime-local"></label><label class="wide-field">Description<textarea v-model="newBatch.description" rows="2"></textarea></label></div><div class="zoom-batch-actions"><button class="primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save batch' }}</button><button v-if="batch" type="button" class="secondary-button" @click="newBatch.name = ''">Cancel</button></div>
        </form>
        <template v-if="batch && !newBatch.name">
          <div class="zoom-batch-title"><div><p class="eyebrow">{{ batch.status.toUpperCase() }} · {{ batch.enrolled_count }} / {{ batch.capacity }} STUDENTS</p><h3>{{ batch.name }}</h3><p>{{ batch.description || 'No description.' }} · {{ dateLabel(batch.starts_at) }} → {{ dateLabel(batch.ends_at) }}</p></div><button class="category-action" @click="editSelected">Edit batch</button></div>
          <section class="zoom-meeting-details"><div class="category-editor-heading"><div><p class="eyebrow">ZOOM MEETING</p><h3>{{ batch.session?.topic || 'No meeting assigned' }}</h3></div><span class="category-state" :class="batch.session?.registration_enabled ? 'available' : 'unavailable'">{{ batch.session?.registration_enabled ? 'Registration on' : 'Not configured' }}</span></div>
            <template v-if="batch.session"><dl class="zoom-session-facts"><div><dt>Meeting ID</dt><dd>{{ batch.session.meeting_id }}</dd></div><div><dt>Schedule</dt><dd>{{ dateLabel(batch.session.scheduled_at) }}</dd></div><div><dt>Pattern</dt><dd>{{ recurrenceLabel(batch.session.recurrence) }}</dd></div><div><dt>Status</dt><dd>{{ batch.session.status }}</dd></div></dl><button type="button" class="secondary-button" @click="startMeeting">Start Zoom Meeting</button><a :href="batch.session.join_url" target="_blank" rel="noopener noreferrer">Main meeting details <span aria-hidden="true">↗</span></a><span v-if="batch.session.registration_url"> · <a :href="batch.session.registration_url" target="_blank" rel="noopener noreferrer">Registration page</a></span></template>
            <form class="zoom-meeting-form" @submit.prevent="createMeeting"><h4>Create Zoom Meeting</h4><div class="zoom-batch-fields"><label>Topic<input v-model.trim="meeting.topic" required maxlength="200"></label><label>Host<select v-model="meeting.host_id" required><option disabled value="">Choose Zoom host</option><option v-for="host in hosts" :key="host.id" :value="host.id">{{ host.name }} · {{ host.email }}</option></select></label><label>Starts<input v-model="meeting.start_time" type="datetime-local" required></label><label>Duration (minutes)<input v-model.number="meeting.duration" type="number" min="1" max="1440" required></label><label>Time zone<input v-model="meeting.timezone" required placeholder="Asia/Singapore"></label><label>Recurrence<select v-model="meeting.frequency"><option value="one_time">One time</option><option value="daily">Daily fixed time</option><option value="weekly">Weekly fixed time</option><option value="monthly">Monthly fixed time</option></select></label><label v-if="meeting.frequency !== 'one_time'">Repeat interval<input v-model.number="meeting.repeat_interval" type="number" min="1" max="50"></label><label v-if="meeting.frequency !== 'one_time'">Recurrence end date<input v-model="meeting.end_date" type="date" required></label><label v-if="meeting.frequency === 'weekly'" class="wide-field">Weekdays <span class="zoom-weekdays"><label v-for="day in [{n:1,l:'Sun'},{n:2,l:'Mon'},{n:3,l:'Tue'},{n:4,l:'Wed'},{n:5,l:'Thu'},{n:6,l:'Fri'},{n:7,l:'Sat'}]" :key="day.n"><input v-model="meeting.weekly_days" type="checkbox" :value="day.n">{{ day.l }}</label></span></label><label class="wide-field">Description<textarea v-model="meeting.description" rows="2"></textarea></label><p class="zoom-settings-copy wide-field">Registration is enabled. Recurring meetings use Zoom fixed-time type 8, which supports one registration for all occurrences. A no-fixed-time recurring meeting is not used.</p></div><button class="primary" :disabled="saving || !hosts.length">{{ saving ? 'Creating…' : 'Create Zoom meeting' }}</button></form>
          </section>
          <section v-if="batch.session" class="zoom-roster zoom-attendance-panel"><div class="category-editor-heading"><div><p class="eyebrow">MEETING REPORTS</p><h3>Attendance sync</h3></div></div><div class="zoom-batch-fields"><label>Attendance from<input v-model="attendanceFrom" type="date" :max="attendanceDateLimit()"></label><label>Attendance to<input v-model="attendanceTo" type="date" :min="attendanceFrom || undefined" :max="attendanceDateLimit()"></label><button class="primary" :disabled="syncingAttendance" @click="syncAttendance">{{ syncingAttendance ? 'Syncing…' : 'Sync attendance' }}</button><small class="wide-field">Dates use the application timezone (Asia/Manila). Leave blank to scan the available meeting history through today.</small></div><div v-if="syncSummary" class="attendance-sync-summary"><h4>Latest occurrence sync</h4><dl><div><dt>Meeting date and start time</dt><dd>{{ dateLabel(syncSummary.meeting_date) }}</dd></div><div><dt>Occurrence ID</dt><dd>{{ syncSummary.occurrence_id || 'Not provided' }}</dd></div><div><dt>Sync completed</dt><dd>{{ dateLabel(syncSummary.sync_completed_at) }}</dd></div><div><dt>Participants detected</dt><dd>{{ syncSummary.participants_detected }}</dd></div><div><dt>Duration</dt><dd>{{ durationLabel(syncSummary.duration_seconds) }}</dd></div><div><dt>Sync status</dt><dd>{{ syncSummary.sync_status }}</dd></div></dl></div><small v-else-if="batch.session.last_synced_at">Last sync: {{ dateLabel(batch.session.last_synced_at) }}. Sync to view the latest occurrence summary.</small></section>
          <section class="zoom-roster"><div class="category-editor-heading"><div><p class="eyebrow">BATCH ROSTER</p><h3>Students</h3><small>{{ selectedCount }} selected</small></div><div class="zoom-roster-bulk"><label><input type="checkbox" :checked="allSelected" @change="toggleAllStudents"> Select page</label><button class="primary" :disabled="saving || !selectedCount || !batch.session" @click="register(selectedIds)">{{ saving ? 'Working…' : `Register selected (${selectedCount})` }}</button></div></div>
            <div class="zoom-add-student"><select v-model="selectedStudent"><option value="">Choose a student to add</option><option v-for="student in availableStudents" :key="student.id" :value="String(student.id)">{{ student.name }} · {{ student.pseudocode }}</option></select><button class="secondary-button" :disabled="saving || !selectedStudent" @click="addStudent">Add student</button></div>
            <div v-if="!batch.students?.data?.length" class="category-empty-state">No students enrolled in this batch.</div><div v-for="student in batch.students?.data || []" :key="student.id" class="zoom-roster-row"><input type="checkbox" :checked="selectedIds.includes(student.id)" :aria-label="`Select ${student.name}`" @change="toggleStudent(student.id)"><div class="zoom-student-identity"><strong>{{ student.name }}</strong><span>{{ student.pseudocode }} · {{ student.email }}</span></div><span class="category-state" :class="student.registration?.status === 'registered' ? 'available' : student.registration?.status === 'failed' ? 'unavailable' : 'archived'">{{ student.registration?.status || 'pending' }}</span><div class="zoom-registration-actions"><template v-if="student.registration?.status === 'registered' && student.registration.join_url"><button class="category-action" @click="copy(student.registration.join_url!)">Copy link</button><a :href="student.registration.join_url" target="_blank" rel="noopener noreferrer">View</a></template><button v-else-if="student.registration?.status === 'failed'" class="category-action" :disabled="saving || !batch.session" @click="register([student.id])">Retry</button><span v-if="student.registration?.error" class="zoom-registration-error">{{ student.registration.error }}</span><select v-if="batchChoices.some(item => item.id !== batch?.id)" v-model="transferTargets[student.id]" aria-label="Transfer student to"><option value="">Transfer to…</option><option v-for="item in batchChoices.filter(item => item.id !== batch?.id && item.status !== 'archived')" :key="item.id" :value="String(item.id)">{{ item.name }}</option></select><button v-if="transferTargets[student.id]" class="category-action" @click="transferStudent(student)">Transfer</button><button class="category-action remove" @click="removeStudent(student)">Remove</button></div></div>
            <div v-if="rosterLastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="rosterPage <= 1 || loading" @click="openBatch(batch.id, rosterPage - 1)">Previous</button><span>Page {{ rosterPage }} of {{ rosterLastPage }} · {{ rosterTotal }} students</span><button class="secondary-button" :disabled="rosterPage >= rosterLastPage || loading" @click="openBatch(batch.id, rosterPage + 1)">Next</button></div>
          </section>
        </template>
        <div v-else-if="!batches.length && !loading" class="category-empty-state">Create a batch to manage its meeting and student roster.</div>
      </div>
    </div>
  </details>
</template>
