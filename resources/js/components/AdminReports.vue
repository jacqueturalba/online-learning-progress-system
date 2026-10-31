<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BarChart3, ChevronLeft, ChevronRight, FileText } from '@lucide/vue';

type ReportKey = 'lesson_completion' | 'student_completion' | 'category_progress' | 'realizations' | 'transfers';
type ReportRow = Record<string, string | number | null>;
type ReportPage = { data: ReportRow[]; current_page: number; last_page: number; total: number; per_page: number };
type Column = { key: string; label: string; format?: 'pace' | 'target' | 'percentage' | 'date' };
const reportOptions: { key: ReportKey; title: string; description: string; columns: Column[] }[] = [
  { key: 'lesson_completion', title: 'Category lesson completion', description: 'Students who completed each lesson.', columns: [{ key: 'category_name', label: 'Category' }, { key: 'lesson_title', label: 'Lesson' }, { key: 'completed_students', label: 'Students completed' }, { key: 'latest_completion', label: 'Latest completion', format: 'date' }] },
  { key: 'student_completion', title: 'Student lesson completion', description: 'Completed lesson totals for each student.', columns: [{ key: 'student_name', label: 'Student' }, { key: 'pseudocode', label: 'Pseudocode' }, { key: 'pace_group', label: 'Pace group', format: 'pace' }, { key: 'weekly_target', label: 'Weekly target', format: 'target' }, { key: 'completed_lessons', label: 'Lessons completed' }, { key: 'last_completion', label: 'Last completion', format: 'date' }] },
  { key: 'category_progress', title: 'Category student progress', description: 'Student progress grouped by category.', columns: [{ key: 'category_name', label: 'Category' }, { key: 'student_name', label: 'Student' }, { key: 'pseudocode', label: 'Pseudocode' }, { key: 'pace_group', label: 'Pace group', format: 'pace' }, { key: 'weekly_target', label: 'Weekly target', format: 'target' }, { key: 'completed_lessons', label: 'Completed' }, { key: 'total_lessons', label: 'Lessons' }, { key: 'progress_percentage', label: 'Progress', format: 'percentage' }, { key: 'last_activity', label: 'Last activity', format: 'date' }] },
  { key: 'realizations', title: 'Realization report', description: 'Submission counts grouped by category, lesson, and student.', columns: [{ key: 'category_name', label: 'Category' }, { key: 'lesson_title', label: 'Lesson' }, { key: 'student_name', label: 'Student' }, { key: 'pseudocode', label: 'Pseudocode' }, { key: 'realization_count', label: 'Submissions' }, { key: 'latest_submission', label: 'Latest submission', format: 'date' }] },
  { key: 'transfers', title: 'Transfer report', description: 'Transfer counts derived from completed categories followed by activity in another category.', columns: [{ key: 'student_name', label: 'Student' }, { key: 'pseudocode', label: 'Pseudocode' }, { key: 'pace_group', label: 'Pace group', format: 'pace' }, { key: 'weekly_target', label: 'Weekly target', format: 'target' }, { key: 'from_category', label: 'Completed category' }, { key: 'to_category', label: 'Next category' }, { key: 'transferred_at', label: 'Next category started', format: 'date' }] },
];
type Category = { id: number; name: string; deleted_at: string | null };
const activeReport = ref<ReportKey>('lesson_completion'), rows = ref<ReportRow[]>([]), categories = ref<Category[]>([]);
const categoryId = ref(''), dateFrom = ref(''), dateTo = ref(''), page = ref(1), lastPage = ref(1), total = ref(0);
const loading = ref(false), error = ref(''), transferSummary = ref<{ eligible_students: number; transferred_students: number; transfer_rate: number } | null>(null);
const config = computed(() => reportOptions.find(report => report.key === activeReport.value)!);
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

async function load(targetPage = page.value) {
  loading.value = true; error.value = ''; page.value = targetPage;
  try {
    const params = new URLSearchParams({ report: activeReport.value, page: String(page.value) });
    if (categoryId.value) params.set('category_id', categoryId.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    const response = await fetch(`/api/admin/reports?${params}`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'Report could not be loaded.');
    rows.value = data.rows.data; page.value = data.rows.current_page; lastPage.value = data.rows.last_page; total.value = data.rows.total;
    categories.value = data.filters.categories; transferSummary.value = data.transfer_summary;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function chooseReport(key: ReportKey) { activeReport.value = key; page.value = 1; void load(1); }
function applyFilters() { void load(1); }
function display(row: ReportRow, column: Column): string {
  const value = row[column.key];
  if (value === null || value === undefined || value === '') return '—';
  if (column.format === 'pace') return `Group ${value}`;
  if (column.format === 'target') return `${value} lessons / week`;
  if (column.format === 'percentage') return `${value}%`;
  if (column.format === 'date') return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(String(value)));
  return String(value);
}
onMounted(() => void load(1));
</script>

<template>
  <section class="admin-reports-page">
    <nav class="report-tabs" aria-label="Report types"><button v-for="report in reportOptions" :key="report.key" :class="{ active: activeReport === report.key }" :aria-current="activeReport === report.key ? 'page' : undefined" @click="chooseReport(report.key)"><FileText :size="15" /><span>{{ report.title }}</span></button></nav>
    <div class="reports-panel">
      <div class="report-heading"><div><p class="eyebrow">ADMIN REPORTS</p><h2>{{ config.title }}</h2><p>{{ config.description }}</p></div><span class="report-record-count">{{ total }} records</span></div>
      <div class="report-filters"><label>Category<select v-model="categoryId"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (Archived)' : '' }}</option></select></label><label>From<input v-model="dateFrom" type="date"></label><label>To<input v-model="dateTo" type="date" :min="dateFrom || undefined"></label><button class="primary" @click="applyFilters">Apply filters</button></div>
      <div v-if="transferSummary" class="transfer-summary"><article><span>Eligible students</span><b>{{ transferSummary.eligible_students }}</b></article><article><span>Transferred students</span><b>{{ transferSummary.transferred_students }}</b></article><article><span>Transfer rate</span><b>{{ transferSummary.transfer_rate }}%</b></article></div>
      <p v-if="error" class="student-error" role="alert">{{ error }}</p><div v-if="loading" class="category-empty-state">Loading report…</div><div v-else-if="!rows.length" class="category-empty-state">No records match these filters.</div>
      <div v-else class="report-table-wrap"><table class="report-table"><thead><tr><th v-for="column in config.columns" :key="column.key">{{ column.label }}</th></tr></thead><tbody><tr v-for="(row, index) in rows" :key="String(row.id ?? row.student_id ?? row.lesson_id ?? index)"><td v-for="column in config.columns" :key="column.key">{{ display(row, column) }}</td></tr></tbody></table></div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1 || loading" @click="load(page - 1)"><ChevronLeft :size="15" /> Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage || loading" @click="load(page + 1)">Next <ChevronRight :size="15" /></button></div>
      <p class="report-pagination-note"><BarChart3 :size="14" /> Reports are aggregated on the server and returned 25 rows at a time.</p>
    </div>
  </section>
</template>
