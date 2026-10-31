<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ChevronDown, ChevronRight, Clock3, Search } from '@lucide/vue';

type Choice = { id: number; name?: string; title?: string; category_id?: number; deleted_at?: string | null };
type Realization = { id: number; student_id: number; student_name: string; pseudocode: string; category_id: number; category_name: string; lesson_id: number; lesson_title: string; realization: string; submitted_at: string };
type LearningCategory = { id: number; name: string; completed_lessons: number; total_lessons: number; progress_percentage: number; lessons: { id: number; title: string; is_completed: boolean; progress_percentage: number; last_watched_at: string | null }[] };
const rows = ref<Realization[]>([]), categories = ref<Choice[]>([]), lessons = ref<Choice[]>([]);
const student = ref(''), categoryId = ref(''), lessonId = ref(''), dateFrom = ref(''), dateTo = ref('');
const page = ref(1), lastPage = ref(1), loading = ref(false), error = ref('');
const selectedId = ref<number | null>(null), history = ref<LearningCategory[]>([]), historyLoading = ref(false), historyError = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;
const countLabel = computed(() => rows.value.length === 1 ? '1 submission' : `${rows.value.length} submissions`);

async function load(targetPage = page.value) {
  loading.value = true; error.value = ''; page.value = targetPage;
  try {
    const params = new URLSearchParams({ page: String(page.value) });
    if (student.value.trim()) params.set('student', student.value.trim());
    if (categoryId.value) params.set('category_id', categoryId.value);
    if (lessonId.value) params.set('lesson_id', lessonId.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    const response = await fetch(`/api/admin/realizations?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Realizations could not be loaded.');
    rows.value = body.realizations.data; categories.value = body.filters.categories; lessons.value = body.filters.lessons;
    page.value = body.realizations.current_page; lastPage.value = body.realizations.last_page;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function scheduleSearch() { if (searchTimer) clearTimeout(searchTimer); searchTimer = setTimeout(() => void load(1), 250); }
function changeCategory() { lessonId.value = ''; void load(1); }
async function openContext(item: Realization) {
  if (selectedId.value === item.student_id) { selectedId.value = null; return; }
  selectedId.value = item.student_id; history.value = []; historyLoading.value = true; historyError.value = '';
  try {
    const response = await fetch(`/api/admin/students/${item.student_id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Student learning context could not be loaded.');
    history.value = body.student.learning_history;
  } catch (caught) { historyError.value = (caught as Error).message; }
  finally { historyLoading.value = false; }
}
function formatDate(value: string) { return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }
onMounted(() => void load(1));
</script>

<template>
  <section class="admin-realizations-page">
    <div class="realization-panel">
      <div class="realization-toolbar">
        <label class="realization-search"><Search :size="17" /><input v-model="student" type="search" placeholder="Search student or pseudocode" aria-label="Search student" @input="scheduleSearch"></label>
        <label>Category<select v-model="categoryId" @change="changeCategory"><option value="">All categories</option><option v-for="item in categories" :key="item.id" :value="String(item.id)">{{ item.name }}{{ item.deleted_at ? ' (Archived)' : '' }}</option></select></label>
        <label>Lesson<select v-model="lessonId" :disabled="!categoryId" @change="load(1)"><option value="">All lessons</option><option v-for="item in lessons" :key="item.id" :value="String(item.id)">{{ item.title }}{{ item.deleted_at ? ' (Archived)' : '' }}</option></select></label>
        <label>From<input v-model="dateFrom" type="date" @change="load(1)"></label>
        <label>To<input v-model="dateTo" type="date" :min="dateFrom || undefined" @change="load(1)"></label>
      </div>
      <div class="realization-summary"><h2>Student realizations</h2><span>{{ countLabel }} on this page</span></div>
      <p v-if="error" class="student-error" role="alert">{{ error }}</p>
      <div v-if="loading" class="category-empty-state">Loading realizations…</div>
      <div v-else-if="!rows.length" class="category-empty-state">No realizations match these filters.</div>
      <div v-else class="realization-table-wrap">
        <table class="realization-table"><thead><tr><th>Student</th><th>Category</th><th>Lesson</th><th>Realization</th><th>Submitted</th><th></th></tr></thead>
          <tbody><template v-for="item in rows" :key="item.id"><tr><td><strong>{{ item.student_name }}</strong><small>{{ item.pseudocode }}</small></td><td>{{ item.category_name }}</td><td>{{ item.lesson_title }}</td><td class="realization-text">{{ item.realization }}</td><td><time>{{ formatDate(item.submitted_at) }}</time></td><td><button class="category-action" :aria-expanded="selectedId === item.student_id" @click="openContext(item)">{{ selectedId === item.student_id ? 'Close context' : 'Learning context' }} <ChevronDown v-if="selectedId === item.student_id" :size="14" /><ChevronRight v-else :size="14" /></button></td></tr>
            <tr v-if="selectedId === item.student_id" class="realization-context-row"><td colspan="6"><div v-if="historyLoading" class="category-empty-state">Loading learning history…</div><p v-else-if="historyError" class="student-error" role="alert">{{ historyError }}</p><div v-else-if="!history.length" class="category-empty-state">No learning history recorded.</div><div v-else class="realization-context"><h3>Learning history for {{ item.student_name }}</h3><article v-for="category in history" :key="category.id"><div><b>{{ category.name }}</b><span>{{ category.completed_lessons }}/{{ category.total_lessons }} completed · {{ category.progress_percentage }}%</span></div><ul><li v-for="lesson in category.lessons" :key="lesson.id"><span>{{ lesson.title }}</span><span>{{ lesson.is_completed ? 'Completed' : lesson.progress_percentage ? `${lesson.progress_percentage}% watched` : 'Not completed' }}</span></li></ul></article></div></td></tr>
          </template></tbody>
        </table>
      </div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1 || loading" @click="load(page - 1)">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage || loading" @click="load(page + 1)">Next</button></div>
      <p class="realization-retention-note"><Clock3 :size="14" /> Submissions are retained as learning history. This page is read-only.</p>
    </div>
  </section>
</template>
