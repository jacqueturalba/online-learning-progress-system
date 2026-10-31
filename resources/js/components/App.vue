<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BookOpen, ChartNoAxesColumnIncreasing, Check, ChevronRight, CirclePlay, ClipboardCheck, Clock3, FileText, FolderTree, GraduationCap, House, LayoutDashboard, LogOut, Menu, Play, Settings, UserRound, UsersRound, Video, X } from '@lucide/vue';
import AdminDashboard from './AdminDashboard.vue';
import AdminStudents from './AdminStudents.vue';
import AdminCategories from './AdminCategories.vue';
import AdminLessons from './AdminLessons.vue';
import AdminZoom from './AdminZoom.vue';
import AdminProgress from './AdminProgress.vue';
import AdminRealizations from './AdminRealizations.vue';
import AdminReports from './AdminReports.vue';

type User = { id: number; name: string; email: string; role: 'admin' | 'student' };
type LessonProgress = { last_position_seconds: number; watched_seconds: number; progress_percentage: number; is_completed: boolean; last_watched_at: string | null };
type Lesson = { id: number; title: string; description?: string; video_url?: string; duration_seconds?: number; sort_order: number; progress: LessonProgress | null };
type Category = { id: number; name: string; description?: string; total_lessons: number; completed_lessons: number; progress_percentage: number; lessons: Lesson[] };
type AdminDashboard = {
  total_students: number;
  active_students: number;
  total_lessons: number;
  completed_lessons: number;
  transfer_eligible_students: number;
  students_by_category: { id: number; name: string; is_active: boolean; is_archived: boolean; students_count: number }[];
  recent_activity: { student_name: string; lesson_title: string; category_name: string | null; last_watched_at: string; is_completed: boolean }[];
};
type AdminSection = 'dashboard' | 'students' | 'categories' | 'lessons' | 'zoom' | 'progress' | 'realizations' | 'reports' | 'settings';
const adminNavigation = [
  { section: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, description: 'Your administration workspace.' },
  { section: 'students', label: 'Students', icon: UsersRound, description: 'Search, review student records, and manage pace groups.' },
  { section: 'categories', label: 'Categories', icon: FolderTree, description: 'The protected category management API is ready for its admin interface.' },
  { section: 'lessons', label: 'Lessons', icon: CirclePlay, description: 'Manage video lessons, publishing, and category order.' },
  { section: 'zoom', label: 'Zoom', icon: Video, description: 'Configure Zoom integration and manage student links.' },
  { section: 'progress', label: 'Progress', icon: ChartNoAxesColumnIncreasing, description: 'Review verified student learning activity and history.' },
  { section: 'realizations', label: 'Realizations', icon: ClipboardCheck, description: 'Review student lesson realizations and learning context.' },
  { section: 'reports', label: 'Reports', icon: FileText, description: 'Review lesson completion, category progress, realizations, and transfers.' },
  { section: 'settings', label: 'Settings', icon: Settings, description: 'System settings will be available here.' },
] as const;
function getAdminSection(): AdminSection {
  const requested = location.pathname.split('/').filter(Boolean)[1] as AdminSection | undefined;
  return adminNavigation.some(item => item.section === requested) ? requested! : 'dashboard';
}
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const user = ref<User | null>(null), categories = ref<Category[]>([]), loading = ref(true), error = ref(''), notice = ref(''), playbackError = ref(''), autoplayBlocked = ref(false), replayRequired = ref(false), selected = ref<Lesson | null>(null), navOpen = ref(false), loginMode = ref(true), resetMode = ref(false), expandedCategories = ref<number[]>([]), realizationText = ref(''), realizationNotice = ref(''), realizationError = ref(''), realizationSaving = ref(false);
const adminSection = ref<AdminSection>(getAdminSection());
const activeAdminPage = computed(() => adminNavigation.find(item => item.section === adminSection.value) ?? adminNavigation[0]);
const adminDashboard = ref<AdminDashboard | null>(null), dashboardLoading = ref(false), dashboardError = ref('');
const resetToken = ref(location.pathname.startsWith('/reset-password/') ? decodeURIComponent(location.pathname.split('/').pop() || '') : '');
const resetEmail = ref(new URLSearchParams(location.search).get('email') || '');
const totalLessons = computed(() => categories.value.reduce((n, c) => n + c.total_lessons, 0));
const completeLessons = computed(() => categories.value.reduce((n, c) => n + c.completed_lessons, 0));
const overall = computed(() => totalLessons.value ? Math.round(completeLessons.value / totalLessons.value * 100) : 0);
const allLessons = computed(() => categories.value.flatMap(category => category.lessons));
const unfinishedLessons = computed(() => allLessons.value.filter(lesson => !lesson.progress?.is_completed));
const continueLesson = computed(() => [...unfinishedLessons.value]
  .filter(lesson => lesson.progress?.last_watched_at)
  .sort((a, b) => Date.parse(b.progress!.last_watched_at!) - Date.parse(a.progress!.last_watched_at!))[0] ?? unfinishedLessons.value[0] ?? null);
const nextCategoryLesson = computed(() => {
  if (!selected.value) return null;
  const currentCategoryIndex = categories.value.findIndex(category => category.lessons.some(lesson => lesson.id === selected.value?.id));
  return categories.value.slice(currentCategoryIndex + 1).find(category => category.lessons.length)?.lessons[0] ?? null;
});
type PlaybackUpdate = { lessonId: number; positionSeconds: number; isPlaying: boolean };
let autoplayOnLoad = false;
let playbackQueue: Promise<LessonProgress | null> = Promise.resolve(null);
let lastReportedSecond = -1;
async function api(url: string, init: RequestInit = {}) {
  const res = await fetch(url, { credentials: 'same-origin', ...init, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...init.headers } });
  const body = await res.json().catch(() => ({})); if (!res.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Something went wrong.'); return body;
}
async function load() {
    loading.value = true;

    try {
        const response = await api('/api/me');
        user.value = response.user;

        categories.value = user.value?.role === 'student'
            ? (await api('/api/learning')).categories
            : [];
        if (user.value?.role === 'admin') {
            if (location.pathname === '/') history.replaceState({}, '', '/admin/dashboard');
            adminSection.value = getAdminSection();
            if (adminSection.value === 'dashboard') {
                dashboardLoading.value = true;
                dashboardError.value = '';
                try {
                    adminDashboard.value = await api('/api/admin/dashboard');
                } catch (e) {
                    dashboardError.value = (e as Error).message;
                } finally {
                    dashboardLoading.value = false;
                }
            }
        }
    } catch {
        user.value = null;
        categories.value = [];
    } finally {
        loading.value = false;
    }
}
async function authenticate(e: Event) {
  const data = Object.fromEntries(new FormData(e.target as HTMLFormElement)); error.value = '';
  try { await api(loginMode.value ? '/login' : '/register', { method: 'POST', body: JSON.stringify(data) }); await load(); } catch (e) { error.value = (e as Error).message; }
}
async function requestReset(e: Event) {
  const data = Object.fromEntries(new FormData(e.target as HTMLFormElement)); error.value = '';
  try { const result = await api('/forgot-password', { method: 'POST', body: JSON.stringify(data) }); notice.value = result.message; } catch (e) { error.value = (e as Error).message; }
}
async function submitReset(e: Event) {
  const data = Object.fromEntries(new FormData(e.target as HTMLFormElement)); error.value = '';
  try { await api('/reset-password', { method: 'POST', body: JSON.stringify({ ...data, token: resetToken.value }) }); history.replaceState({}, '', '/'); resetToken.value = ''; loginMode.value = true; notice.value = 'Password updated. Sign in with your new password.'; } catch (e) { error.value = (e as Error).message; }
}
async function signOut() {
  const wasAdmin = user.value?.role === 'admin';
  await api('/logout', { method: 'POST' });
  user.value = null;
  categories.value = [];
  if (wasAdmin && location.pathname.startsWith('/admin')) {
    history.replaceState({}, '', '/');
    adminSection.value = 'dashboard';
  }
}
function watchLesson(lesson: Lesson, autoplay = true) {
  selected.value = lesson;
  realizationText.value = ''; realizationNotice.value = ''; realizationError.value = '';
  autoplayOnLoad = autoplay;
  autoplayBlocked.value = false;
  replayRequired.value = false;
  playbackError.value = '';
  lastReportedSecond = lesson.progress?.last_position_seconds ?? -1;
}
async function submitRealization() {
  if (!selected.value || realizationSaving.value) return;
  realizationSaving.value = true; realizationNotice.value = ''; realizationError.value = '';
  try {
    await api(`/api/lessons/${selected.value.id}/realizations`, { method: 'POST', body: JSON.stringify({ realization: realizationText.value }) });
    realizationText.value = ''; realizationNotice.value = 'Your realization has been saved.';
  } catch (caught) { realizationError.value = (caught as Error).message; }
  finally { realizationSaving.value = false; }
}
function toggleCategory(categoryId: number) {
  expandedCategories.value = expandedCategories.value.includes(categoryId)
    ? expandedCategories.value.filter(id => id !== categoryId)
    : [...expandedCategories.value, categoryId];
}
function formatTime(seconds?: number) { if (seconds === undefined || seconds === null) return 'On demand'; if (seconds < 60) return `${seconds} sec`; const minutes = Math.floor(seconds / 60); const remainder = seconds % 60; return remainder ? `${minutes} min ${remainder} sec` : `${minutes} min`; }
function postPlaybackUpdate(update: PlaybackUpdate): Promise<LessonProgress | null> {
  const task = playbackQueue.catch(() => null).then(async () => {
    try {
      const response = await api(`/api/lessons/${update.lessonId}/progress`, {
        method: 'PUT',
        body: JSON.stringify({ position_seconds: update.positionSeconds, is_playing: update.isPlaying }),
      });
      const progress = response.progress as LessonProgress;
      if (selected.value?.id === update.lessonId) selected.value = { ...selected.value, progress };
      playbackError.value = '';
      return progress;
    } catch (e) {
      playbackError.value = (e as Error).message || 'Progress could not be saved. Try again.';
      return null;
    }
  });
  playbackQueue = task;
  return task;
}
async function syncPlayback(event: Event, lessonId: number, isPlaying: boolean, force = false): Promise<LessonProgress | null> {
  const video = event.currentTarget as HTMLVideoElement;
  const second = Math.max(0, Math.floor(video.currentTime));
  if (!force && Math.abs(second - lastReportedSecond) < 10) return null;
  lastReportedSecond = second;
  return postPlaybackUpdate({ lessonId, positionSeconds: second, isPlaying });
}
async function resumePlayback(event: Event, lessonId: number) {
  const video = event.currentTarget as HTMLVideoElement;
  const lesson = selected.value?.id === lessonId ? selected.value : null;
  if (lesson?.progress?.last_position_seconds) video.currentTime = lesson.progress.last_position_seconds;
  void syncPlayback(event, lessonId, false, true);
  if (autoplayOnLoad) {
    autoplayOnLoad = false;
    await tryPlay(video);
  }
}
async function tryPlay(video: HTMLVideoElement) {
  try {
    await video.play();
    autoplayBlocked.value = false;
  } catch {
    autoplayBlocked.value = true;
  }
}
async function continueAfterAutoplayBlock() {
  const video = document.querySelector<HTMLVideoElement>('.lesson-modal video');
  if (!video) return;
  autoplayBlocked.value = false;
  await tryPlay(video);
}
async function replayLesson() {
  const video = document.querySelector<HTMLVideoElement>('.lesson-modal video');
  if (!video) return;
  video.currentTime = 0;
  replayRequired.value = false;
  playbackError.value = '';
  await tryPlay(video);
}
function handlePlay(event: Event, lessonId: number) { void syncPlayback(event, lessonId, true, true); }
function handlePause(event: Event, lessonId: number) { void syncPlayback(event, lessonId, false, true); }
function handleTimeUpdate(event: Event, lessonId: number) {
  const video = event.currentTarget as HTMLVideoElement;
  if (Math.abs(Math.floor(video.currentTime) - lastReportedSecond) >= 10) {
    void syncPlayback(event, lessonId, !video.paused && !video.ended);
  }
}
function lessonAfter(lessonId: number): Lesson | null {
  const category = categories.value.find(item => item.lessons.some(lesson => lesson.id === lessonId));
  if (!category) return null;
  const index = category.lessons.findIndex(lesson => lesson.id === lessonId);
  return category.lessons[index + 1] ?? null;
}
async function handleEnded(event: Event, lessonId: number) {
  const progress = await syncPlayback(event, lessonId, false, true);
  if (!progress?.is_completed) {
    playbackError.value = 'This lesson needs a little more verified watch time. Replay it to finish.';
    replayRequired.value = true;
    return;
  }
  await load();
  const nextLessonInCategory = lessonAfter(lessonId);
  if (nextLessonInCategory) watchLesson(nextLessonInCategory, true);
}
async function closeLesson() {
  const video = document.querySelector<HTMLVideoElement>('.lesson-modal video');
  const lessonId = selected.value?.id;
  if (video && lessonId) await postPlaybackUpdate({ lessonId, positionSeconds: Math.floor(video.currentTime), isPlaying: false });
  selected.value = null;
}
onMounted(() => {
  load();
  window.addEventListener('pagehide', () => {
    const video = document.querySelector<HTMLVideoElement>('.lesson-modal video');
    const lessonId = selected.value?.id;
    if (!video || !lessonId) return;
    void fetch(`/api/lessons/${lessonId}/progress`, {
      method: 'PUT', keepalive: true, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify({ position_seconds: Math.floor(video.currentTime), is_playing: false }),
    });
  });
});
</script>

<template>
  <main v-if="loading" class="loading"><div class="spinner"></div><span>Getting your learning space ready…</span></main>
  <section v-else-if="!user" class="auth-page">
    <div class="auth-art"><div class="art-mark"><GraduationCap :size="28" /></div><div><p class="eyebrow">A LITTLE PROGRESS, EVERY DAY</p><h1>Make room<br>to grow.</h1><p class="art-copy">A calmer way to learn, one lesson at a time.</p></div><div class="art-footer">Learnwell <span>·</span> Your learning journey</div></div>
    <div class="auth-form-wrap">
      <form v-if="resetToken" class="auth-form" @submit.prevent="submitReset"><div class="mobile-brand"><GraduationCap /> Learnwell</div><p class="eyebrow">ACCOUNT RECOVERY</p><h2>Choose a new password.</h2><p class="muted">Set a new password to get back to learning.</p><label>Email address<input v-model="resetEmail" name="email" type="email" autocomplete="email" required></label><label>New password<input name="password" type="password" autocomplete="new-password" required minlength="8" placeholder="At least 8 characters"></label><label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" placeholder="Enter it again"></label><p v-if="error" class="error">{{ error }}</p><button class="primary wide">Update password <ChevronRight :size="17" /></button></form>
      <form v-else-if="resetMode" class="auth-form" @submit.prevent="requestReset"><div class="mobile-brand"><GraduationCap /> Learnwell</div><p class="eyebrow">ACCOUNT RECOVERY</p><h2>Forgot your password?</h2><p class="muted">We’ll email you a link to choose a new one.</p><label>Email address<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label><p v-if="error" class="error">{{ error }}</p><p v-if="notice" class="success">{{ notice }}</p><button class="primary wide">Send reset link <ChevronRight :size="17" /></button><p class="switch"><button type="button" @click="resetMode = false; error = ''; notice = ''">Back to sign in</button></p></form>
      <form v-else class="auth-form" @submit.prevent="authenticate"><div class="mobile-brand"><GraduationCap /> Learnwell</div><p class="eyebrow">{{ loginMode ? 'WELCOME BACK' : 'GET STARTED' }}</p><h2>{{ loginMode ? 'Good to see you.' : 'Create your account.' }}</h2><p class="muted">{{ loginMode ? 'Pick up where you left off.' : 'Your next chapter starts here.' }}</p><p v-if="notice" class="success">{{ notice }}</p><label v-if="!loginMode">Your name<input name="name" autocomplete="name" required placeholder="Alex Morgan"></label><label>Email address<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label><label>Password<input name="password" type="password" :autocomplete="loginMode ? 'current-password' : 'new-password'" required minlength="8" placeholder="At least 8 characters"></label><label v-if="!loginMode">Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" placeholder="Enter it again"></label><p v-if="error" class="error">{{ error }}</p><button class="primary wide">{{ loginMode ? 'Sign in' : 'Create account' }} <ChevronRight :size="17" /></button><p v-if="loginMode" class="switch"><button type="button" @click="resetMode = true; error = ''">Forgot password?</button></p><p class="switch">{{ loginMode ? 'New to Learnwell?' : 'Already have an account?' }} <button type="button" @click="loginMode = !loginMode; error = ''">{{ loginMode ? 'Create an account' : 'Sign in' }}</button></p></form>
    </div>
  </section>
  <div v-else class="app-shell">
    <aside v-if="user.role === 'admin'" class="sidebar admin-sidebar" :class="{ 'sidebar-open': navOpen }"><a class="brand" href="/admin/dashboard"><span class="brand-icon"><GraduationCap :size="21" /></span> learnwell</a><div class="nav-label">ADMINISTRATION</div><nav aria-label="Admin navigation"><a v-for="item in adminNavigation" :key="item.section" :href="'/admin/' + item.section" class="nav-item admin-nav-item" :class="{ active: adminSection === item.section }" :aria-current="adminSection === item.section ? 'page' : undefined" @click="navOpen = false"><component :is="item.icon" :size="18" /><span>{{ item.label }}</span></a></nav><div class="sidebar-bottom"><button class="profile" @click="signOut"><span class="avatar">{{ user.name.charAt(0).toUpperCase() }}</span><span class="profile-text"><b>{{ user.name }}</b><small>Administrator</small></span><LogOut :size="16" /></button></div></aside><aside v-else class="sidebar" :class="{ 'sidebar-open': navOpen }"><a class="brand" href="#"><span class="brand-icon"><GraduationCap :size="21" /></span> learnwell</a><div class="nav-label">YOUR SPACE</div><nav><a class="nav-item active"><House :size="18" />Home</a><a class="nav-item" href="#learning"><BookOpen :size="18" />My learning</a><a class="nav-item" href="#progress"><Check :size="18" />Progress</a><a class="nav-item"><UserRound :size="18" />Profile</a></nav><div class="sidebar-bottom"><div class="help-card"><div class="help-icon">✦</div><b>Keep your rhythm</b><p>Small steps add up. You’re doing great.</p></div><button class="profile" @click="signOut"><span class="avatar">{{ user.name.charAt(0).toUpperCase() }}</span><span class="profile-text"><b>{{ user.name }}</b><small>Student</small></span><LogOut :size="16" /></button></div></aside>
    <div v-if="navOpen" class="backdrop" @click="navOpen = false"></div>
    <section class="main-area"><header class="topbar"><button class="icon-button mobile-menu" @click="navOpen = !navOpen"><Menu :size="20" /></button><span class="crumb"><span v-if="user.role === 'admin'">Administration</span><span v-else>Your space</span><ChevronRight :size="14" /> <b>{{ user.role === 'admin' ? activeAdminPage.label : 'Home' }}</b></span><button class="profile-chip" @click="signOut"><span class="avatar small">{{ user.name.charAt(0).toUpperCase() }}</span><span>{{ user.name.split(' ')[0] }}</span><LogOut :size="15" /></button></header>
      <div v-if="user.role === 'admin'" class="content admin-content"><div class="welcome"><div><p class="eyebrow">ADMIN WORKSPACE</p><h1>{{ activeAdminPage.section === 'dashboard' ? 'Welcome, ' + user.name.split(' ')[0] + '.' : activeAdminPage.label }}</h1><p class="muted">{{ activeAdminPage.description }}</p></div></div><AdminDashboard v-if="activeAdminPage.section === 'dashboard'" :dashboard="adminDashboard" :loading="dashboardLoading" :error="dashboardError" /><AdminStudents v-else-if="activeAdminPage.section === 'students'" /><AdminCategories v-else-if="activeAdminPage.section === 'categories'" /><AdminLessons v-else-if="activeAdminPage.section === 'lessons'" /><AdminZoom v-else-if="activeAdminPage.section === 'zoom'" /><AdminProgress v-else-if="activeAdminPage.section === 'progress'" /><AdminRealizations v-else-if="activeAdminPage.section === 'realizations'" /><AdminReports v-else-if="activeAdminPage.section === 'reports'" /><div v-else class="admin-notice"><component :is="activeAdminPage.icon" :size="22" /><div><b>{{ activeAdminPage.label }} workspace</b><p>This workspace is ready for its next management phase.</p></div></div></div><div v-else class="content"><div class="welcome"><div><p class="eyebrow">{{ new Date().toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric' }).toUpperCase() }}</p><h1>Good morning, {{ user.name.split(' ')[0] }}<span class="wave">✳</span></h1><p class="muted">Every lesson brings you a little further.</p></div><div class="streak"><span class="streak-icon">✦</span><div><b>{{ completeLessons }} <small>of {{ totalLessons }} lessons</small></b><span>completed so far</span></div></div></div>
        <div class="hero"><div class="hero-copy"><span class="hero-tag"><CirclePlay :size="14" /> YOUR NEXT STEP</span><h2>{{ continueLesson ? 'Ready when you are.' : 'A journey well learned.' }}</h2><p>{{ continueLesson ? `Pick up ${continueLesson.title} and keep your momentum going.` : 'You’ve completed every published lesson. Nice work.' }}</p><button v-if="continueLesson" class="hero-button" @click="watchLesson(continueLesson, true)"><Play :size="15" fill="currentColor" /> Continue learning <span v-if="continueLesson.progress?.last_position_seconds">· Resume at {{ formatTime(continueLesson.progress.last_position_seconds) }}</span></button></div><div class="hero-art"><div class="sun"></div><div class="hill hill-back"></div><div class="hill hill-front"></div><div class="book-art"><BookOpen :size="49" :stroke-width="1.2" /></div></div></div>
        <section id="learning" class="learning-section"><div class="section-heading"><div><p class="eyebrow">YOUR CURRICULUM</p><h2>Learning path</h2></div><a href="#progress" class="text-link">View progress <ChevronRight :size="16" /></a></div><div v-if="!categories.length" class="empty">Your courses will appear here once they’re published.</div><div class="category-grid"><article v-for="(category, i) in categories" :key="category.id" class="category-card"><div class="card-top"><span class="category-icon" :class="`tone-${i % 4}`"><BookOpen :size="19" /></span><span class="lesson-count">{{ category.completed_lessons }}/{{ category.total_lessons }} lessons</span></div><h3>{{ category.name }}</h3><p class="category-desc">{{ category.description || 'Build your knowledge at your own pace.' }}</p><div class="progress-meta"><span>{{ category.progress_percentage }}% complete</span><span>{{ category.total_lessons - category.completed_lessons }} to go</span></div><div class="progress-track"><span :style="{ width: `${category.progress_percentage}%` }"></span></div><button v-for="lesson in (expandedCategories.includes(category.id) ? category.lessons : category.lessons.slice(0, 2))" :key="lesson.id" class="lesson-row" @click="watchLesson(lesson, true)"><span class="lesson-state" :class="{ done: lesson.progress?.is_completed, 'in-progress': !lesson.progress?.is_completed && !!lesson.progress?.last_position_seconds, available: !lesson.progress?.is_completed && !lesson.progress?.last_position_seconds }" :aria-label="lesson.progress?.is_completed ? 'Completed' : (lesson.progress?.last_position_seconds ? 'In progress' : 'Available')">{{ lesson.progress?.is_completed ? '✓' : '▶' }}</span><span class="lesson-name">{{ lesson.title }}</span><span v-if="lesson.progress?.is_completed" class="resume-label">Complete</span><span v-else-if="lesson.progress?.last_position_seconds" class="resume-label">Resume</span><ChevronRight :size="16" /></button><button v-if="category.lessons.length > 2" class="show-lessons" @click="toggleCategory(category.id)">{{ expandedCategories.includes(category.id) ? 'Show fewer lessons' : `Show all ${category.lessons.length} lessons` }}</button></article></div></section>
        <section id="progress" class="progress-overview"><div class="section-heading"><div><p class="eyebrow">YOUR MOMENTUM</p><h2>Progress overview</h2></div></div><div class="overview-card"><div class="overall-ring" :style="{ '--progress': `${overall * 3.6}deg` }"><div><b>{{ overall }}%</b><small>overall</small></div></div><div class="overview-text"><h3>You’re building a great habit.</h3><p>You’ve finished <b>{{ completeLessons }}</b> out of <b>{{ totalLessons }}</b> available lessons. Keep going at a pace that feels right.</p></div><div class="overview-stat"><span><Check :size="17" /></span><div><b>{{ completeLessons }}</b><small>lessons completed</small></div></div></div></section>
      </div>
    </section>
    <div v-if="selected" class="modal-backdrop" @click.self="closeLesson"><section class="lesson-modal"><button class="modal-close" aria-label="Close lesson" @click="closeLesson"><X :size="19" /></button><p class="eyebrow">LESSON</p><h2>{{ selected.title }}</h2><p class="muted">{{ selected.description || 'Take your time and enjoy this lesson.' }}</p><video v-if="selected.video_url" class="video" controls playsinline :src="selected.video_url" :data-lesson-id="selected.id" @loadedmetadata="resumePlayback($event, selected.id)" @play="handlePlay($event, selected.id)" @pause="handlePause($event, selected.id)" @timeupdate="handleTimeUpdate($event, selected.id)" @ended="handleEnded($event, selected.id)" /><div v-else class="video-placeholder"><CirclePlay :size="38" /><span>This lesson’s video hasn’t been added yet.</span></div><p v-if="playbackError" class="error playback-error" role="status">{{ playbackError }}</p><button v-if="autoplayBlocked" class="primary continue-button" @click="continueAfterAutoplayBlock">Continue lesson <Play :size="16" fill="currentColor" /></button><button v-if="replayRequired" class="primary continue-button" @click="replayLesson">Replay lesson <Play :size="16" fill="currentColor" /></button><form class="student-realization-form" @submit.prevent="submitRealization"><label for="student-realization">What did you take away from this lesson?</label><textarea id="student-realization" v-model="realizationText" maxlength="20000" required minlength="3" rows="3" placeholder="Write a short reflection or describe what you practiced."></textarea><p v-if="realizationNotice" class="success" role="status">{{ realizationNotice }}</p><p v-if="realizationError" class="error" role="alert">{{ realizationError }}</p><button class="primary" :disabled="realizationSaving">{{ realizationSaving ? 'Saving…' : 'Save realization' }}</button></form><div class="modal-footer"><span><Clock3 :size="16" /> {{ formatTime(selected.duration_seconds) }}</span><span v-if="selected.progress?.is_completed" class="completed-pill"><Check :size="16" /> Lesson complete</span><button v-if="selected.progress?.is_completed && nextCategoryLesson" class="primary" @click="watchLesson(nextCategoryLesson, true)">Continue to next category <ChevronRight :size="16" /></button></div></section></div>
  </div>
</template>
