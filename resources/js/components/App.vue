<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { BookOpen, ChartNoAxesColumnIncreasing, Check, ChevronRight, CirclePlay, ClipboardCheck, Clock3, FileText, FolderTree, GraduationCap, House, LayoutDashboard, LogOut, Menu, Play, Settings, UserRound, UsersRound, Video, X } from '@lucide/vue';
import AdminDashboard from './AdminDashboard.vue';
import AdminStudents from './AdminStudents.vue';
import AdminCategories from './AdminCategories.vue';
import AdminLessons from './AdminLessons.vue';
import AdminZoom from './AdminZoom.vue';
import AdminProgress from './AdminProgress.vue';
import AdminRealizations from './AdminRealizations.vue';
import AdminReports from './AdminReports.vue';
import AdminBranding from './AdminBranding.vue';
import StudentProfile from './StudentProfile.vue';
import StudentZoom from './StudentZoom.vue';

type User = { id: number; name: string; email: string; role: 'admin' | 'super_admin' | 'student'; country?: string|null; address?: string|null; phone_country_code?: string|null; phone_number?: string|null };
type Branding = { brand_name: string; brand_description: string; colors: { primary: string; secondary: string; accent: string; background: string; text: string }; logo_url: string | null; hero_image_url?: string; daily_verse_text?: string; daily_verse_reference?: string };
type LessonProgress = { last_position_seconds: number; watched_seconds: number; progress_percentage: number; is_completed: boolean; last_watched_at: string | null };
type Lesson = { id: number; title: string; description?: string; thumbnail_url?: string | null; video_stream_url?: string | null; duration_seconds?: number; sort_order: number; is_unlocked: boolean; realization_submitted: boolean; progress: LessonProgress | null };
type Category = { id: number; name: string; description?: string; thumbnail_url?: string | null; background_color?: string | null; total_lessons: number; completed_lessons: number; progress_percentage: number; lessons: Lesson[] };
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
  { section: 'settings', label: 'Branding', icon: Settings, description: 'Update your site name, logo, tagline, and colors.' },
] as const;
function getAdminSection(): AdminSection {
  const requested = location.pathname.split('/').filter(Boolean)[1] as AdminSection | undefined;
  return adminNavigation.some(item => item.section === requested) ? requested! : 'dashboard';
}
async function openEnrollmentForm() { studentPage.value = 'profile'; await nextTick(); document.getElementById('profile-enrollment-title')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const defaultBranding: Branding = { brand_name: 'Learnwell', brand_description: 'Learn at your own pace.', logo_url: null, colors: { primary: '#087E9B', secondary: '#126B52', accent: '#855F18', background: '#F7FAFC', text: '#17212B' } };
const branding = ref<Branding>({ ...defaultBranding, colors: { ...defaultBranding.colors } });
const studentPage = ref<'home' | 'profile' | 'zoom'>('home');
const enrollment = ref<{eligible:boolean;requirement_enabled:boolean;category_name:string|null;lesson_number:number;status:string;revision_reason:string|null}>({eligible:false,requirement_enabled:false,category_name:null,lesson_number:0,status:'not_started',revision_reason:null});
const user = ref<User | null>(null), categories = ref<Category[]>([]), loading = ref(true), error = ref(''), notice = ref(''), playbackError = ref(''), navigationNotice = ref(''), autoplayBlocked = ref(false), replayRequired = ref(false), selected = ref<Lesson | null>(null), navOpen = ref(false), loginMode = ref(true), resetMode = ref(false), lessonPages = ref<Record<number, number>>({}), realizationText = ref(''), realizationNotice = ref(''), realizationError = ref(''), realizationSaving = ref(false);
const adminSection = ref<AdminSection>(getAdminSection());
const lessonView = ref<'grid' | 'list'>('grid');
const activeAdminPage = computed(() => adminNavigation.find(item => item.section === adminSection.value) ?? adminNavigation[0]);
const adminDashboard = ref<AdminDashboard | null>(null), dashboardLoading = ref(false), dashboardError = ref('');
const resetToken = ref(location.pathname.startsWith('/reset-password/') ? decodeURIComponent(location.pathname.split('/').pop() || '') : '');
const resetEmail = ref(new URLSearchParams(location.search).get('email') || '');
const totalLessons = computed(() => categories.value.reduce((n, c) => n + c.total_lessons, 0));
const completeLessons = computed(() => categories.value.reduce((n, c) => n + c.completed_lessons, 0));
const overall = computed(() => totalLessons.value ? Math.round(completeLessons.value / totalLessons.value * 100) : 0);
const allLessons = computed(() => categories.value.flatMap(category => category.lessons));
const lessonsPerPage = 4;
const lessonPageCount = (category: Category) => Math.max(1, Math.ceil(category.lessons.length / lessonsPerPage));
function colorLuminance(hex: string) {
  const values = [1, 3, 5].map(index => parseInt(hex.slice(index, index + 2), 16) / 255).map(value => value <= .04045 ? value / 12.92 : ((value + .055) / 1.055) ** 2.4);
  return .2126 * values[0] + .7152 * values[1] + .0722 * values[2];
}
function applyBranding(value: Branding) {
  branding.value = value;
  const root = document.documentElement.style;
  root.setProperty('--brand-blue', value.colors.primary);
  root.setProperty('--growth-green', value.colors.secondary);
  root.setProperty('--sky-blue', value.colors.accent);
  root.setProperty('--canvas', value.colors.background);
  root.setProperty('--text-primary', value.colors.text);
  root.setProperty('--link-ink', 'color-mix(in srgb, ' + value.colors.primary + ' 78%, black)');
  root.setProperty('--success-ink', 'color-mix(in srgb, ' + value.colors.secondary + ' 78%, black)');
  root.setProperty('--ink', (1.05 / (colorLuminance(value.colors.accent) + .05)) >= ((colorLuminance(value.colors.accent) + .05) / .05) ? '#FFFFFF' : '#000000');
  root.setProperty('--blue-tint', 'color-mix(in srgb, ' + value.colors.primary + ' 6%, white)');
  root.setProperty('--green-tint', 'color-mix(in srgb, ' + value.colors.secondary + ' 6%, white)');
  root.setProperty('--focus-ring', '0 0 0 3px color-mix(in srgb, ' + value.colors.primary + ' 35%, transparent)');
  root.setProperty('--action-ink', (1.05 / (colorLuminance(value.colors.primary) + .05)) >= ((colorLuminance(value.colors.primary) + .05) / .05) ? '#FFFFFF' : '#000000');
  document.title = value.brand_name;
}
async function loadBranding(value?: Branding) {
  if (value) { applyBranding(value); return; }
  try {
    const response = await fetch('/api/branding', { headers: { Accept: 'application/json' } });
    if (response.ok) applyBranding((await response.json()).branding);
  } catch { /* Default branding remains available offline. */ }
}
const unfinishedLessons = computed(() => allLessons.value.filter(lesson => lesson.is_unlocked && (!lesson.progress?.is_completed || !lesson.realization_submitted)));
const continueLesson = computed(() => [...unfinishedLessons.value]
  .filter(lesson => lesson.progress?.last_watched_at)
  .sort((a, b) => Date.parse(b.progress!.last_watched_at!) - Date.parse(a.progress!.last_watched_at!))[0] ?? unfinishedLessons.value[0] ?? null);
const selectedLessonIndex = computed(() => allLessons.value.findIndex(lesson => lesson.id === selected.value?.id));
const previousLesson = computed(() => selectedLessonIndex.value > 0 ? allLessons.value.slice(0, selectedLessonIndex.value).reverse().find(lesson => lesson.is_unlocked) ?? null : null);
const nextLessonCandidate = computed(() => selectedLessonIndex.value >= 0 ? allLessons.value[selectedLessonIndex.value + 1] ?? null : null);
const nextLesson = computed(() => nextLessonCandidate.value?.is_unlocked ? nextLessonCandidate.value : null);
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

        if (user.value?.role === 'student') { const learning = await api('/api/learning'); categories.value = learning.categories; enrollment.value = learning.enrollment; const current = continueLesson.value; if (current) { const category = learning.categories.find((item: Category) => item.lessons.some((lesson: Lesson) => lesson.id === current.id)); if (category) lessonPages.value[category.id] = Math.floor(category.lessons.findIndex((lesson: Lesson) => lesson.id === current.id) / lessonsPerPage); } }
        else categories.value = [];
        if (user.value && ['admin', 'super_admin'].includes(user.value.role)) {
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
  const wasAdmin = user.value ? ['admin', 'super_admin'].includes(user.value.role) : false;
  await api('/logout', { method: 'POST' });
  user.value = null;
  categories.value = [];
  studentPage.value = 'home';
  if (wasAdmin && location.pathname.startsWith('/admin')) {
    history.replaceState({}, '', '/');
    adminSection.value = 'dashboard';
  }
}
function watchLesson(lesson: Lesson, autoplay = true) {
  if (!lesson.is_unlocked) return;
  selected.value = lesson;
  realizationText.value = ''; realizationNotice.value = ''; realizationError.value = '';
  navigationNotice.value = '';
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
    const lessonId = selected.value.id;
    await api(`/api/lessons/${lessonId}/realizations`, { method: 'POST', body: JSON.stringify({ realization: realizationText.value }) });
    realizationText.value = ''; realizationNotice.value = 'Your realization has been saved.';
    await refreshStudentLearning(lessonId);
    navigationNotice.value = nextLesson.value ? 'Realization saved. The next lesson is ready when you are.' : 'Realization saved.';
  } catch (caught) { realizationError.value = (caught as Error).message; }
  finally { realizationSaving.value = false; }
}
async function refreshStudentLearning(lessonId = selected.value?.id) {
  const response = await api('/api/learning');
  categories.value = response.categories;
  if (lessonId !== undefined) {
    const refreshed = response.categories.flatMap((category: Category) => category.lessons).find((lesson: Lesson) => lesson.id === lessonId);
    if (refreshed && selected.value?.id === lessonId) selected.value = refreshed;
  }
}
function lessonPage(category: Category) { return Math.min(lessonPages.value[category.id] ?? 0, lessonPageCount(category) - 1); }
function setLessonPage(category: Category, page: number) { lessonPages.value[category.id] = Math.max(0, Math.min(page, lessonPageCount(category) - 1)); }
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
async function handleEnded(event: Event, lessonId: number) {
  const progress = await syncPlayback(event, lessonId, false, true);
  if (!progress?.is_completed) {
    playbackError.value = 'This lesson needs a little more verified watch time. Replay it to finish.';
    navigationNotice.value = 'Watch at least 50% of this lesson to unlock the next lesson.';
    replayRequired.value = true;
    return;
  }
  playbackError.value = '';
  replayRequired.value = false;
  await refreshStudentLearning(lessonId);
  navigationNotice.value = selected.value?.realization_submitted
    ? 'Lesson complete. Use Next Lesson when you are ready.'
    : 'Lesson complete. Submit your realization above to unlock the next lesson.';
}
async function closeLesson() {
  const video = document.querySelector<HTMLVideoElement>('.lesson-modal video');
  const lessonId = selected.value?.id;
  if (video && lessonId) void postPlaybackUpdate({ lessonId, positionSeconds: Math.floor(video.currentTime), isPlaying: false });
  selected.value = null;
}
onMounted(() => {
  void loadBranding();
  window.addEventListener('branding-updated', (event) => void loadBranding((event as CustomEvent<Branding>).detail));
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
    <div class="auth-art">
      <div class="art-mark">
        <!-- <GraduationCap :size="28" /> -->
         <img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" />
      </div>
      <div><p class="eyebrow">A LITTLE PROGRESS, EVERY DAY</p><h1>Make room<br>to grow.</h1><p class="art-copy">A calmer way to learn, one lesson at a time.</p></div>
      <div class="art-footer">{{ branding.brand_name }} <span>·</span> {{ branding.brand_description }}</div>
    </div>
    <div class="auth-form-wrap">
      <form v-if="resetToken" class="auth-form" @submit.prevent="submitReset"><div class="mobile-brand"><!--<GraduationCap />--><img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" /><span><b>{{ branding.brand_name }}</b><small>{{ branding.brand_description }}</small></span></div><p class="eyebrow">ACCOUNT RECOVERY</p><h2>Choose a new password.</h2><p class="muted">Set a new password to get back to learning.</p><label>Email address<input v-model="resetEmail" name="email" type="email" autocomplete="email" required></label><label>New password<input name="password" type="password" autocomplete="new-password" required minlength="8" placeholder="At least 8 characters"></label><label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" placeholder="Enter it again"></label><p v-if="error" class="error">{{ error }}</p><button class="primary wide">Update password <ChevronRight :size="17" /></button></form>
      <form v-else-if="resetMode" class="auth-form" @submit.prevent="requestReset"><div class="mobile-brand"><!--<GraduationCap />--><img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" /><span><b>{{ branding.brand_name }}</b><small>{{ branding.brand_description }}</small></span></div><p class="eyebrow">ACCOUNT RECOVERY</p><h2>Forgot your password?</h2><p class="muted">We’ll email you a link to choose a new one.</p><label>Email address<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label><p v-if="error" class="error">{{ error }}</p><p v-if="notice" class="success">{{ notice }}</p><button class="primary wide">Send reset link <ChevronRight :size="17" /></button><p class="switch"><button type="button" @click="resetMode = false; error = ''; notice = ''">Back to sign in</button></p></form>
      <form v-else class="auth-form" @submit.prevent="authenticate"><div class="mobile-brand"><!--<GraduationCap />--><img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" /><span><b>{{ branding.brand_name }}</b><small>{{ branding.brand_description }}</small></span></div><p class="eyebrow">{{ loginMode ? 'WELCOME BACK' : 'GET STARTED' }}</p><h2>{{ loginMode ? 'Good to see you.' : 'Create your account.' }}</h2><p class="muted">{{ loginMode ? 'Pick up where you left off.' : 'Your next chapter starts here.' }}</p><p v-if="notice" class="success">{{ notice }}</p><label v-if="!loginMode">Your name<input name="name" autocomplete="name" required placeholder="Your Name"></label><label v-if="!loginMode">Country<input name="country" list="registration-countries" required placeholder="Search or type country"><datalist id="registration-countries"><option>Philippines</option><option>Singapore</option><option>United States</option><option>United Kingdom</option><option>Canada</option><option>Australia</option><option>Malaysia</option><option>Japan</option><option>South Korea</option><option>India</option></datalist></label><label v-if="!loginMode">Complete address<input name="address" autocomplete="street-address" required></label><label v-if="!loginMode">Phone number<div class="phone-input"><select name="phone_country_code" required aria-label="Country calling code"><option value="">Code</option><option value="+63">Philippines +63</option><option value="+65">Singapore +65</option><option value="+1">United States/Canada +1</option><option value="+44">United Kingdom +44</option><option value="+61">Australia +61</option><option value="+60">Malaysia +60</option><option value="+91">India +91</option><option value="+81">Japan +81</option><option value="+82">South Korea +82</option></select><input name="phone_number" inputmode="tel" autocomplete="tel-national" required placeholder="Phone number"></div></label><label>Email address<input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></label><label>Password<input name="password" type="password" :autocomplete="loginMode ? 'current-password' : 'new-password'" required minlength="8" placeholder="At least 8 characters"></label><label v-if="!loginMode">Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="8" placeholder="Enter it again"></label><p v-if="error" class="error">{{ error }}</p><button class="primary wide">{{ loginMode ? 'Sign in' : 'Create account' }} <ChevronRight :size="17" /></button><p v-if="loginMode" class="switch"><button type="button" @click="resetMode = true; error = ''">Forgot password?</button></p><p class="switch">{{ loginMode ? 'New to ' + branding.brand_name + '?' : 'Already have an account?' }} <button type="button" @click="loginMode = !loginMode; error = ''">{{ loginMode ? 'Create an account' : 'Sign in' }}</button></p></form>
    </div>
  </section>
  <div v-else class="app-shell">
      <aside v-if="['admin','super_admin'].includes(user.role)" class="sidebar admin-sidebar" :class="{ 'sidebar-open': navOpen }">
        <a class="brand" href="/admin/dashboard">
          <img v-if="branding.logo_url" class="brand-logo" :src="branding.logo_url" alt="">
          <span v-else class="brand-icon"><!--<GraduationCap :size="21" />-->
            <img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" />
          </span><span class="brand-copy"><b>{{ branding.brand_name }}</b><small>{{ branding.brand_description }}</small></span>
        </a>
        <div class="nav-label">ADMINISTRATION</div>
        <nav aria-label="Admin navigation">
          <a v-for="item in adminNavigation" :key="item.section" :href="'/admin/' + item.section" class="nav-item admin-nav-item" :class="{ active: adminSection === item.section }" :aria-current="adminSection === item.section ? 'page' : undefined" @click="navOpen = false">
            <component :is="item.icon" :size="18" />
            <span>{{ item.label }}</span>
          </a>
        </nav>
        <div class="sidebar-bottom">
          <button class="profile" @click="signOut">
            <span class="avatar">{{ user.name.charAt(0).toUpperCase() }}</span>
            <span class="profile-text"><b>{{ user.name }}</b><small>Administrator</small></span>
            <LogOut :size="16" />
          </button>
        </div>
      </aside>
      <aside v-else class="sidebar" :class="{ 'sidebar-open': navOpen }">
        <a class="brand" href="#">
          <img v-if="branding.logo_url" class="brand-logo" :src="branding.logo_url" alt="">
          <span v-else class="brand-icon"><!--<GraduationCap :size="21" />-->
            <img src="https://biblestudy.zcmc.org.ph/storage/branding/zcmc-logo.svg" alt="Logo" class="w-7 h-7 object-contain" />
          </span>
          <span class="brand-copy"><b>{{ branding.brand_name }}</b><small>{{ branding.brand_description }}</small></span>
        </a>
        <div class="nav-label">YOUR SPACE</div>
        <nav>
          <a href="#" class="nav-item" :class="{ active: studentPage === 'home' }" @click.prevent="studentPage = 'home'; navOpen = false">
            <House :size="18" />Home
          </a>
          <a class="nav-item" href="#learning" @click="studentPage = 'home'">
            <BookOpen :size="18" />My learning
          </a>
          <a class="nav-item" href="#progress" @click="studentPage = 'home'">
            <Check :size="18" />Progress
          </a>
          <a href="#zoom" class="nav-item" :class="{ active: studentPage === 'zoom' }" @click.prevent="studentPage = 'zoom'; navOpen = false">
            <Video :size="18" />My Zoom
          </a>
          <a href="#profile" class="nav-item" :class="{ active: studentPage === 'profile' }" @click.prevent="studentPage = 'profile'; navOpen = false">
            <UserRound :size="18" />Profile
          </a>
        </nav>
        <div class="sidebar-bottom">
          <!--<div class="help-card">
            <div class="help-icon"><b>Keep your rhythm</b></div>
            <p>Small steps add up. You’re doing great.</p>
          </div>-->
          <button class="profile" @click="signOut">
            <span class="avatar">{{ user.name.charAt(0).toUpperCase() }}</span>
            <span class="profile-text"><b>{{ user.name }}</b><small>Student</small></span>
            <LogOut :size="16" />
          </button>
        </div>
      </aside>
    <div v-if="navOpen" class="backdrop" @click="navOpen = false"></div>
    <section class="main-area"><header class="topbar"><button class="icon-button mobile-menu" @click="navOpen = !navOpen"><Menu :size="20" /></button><span class="crumb"><span v-if="['admin','super_admin'].includes(user.role)">Administration</span><span v-else>Your space</span><ChevronRight :size="14" /> <b>{{ ['admin','super_admin'].includes(user.role) ? activeAdminPage.label : studentPage === 'profile' ? 'Profile' : studentPage === 'zoom' ? 'My Zoom' : 'Home' }}</b></span><button class="profile-chip" @click="signOut"><span class="avatar small">{{ user.name.charAt(0).toUpperCase() }}</span><span>{{ user.name.split(' ')[0] }}</span><LogOut :size="15" /></button></header>
      <div v-if="['admin','super_admin'].includes(user.role)" class="content admin-content"><div class="welcome"><div><p class="eyebrow">ADMIN WORKSPACE</p><h1>{{ activeAdminPage.section === 'dashboard' ? 'Welcome, ' + user.name.split(' ')[0] + '.' : activeAdminPage.label }}</h1><p class="muted">{{ activeAdminPage.description }}</p></div></div><AdminDashboard v-if="activeAdminPage.section === 'dashboard'" :dashboard="adminDashboard" :loading="dashboardLoading" :error="dashboardError" /><AdminStudents v-else-if="activeAdminPage.section === 'students'" /><AdminCategories v-else-if="activeAdminPage.section === 'categories'" /><AdminLessons v-else-if="activeAdminPage.section === 'lessons'" /><AdminZoom v-else-if="activeAdminPage.section === 'zoom'" :can-manage-integration="user.role === 'super_admin'" /><AdminProgress v-else-if="activeAdminPage.section === 'progress'" /><AdminRealizations v-else-if="activeAdminPage.section === 'realizations'" /><AdminReports v-else-if="activeAdminPage.section === 'reports'" /><AdminBranding v-else /></div><div v-else class="content"><template v-if="studentPage === 'home'"><div class="welcome"><div><p class="eyebrow">{{ new Date().toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric' }).toUpperCase() }}</p><h1>Good day, {{ user.name.split(' ')[0] }}<span class="wave">&#33;</span></h1><p class="muted">Every lesson brings you a little further.</p></div><div class="streak"><span class="streak-icon">{{ completeLessons }}</span><div><b>{{ completeLessons }} <small>of {{ totalLessons }} lessons</small></b><span>completed so far</span></div></div></div>
        <div v-if="enrollment.eligible && enrollment.status === 'needs_revision'" class="enrollment-notice enrollment-revision" role="alert"><FileText :size="21"/><div><b>Your enrollment form requires revisions</b><span>Please review and update the requested information, then resubmit your form.</span><blockquote v-if="enrollment.revision_reason"><strong>Admin instructions</strong><span>{{ enrollment.revision_reason }}</span></blockquote></div><button class="primary" @click="openEnrollmentForm">Edit Enrollment Form</button></div><div v-else-if="enrollment.eligible && enrollment.status !== 'approved'" class="enrollment-notice" role="status"><FileText :size="20"/><div><b>Official Enrollment Form is ready</b><span>{{ enrollment.status === 'not_started' ? 'Please complete and submit your enrollment information.' : 'Status: ' + enrollment.status.replace('_',' ') }}</span></div><button class="primary" @click="studentPage = 'profile'">{{ enrollment.status === 'not_started' ? 'Complete form' : 'Open form' }}</button></div><div v-if="enrollment.requirement_enabled && !enrollment.eligible && enrollment.status !== 'approved'" class="enrollment-notice enrollment-locked" role="status"><FileText :size="20"/><div><b>Enrollment form unlocks after your lesson</b><span>Complete Lesson {{ enrollment.lesson_number }} in {{ enrollment.category_name || 'the required category' }} to access the Official Enrollment Form.</span></div></div><div class="hero" :style="branding.hero_image_url ? { backgroundImage: `linear-gradient(90deg,rgb(6, 43, 69, .97) 0%, rgb(6, 43, 69,.90) 50%, rgb(6, 43, 69,.18) 100%), url('${branding.hero_image_url}')` } : undefined">
          <div class="hero-copy"><span class="hero-tag"><CirclePlay :size="14" /> YOUR NEXT STEP</span><h2>{{ continueLesson ? 'Ready when you are.' : 'A journey well learned.' }}</h2><p>{{ continueLesson ? `Pick up ${continueLesson.title} and keep your momentum going.` : 'You’ve completed every published lesson. Nice work.' }}</p><button v-if="continueLesson" class="hero-button" @click="watchLesson(continueLesson, true)"><Play :size="15" fill="currentColor" /> Continue learning <span v-if="continueLesson.progress?.last_position_seconds">· Resume at {{ formatTime(continueLesson.progress.last_position_seconds) }}</span></button></div>
            <div v-if="!branding.hero_image_url" class="hero-art">
              <div class="sun"></div>
              <div class="hill hill-back"></div>
              <div class="hill hill-front"></div>
              <div class="book-art"><BookOpen :size="49" :stroke-width="1.2" /></div>
            </div>
          <aside class="daily-verse"><span>DAILY VERSE</span><p>“{{ branding.daily_verse_text || 'Your word is a lamp to my feet and a light to my path.' }}”</p><b>{{ branding.daily_verse_reference || 'Psalm 119:105' }}</b></aside>
          </div>
        <section id="learning" class="learning-section"><div class="section-heading"><div><p class="eyebrow">YOUR LEARNING ROADMAP</p><h2>Learning path</h2></div><div class="learning-tools"><div class="view-toggle" role="group" aria-label="Lesson view"><button :class="{ active: lessonView === 'grid' }" @click="lessonView = 'grid'">Grid</button><button :class="{ active: lessonView === 'list' }" @click="lessonView = 'list'">List</button></div><a href="#progress" class="text-link">View progress <ChevronRight :size="16" /></a></div></div><div v-if="!categories.length" class="empty">Your courses will appear here once they’re published.</div><div class="category-grid"><article v-for="(category, i) in categories" :key="category.id" class="category-card" :style="{ backgroundColor: category.background_color || undefined }"><img v-if="category.thumbnail_url" class="category-thumb" :src="category.thumbnail_url" :alt="`${category.name} course artwork`"><div class="card-top"><span class="category-icon" :class="`tone-${i % 4}`"><BookOpen :size="19" /></span><span class="lesson-count">{{ category.completed_lessons }}/{{ category.total_lessons }} lessons</span></div><h3>{{ category.name }}</h3><p class="category-desc">{{ category.description || 'Build your knowledge at your own pace.' }}</p><div class="progress-meta"><span>{{ category.progress_percentage }}% complete</span><span>{{ category.total_lessons - category.completed_lessons }} to go</span></div><div class="progress-track"><span :style="{ width: `${category.progress_percentage}%` }"></span></div><div class="lesson-cards" :class="`lesson-cards-${lessonView}`"><button v-for="lesson in category.lessons.slice(lessonPage(category) * lessonsPerPage, lessonPage(category) * lessonsPerPage + lessonsPerPage)" :key="lesson.id" class="lesson-tile" :disabled="!lesson.is_unlocked" :aria-label="lesson.is_unlocked ? lesson.title : `${lesson.title} · Locked until the previous lesson is complete and its realization is submitted`" @click="watchLesson(lesson, true)"><img v-if="lesson.thumbnail_url" :src="lesson.thumbnail_url" :alt="`${lesson.title} thumbnail`"><span v-else class="lesson-thumb-fallback"><BookOpen :size="24" /></span><span class="lesson-tile-copy"><b>{{ lesson.title }}</b><small>{{ !lesson.is_unlocked ? 'Locked' : lesson.progress?.is_completed ? 'Complete' : lesson.progress?.last_position_seconds ? 'Resume lesson' : 'Start lesson' }}</small></span></button></div><nav v-if="category.lessons.length > lessonsPerPage" class="lesson-pagination" :aria-label="`${category.name} lesson pages`"><button type="button" class="secondary-button" :disabled="lessonPage(category) === 0" @click="setLessonPage(category, lessonPage(category) - 1)">Previous</button><span>Page {{ lessonPage(category) + 1 }} of {{ lessonPageCount(category) }}</span><button type="button" class="secondary-button" :disabled="lessonPage(category) >= lessonPageCount(category) - 1" @click="setLessonPage(category, lessonPage(category) + 1)">Next</button></nav></article></div></section>
        <section id="progress" class="progress-overview"><div class="section-heading"><div><p class="eyebrow">YOUR MOMENTUM</p><h2>Progress overview</h2></div></div><div class="overview-card"><div class="overall-ring" :style="{ '--progress': `${overall * 3.6}deg` }"><div><b>{{ overall }}%</b><small>overall</small></div></div><div class="overview-text"><h3>You’re building a great habit.</h3><p>You’ve finished <b>{{ completeLessons }}</b> out of <b>{{ totalLessons }}</b> available lessons. Keep going at a pace that feels right.</p></div><div class="overview-stat"><span><Check :size="17" /></span><div><b>{{ completeLessons }}</b><small>lessons completed</small></div></div></div></section>
        </template>
        <StudentZoom v-else-if="studentPage === 'zoom'" /><StudentProfile v-else :user="user" :enrollment-eligible="enrollment.eligible" @enrollment-submitted="enrollment.status = $event; enrollment.revision_reason = null" />
      </div>
    </section>
    <div v-if="selected" class="modal-backdrop" @click.self="closeLesson"><section class="lesson-modal"><button class="modal-close" aria-label="Close lesson" @click="closeLesson"><X :size="19" /></button><p class="eyebrow">LESSON</p><h2>{{ selected.title }}</h2><p class="muted">{{ selected.description || 'Take your time and enjoy this lesson.' }}</p><video v-if="selected.video_stream_url" class="video" controls controlsList="nodownload" playsinline :src="selected.video_stream_url" :data-lesson-id="selected.id" @contextmenu.prevent @loadedmetadata="resumePlayback($event, selected.id)" @play="handlePlay($event, selected.id)" @pause="handlePause($event, selected.id)" @timeupdate="handleTimeUpdate($event, selected.id)" @ended="handleEnded($event, selected.id)" /><div v-else class="video-placeholder"><CirclePlay :size="38" /><span>This lesson’s video hasn’t been added yet.</span></div><p v-if="playbackError" class="error playback-error" role="status">{{ playbackError }}</p><p v-if="navigationNotice" class="lesson-navigation-notice" role="status">{{ navigationNotice }}</p><button v-if="autoplayBlocked" class="primary continue-button" @click="continueAfterAutoplayBlock">Continue lesson <Play :size="16" fill="currentColor" /></button><button v-if="replayRequired" class="primary continue-button" @click="replayLesson">Replay lesson <Play :size="16" fill="currentColor" /></button><form class="student-realization-form" @submit.prevent="submitRealization"><label for="student-realization">What did you take away from this lesson?</label><textarea id="student-realization" v-model="realizationText" maxlength="20000" required minlength="3" rows="3" placeholder="Write a short reflection or describe what you practiced."></textarea><p v-if="realizationNotice" class="success" role="status">{{ realizationNotice }}</p><p v-if="realizationError" class="error" role="alert">{{ realizationError }}</p><button class="primary" :disabled="realizationSaving">{{ realizationSaving ? 'Saving…' : 'Save realization' }}</button></form><div class="modal-footer"><span><Clock3 :size="16" /> {{ formatTime(selected.duration_seconds) }}</span><span v-if="selected.progress?.is_completed" class="completed-pill"><Check :size="16" /> Lesson complete</span></div><div class="lesson-navigation"><button type="button" class="secondary-button" :disabled="!previousLesson" @click="previousLesson && watchLesson(previousLesson, false)">Previous Lesson</button><p v-if="nextLessonCandidate && !selected.progress?.is_completed" class="lesson-navigation-help">Watch at least 50% of this lesson to unlock the next lesson.</p><p v-else-if="nextLessonCandidate && !selected.realization_submitted" class="lesson-navigation-help">Submit your realization above before proceeding to the next lesson.</p><button type="button" class="primary" :disabled="!selected.progress?.is_completed || !selected.realization_submitted || !nextLesson" @click="nextLesson && watchLesson(nextLesson, false)">Next Lesson</button></div></section></div>
  </div>
</template>
