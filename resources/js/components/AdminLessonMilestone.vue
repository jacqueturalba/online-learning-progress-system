<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
type Item = { id: number; title: string; sort_order?: number; category?: { name: string }; scheduled_at?: string; zoom_batch_id?: number };
const form = reactive({ enabled: false, lesson_id: '', zoom_session_id: '', message: '', remove_image: false });
const lessons = ref<Item[]>([]), sessions = ref<Item[]>([]), image = ref<File|null>(null), imageUrl = ref<string|null>(null), loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
async function load() { loading.value=true; try { const r=await fetch('/api/admin/lesson-milestone',{credentials:'same-origin',headers:{Accept:'application/json'}}); const b=await r.json(); if(!r.ok) throw new Error(b.message||'Settings unavailable.'); lessons.value=b.lessons; sessions.value=b.sessions; Object.assign(form,{enabled:Boolean(b.settings.enabled),lesson_id:b.settings.lesson_id?String(b.settings.lesson_id):'',zoom_session_id:b.settings.zoom_session_id?String(b.settings.zoom_session_id):'',message:b.settings.message||'',remove_image:false}); imageUrl.value=b.settings.image_path?`/storage/${b.settings.image_path}`:null; } catch(e){error.value=(e as Error).message} finally{loading.value=false} }
async function save(){ if(saving.value)return; saving.value=true; error.value=''; notice.value=''; const data=new FormData(); data.append('_method','PUT'); data.append('enabled',form.enabled?'1':'0'); data.append('lesson_id',form.lesson_id); data.append('zoom_session_id',form.zoom_session_id); data.append('message',form.message); if(image.value)data.append('image',image.value); if(form.remove_image)data.append('remove_image','1'); try{const r=await fetch('/api/admin/lesson-milestone',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf()},body:data}); const b=await r.json(); if(!r.ok)throw new Error(b.message||'Settings could not be saved.'); notice.value='Milestone settings saved.'; image.value=null; await load();}catch(e){error.value=(e as Error).message}finally{saving.value=false} }
onMounted(()=>void load());
</script>
<template>
  <details class="settings-collapse milestone-settings-disclosure">
    <summary><span><strong>Student Discussion</strong><small>Lesson milestone notification, image, and upcoming meeting</small></span><span class="collapse-action">Expand / Collapse</span></summary>
    <div class="settings-content milestone-settings-content">
      <div class="milestone-settings-intro"><h3>Lesson milestone notification</h3><p>Use an existing upcoming Zoom session. A student only sees their registered, individual join link.</p></div>
      <p v-if="loading" role="status">Loading settings…</p>
      <form v-else class="milestone-admin-form" @submit.prevent="save">
        <label class="lesson-publish-toggle"><input v-model="form.enabled" type="checkbox"><span><strong>Enable notification</strong></span></label>
        <label>Milestone lesson<select v-model="form.lesson_id"><option value="">Choose lesson</option><option v-for="lesson in lessons" :key="lesson.id" :value="String(lesson.id)">{{lesson.category?.name}} · Lesson {{lesson.sort_order}}: {{lesson.title}}</option></select></label>
        <label>Upcoming Zoom session<select v-model="form.zoom_session_id"><option value="">Choose session</option><option v-for="session in sessions" :key="session.id" :value="String(session.id)">{{session.title}} · {{new Date(session.scheduled_at!).toLocaleString()}}</option></select></label>
        <label>Notification message<textarea v-model="form.message" rows="3" maxlength="2000" placeholder="Invite students to share questions and expectations."></textarea></label>
        <label>Accompanying image<input type="file" accept="image/jpeg,image/png,image/webp" @change="image=($event.target as HTMLInputElement).files?.[0]||null"></label>
        <img v-if="imageUrl" class="milestone-image-preview" :src="imageUrl" alt="Milestone image">
        <label v-if="imageUrl" class="lesson-publish-toggle"><input v-model="form.remove_image" type="checkbox"><span>Remove current image</span></label>
        <p v-if="error" class="student-error" role="alert">{{error}}</p><p v-if="notice" class="student-success" role="status">{{notice}}</p>
        <button class="primary" :disabled="saving">{{saving?'Saving…':'Save milestone settings'}}</button>
      </form>
    </div>
  </details>
</template>
