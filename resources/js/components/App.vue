<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { BookOpen, Check, ChevronRight, CirclePlay, Clock3, GraduationCap, House, LogOut, Menu, Play, UserRound, X } from 'lucide-vue-next';

type User = { id: number; name: string; email: string; role: 'admin' | 'student' };
type Lesson = { id: number; title: string; description?: string; video_url?: string; duration_seconds?: number; sort_order: number; progress: null | { last_position_seconds: number; progress_percentage: number; is_completed: boolean } };
type Category = { id: number; name: string; description?: string; total_lessons: number; completed_lessons: number; progress_percentage: number; lessons: Lesson[] };
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const user = ref<User | null>(null), categories = ref<Category[]>([]), loading = ref(true), error = ref(''), notice = ref(''), selected = ref<Lesson | null>(null), navOpen = ref(false), loginMode = ref(true), resetMode = ref(false);
const resetToken = ref(location.pathname.startsWith('/reset-password/') ? decodeURIComponent(location.pathname.split('/').pop() || '') : '');
const resetEmail = ref(new URLSearchParams(location.search).get('email') || '');
const totalLessons = computed(() => categories.value.reduce((n, c) => n + c.total_lessons, 0));
const completeLessons = computed(() => categories.value.reduce((n, c) => n + c.completed_lessons, 0));
const overall = computed(() => totalLessons.value ? Math.round(completeLessons.value / totalLessons.value * 100) : 0);
const nextLesson = computed(() => categories.value.flatMap(c => c.lessons).find(l => !l.progress?.is_completed));
let lastSavedSecond = -1;
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
async function signOut() { await api('/logout', { method: 'POST' }); user.value = null; }
function watchLesson(lesson: Lesson) { selected.value = lesson; lastSavedSecond = lesson.progress?.last_position_seconds ?? -1; }
function formatTime(seconds?: number) { if (!seconds) return 'On demand'; const m = Math.floor(seconds / 60); return `${m} min`; }
async function savePlayback(event: Event) { const video = event.target as HTMLVideoElement; const lesson = selected.value; const second = Math.floor(video.currentTime); if (!lesson || second % 15 !== 0 || second === lastSavedSecond) return; lastSavedSecond = second; await api(`/api/lessons/${lesson.id}/progress`, { method: 'PUT', body: JSON.stringify({ position_seconds: second }) }); }
function resumePlayback(event: Event) { const video = event.target as HTMLVideoElement; if (selected.value?.progress?.last_position_seconds) video.currentTime = selected.value.progress.last_position_seconds; }
async function markComplete() { if (!selected.value) return; await api(`/api/lessons/${selected.value.id}/progress`, { method: 'PUT', body: JSON.stringify({ position_seconds: selected.value.duration_seconds || 1 }) }); selected.value = null; await load(); }
onMounted(load);
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
    <aside class="sidebar" :class="{ 'sidebar-open': navOpen }"><a class="brand" href="#"><span class="brand-icon"><GraduationCap :size="21" /></span> learnwell</a><div class="nav-label">YOUR SPACE</div><nav><a class="nav-item active"><House :size="18" />Home</a><a class="nav-item" href="#learning"><BookOpen :size="18" />My learning</a><a class="nav-item" href="#progress"><Check :size="18" />Progress</a><a class="nav-item"><UserRound :size="18" />Profile</a></nav><div class="sidebar-bottom"><div class="help-card"><div class="help-icon">✦</div><b>Keep your rhythm</b><p>Small steps add up. You’re doing great.</p></div><button class="profile" @click="signOut"><span class="avatar">{{ user.name.charAt(0).toUpperCase() }}</span><span class="profile-text"><b>{{ user.name }}</b><small>{{ user.role === 'admin' ? 'Administrator' : 'Student' }}</small></span><LogOut :size="16" /></button></div></aside>
    <div v-if="navOpen" class="backdrop" @click="navOpen = false"></div>
    <section class="main-area"><header class="topbar"><button class="icon-button mobile-menu" @click="navOpen = !navOpen"><Menu :size="20" /></button><span class="crumb">Your space <ChevronRight :size="14" /> <b>Home</b></span><button class="profile-chip" @click="signOut"><span class="avatar small">{{ user.name.charAt(0).toUpperCase() }}</span><span>{{ user.name.split(' ')[0] }}</span><LogOut :size="15" /></button></header>
      <div v-if="user.role === 'admin'" class="content"><div class="welcome"><div><p class="eyebrow">ADMIN WORKSPACE</p><h1>Welcome, {{ user.name.split(' ')[0] }}.</h1><p class="muted">Your learning system is ready to manage.</p></div></div><div class="admin-notice"><BookOpen :size="22" /><div><b>Management API is ready</b><p>Student and progress monitoring endpoints are available to your admin tools.</p></div></div></div>
      <div v-else class="content"><div class="welcome"><div><p class="eyebrow">{{ new Date().toLocaleDateString('en', { weekday: 'long', month: 'long', day: 'numeric' }).toUpperCase() }}</p><h1>Good morning, {{ user.name.split(' ')[0] }}<span class="wave">✳</span></h1><p class="muted">Every lesson brings you a little further.</p></div><div class="streak"><span class="streak-icon">✦</span><div><b>{{ completeLessons }} <small>of {{ totalLessons }} lessons</small></b><span>completed so far</span></div></div></div>
        <div class="hero"><div class="hero-copy"><span class="hero-tag"><CirclePlay :size="14" /> YOUR NEXT STEP</span><h2>{{ nextLesson ? 'Ready when you are.' : 'A journey well learned.' }}</h2><p>{{ nextLesson ? `Continue with ${nextLesson.title} and keep your momentum going.` : 'You’ve completed every published lesson. Nice work.' }}</p><button v-if="nextLesson" class="hero-button" @click="watchLesson(nextLesson)"><Play :size="15" fill="currentColor" /> {{ nextLesson.progress?.last_position_seconds ? 'Resume learning' : 'Start this lesson' }} <span>· {{ formatTime(nextLesson.duration_seconds) }}</span></button></div><div class="hero-art"><div class="sun"></div><div class="hill hill-back"></div><div class="hill hill-front"></div><div class="book-art"><BookOpen :size="49" :stroke-width="1.2" /></div></div></div>
        <section id="learning" class="learning-section"><div class="section-heading"><div><p class="eyebrow">YOUR CURRICULUM</p><h2>Learning path</h2></div><a href="#progress" class="text-link">View progress <ChevronRight :size="16" /></a></div><div v-if="!categories.length" class="empty">Your courses will appear here once they’re published.</div><div class="category-grid"><article v-for="(category, i) in categories" :key="category.id" class="category-card"><div class="card-top"><span class="category-icon" :class="`tone-${i % 4}`"><BookOpen :size="19" /></span><span class="lesson-count">{{ category.completed_lessons }}/{{ category.total_lessons }} lessons</span></div><h3>{{ category.name }}</h3><p class="category-desc">{{ category.description || 'Build your knowledge at your own pace.' }}</p><div class="progress-meta"><span>{{ category.progress_percentage }}% complete</span><span>{{ category.total_lessons - category.completed_lessons }} to go</span></div><div class="progress-track"><span :style="{ width: `${category.progress_percentage}%` }"></span></div><button v-for="lesson in category.lessons.slice(0, 2)" :key="lesson.id" class="lesson-row" @click="watchLesson(lesson)"><span :class="{ done: lesson.progress?.is_completed }">{{ lesson.progress?.is_completed ? '✓' : '▶' }}</span><span class="lesson-name">{{ lesson.title }}</span><span v-if="lesson.progress?.last_position_seconds && !lesson.progress.is_completed" class="resume-label">Resume</span><ChevronRight :size="15" /></button><div v-if="category.lessons.length > 2" class="more-lessons">+ {{ category.lessons.length - 2 }} more lessons</div></article></div></section>
        <section id="progress" class="progress-overview"><div class="section-heading"><div><p class="eyebrow">YOUR MOMENTUM</p><h2>Progress overview</h2></div></div><div class="overview-card"><div class="overall-ring" :style="{ '--progress': `${overall * 3.6}deg` }"><div><b>{{ overall }}%</b><small>overall</small></div></div><div class="overview-text"><h3>You’re building a great habit.</h3><p>You’ve finished <b>{{ completeLessons }}</b> out of <b>{{ totalLessons }}</b> available lessons. Keep going at a pace that feels right.</p></div><div class="overview-stat"><span><Check :size="17" /></span><div><b>{{ completeLessons }}</b><small>lessons completed</small></div></div></div></section>
      </div>
    </section>
    <div v-if="selected" class="modal-backdrop" @click.self="selected = null"><section class="lesson-modal"><button class="modal-close" @click="selected = null"><X :size="19" /></button><p class="eyebrow">LESSON</p><h2>{{ selected.title }}</h2><p class="muted">{{ selected.description || 'Take your time and enjoy this lesson.' }}</p><video v-if="selected.video_url" class="video" controls :src="selected.video_url" @timeupdate="savePlayback" @loadedmetadata="resumePlayback" /> <div v-else class="video-placeholder"><CirclePlay :size="38" /><span>Your lesson video will appear here.</span></div><div class="modal-footer"><span><Clock3 :size="15" /> {{ formatTime(selected.duration_seconds) }}</span><span v-if="selected.progress?.is_completed" class="completed-pill"><Check :size="14" /> Completed</span><button v-else class="primary" @click="markComplete">Mark lesson complete <ChevronRight :size="16" /></button></div></section></div>
  </div>
</template>
