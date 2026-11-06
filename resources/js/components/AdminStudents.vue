<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Check, ChevronLeft, ChevronRight, Clock3, ExternalLink, Eye, Search, UserRound, X } from '@lucide/vue';

type PaceOption = { value: string; label: string; weekly_target: number };
type StudentRow = {
  id: number; name: string; email: string; pseudocode: string; pace_group: string; weekly_target: number; is_active: boolean; registered_at: string;
  current_category: { id: number; name: string; is_active: boolean; is_archived: boolean } | null;
  progress: { completed_lessons: number; total_lessons: number; percentage: number }; enrollment_status?: string; enrollment_submitted_at?: string|null;
};
type StudentPage = { data: StudentRow[]; current_page: number; last_page: number; total: number };
type StudentDetails = {
  id: number; real_name: string; email: string; pseudocode: string; pace_group: string; weekly_target: number; is_active: boolean; registered_at: string;
  personal_information: { real_name: string; email: string; country?:string|null; address?:string|null; phone_country_code?:string|null; phone_number?:string|null };
  current_category: HistoryCategory | null;
  zoom_batch: { batch_id: number; batch_name: string; topic: string | null; scheduled_at: string | null; recurrence: Record<string, unknown> | null; registration_status: string; join_url: string | null; registration_error: string | null } | null;
  learning_history: HistoryCategory[];
};
type HistoryCategory = {
  id: number; name: string; is_active: boolean; is_archived: boolean; completed_at: string | null; completed_lessons: number; total_lessons: number; progress_percentage: number;
  lessons: { id: number; title: string; is_published: boolean; is_completed: boolean; progress_percentage: number; last_watched_at: string | null }[];
};
type CategoryOption = { id: number; name: string; is_active: boolean; deleted_at: string | null };

const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const students = ref<StudentPage | null>(null), categories = ref<CategoryOption[]>([]), paceGroups = ref<PaceOption[]>([]);
const search = ref(''), categoryId = ref(''), paceGroup = ref('');
const loading = ref(false), error = ref(''), detailLoading = ref(false), detailError = ref(''), selectedStudent = ref<StudentDetails | null>(null),
  savingPace = ref(false), paceNotice = ref('');
const updatingPassword = ref(false), resettingPassword = ref(false), passwordNotice = ref('');
const targetDrafts = ref<Record<string, number>>({}), savingTargets = ref(false), targetNotice = ref(''), targetError = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const currentStudents = computed(() => students.value?.data ?? []);
const selectedIds=ref<number[]>([]), enrollmentSetting=ref({enabled:false,category_id:null as number|null,lesson_number:0}), studentRequirement=ref({enabled:false,category_id:null as number|null,lesson_number:0}), settingNotice=ref(''), settingError=ref(''), reviewForms=ref<any[]>([]), reviewStudentId=ref<number|null>(null), revisionReason=ref('');
const allSelected=computed(()=>currentStudents.value.length>0&&currentStudents.value.every(s=>selectedIds.value.includes(s.id)));
const paceLabel = (value: string) => paceGroups.value.find(option => option.value === value)?.label ?? value;
function csrfHeaders() { return { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }; }

async function loadStudents(page = 1) {
  loading.value = true;
  error.value = '';
  try {
    const params = new URLSearchParams({ page: String(page) });
    if (search.value.trim()) params.set('search', search.value.trim());
    if (categoryId.value) params.set('category_id', categoryId.value);
    if (paceGroup.value) params.set('pace_group', paceGroup.value);
    const response = await fetch(`/api/admin/students?${params}`, { credentials: 'same-origin', headers: csrfHeaders() });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Student list could not be loaded.');
    students.value = body.students;
    categories.value = body.categories;
    paceGroups.value = body.pace_groups;
    targetDrafts.value = Object.fromEntries(paceGroups.value.map(group => [group.value, group.weekly_target]));
    selectedIds.value=[];
  } catch (caught) {
    error.value = (caught as Error).message;
  } finally {
    loading.value = false;
  }
}

async function loadEnrollmentSettings(){try{const r=await fetch('/api/admin/enrollment-settings',{credentials:'same-origin',headers:csrfHeaders()}),b=await r.json();if(b.settings)enrollmentSetting.value={enabled:!!b.settings.enabled,category_id:b.settings.category_id,lesson_number:b.settings.lesson_number};if(!categories.value.length)categories.value=b.categories||[]}catch(e){settingError.value=(e as Error).message}}
async function saveEnrollmentSettings(){settingError.value='';settingNotice.value='';const r=await fetch('/api/admin/enrollment-settings',{method:'PUT',credentials:'same-origin',headers:csrfHeaders(),body:JSON.stringify(enrollmentSetting.value)}),b=await r.json();if(!r.ok){settingError.value=b.message||'Settings could not be saved.';return}settingNotice.value='Enrollment form requirement saved.'}
function toggleAll(){selectedIds.value=allSelected.value?[]:currentStudents.value.map(s=>s.id)}
async function exportSelected(kind:'export'|'print'){if(!selectedIds.value.length)return;const r=await fetch(`/api/admin/students/enrollment/${kind}`,{method:'POST',credentials:'same-origin',headers:csrfHeaders(),body:JSON.stringify({student_ids:selectedIds.value})});if(!r.ok){const b=await r.json();error.value=b.message||'Export could not be generated.';return}if(kind==='export'){const blob=await r.blob(),a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='enrollment-forms.xlsx';a.click();URL.revokeObjectURL(a.href)}else{const html=await r.text(),w=window.open('','_blank');if(w){w.document.write(html);w.document.close()}}}
async function viewEnrollment(id:number){const r=await fetch(`/api/admin/students/${id}/enrollment`,{credentials:'same-origin',headers:csrfHeaders()}),b=await r.json();if(!r.ok){error.value=b.message||'Enrollment form could not be loaded.';return}reviewStudentId.value=id;reviewForms.value=b.forms;studentRequirement.value=b.requirement?{enabled:!!b.requirement.enabled,category_id:b.requirement.category_id,lesson_number:b.requirement.lesson_number}:{...enrollmentSetting.value}}
async function saveStudentRequirement(){if(!reviewStudentId.value)return;const r=await fetch(`/api/admin/students/${reviewStudentId.value}/enrollment-settings`,{method:'PUT',credentials:'same-origin',headers:csrfHeaders(),body:JSON.stringify(studentRequirement.value)}),b=await r.json();if(!r.ok){error.value=b.message||'Student requirement could not be saved.';return}settingNotice.value='Student-specific enrollment requirement saved.'}
async function review(form:any,decision:'approve'|'revision'){if(!reviewStudentId.value)return;const r=await fetch(`/api/admin/students/${reviewStudentId.value}/enrollment/review`,{method:'POST',credentials:'same-origin',headers:csrfHeaders(),body:JSON.stringify({form_id:form.id,decision,reason:revisionReason.value})}),b=await r.json();if(!r.ok){error.value=b.message||'Review could not be saved.';return}form.status=b.form.status;revisionReason.value='';await loadStudents(students.value?.current_page||1)}

function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void loadStudents(1), 250);
}

async function viewStudent(id: number) {
  detailLoading.value = true;
  detailError.value = '';
  passwordNotice.value = '';
  paceNotice.value = '';
  selectedStudent.value = null;
  try {
    const response = await fetch(`/api/admin/students/${id}`, { credentials: 'same-origin', headers: csrfHeaders() });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Student details could not be loaded.');
    selectedStudent.value = body.student;
    paceGroups.value = body.pace_groups;
  } catch (caught) {
    detailError.value = (caught as Error).message;
  } finally {
    detailLoading.value = false;
  }
}

async function savePaceGroup() {
  if (!selectedStudent.value) return;
  savingPace.value = true;
  paceNotice.value = '';
  detailError.value = '';
  try {
    const response = await fetch(`/api/admin/students/${selectedStudent.value.id}/pace-group`, {
      method: 'PUT', credentials: 'same-origin', headers: csrfHeaders(),
      body: JSON.stringify({ pace_group: selectedStudent.value.pace_group }),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Pace group could not be saved.');
    selectedStudent.value.pace_group = body.pace_group;
    selectedStudent.value.weekly_target = body.weekly_target;
    paceNotice.value = 'Pace group updated.';
    await loadStudents(students.value?.current_page ?? 1);
  } catch (caught) {
    detailError.value = (caught as Error).message;
  } finally {
    savingPace.value = false;
  }
}

async function updateStudentPassword(event: Event) {
  if (!selectedStudent.value || updatingPassword.value) return;
  const form = event.currentTarget as HTMLFormElement;
  const values = new FormData(form);
  const password = String(values.get('password') ?? '');
  const passwordConfirmation = String(values.get('password_confirmation') ?? '');
  if (!password || password !== passwordConfirmation) {
    detailError.value = 'The password confirmation does not match.';
    return;
  }
  if (!window.confirm('Update the password for ' + selectedStudent.value.real_name + '?')) return;
  updatingPassword.value = true; passwordNotice.value = ''; detailError.value = '';
  try {
    const response = await fetch('/api/admin/students/' + selectedStudent.value.id + '/password', {
      method: 'PUT', credentials: 'same-origin', headers: csrfHeaders(),
      body: JSON.stringify({ password, password_confirmation: passwordConfirmation }),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Student password could not be updated.');
    form.reset();
    passwordNotice.value = 'Student password updated.';
  } catch (caught) { detailError.value = (caught as Error).message; }
  finally { updatingPassword.value = false; }
}

async function resetStudentPassword() {
  if (!selectedStudent.value || resettingPassword.value) return;
  if (!window.confirm('Reset ' + selectedStudent.value.real_name + '\'s password to the configured default password?')) return;
  resettingPassword.value = true; passwordNotice.value = ''; detailError.value = '';
  try {
    const response = await fetch('/api/admin/students/' + selectedStudent.value.id + '/password/reset', {
      method: 'POST', credentials: 'same-origin', headers: csrfHeaders(),
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Student password could not be reset.');
    document.querySelector<HTMLFormElement>('#student-password-form')?.reset();
    passwordNotice.value = 'Student password reset to the configured default.';
  } catch (caught) { detailError.value = (caught as Error).message; }
  finally { resettingPassword.value = false; }
}

async function saveTargets() {
  savingTargets.value = true; targetNotice.value = ''; targetError.value = '';
  try {
    const response = await fetch('/api/admin/pace-groups', { method: 'PUT', credentials: 'same-origin', headers: csrfHeaders(), body: JSON.stringify({ groups: paceGroups.value.map(group => ({ code: group.value, weekly_target: targetDrafts.value[group.value] })) }) });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Weekly targets could not be saved.');
    paceGroups.value = body.pace_groups;
    targetDrafts.value = Object.fromEntries(paceGroups.value.map(group => [group.value, group.weekly_target]));
    targetNotice.value = 'Weekly lesson targets saved.';
  } catch (caught) { targetError.value = (caught as Error).message; }
  finally { savingTargets.value = false; }
}

function formatDate(value: string | null) {
  if (!value) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}
function formatDateTime(value: string | null) {
  if (!value) return 'Schedule not set';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

onMounted(() => {void loadStudents();void loadEnrollmentSettings()});
</script>

<template>
  <div class="admin-students-page">
    <section class="pace-target-panel"><div class="pace-target-heading"><div><p class="eyebrow">ENROLLMENT FORM SETTINGS</p><h2>Lesson completion requirement</h2><p>Lesson 0 makes the form available immediately.</p></div></div><div class="enrollment-settings"><label><input v-model="enrollmentSetting.enabled" type="checkbox"> Enable Enrollment Form Requirement</label><label>Required Category<select v-model.number="enrollmentSetting.category_id"><option :value="null">Select category</option><option v-for="category in categories.filter(c=>!c.deleted_at)" :key="category.id" :value="category.id">{{ category.name }}</option></select></label><label>Required lesson number<input v-model.number="enrollmentSetting.lesson_number" type="number" min="0" max="10000"></label><button class="primary" @click="saveEnrollmentSettings">Save requirement</button></div><p v-if="settingError" class="student-error" role="alert">{{ settingError }}</p><p v-if="settingNotice" class="student-success" role="status">{{ settingNotice }}</p></section>
    <section class="pace-target-panel" aria-labelledby="pace-target-title"><div class="pace-target-heading"><div><p class="eyebrow">PACE SETTINGS</p><h2 id="pace-target-title">Weekly lesson targets</h2><p>Set the expected lessons per week for each group. A target of zero means the group is not configured yet.</p></div><button class="primary" :disabled="savingTargets" @click="saveTargets">{{ savingTargets ? 'Saving…' : 'Save targets' }}</button></div><div class="pace-target-grid"><label v-for="group in paceGroups" :key="group.value">{{ group.label }}<span><input v-model.number="targetDrafts[group.value]" type="number" min="0" max="1000" :aria-label="`${group.label} weekly lesson target`"><small>lessons / week</small></span></label></div><p v-if="targetError" class="student-error" role="alert">{{ targetError }}</p><p v-if="targetNotice" class="student-success" role="status">{{ targetNotice }}</p></section>
    <section class="student-list-panel">
      <div class="students-toolbar">
        <label class="student-search"><Search :size="17" /><input v-model="search" type="search" placeholder="Search name, email, or pseudocode" aria-label="Search students" @input="scheduleSearch"></label>
        <label class="student-filter">Category<select v-model="categoryId" @change="loadStudents(1)"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (archived)' : category.is_active ? '' : ' (unavailable)' }}</option></select></label>
        <label class="student-filter">Pace group<select v-model="paceGroup" @change="loadStudents(1)"><option value="">All pace groups</option><option v-for="option in paceGroups" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
      </div>
      <div class="bulk-toolbar"><label><input type="checkbox" :checked="allSelected" @change="toggleAll"> Select all on this page</label><span>{{ selectedIds.length }} selected</span><button class="secondary-button" :disabled="!selectedIds.length" @click="exportSelected('export')">Export to Excel</button><button class="secondary-button" :disabled="!selectedIds.length" @click="exportSelected('print')">Print forms / Save PDF</button></div>
      <p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div class="student-table-wrap">
        <table class="student-table">
          <thead><tr><th scope="col">Select</th><th scope="col">Student</th><th scope="col">Pseudocode</th><th scope="col">Current category</th><th scope="col">Progress</th><th scope="col">Pace group</th><th scope="col">Registered</th><th scope="col">Enrollment form</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="10" class="student-table-message">Loading students…</td></tr>
            <tr v-else-if="!currentStudents.length"><td colspan="10" class="student-table-message">No students match these filters.</td></tr>
            <tr v-for="student in currentStudents" v-else :key="student.id">
              <td><input v-model="selectedIds" type="checkbox" :value="student.id" :aria-label="`Select ${student.name}`"></td>
              <td><strong>{{ student.name }}</strong><small>{{ student.email }}</small></td>
              <td><span class="student-pseudocode">{{ student.pseudocode }}</span></td>
              <td>{{ student.current_category?.name ?? 'Not started' }}<small v-if="student.current_category?.is_archived">Archived category</small><small v-else-if="student.current_category && !student.current_category.is_active">Unavailable category</small></td>
              <td><span>{{ student.progress.completed_lessons }} / {{ student.progress.total_lessons }} lessons</span><div class="student-progress-track"><span :style="{ width: `${student.progress.percentage}%` }"></span></div></td>
              <td>{{ paceLabel(student.pace_group) }}<small>{{ student.weekly_target }} lessons / week</small></td>
              <td>{{ formatDate(student.registered_at) }}</td>
              <td><button class="student-view-button" @click="viewEnrollment(student.id)">{{ (student.enrollment_status||'not_started').replace('_',' ') }}</button><small>{{ formatDate(student.enrollment_submitted_at||null) }}</small></td>
              <td><span class="student-status" :class="student.is_active ? 'active' : 'inactive'">{{ student.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td><button class="student-view-button" :aria-label="`View ${student.name}`" @click="viewStudent(student.id)"><Eye :size="16" /><span>View</span></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="students" class="student-pagination"><span>{{ students.total }} students</span><div><button :disabled="students.current_page <= 1 || loading" aria-label="Previous page" @click="loadStudents(students.current_page - 1)"><ChevronLeft :size="17" /></button><span>Page {{ students.current_page }} of {{ students.last_page }}</span><button :disabled="students.current_page >= students.last_page || loading" aria-label="Next page" @click="loadStudents(students.current_page + 1)"><ChevronRight :size="17" /></button></div></div>
    </section>

    <section v-if="reviewStudentId" class="student-detail-panel"><div class="student-detail-heading"><div><p class="eyebrow">ENROLLMENT REVIEW</p><h2>Submission history</h2></div><button class="student-close-button" @click="reviewStudentId=null">Close</button></div><article class="student-info-card"><h3>Student-specific access requirement</h3><div class="enrollment-settings"><label><input v-model="studentRequirement.enabled" type="checkbox"> Enable requirement for this student</label><label>Category<select v-model.number="studentRequirement.category_id"><option :value="null">Select category</option><option v-for="category in categories.filter(c=>!c.deleted_at)" :key="category.id" :value="category.id">{{ category.name }}</option></select></label><label>Lesson number (0 = immediate)<input v-model.number="studentRequirement.lesson_number" type="number" min="0"></label><button class="primary" @click="saveStudentRequirement">Save student setting</button></div><small>These values override the global settings for this student.</small></article><article v-for="form in reviewForms" :key="form.id" class="student-info-card"><h3>Revision {{ form.revision }} · {{ form.status }}</h3><p>Submitted {{ formatDateTime(form.submitted_at) }} · Privacy {{ form.privacy_acknowledged?'acknowledged':'not acknowledged' }} ({{ form.privacy_version||'—' }})</p><div class="enrollment-admin-data"><div v-for="(value,key) in form.data" :key="key"><b>{{ String(key).replaceAll('_',' ') }}</b><span>{{ String(value) }}</span></div></div><p v-if="form.revision_reason">Revision reason: {{ form.revision_reason }}</p><p><a v-if="form.id_document_path" :href="`/api/enrollment-forms/${form.id}/documents/id`" target="_blank">Review ID document</a> · <a v-if="form.picture_path" :href="`/api/enrollment-forms/${form.id}/documents/picture`" target="_blank">Review picture</a></p><template v-if="form.status==='submitted'"><label>Revision reason (required to return)<textarea v-model="revisionReason" rows="2"></textarea></label><button class="primary" @click="review(form,'approve')">Approve</button> <button class="secondary-button" @click="review(form,'revision')">Return for revision</button></template></article></section>

    <section v-if="detailLoading || detailError || selectedStudent" class="student-detail-panel" aria-labelledby="student-detail-title">
      <div class="student-detail-heading"><div><p class="eyebrow">STUDENT PROFILE</p><h2 id="student-detail-title">{{ selectedStudent?.real_name ?? 'Student details' }}</h2></div><button class="student-close-button" aria-label="Close student details" @click="selectedStudent = null; detailError = ''"><X :size="18" /></button></div>
      <p v-if="detailLoading" class="student-table-message">Loading student history…</p>
      <p v-if="detailError" class="student-error" role="alert">{{ detailError }}</p>
      <template v-if="selectedStudent">
        <div class="student-detail-grid">
          <article class="student-info-card"><h3><UserRound :size="17" /> Personal information</h3><dl><div><dt>Real name</dt><dd>{{ selectedStudent.personal_information.real_name }}</dd></div><div><dt>Email</dt><dd>{{ selectedStudent.personal_information.email }}</dd></div><div><dt>Country</dt><dd>{{ selectedStudent.personal_information.country || '—' }}</dd></div><div><dt>Complete address</dt><dd>{{ selectedStudent.personal_information.address || '—' }}</dd></div><div><dt>Phone</dt><dd>{{ selectedStudent.personal_information.phone_country_code || '' }} {{ selectedStudent.personal_information.phone_number || '—' }}</dd></div><div><dt>Pseudocode</dt><dd><span class="student-pseudocode">{{ selectedStudent.pseudocode }}</span><small>Permanent identifier · student editing is not available</small></dd></div><div><dt>Registration date</dt><dd>{{ formatDate(selectedStudent.registered_at) }}</dd></div><div><dt>Account status</dt><dd><span class="student-status" :class="selectedStudent.is_active ? 'active' : 'inactive'">{{ selectedStudent.is_active ? 'Active' : 'Inactive' }}</span></dd></div></dl></article>
          <article class="student-info-card"><h3><Clock3 :size="17" /> Learning assignment</h3><dl><div><dt>Current category</dt><dd>{{ selectedStudent.current_category?.name ?? 'Not started' }}<small v-if="selectedStudent.current_category">{{ selectedStudent.current_category.completed_lessons }} of {{ selectedStudent.current_category.total_lessons }} lessons · {{ selectedStudent.current_category.progress_percentage }}%</small></dd></div><div><dt>Pace group</dt><dd><div class="pace-editor"><select v-model="selectedStudent.pace_group" aria-label="Student pace group"><option v-for="option in paceGroups" :key="option.value" :value="option.value">{{ option.label }} · {{ option.weekly_target }}/week</option></select><button class="primary" :disabled="savingPace" @click="savePaceGroup">{{ savingPace ? 'Saving…' : 'Save pace' }}</button></div><small>{{ selectedStudent.weekly_target }} lessons / week</small><small v-if="paceNotice" class="student-success" role="status">{{ paceNotice }}</small></dd></div></dl></article>
        </div>
        <section class="student-info-card student-password-section" aria-labelledby="student-password-title">
          <h3 id="student-password-title">Password management</h3>
          <p class="student-password-help">Set a new password at the student’s request, or reset it to the configured default. Existing passwords are never shown.</p>
          <form id="student-password-form" class="student-password-form" @submit.prevent="updateStudentPassword">
            <label>New password<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
            <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
            <button class="primary" :disabled="updatingPassword || resettingPassword">{{ updatingPassword ? 'Updating…' : 'Update password' }}</button>
            <button type="button" class="secondary-button" :disabled="updatingPassword || resettingPassword" @click="resetStudentPassword">{{ resettingPassword ? 'Resetting…' : 'Reset to default' }}</button>
          </form>
          <p v-if="passwordNotice" class="student-success" role="status">{{ passwordNotice }}</p>
        </section>
        <section class="student-info-card student-zoom-section"><h3><Clock3 :size="17" /> Zoom assignment</h3><template v-if="selectedStudent.zoom_batch"><div class="student-zoom-list"><div><strong>{{ selectedStudent.zoom_batch.batch_name }}</strong><small>{{ selectedStudent.zoom_batch.topic || 'Meeting not created' }} · {{ formatDateTime(selectedStudent.zoom_batch.scheduled_at) }} · {{ selectedStudent.zoom_batch.recurrence?.frequency || 'one-time' }} · {{ selectedStudent.zoom_batch.registration_status }}</small><small v-if="selectedStudent.zoom_batch.registration_error">{{ selectedStudent.zoom_batch.registration_error }}</small></div><a v-if="selectedStudent.zoom_batch.join_url" :href="selectedStudent.zoom_batch.join_url" target="_blank" rel="noopener noreferrer">Individual join link <ExternalLink :size="14" /></a><a :href="`/admin/zoom?batch=${selectedStudent.zoom_batch.batch_id}`">Batch details</a></div></template><p v-else class="student-detail-empty">No Zoom batch is currently assigned to this student.</p></section>
        <section class="student-info-card student-history-section"><div class="student-detail-heading"><div><p class="eyebrow">LEARNING RECORD</p><h3>Detailed learning history</h3></div></div><div v-if="selectedStudent.learning_history.length" class="student-history-list"><article v-for="category in selectedStudent.learning_history" :key="category.id" class="student-history-category"><div class="student-history-heading"><div><strong>{{ category.name }}</strong><span v-if="category.is_archived" class="dashboard-archived">Archived</span></div><span>{{ category.completed_lessons }}/{{ category.total_lessons }} complete · {{ category.progress_percentage }}%</span></div><div class="student-progress-track"><span :style="{ width: `${category.progress_percentage}%` }"></span></div><p v-if="category.completed_at" class="student-history-complete">Category completed {{ formatDate(category.completed_at) }}</p><ul><li v-for="lesson in category.lessons" :key="lesson.id"><span class="history-state" :class="{ complete: lesson.is_completed }"><Check v-if="lesson.is_completed" :size="14" /><CirclePlay v-else :size="14" /></span><span>{{ lesson.title }}<small>{{ lesson.is_completed ? 'Completed' : lesson.last_watched_at ? 'In progress · '+lesson.progress_percentage+'%' : lesson.is_published ? 'Not started' : 'Archived' }}</small></span><time v-if="lesson.last_watched_at">{{ formatDate(lesson.last_watched_at) }}</time></li></ul></article></div><p v-else class="student-detail-empty">No learning history has been recorded yet.</p></section>
      </template>
    </section>
  </div>
</template>
