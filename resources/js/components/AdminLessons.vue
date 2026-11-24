<script setup lang="ts">
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import { subtitleWorkflowPollingState } from '../utils/subtitlePolling';
import { reconcileServerDraft } from '../utils/subtitleDrafts';

type Category = { id: number; name: string; deleted_at: string | null };
type Lesson = { id: number; category_id: number; title: string; description: string | null; video_stream_url: string | null; thumbnail_url: string | null; duration_seconds: number | null; sort_order: number; is_published: boolean; deleted_at: string | null; progress_count: number; category?: Category };
type SubtitleVersion = { operation_id: number; language: 'en' | 'fil'; version_id: string; approved_at: string | null; is_current: boolean; download_url: string };
type SubtitleWorkflow = { preparations: any[]; transcripts: any[]; alignments: any[]; translations: any[] };
const MAX_VIDEO_SIZE_BYTES = 1000 * 1024 * 1024;
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const lessons = ref<Lesson[]>([]), categories = ref<Category[]>([]), total = ref(0), loading = ref(false), saving = ref(false), uploading = ref(false), uploadPercent = ref<number | null>(null), error = ref(''), notice = ref('');
const search = ref(''), categoryId = ref(''), status = ref('all'), page = ref(1), lastPage = ref(1), editing = ref<number | null>(null);
const uploadInputKey = ref(0);
const subtitleLessonId = ref(''), subtitleVersions = ref<SubtitleVersion[]>([]), subtitleFiles = reactive<Record<number, File | null>>({});
const subtitleLesson = ref<Lesson | null>(null), subtitleWorkflow = ref<SubtitleWorkflow | null>(null), workflowBusy = ref(false), workflowError = ref('');
const workflowNotice = ref('');
const workflowWarning = ref('');
const workflowLoading = ref(false);
const transcriptDrafts = reactive<Record<number, string>>({}), translationDrafts = reactive<Record<number, string>>({}), alignmentSrtDrafts = reactive<Record<number, string>>({});
const transcriptServerValues = reactive<Record<number, string>>({}), translationServerValues = reactive<Record<number, string>>({});
type FilipinoCue = { index: number; start: string; end: string; text: string };
const translationCueDrafts = reactive<Record<number, FilipinoCue[]>>({}), translationCueServerValues = reactive<Record<number, string>>({});
let subtitlePoll: ReturnType<typeof setTimeout> | null = null;
let subtitlePollFailures = 0;
const SUBTITLE_POLL_INTERVAL_MS = 10_000;
const thumbnailFile = ref<File | null>(null), thumbnailPreview = ref<string | null>(null), removeThumbnail = ref(false);
const form = reactive({ category_id: '', title: '', video: null as File | null, description: '', duration_seconds: '', sort_order: '', is_published: false });
async function request(url: string, method = 'GET', body?: unknown) {
  const isForm = body instanceof FormData;
  const headers: Record<string, string> = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() };
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
  const response = await fetch(url, { method, credentials: 'same-origin', headers, body: body === undefined ? undefined : isForm ? body : JSON.stringify(body) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'Request failed.');
  return data;
}
function uploadRequest(url: string, body: FormData) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
    xhr.upload.addEventListener('progress', event => {
      if (event.lengthComputable) uploadPercent.value = Math.min(100, Math.round(event.loaded / event.total * 100));
    });
    xhr.addEventListener('load', () => {
      const contentType = xhr.getResponseHeader('Content-Type') || '';
      let data: Record<string, any> = {};
      if (contentType.toLowerCase().includes('application/json')) {
        try {
          const parsed = JSON.parse(xhr.responseText || '{}');
          if (parsed && typeof parsed === 'object') data = parsed;
        } catch {
          // A broken JSON response is still handled as a generic server error below.
        }
      }
      if (xhr.status >= 200 && xhr.status < 300) resolve(data);
      else if (xhr.status === 413) {
        reject(new Error('The server rejected this request because its request-body limit is below the upload size. Check the web server, proxy/CDN, and PHP post_max_size limits.'));
      } else {
        const videoErrors = data.errors?.video as string[] | undefined;
        let message = Object.values(data.errors || {}).flat()[0] as string | undefined;
        if (videoErrors?.some(item => /mimes|extension|video type|file type|file of type/i.test(item))) message = 'Unsupported video type. Choose an MP4, MOV, WebM, M4V, or AVI file.';
        else if (videoErrors?.some(item => /max|1024000|kilobytes|1000 MB|server limit/i.test(item))) message = 'The video is too large. Choose a file no larger than 1000 MB.';
        reject(new Error(message || data.message || 'The lesson could not be saved. The server returned an unexpected response.'));
      }
    });
    xhr.addEventListener('error', () => reject(new Error('The upload failed because of a network error. Your form entries are still here.')));
    xhr.addEventListener('abort', () => reject(new Error('The upload was canceled. Your form entries are still here.')));
    xhr.send(body);
  });
}
function chooseVideo(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  error.value = '';
  const extension = file.name.split('.').pop()?.toLowerCase();
  if (!['mp4', 'mov', 'webm', 'm4v', 'avi'].includes(extension || '')) {
    error.value = 'Unsupported video type. Choose an MP4, MOV, WebM, M4V, or AVI file.';
    input.value = '';
    return;
  }
  if (file.size > MAX_VIDEO_SIZE_BYTES) {
    error.value = 'The video is too large. Choose a file no larger than 1000 MB.';
    input.value = '';
    return;
  }
  form.video = file;
}
async function load() {
  loading.value = true; error.value = '';
  try {
    const query = new URLSearchParams({ page: String(page.value), status: status.value });
    if (search.value.trim()) query.set('search', search.value.trim());
    if (categoryId.value) query.set('category_id', categoryId.value);
    const result = await request(`/api/admin/lessons?${query}`);
    lessons.value = result.lessons.data; total.value = result.lessons.total; lastPage.value = result.lessons.last_page; page.value = result.lessons.current_page;
    categories.value = result.categories;
    if (!form.category_id) form.category_id = categories.value.find(category => !category.deleted_at)?.id.toString() || '';
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function resetForm() { editing.value = null; uploadInputKey.value++; Object.assign(form, { category_id: categories.value.find(c => !c.deleted_at)?.id.toString() || '', title: '', video: null, description: '', duration_seconds: '', sort_order: '', is_published: false }); thumbnailFile.value = null; thumbnailPreview.value = null; removeThumbnail.value = false; }
function edit(lesson: Lesson) { editing.value = lesson.id; uploadInputKey.value++; Object.assign(form, { category_id: String(lesson.category_id), title: lesson.title, video: null, description: lesson.description || '', duration_seconds: lesson.duration_seconds ? String(lesson.duration_seconds) : '', sort_order: String(lesson.sort_order), is_published: lesson.is_published }); thumbnailFile.value = null; thumbnailPreview.value = lesson.thumbnail_url; removeThumbnail.value = false; error.value = ''; notice.value = ''; }
function chooseThumbnail(event: Event) { const file = (event.target as HTMLInputElement).files?.[0] ?? null; if (!file) return; if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { error.value = 'Choose a JPG, PNG, or WebP image no larger than 5 MB.'; return; } thumbnailFile.value = file; removeThumbnail.value = false; if (thumbnailPreview.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreview.value); thumbnailPreview.value = URL.createObjectURL(file); }
function clearThumbnail() { if (thumbnailPreview.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreview.value); thumbnailFile.value = null; thumbnailPreview.value = null; removeThumbnail.value = true; }
async function save() {
  if (saving.value || uploading.value) return;
  saving.value = true; error.value = ''; notice.value = '';
  const payload = new FormData();
  payload.append('_method', editing.value ? 'PUT' : 'POST');
  payload.append('category_id', form.category_id); payload.append('title', form.title); payload.append('description', form.description); payload.append('duration_seconds', form.duration_seconds); payload.append('is_published', form.is_published ? '1' : '0');
  if (form.sort_order) payload.append('sort_order', form.sort_order);
  if (form.video) payload.append('video', form.video);
  if (thumbnailFile.value) payload.append('thumbnail', thumbnailFile.value); if (removeThumbnail.value) payload.append('remove_thumbnail', '1');
  try {
    uploading.value = Boolean(form.video); uploadPercent.value = form.video ? 0 : null;
    if (editing.value) await uploadRequest(`/api/admin/lessons/${editing.value}`, payload);
    else await uploadRequest('/api/admin/lessons', payload);
    notice.value = editing.value ? 'Lesson updated.' : 'Lesson added.'; resetForm(); await load();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; uploading.value = false; uploadPercent.value = null; }
}
async function archive(lesson: Lesson) { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}`, 'DELETE'); notice.value = 'Lesson archived. Progress is preserved.'; await load(); } catch (caught) { error.value = (caught as Error).message; } }
async function restore(lesson: Lesson) { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}/restore`, 'POST'); notice.value = 'Lesson restored.'; await load(); } catch (caught) { error.value = (caught as Error).message; } }
async function move(lesson: Lesson, direction: 'up' | 'down') { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}/move`, 'PATCH', { direction }); await load(); } catch (caught) { error.value = (caught as Error).message; } }
function onFilter() { page.value = 1; void load(); }
function chooseSubtitleFile(operationId: number, event: Event) { subtitleFiles[operationId] = (event.target as HTMLInputElement).files?.[0] || null; }
type SubtitleChunk = { operation_id?: number | null; status?: string; error_code?: string | null; error_message?: string | null };
function preparationHasFailedChunk(preparation: { chunks?: SubtitleChunk[] }) {
  return (preparation.chunks ?? []).some(chunk => chunk.status === 'failed');
}
function failedTranscriptionChunks(preparation: { chunks?: SubtitleChunk[] }) {
  return (preparation.chunks ?? []).filter(chunk => chunk.status === 'failed');
}
function stopSubtitlePolling() {
  if (subtitlePoll) clearTimeout(subtitlePoll);
  subtitlePoll = null;
}
function scheduleSubtitlePolling(workflow: SubtitleWorkflow | null) {
  stopSubtitlePolling();
  if (!workflow || !subtitleLessonId.value) return;
  const pollingState = subtitleWorkflowPollingState(workflow);
  workflowWarning.value = pollingState.warning || '';
  if (!pollingState.shouldPoll) return;
  subtitlePoll = setTimeout(() => void refreshSubtitleWorkspace(false), SUBTITLE_POLL_INTERVAL_MS);
}
async function refreshSubtitleWorkspace(manual = true) {
  if (!subtitleLessonId.value || workflowLoading.value) return;
  if (manual) subtitlePollFailures = 0;
  workflowLoading.value = true; workflowError.value = '';
  try {
    const [workflow, subtitles] = await Promise.all([
      request(`/api/admin/lessons/${subtitleLessonId.value}/subtitle-workflow`),
      request(`/api/admin/lessons/${subtitleLessonId.value}/subtitles`),
    ]);
    subtitleWorkflow.value = workflow;
    subtitleVersions.value = subtitles.subtitles || [];
    for (const transcript of workflow.transcripts || []) {
      reconcileServerDraft(transcriptDrafts, transcriptServerValues, transcript.operation_id, transcript.text || '');
    }
    for (const translation of workflow.translations || []) {
      reconcileServerDraft(translationDrafts, translationServerValues, translation.operation_id, translation.text || '');
      if (Array.isArray(translation.cues) && translation.cues.length) {
        const server = JSON.stringify(translation.cues);
        if (translationCueDrafts[translation.operation_id] === undefined || translationCueServerValues[translation.operation_id] === JSON.stringify(translationCueDrafts[translation.operation_id])) {
          translationCueDrafts[translation.operation_id] = structuredClone(translation.cues);
        }
        translationCueServerValues[translation.operation_id] = server;
      }
    }
    for (const alignment of workflow.alignments || []) {
      if (alignment.status === 'succeeded' && alignmentSrtDrafts[alignment.operation_id] === undefined) {
        try {
          const preview = await request(`/api/admin/whisperx-alignments/${alignment.operation_id}/preview`);
          alignmentSrtDrafts[alignment.operation_id] = preview.srt_text;
        } catch { alignmentSrtDrafts[alignment.operation_id] = ''; }
      }
    }
    subtitlePollFailures = 0;
  } catch (caught) {
    workflowError.value = (caught as Error).message;
    if (!manual && ++subtitlePollFailures < 4 && subtitleLessonId.value) {
      stopSubtitlePolling();
      subtitlePoll = setTimeout(() => void refreshSubtitleWorkspace(false), SUBTITLE_POLL_INTERVAL_MS * subtitlePollFailures);
    } else if (subtitlePollFailures >= 4) {
      workflowWarning.value = 'Automatic status checks paused after repeated connection errors. Use Refresh status to try again.';
    }
  }
  finally {
    workflowLoading.value = false;
    if (subtitleWorkflow.value && !workflowError.value) scheduleSubtitlePolling(subtitleWorkflow.value);
  }
}
async function openSubtitleWorkspace(lesson: Lesson) {
  stopSubtitlePolling();
  subtitleLesson.value = lesson; subtitleLessonId.value = String(lesson.id); subtitleWorkflow.value = null;
  workflowError.value = ''; workflowNotice.value = ''; workflowWarning.value = ''; workflowBusy.value = false; subtitlePollFailures = 0;
  await refreshSubtitleWorkspace();
}
function closeSubtitleWorkspace() {
  stopSubtitlePolling(); subtitleLesson.value = null; subtitleWorkflow.value = null; subtitleLessonId.value = '';
}
async function generateEnglishTranscript() {
  if (!subtitleLesson.value || workflowBusy.value) return;
  if (!window.confirm('Prepare lesson audio and queue English transcription? The assigned provider may charge for transcription.')) return;
  workflowBusy.value = true; workflowError.value = '';
  try { await request(`/api/admin/lessons/${subtitleLesson.value.id}/subtitle-workflow/transcribe`, 'POST', {}); await refreshSubtitleWorkspace(); }
  catch (caught) {
    const requestError = (caught as Error).message;
    await refreshSubtitleWorkspace();
    if (!subtitleWorkflow.value?.preparations.some(preparation => preparation.status === 'failed')) workflowError.value = requestError;
  }
  finally { workflowBusy.value = false; }
}
async function saveEnglishTranscript(transcriptId: number) {
  if (!subtitleLesson.value) return;
  try { await request(`/api/admin/lessons/${subtitleLesson.value.id}/transcripts/${transcriptId}/draft`, 'PUT', { text: transcriptDrafts[transcriptId] }); await refreshSubtitleWorkspace(); }
  catch (caught) { workflowError.value = (caught as Error).message; }
}
async function approveEnglishTranscript(transcriptId: number) {
  if (!subtitleLesson.value) return;
  try {
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/transcripts/${transcriptId}/draft`, 'PUT', { text: transcriptDrafts[transcriptId] });
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/transcripts/${transcriptId}/approve`, 'POST', { language: 'en' });
    workflowNotice.value = 'English transcript approved.'; await refreshSubtitleWorkspace();
  }
  catch (caught) { workflowError.value = (caught as Error).message; }
}
async function alignEnglishTranscript(transcriptId: number) {
  if (!subtitleLesson.value) return;
  workflowBusy.value = true; workflowError.value = '';
  try { await request(`/api/admin/lessons/${subtitleLesson.value.id}/whisperx-alignments`, 'POST', { transcript_operation_id: transcriptId }); await refreshSubtitleWorkspace(); }
  catch (caught) { workflowError.value = (caught as Error).message; }
  finally { workflowBusy.value = false; }
}
async function approveEnglishSubtitle(alignmentId: number) {
  if (!subtitleLesson.value) return;
  try {
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/subtitles/${alignmentId}/en/approve`, 'POST', { srt_text: alignmentSrtDrafts[alignmentId] });
    await refreshSubtitleWorkspace();
  } catch (caught) { workflowError.value = (caught as Error).message; }
}
async function generateFilipinoTranslation(transcriptId: number, alignmentId: number, versionId: string) {
  if (!subtitleLesson.value) return;
  if (!window.confirm('Queue a Filipino translation from the approved English transcript? The assigned provider may charge for translation.')) return;
  workflowBusy.value = true; workflowError.value = '';
  try { await request(`/api/admin/lessons/${subtitleLesson.value.id}/transcripts/${transcriptId}/translate`, 'POST', { source_alignment_operation_id: alignmentId, source_english_srt_version_id: versionId }); await refreshSubtitleWorkspace(); }
  catch (caught) { workflowError.value = (caught as Error).message; }
  finally { workflowBusy.value = false; }
}
async function saveFilipinoTranslation(translationId: number) {
  if (!subtitleLesson.value) return;
  try {
    const cues = translationCueDrafts[translationId];
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/translations/${translationId}/draft`, 'PUT', cues?.length ? { cues: cues.map(({ index, text }) => ({ index, text })) } : { text: translationDrafts[translationId] });
    await refreshSubtitleWorkspace();
  }
  catch (caught) { workflowError.value = (caught as Error).message; }
}
async function approveFilipinoSubtitle(translationId: number) {
  if (!subtitleLesson.value) return;
  const file = subtitleFiles[translationId];
  const body = file ? new FormData() : {};
  if (file && body instanceof FormData) body.append('srt_file', file);
  try {
    const cues = translationCueDrafts[translationId];
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/translations/${translationId}/draft`, 'PUT', cues?.length ? { cues: cues.map(({ index, text }) => ({ index, text })) } : { text: translationDrafts[translationId] });
    await request(`/api/admin/lessons/${subtitleLesson.value.id}/subtitles/${translationId}/fil/approve`, 'POST', body);
    delete subtitleFiles[translationId]; workflowNotice.value = 'Filipino subtitle version approved.'; await refreshSubtitleWorkspace();
  } catch (caught) { workflowError.value = (caught as Error).message; }
}
let searchTimer: ReturnType<typeof setTimeout>;
function searchChanged() { clearTimeout(searchTimer); page.value = 1; searchTimer = setTimeout(load, 250); }
onMounted(() => { resetForm(); void load(); });
onUnmounted(stopSubtitlePolling);
</script>

<template>
  <div v-if="subtitleLesson" class="subtitle-management-workspace">
    <header class="subtitle-workspace-header"><button class="secondary-button" type="button" @click="closeSubtitleWorkspace">← Curriculum</button><div><p class="eyebrow">CURRICULUM · LESSONS</p><h2>{{ subtitleLesson.title }} · Subtitle Management</h2></div></header>
    <p v-if="workflowError" class="student-error" role="alert">{{ workflowError }}</p>
    <p v-if="workflowNotice" class="category-notice" role="status">{{ workflowNotice }}</p>
    <p v-if="workflowWarning" class="subtitle-worker-warning" role="status">{{ workflowWarning }}</p>
    <p v-if="workflowLoading && !subtitleWorkflow" class="category-empty-state">Loading subtitle workflow…</p>
    <template v-if="subtitleWorkflow">
      <section class="lesson-list-panel subtitle-workflow-section">
        <div class="subtitle-workflow-toolbar"><span class="muted">Status refreshes while queue work is active.</span><button class="secondary-button" type="button" :disabled="workflowLoading" @click="refreshSubtitleWorkspace()">{{ workflowLoading ? 'Refreshing…' : 'Refresh status' }}</button></div>
        <div class="category-editor-heading"><div><h3>English transcript</h3><p class="muted">Prepare audio, transcribe, edit, approve, then align with WhisperX.</p></div><span class="category-state" :class="subtitleWorkflow.transcripts.some(item => item.approved) ? 'available' : 'unavailable'">{{ subtitleWorkflow.transcripts.some(item => item.approved) ? 'Transcript approved' : 'Needs review' }}</span></div>
        <button class="primary" type="button" :disabled="workflowBusy" @click="generateEnglishTranscript">{{ workflowBusy ? 'Queueing…' : 'Generate English transcript' }}</button>
        <p class="muted">Audio preparation and transcription run in the background. Transcription uses the assigned provider.</p>
        <div v-for="prep in subtitleWorkflow.preparations" :key="prep.operation_id" class="subtitle-operation-row">
          <strong>Audio preparation #{{ prep.operation_id }}</strong><span class="category-state" :class="prep.status === 'succeeded' ? 'available' : prep.status === 'failed' ? 'unavailable' : 'archived'">{{ prep.status === 'succeeded' ? 'Audio ready' : prep.status === 'processing' ? 'Preparing audio' : prep.status }}</span>
          <p v-if="prep.error_message" class="student-error">{{ prep.error_message }}</p>
          <small v-if="prep.status === 'queued'">Waiting for the {{ prep.queue?.queue || 'audio preparation' }} queue worker.</small>
          <small v-if="prep.status === 'succeeded' && !prep.chunks.length">Audio preparation finished; waiting for transcript status.</small>
          <small v-for="chunk in prep.chunks" :key="chunk.operation_id || chunk.offset_seconds">Transcript chunk {{ chunk.operation_id || 'pending' }} · {{ Number(chunk.offset_seconds || 0).toFixed(3) }}s · {{ chunk.status === 'succeeded' ? 'transcribed' : chunk.status === 'processing' ? 'transcribing' : chunk.status === 'queued' ? `waiting in ${chunk.queue?.queue || 'default'} queue` : chunk.status }}</small>
          <small v-for="chunk in failedTranscriptionChunks(prep)" :key="`error-${chunk.operation_id}`" class="student-error">Chunk {{ chunk.operation_id }} failed{{ chunk.error_code ? ` (${chunk.error_code})` : '' }}: {{ chunk.error_message || 'No error detail was recorded.' }}</small>
          <small v-if="preparationHasFailedChunk(prep)" class="student-error">A transcription chunk failed. Check the operation error before retrying generation.</small>
        </div>
        <article v-for="transcript in subtitleWorkflow.transcripts" :key="transcript.operation_id" class="subtitle-operation-row">
          <div class="subtitle-operation-heading"><strong>Transcript #{{ transcript.operation_id }}</strong><span class="category-state" :class="transcript.approved ? 'available' : transcript.status === 'failed' ? 'unavailable' : 'archived'">{{ transcript.approved ? 'Approved' : transcript.status === 'succeeded' ? 'Pending review' : transcript.status }}</span></div>
          <small>Timeline offset {{ Number(transcript.offset_seconds || 0).toFixed(3) }} seconds</small>
          <small v-if="transcript.recovered_artifact" class="subtitle-recovery-note">Recovered from existing private files. This is a new draft awaiting review. Historical alignment input association is unverified.</small>
          <small v-if="transcript.recovered_artifact" v-for="candidate in transcript.recovery_details?.source_audio_candidates || []" :key="candidate.sha256" class="muted">Audio candidate {{ candidate.path }} · SHA-256 {{ candidate.sha256 }}</small>
          <p v-if="transcript.error_message" class="student-error">{{ transcript.error_message }}</p>
          <textarea v-model="transcriptDrafts[transcript.operation_id]" :disabled="transcript.status !== 'succeeded'" rows="5" aria-label="English transcript draft"></textarea>
          <div class="subtitle-actions">
            <button class="secondary-button" type="button" :disabled="transcript.status !== 'succeeded'" @click="saveEnglishTranscript(transcript.operation_id)">Save transcript draft</button>
            <button class="secondary-button" type="button" :disabled="transcript.status !== 'succeeded' || transcript.approved" @click="approveEnglishTranscript(transcript.operation_id)">Approve English transcript</button>
            <button class="secondary-button" type="button" :disabled="!transcript.approved || workflowBusy" @click="alignEnglishTranscript(transcript.operation_id)">Align with WhisperX</button>
          </div>
        </article>
      </section>

      <section class="lesson-list-panel subtitle-workflow-section">
        <div class="category-editor-heading"><div><h3>English subtitles</h3><p class="muted">Review the aligned SRT, edit if needed, then approve an immutable version.</p></div></div>
        <p v-if="!subtitleWorkflow.alignments.length" class="category-empty-state">No alignment jobs yet. Approve an English transcript, then run WhisperX alignment.</p>
        <article v-for="alignment in subtitleWorkflow.alignments" :key="alignment.operation_id" class="subtitle-operation-row">
          <div class="subtitle-operation-heading"><strong>Alignment #{{ alignment.operation_id }}</strong><span class="category-state" :class="alignment.approved ? 'available' : alignment.status === 'failed' ? 'unavailable' : 'archived'">{{ alignment.approved ? 'Approved' : alignment.status === 'succeeded' ? 'Pending subtitle review' : alignment.status }}</span></div>
          <p v-if="alignment.error_message" class="student-error">{{ alignment.error_message }}</p>
          <small v-if="alignment.recovered_artifact" class="subtitle-recovery-note">Recovered alignment draft. Historical audio input association is unverified; timestamps are relative to the supplied chunk with no original lesson offset applied.</small>
          <textarea v-if="alignment.status === 'succeeded'" v-model="alignmentSrtDrafts[alignment.operation_id]" rows="8" aria-label="English SRT review draft"></textarea>
          <button v-if="alignment.status === 'succeeded'" class="secondary-button" type="button" @click="approveEnglishSubtitle(alignment.operation_id)">{{ alignment.approved ? 'Approve revised English SRT' : 'Approve English SRT' }}</button>
          <button v-if="alignment.approved && alignment.download_version_id && alignment.transcript_operation_id" class="secondary-button" type="button" :disabled="workflowBusy" @click="generateFilipinoTranslation(alignment.transcript_operation_id, alignment.operation_id, alignment.download_version_id)">Translate this approved English SRT into Filipino</button>
        </article>
      </section>

      <section class="lesson-list-panel subtitle-workflow-section">
        <div class="category-editor-heading"><div><h3>Filipino translation and subtitles</h3><p class="muted">Translation is based on an approved English transcript and only enabled after English subtitles are approved.</p></div></div>
        <p v-if="!subtitleWorkflow.translations.length" class="category-empty-state">No Filipino translations have been queued.</p>
        <article v-for="translation in subtitleWorkflow.translations" :key="translation.operation_id" class="subtitle-operation-row">
          <div class="subtitle-operation-heading"><strong>Translation #{{ translation.operation_id }}</strong><span class="category-state" :class="translation.approved ? 'available' : translation.status === 'failed' ? 'unavailable' : 'archived'">{{ translation.approved ? 'Approved' : translation.status === 'succeeded' ? 'Pending review' : translation.status }}</span></div>
          <small>Target language: {{ translation.target_language || 'Filipino (legacy target)' }}</small>
          <p v-if="translation.error_message" class="student-error">{{ translation.error_message }}</p>
          <div v-if="translation.cues?.length" class="filipino-cue-review">
            <label v-for="(cue, cueIndex) in translationCueDrafts[translation.operation_id] || translation.cues" :key="cue.index" class="filipino-cue-row">
              <small>{{ cue.index }} · {{ cue.start }} → {{ cue.end }}</small>
              <textarea v-model="translationCueDrafts[translation.operation_id][cueIndex].text" :disabled="translation.status !== 'succeeded'" rows="2" :aria-label="`Filipino cue ${cue.index}`"></textarea>
            </label>
          </div>
          <textarea v-else v-model="translationDrafts[translation.operation_id]" :disabled="translation.status !== 'succeeded'" rows="5" aria-label="Filipino translation draft"></textarea>
          <div class="subtitle-actions">
            <button class="secondary-button" type="button" :disabled="translation.status !== 'succeeded'" @click="saveFilipinoTranslation(translation.operation_id)">Save Filipino draft</button>
            <label>Optional timed Filipino SRT override<input type="file" accept=".srt,application/x-subrip,text/plain" :aria-label="`Filipino SRT for translation ${translation.operation_id}`" @change="chooseSubtitleFile(translation.operation_id, $event)"></label>
            <button class="secondary-button" type="button" :disabled="translation.status !== 'succeeded'" @click="approveFilipinoSubtitle(translation.operation_id)">{{ translation.approved ? 'Approve revised Filipino SRT' : 'Approve Filipino subtitles' }}</button>
          </div>
          <small v-if="translation.cue_timed">Cue order and timestamps are inherited from the exact approved English SRT version.</small>
          <small v-else>Legacy text-only translations require a timed SRT upload before approval.</small>
        </article>
      </section>

      <section class="lesson-list-panel subtitle-workflow-section">
        <div class="category-editor-heading"><div><h3>Approved subtitle downloads</h3><p class="muted">Each link downloads the exact approved version.</p></div></div>
        <div v-for="language in ['en', 'fil']" :key="language" class="approved-subtitle-language">
          <strong>{{ language === 'en' ? 'English .SRT' : 'Filipino .SRT' }}</strong>
          <a v-for="version in subtitleVersions.filter(item => item.language === language)" :key="version.version_id" :href="version.download_url" class="text-link">Download {{ version.is_current ? 'current approved' : 'approved' }} version{{ version.approved_at ? ` · ${new Date(version.approved_at).toLocaleDateString()}` : '' }}</a>
          <span v-if="!subtitleVersions.some(item => item.language === language)" class="category-state unavailable">Not approved</span>
        </div>
        <div class="approved-subtitle-language future-video-download"><strong>Video with selectable subtitle tracks</strong><span class="category-state unavailable">Future enhancement</span></div>
        <small>Subtitle downloads are available only to authorized admins.</small>
      </section>
    </template>
  </div>
  <div v-else class="admin-lessons-page">
    <section class="lesson-editor-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">VIDEO LIBRARY</p><h2>{{ editing ? 'Edit lesson' : 'Add lesson' }}</h2></div><button v-if="editing" class="secondary-button" type="button" @click="resetForm">Cancel</button></div>
      <form class="lesson-editor-form" @submit.prevent="save">
        <label>Category<select v-model="form.category_id" required><option value="" disabled>Select category</option><option v-for="category in categories.filter(item => !item.deleted_at)" :key="category.id" :value="String(category.id)">{{ category.name }}</option></select></label>
        <label>Title<input v-model.trim="form.title" maxlength="180" required placeholder="Lesson title"></label>
        <label>Video Upload<input :key="uploadInputKey" type="file" accept="video/mp4,video/quicktime,video/webm,video/x-m4v,video/x-msvideo,.mp4,.mov,.webm,.m4v,.avi" :disabled="uploading" @change="chooseVideo"><small>MP4, MOV, WebM, M4V, or AVI · up to 1000 MB{{ editing ? ' · leave empty to keep the current video' : '' }}</small></label>
        <label>Lesson thumbnail<input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseThumbnail"><small>JPG, PNG, or WebP · up to 5 MB</small></label><div v-if="thumbnailPreview" class="catalog-image-preview"><img :src="thumbnailPreview" alt="Lesson thumbnail preview"><button type="button" class="secondary-button" @click="clearThumbnail">Remove thumbnail</button></div>
        <label>Description <span class="optional-label">Optional</span><textarea v-model.trim="form.description" rows="3" placeholder="What will students learn?"></textarea></label>
        <div class="lesson-editor-row"><label>Duration in seconds<input v-model="form.duration_seconds" type="number" min="1" step="1" placeholder="Required to publish"></label><label>Position<input v-model="form.sort_order" type="number" min="0" step="1" placeholder="Auto assigned"></label></div>
        <label class="lesson-publish-toggle"><input v-model="form.is_published" type="checkbox"><span><strong>Published</strong><small>Published lessons are visible to students.</small></span></label>
        <div v-if="uploading" class="lesson-upload-progress" role="status" aria-live="polite"><span>{{ uploadPercent === null ? 'Uploading video…' : `Uploading video · ${uploadPercent}%` }}</span><progress aria-label="Video upload progress" max="100" :value="uploadPercent === null ? undefined : uploadPercent"></progress></div>
        <p v-if="error" class="student-error" role="alert">{{ error }}</p><p v-if="notice" class="category-notice" role="status">{{ notice }}</p>
        <div class="category-form-actions"><button class="primary" :disabled="saving || uploading || !categories.some(category => !category.deleted_at)">{{ uploading ? 'Uploading…' : saving ? 'Saving…' : editing ? 'Save changes' : 'Add lesson' }}</button></div>
      </form>
    </section>
    <section class="lesson-list-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">CURRICULUM</p><h2>Lessons <span class="category-total">{{ total }}</span></h2></div></div>
      <div class="lesson-filters"><label>Search lessons<input v-model="search" type="search" placeholder="Search by title" @input="searchChanged"></label><label>Category<select v-model="categoryId" @change="onFilter"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (Archived)' : '' }}</option></select></label><label>Status<select v-model="status" @change="onFilter"><option value="all">All lessons</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select></label></div>
      <div v-if="loading" class="category-empty-state">Loading lessons…</div><div v-else-if="!lessons.length" class="category-empty-state">No lessons match these filters.</div>
      <div v-else class="managed-lesson-list"><article v-for="lesson in lessons" :key="lesson.id" class="managed-lesson-card" :class="{ 'is-archived': !!lesson.deleted_at }"><img v-if="lesson.thumbnail_url" class="admin-lesson-thumb" :src="lesson.thumbnail_url" :alt="`${lesson.title} thumbnail`"><div v-else class="admin-lesson-thumb fallback">▶</div><div class="managed-lesson-copy"><div class="managed-lesson-heading"><h3>{{ lesson.title }}</h3><span class="category-state" :class="lesson.deleted_at ? 'archived' : lesson.is_published ? 'available' : 'unavailable'">{{ lesson.deleted_at ? 'Archived' : lesson.is_published ? 'Published' : 'Draft' }}</span></div><p>{{ lesson.category?.name || 'Archived category' }} <span>·</span> Order {{ lesson.sort_order }} <span>·</span> {{ lesson.duration_seconds ? `${Math.floor(lesson.duration_seconds / 60)}:${String(lesson.duration_seconds % 60).padStart(2, '0')}` : 'Duration not set' }} <span>·</span> {{ lesson.progress_count }} progress records</p></div><div class="managed-lesson-actions"><template v-if="!lesson.deleted_at"><button v-if="status === 'all'" class="category-action" :aria-label="`Move ${lesson.title} up`" @click="move(lesson, 'up')">↑</button><button v-if="status === 'all'" class="category-action" :aria-label="`Move ${lesson.title} down`" @click="move(lesson, 'down')">↓</button><button class="category-action" :aria-label="`Edit ${lesson.title}`" @click="edit(lesson)">Edit</button><button class="category-action" :aria-label="`Manage subtitles for ${lesson.title}`" @click="openSubtitleWorkspace(lesson)">Subtitles</button><button class="category-action archive" :aria-label="`Archive ${lesson.title}`" @click="archive(lesson)">Archive</button></template><button v-else class="category-action restore" :aria-label="`Restore ${lesson.title}`" @click="restore(lesson)">Restore</button></div></article></div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage" @click="page++; load()">Next</button></div>
    </section>
  </div>
</template>
