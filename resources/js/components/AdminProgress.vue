<script setup lang="ts">
import { onMounted, ref } from 'vue';

type Category = { id: number; name: string; deleted_at: string | null };
type LessonOption = { id: number; title: string; deleted_at: string | null };
type ProgressStudent = { id: number; name: string; pseudocode: string; pace_group: string | null; weekly_target: number; lessons_started: number; lessons_completed: number; completion_percentage: number; current_lesson: string | null; current_category: string | null; transfer_eligible: boolean };
type HistoryLesson = { id: number; title: string; is_published: boolean; is_completed: boolean; progress_percentage: number; last_watched_at: string | null };
type HistoryCategory = { id: number; name: string; completed_at: string | null; completed_lessons: number; total_lessons: number; progress_percentage: number; lessons: HistoryLesson[] };
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const rows = ref<ProgressStudent[]>([]), categories = ref<Category[]>([]), lessons = ref<LessonOption[]>([]), paceGroups = ref<{ value: string; label: string; weekly_target: number }[]>([]);
const search = ref(''), categoryId = ref(''), lessonId = ref(''), paceGroup = ref(''), dateFrom = ref(''), dateTo = ref('');
const page = ref(1), lastPage = ref(1), total = ref(0), loading = ref(false), error = ref('');
const selected = ref<number | null>(null), historyLoading = ref(false), historyError = ref(''), history = ref<HistoryCategory[]>([]);
async function load() {
  loading.value = true; error.value = '';
  try {
    const query = new URLSearchParams({ page: String(page.value) });
    if (search.value.trim()) query.set('student', search.value.trim());
    if (categoryId.value) query.set('category_id', categoryId.value);
    if (lessonId.value) query.set('lesson_id', lessonId.value);
    if (paceGroup.value) query.set('pace_group', paceGroup.value);
    if (dateFrom.value) query.set('date_from', dateFrom.value);
    if (dateTo.value) query.set('date_to', dateTo.value);
    const response = await fetch(`/api/admin/progress?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'Progress could not be loaded.');
    rows.value = data.students.data; page.value = data.students.current_page; lastPage.value = data.students.last_page; total.value = data.students.total;
    categories.value = data.filters.categories; lessons.value = data.filters.lessons; paceGroups.value = data.filters.pace_groups;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function applyFilters() { page.value = 1; selected.value = null; void load(); }
function categoryChanged() { lessonId.value = ''; page.value = 1; void load(); }
async function openHistory(student: ProgressStudent) {
  selected.value = selected.value === student.id ? null : student.id;
  history.value = []; historyError.value = '';
  if (selected.value === null) return;
  historyLoading.value = true;
  try {
    const response = await fetch(`/api/admin/students/${student.id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || 'Student history could not be loaded.');
    history.value = data.student.learning_history;
  } catch (caught) { historyError.value = (caught as Error).message; }
  finally { historyLoading.value = false; }
}
function formatDate(value: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'; }
onMounted(() => { void load(); });
</script>

<template>
  <section class="admin-progress-page">
    <div class="progress-report-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">LEARNING ACTIVITY</p><h2>Student progress <span class="category-total">{{ total }}</span></h2></div></div>
      <p class="progress-report-note">Completion percentage is completed lessons divided by lessons started within the selected filters. Current lesson and category reflect each student’s latest activity.</p>
      <div class="progress-filters">
        <label class="progress-student-filter">Student<input v-model="search" type="search" placeholder="Search name, email, or pseudocode" @keydown.enter.prevent="applyFilters"></label>
        <label>Category<select v-model="categoryId" @change="categoryChanged"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (Archived)' : '' }}</option></select></label>
        <label>Lesson<select v-model="lessonId" :disabled="!categoryId" @change="applyFilters"><option value="">All lessons</option><option v-for="lesson in lessons" :key="lesson.id" :value="String(lesson.id)">{{ lesson.title }}{{ lesson.deleted_at ? ' (Archived)' : '' }}</option></select></label>
        <label>From<input v-model="dateFrom" type="date" @change="applyFilters"></label>
        <label>To<input v-model="dateTo" type="date" :min="dateFrom || undefined" @change="applyFilters"></label>
        <label>Pace group<select v-model="paceGroup" @change="applyFilters"><option value="">All pace groups</option><option v-for="pace in paceGroups" :key="pace.value" :value="pace.value">{{ pace.label }} · {{ pace.weekly_target }}/week</option></select></label>
        <button class="primary progress-apply-button" @click="applyFilters">Apply filters</button>
      </div>
      <p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div v-if="loading" class="category-empty-state">Loading learning progress…</div>
      <div v-else-if="!rows.length" class="category-empty-state">No students match these filters.</div>
      <div v-else class="progress-table-wrap">
        <table class="progress-table"><thead><tr><th>Student</th><th>Pace</th><th>Started</th><th>Completed</th><th>Completion</th><th>Current lesson</th><th>Current category</th><th>Transfer</th><th></th></tr></thead>
          <tbody><template v-for="student in rows" :key="student.id"><tr><td><strong>{{ student.name }}</strong><small>{{ student.pseudocode }}</small></td><td>{{ student.pace_group ? `Group ${student.pace_group}` : '—' }}<small>{{ student.weekly_target }} lessons / week</small></td><td>{{ student.lessons_started }}</td><td>{{ student.lessons_completed }}</td><td><div class="progress-value"><span class="progress-track"><i :style="{ width: `${student.completion_percentage}%` }"></i></span><b>{{ student.completion_percentage }}%</b></div></td><td>{{ student.current_lesson || '—' }}</td><td>{{ student.current_category || '—' }}</td><td><span class="category-state" :class="student.transfer_eligible ? 'available' : 'unavailable'">{{ student.transfer_eligible ? 'Eligible' : 'Not eligible' }}</span></td><td><button class="category-action" :aria-expanded="selected === student.id" @click="openHistory(student)">{{ selected === student.id ? 'Close history' : 'View history' }}</button></td></tr>
            <tr v-if="selected === student.id" class="progress-history-row"><td colspan="9"><div v-if="historyLoading" class="category-empty-state">Loading detailed history…</div><p v-else-if="historyError" class="student-error" role="alert">{{ historyError }}</p><div v-else-if="!history.length" class="category-empty-state">No learning history recorded.</div><div v-else class="progress-history-list"><article v-for="category in history" :key="category.id" class="progress-history-category"><div class="progress-history-category-heading"><strong>{{ category.name }}</strong><span>{{ category.completed_lessons }} / {{ category.total_lessons }} completed · {{ category.progress_percentage }}%</span></div><ul><li v-for="lesson in category.lessons" :key="lesson.id"><span>{{ lesson.title }}</span><span class="category-state" :class="lesson.is_completed ? 'available' : 'unavailable'">{{ lesson.is_completed ? 'Completed' : lesson.progress_percentage > 0 ? `${lesson.progress_percentage}% watched` : 'Not completed' }}</span><time>{{ formatDate(lesson.last_watched_at) }}</time></li></ul></article></div></td></tr>
          </template></tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage" @click="page++; load()">Next</button></div>
    </div>
  </section>
</template>
