<script setup lang="ts">
import { onMounted, ref } from 'vue';

type Meeting = { id: number; pseudocode: string; join_url: string | null; registered_at: string | null; batch_name: string; topic: string | null; scheduled_at: string | null; recurrence: Record<string, unknown> | null; registration_status: string; registration_error: string | null };
const meetings = ref<Meeting[]>([]), pseudocode = ref(''), loading = ref(true), error = ref(''), notice = ref('');
async function load() {
  loading.value = true; error.value = '';
  try {
    const response = await fetch('/api/my-zoom', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const text = await response.text(); let data: Record<string, any> = {};
    try { data = JSON.parse(text); } catch { /* Keep server HTML out of the page. */ }
    if (!response.ok) throw new Error(typeof data.message === 'string' ? data.message : `Could not load Zoom meetings (${response.status}).`);
    meetings.value = data.meetings; pseudocode.value = data.pseudocode;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
async function copy(url: string) {
  try { await navigator.clipboard.writeText(url); notice.value = 'Your individual Zoom link is copied.'; }
  catch { error.value = 'Could not access the clipboard. You can open the link and copy it from your browser.'; }
}
function formatDate(value: string | null) { return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'Schedule not set'; }
function recurrenceText(value: Record<string, unknown> | null) { if (!value || value.frequency === 'one_time') return 'Single meeting'; const label = ({ daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' } as Record<string, string>)[String(value.frequency)] || 'Recurring'; return `${label} fixed-time meeting · every ${value.repeat_interval || 1}`; }
onMounted(() => { void load(); });
</script>

<template>
  <section class="student-zoom-page">
    <div class="welcome"><div><p class="eyebrow">YOUR LIVE CLASSES</p><h1>My Zoom</h1><p class="muted">Your pseudocode is {{ pseudocode || 'loading' }}.</p></div></div>
    <p v-if="notice" class="category-notice" role="status">{{ notice }}</p><p v-if="error" class="student-error" role="alert">{{ error }}</p>
    <div v-if="loading" class="category-empty-state">Loading your Zoom meetings…</div>
    <div v-else-if="!meetings.length" class="category-empty-state">No Zoom batch is currently assigned to you.</div>
    <article v-for="meeting in meetings" :key="meeting.id" class="student-zoom-card"><div><p class="eyebrow">{{ meeting.batch_name }}</p><h2>{{ meeting.topic || 'Batch Zoom meeting' }}</h2><p><strong>{{ meeting.pseudocode }}</strong> · {{ formatDate(meeting.scheduled_at) }}</p><p>{{ recurrenceText(meeting.recurrence) }} · Registration {{ meeting.registration_status }}</p><p v-if="meeting.registration_error" class="zoom-registration-error">{{ meeting.registration_error }}</p></div><div v-if="meeting.join_url" class="student-zoom-actions"><button class="secondary-button" @click="copy(meeting.join_url!)">Copy link</button><a class="primary" :href="meeting.join_url" target="_blank" rel="noopener noreferrer">Join meeting ↗</a></div></article>
  </section>
</template>
