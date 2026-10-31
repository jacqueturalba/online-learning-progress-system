<script setup lang="ts">
import { ChartNoAxesColumnIncreasing, Check, ChevronRight, CirclePlay, Clock3, FileText, UsersRound } from '@lucide/vue';

type DashboardData = {
  total_students: number;
  active_students: number;
  total_lessons: number;
  completed_lessons: number;
  transfer_eligible_students: number;
  students_by_category: { id: number; name: string; is_active: boolean; is_archived: boolean; students_count: number }[];
  recent_activity: { student_name: string; lesson_title: string; category_name: string | null; last_watched_at: string; is_completed: boolean }[];
};

defineProps<{ dashboard: DashboardData | null; loading: boolean; error: string }>();

const links = [
  { label: 'Students', href: '/admin/students', icon: UsersRound },
  { label: 'Lessons', href: '/admin/lessons', icon: CirclePlay },
  { label: 'Progress', href: '/admin/progress', icon: ChartNoAxesColumnIncreasing },
  { label: 'Realizations', href: '/admin/realizations', icon: Check },
  { label: 'Reports', href: '/admin/reports', icon: FileText },
];

function formatActivityDate(date: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(date));
}
</script>

<template>
  <div v-if="loading" class="dashboard-message" role="status">Loading operational summaries…</div>
  <div v-else-if="error" class="dashboard-message dashboard-error" role="alert">Unable to load dashboard data: {{ error }}</div>
  <div v-else-if="dashboard" class="admin-dashboard">
    <section class="dashboard-metrics" aria-label="Operational summaries">
      <article class="dashboard-metric">
        <span class="dashboard-metric-icon blue"><UsersRound :size="19" /></span>
        <div><p>Total students</p><strong>{{ dashboard.total_students }}</strong><small>Registered student accounts</small></div>
      </article>
      <article class="dashboard-metric">
        <span class="dashboard-metric-icon green"><Clock3 :size="19" /></span>
        <div><p>Active students</p><strong>{{ dashboard.active_students }}</strong><small>Learning activity in the past 30 days</small></div>
      </article>
      <article class="dashboard-metric">
        <span class="dashboard-metric-icon sky"><CirclePlay :size="19" /></span>
        <div><p>Total lessons</p><strong>{{ dashboard.total_lessons }}</strong><small>Published and draft lessons</small></div>
      </article>
      <article class="dashboard-metric">
        <span class="dashboard-metric-icon fresh"><Check :size="19" /></span>
        <div><p>Completed lessons</p><strong>{{ dashboard.completed_lessons }}</strong><small>Completions across all students</small></div>
      </article>
      <article class="dashboard-metric dashboard-transfer">
        <span class="dashboard-metric-icon green"><ChevronRight :size="19" /></span>
        <div><p>Eligible for category transfer</p><strong>{{ dashboard.transfer_eligible_students }}</strong><small>Students who completed an active category</small></div>
      </article>
    </section>

    <section class="dashboard-panel dashboard-quick-links" aria-labelledby="dashboard-links-title">
      <div class="dashboard-panel-heading"><div><p class="eyebrow">QUICK ACCESS</p><h2 id="dashboard-links-title">Go to</h2></div></div>
      <nav aria-label="Dashboard quick links">
        <a v-for="link in links" :key="link.href" :href="link.href"><component :is="link.icon" :size="18" /><span>{{ link.label }}</span><ChevronRight :size="16" class="dashboard-link-arrow" /></a>
      </nav>
    </section>

    <div class="dashboard-detail-grid">
      <section class="dashboard-panel" aria-labelledby="category-students-title">
        <div class="dashboard-panel-heading"><div><p class="eyebrow">LEARNING DISTRIBUTION</p><h2 id="category-students-title">Students by category</h2></div></div>
        <div v-if="dashboard.students_by_category.length" class="dashboard-category-list">
          <div v-for="category in dashboard.students_by_category" :key="category.id" class="dashboard-category-row">
            <div class="dashboard-category-label"><strong>{{ category.name }}</strong><span v-if="category.is_archived" class="dashboard-archived">Archived</span><span v-else-if="!category.is_active">Unavailable</span><span>{{ category.students_count }} {{ category.students_count === 1 ? 'student' : 'students' }}</span></div>
            <div class="dashboard-category-track"><span :style="{ width: `${dashboard.total_students ? Math.min(100, category.students_count / dashboard.total_students * 100) : 0}%` }"></span></div>
          </div>
        </div>
        <p v-else class="dashboard-empty">No learning categories have been created.</p>
        <p class="dashboard-footnote">Students with recorded learning activity in each category.</p>
      </section>

      <section class="dashboard-panel" aria-labelledby="recent-activity-title">
        <div class="dashboard-panel-heading"><div><p class="eyebrow">LATEST LEARNING</p><h2 id="recent-activity-title">Recent student activity</h2></div></div>
        <ul v-if="dashboard.recent_activity.length" class="dashboard-activity-list">
          <li v-for="(activity, index) in dashboard.recent_activity" :key="`${activity.student_name}-${activity.last_watched_at}-${index}`">
            <span class="dashboard-activity-icon" :class="{ complete: activity.is_completed }"><Check v-if="activity.is_completed" :size="16" /><CirclePlay v-else :size="16" /></span>
            <div class="dashboard-activity-copy"><strong>{{ activity.student_name }}</strong><span>{{ activity.lesson_title }}<template v-if="activity.category_name"> · {{ activity.category_name }}</template></span></div>
            <time :datetime="activity.last_watched_at">{{ formatActivityDate(activity.last_watched_at) }}</time>
          </li>
        </ul>
        <p v-else class="dashboard-empty">Student learning activity will appear here.</p>
      </section>
    </div>
  </div>
</template>
