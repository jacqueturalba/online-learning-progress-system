<script setup lang="ts">
import { onMounted, ref } from 'vue';

type MethodId = 'whisperx_standard' | 'whisperx_bounded' | 'openai_whisper1';
type Option = { label: string; description: string };
const selected = ref<MethodId>('whisperx_standard');
const methodIds: MethodId[] = ['whisperx_standard', 'whisperx_bounded', 'openai_whisper1'];
const options = ref<Partial<Record<MethodId, Option>>>({});
const loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

async function load() {
  loading.value = true; error.value = '';
  try {
    const response = await fetch('/api/admin/subtitle-processing-method', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Could not load subtitle processing settings.');
    selected.value = data.processing_method; options.value = data.options as Record<MethodId, Option>;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}

async function save() {
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const response = await fetch('/api/admin/subtitle-processing-method', { method: 'PUT', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify({ processing_method: selected.value }) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'Could not save subtitle processing settings.');
    selected.value = data.processing_method; options.value = data.options as Record<MethodId, Option>; notice.value = 'The method is saved and applies to new subtitle operations.';
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}

onMounted(() => void load());
</script>

<template>
  <section class="subtitle-method-settings" aria-labelledby="subtitle-method-title">
    <header><p class="eyebrow">SUBTITLES</p><h2 id="subtitle-method-title">Subtitle processing method</h2><p>Choose how new English subtitles are generated. Whisper-1 returns the transcript and word timestamps in one request per prepared audio chunk. Existing queued and historical operations keep their recorded method.</p></header>
    <p v-if="error" class="student-error" role="alert">{{ error }}</p><p v-if="notice" class="student-success" role="status">{{ notice }}</p>
    <form @submit.prevent="save">
      <label v-for="method in methodIds" :key="method" class="method-choice">
        <input v-model="selected" type="radio" name="subtitle-processing-method" :value="method" :disabled="loading || saving">
        <span><strong>{{ options[method]?.label }}</strong><small>{{ options[method]?.description }}</small></span>
      </label>
      <button class="primary" :disabled="loading || saving">{{ saving ? 'Saving…' : 'Save method' }}</button>
    </form>
  </section>
</template>

<style scoped>
.subtitle-method-settings{display:grid;gap:14px;padding:18px;border:1px solid var(--border);border-radius:10px;background:var(--surface)}
.subtitle-method-settings header h2{margin:0;color:var(--text-primary);font-size:21px}.subtitle-method-settings header p:not(.eyebrow){max-width:720px;color:var(--text-secondary);font-size:13px;line-height:1.55}
.subtitle-method-settings form{display:grid;gap:10px;max-width:760px}.method-choice{display:flex;gap:12px;align-items:flex-start;padding:13px;border:1px solid var(--border);border-radius:8px;cursor:pointer}.method-choice input{margin-top:3px}.method-choice span{display:grid;gap:4px}.method-choice strong{color:var(--text-primary);font-size:13px}.method-choice small{color:var(--text-secondary);font-size:12px;line-height:1.5}.subtitle-method-settings button{justify-self:start;min-height:38px}
</style>
