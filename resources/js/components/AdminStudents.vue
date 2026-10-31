<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Check, ChevronLeft, ChevronRight, Clock3, ExternalLink, Eye, Search, UserRound, X } from '@lucide/vue';

type PaceOption = { value: string; label: string; weekly_target: number };
type StudentRow = {
  id: number; name: string; email: string; pseudocode: string; pace_group: string; weekly_target: number; is_active: boolean; registered_at: string;
  current_category: { id: number; name: string; is_active: boolean; is_archived: boolean } | null;
  progress: { completed_lessons: number; total_lessons: number; percentage: number };
};
type StudentPage = { data: StudentRow[]; current_page: number; last_page: number; total: number };
type StudentDetails = {
  id: number; real_name: string; email: string; pseudocode: string; pace_group: string; weekly_target: number; is_active: boolean; registered_at: string;
  personal_information: { real_name: string; email: string };
  current_category: HistoryCategory | null;
  zoom_assignments: { id: number; title: string; meeting_url: string; scheduled_at: string; category_name: string | null; lesson_title: string | null }[];
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
const targetDrafts = ref<Record<string, number>>({}), savingTargets = ref(false), targetNotice = ref(''), targetError = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const currentStudents = computed(() => students.value?.data ?? []);
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
  } catch (caught) {
    error.value = (caught as Error).message;
  } finally {
    loading.value = false;
  }
}

function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => void loadStudents(1), 250);
}

async function viewStudent(id: number) {
  detailLoading.value = true;
  detailError.value = '';
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
function formatDateTime(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

onMounted(() => void loadStudents());
</script>

<template>
  <div class="admin-students-page">
    <section class="pace-target-panel" aria-labelledby="pace-target-title"><div class="pace-target-heading"><div><p class="eyebrow">PACE SETTINGS</p><h2 id="pace-target-title">Weekly lesson targets</h2><p>Set the expected lessons per week for each group. A target of zero means the group is not configured yet.</p></div><button class="primary" :disabled="savingTargets" @click="saveTargets">{{ savingTargets ? 'Saving…' : 'Save targets' }}</button></div><div class="pace-target-grid"><label v-for="group in paceGroups" :key="group.value">{{ group.label }}<span><input v-model.number="targetDrafts[group.value]" type="number" min="0" max="1000" :aria-label="`${group.label} weekly lesson target`"><small>lessons / week</small></span></label></div><p v-if="targetError" class="student-error" role="alert">{{ targetError }}</p><p v-if="targetNotice" class="student-success" role="status">{{ targetNotice }}</p></section>
    <section class="student-list-panel">
      <div class="students-toolbar">
        <label class="student-search"><Search :size="17" /><input v-model="search" type="search" placeholder="Search name, email, or pseudocode" aria-label="Search students" @input="scheduleSearch"></label>
        <label class="student-filter">Category<select v-model="categoryId" @change="loadStudents(1)"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (archived)' : category.is_active ? '' : ' (unavailable)' }}</option></select></label>
        <label class="student-filter">Pace group<select v-model="paceGroup" @change="loadStudents(1)"><option value="">All pace groups</option><option v-for="option in paceGroups" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
      </div>
      <p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div class="student-table-wrap">
        <table class="student-table">
          <thead><tr><th scope="col">Student</th><th scope="col">Pseudocode</th><th scope="col">Current category</th><th scope="col">Progress</th><th scope="col">Pace group</th><th scope="col">Registered</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="8" class="student-table-message">Loading students…</td></tr>
            <tr v-else-if="!currentStudents.length"><td colspan="8" class="student-table-message">No students match these filters.</td></tr>
            <tr v-for="student in currentStudents" v-else :key="student.id">
              <td><strong>{{ student.name }}</strong><small>{{ student.email }}</small></td>
              <td><span class="student-pseudocode">{{ student.pseudocode }}</span></td>
              <td>{{ student.current_category?.name ?? 'Not started' }}<small v-if="student.current_category?.is_archived">Archived category</small><small v-else-if="student.current_category && !student.current_category.is_active">Unavailable category</small></td>
              <td><span>{{ student.progress.completed_lessons }} / {{ student.progress.total_lessons }} lessons</span><div class="student-progress-track"><span :style="{ width: `${student.progress.percentage}%` }"></span></div></td>
              <td>{{ paceLabel(student.pace_group) }}<small>{{ student.weekly_target }} lessons / week</small></td>
              <td>{{ formatDate(student.registered_at) }}</td>
              <td><span class="student-status" :class="student.is_active ? 'active' : 'inactive'">{{ student.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td><button class="student-view-button" :aria-label="`View ${student.name}`" @click="viewStudent(student.id)"><Eye :size="16" /><span>View</span></button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="students" class="student-pagination"><span>{{ students.total }} students</span><div><button :disabled="students.current_page <= 1 || loading" aria-label="Previous page" @click="loadStudents(students.current_page - 1)"><ChevronLeft :size="17" /></button><span>Page {{ students.current_page }} of {{ students.last_page }}</span><button :disabled="students.current_page >= students.last_page || loading" aria-label="Next page" @click="loadStudents(students.current_page + 1)"><ChevronRight :size="17" /></button></div></div>
    </section>

    <section v-if="detailLoading || detailError || selectedStudent" class="student-detail-panel" aria-labelledby="student-detail-title">
      <div class="student-detail-heading"><div><p class="eyebrow">STUDENT PROFILE</p><h2 id="student-detail-title">{{ selectedStudent?.real_name ?? 'Student details' }}</h2></div><button class="student-close-button" aria-label="Close student details" @click="selectedStudent = null; detailError = ''"><X :size="18" /></button></div>
      <p v-if="detailLoading" class="student-table-message">Loading student history…</p>
      <p v-if="detailError" class="student-error" role="alert">{{ detailError }}</p>
      <template v-if="selectedStudent">
        <div class="student-detail-grid">
          <article class="student-info-card"><h3><UserRound :size="17" /> Personal information</h3><dl><div><dt>Real name</dt><dd>{{ selectedStudent.personal_information.real_name }}</dd></div><div><dt>Email</dt><dd>{{ selectedStudent.personal_information.email }}</dd></div><div><dt>Pseudocode</dt><dd><span class="student-pseudocode">{{ selectedStudent.pseudocode }}</span><small>Permanent identifier · student editing is not available</small></dd></div><div><dt>Registration date</dt><dd>{{ formatDate(selectedStudent.registered_at) }}</dd></div><div><dt>Account status</dt><dd><span class="student-status" :class="selectedStudent.is_active ? 'active' : 'inactive'">{{ selectedStudent.is_active ? 'Active' : 'Inactive' }}</span></dd></div></dl></article>
          <article class="student-info-card"><h3><Clock3 :size="17" /> Learning assignment</h3><dl><div><dt>Current category</dt><dd>{{ selectedStudent.current_category?.name ?? 'Not started' }}<small v-if="selectedStudent.current_category">{{ selectedStudent.current_category.completed_lessons }} of {{ selectedStudent.current_category.total_lessons }} lessons · {{ selectedStudent.current_category.progress_percentage }}%</small></dd></div><div><dt>Pace group</dt><dd><div class="pace-editor"><select v-model="selectedStudent.pace_group" aria-label="Student pace group"><option v-for="option in paceGroups" :key="option.value" :value="option.value">{{ option.label }} · {{ option.weekly_target }}/week</option></select><button class="primary" :disabled="savingPace" @click="savePaceGroup">{{ savingPace ? 'Saving…' : 'Save pace' }}</button></div><small>{{ selectedStudent.weekly_target }} lessons / week</small><small v-if="paceNotice" class="student-success" role="status">{{ paceNotice }}</small></dd></div></dl></article>
        </div>
        <section class="student-info-card student-zoom-section"><h3><Clock3 :size="17" /> Zoom assignment</h3><ul v-if="selectedStudent.zoom_assignments.length" class="student-zoom-list"><li v-for="session in selectedStudent.zoom_assignments" :key="session.id"><div><strong>{{ session.title }}</strong><small>{{ formatDateTime(session.scheduled_at) }}<template v-if="session.category_name"> · {{ session.category_name }}</template><template v-if="session.lesson_title"> · {{ session.lesson_title }}</template></small></div><a :href="session.meeting_url" target="_blank" rel="noopener noreferrer">Open meeting <ExternalLink :size="14" /></a></li></ul><p v-else class="student-detail-empty">No upcoming Zoom session is assigned to this student’s current category.</p></section>
        <section class="student-info-card student-history-section"><div class="student-detail-heading"><div><p class="eyebrow">LEARNING RECORD</p><h3>Detailed learning history</h3></div></div><div v-if="selectedStudent.learning_history.length" class="student-history-list"><article v-for="category in selectedStudent.learning_history" :key="category.id" class="student-history-category"><div class="student-history-heading"><div><strong>{{ category.name }}</strong><span v-if="category.is_archived" class="dashboard-archived">Archived</span></div><span>{{ category.completed_lessons }}/{{ category.total_lessons }} complete · {{ category.progress_percentage }}%</span></div><div class="student-progress-track"><span :style="{ width: `${category.progress_percentage}%` }"></span></div><p v-if="category.completed_at" class="student-history-complete">Category completed {{ formatDate(category.completed_at) }}</p><ul><li v-for="lesson in category.lessons" :key="lesson.id"><span class="history-state" :class="{ complete: lesson.is_completed }"><Check v-if="lesson.is_completed" :size="14" /><CirclePlay v-else :size="14" /></span><span>{{ lesson.title }}<small>{{ lesson.is_completed ? 'Completed' : lesson.last_watched_at ? 'In progress · '+lesson.progress_percentage+'%' : lesson.is_published ? 'Not started' : 'Archived' }}</small></span><time v-if="lesson.last_watched_at">{{ formatDate(lesson.last_watched_at) }}</time></li></ul></article></div><p v-else class="student-detail-empty">No learning history has been recorded yet.</p></section>
      </template>
    </section>
  </div>
</template>
