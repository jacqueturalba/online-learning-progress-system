<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';

type Category = { id: number; name: string; deleted_at: string | null };
type Lesson = { id: number; category_id: number; title: string; description: string | null; video_url: string; duration_seconds: number | null; sort_order: number; is_published: boolean; deleted_at: string | null; progress_count: number; category?: Category };
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
const lessons = ref<Lesson[]>([]), categories = ref<Category[]>([]), total = ref(0), loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const search = ref(''), categoryId = ref(''), status = ref('all'), page = ref(1), lastPage = ref(1), editing = ref<number | null>(null);
const form = reactive({ category_id: '', title: '', video_url: '', description: '', duration_seconds: '', sort_order: '', is_published: false });
async function request(url: string, method = 'GET', body?: unknown) {
  const response = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: body === undefined ? undefined : JSON.stringify(body) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] as string || 'Request failed.');
  return data;
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
function resetForm() { editing.value = null; Object.assign(form, { category_id: categories.value.find(c => !c.deleted_at)?.id.toString() || '', title: '', video_url: '', description: '', duration_seconds: '', sort_order: '', is_published: false }); }
function edit(lesson: Lesson) { editing.value = lesson.id; Object.assign(form, { category_id: String(lesson.category_id), title: lesson.title, video_url: lesson.video_url || '', description: lesson.description || '', duration_seconds: lesson.duration_seconds ? String(lesson.duration_seconds) : '', sort_order: String(lesson.sort_order), is_published: lesson.is_published }); error.value = ''; notice.value = ''; }
async function save() {
  saving.value = true; error.value = ''; notice.value = '';
  const payload = { category_id: Number(form.category_id), title: form.title, video_url: form.video_url, description: form.description || null, duration_seconds: form.duration_seconds ? Number(form.duration_seconds) : null, sort_order: form.sort_order ? Number(form.sort_order) : undefined, is_published: form.is_published };
  try {
    if (editing.value) await request(`/api/admin/lessons/${editing.value}`, 'PUT', payload);
    else await request('/api/admin/lessons', 'POST', payload);
    notice.value = editing.value ? 'Lesson updated.' : 'Lesson added.'; resetForm(); await load();
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}
async function archive(lesson: Lesson) { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}`, 'DELETE'); notice.value = 'Lesson archived. Progress is preserved.'; await load(); } catch (caught) { error.value = (caught as Error).message; } }
async function restore(lesson: Lesson) { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}/restore`, 'POST'); notice.value = 'Lesson restored.'; await load(); } catch (caught) { error.value = (caught as Error).message; } }
async function move(lesson: Lesson, direction: 'up' | 'down') { error.value = ''; try { await request(`/api/admin/lessons/${lesson.id}/move`, 'PATCH', { direction }); await load(); } catch (caught) { error.value = (caught as Error).message; } }
function onFilter() { page.value = 1; void load(); }
let searchTimer: ReturnType<typeof setTimeout>;
function searchChanged() { clearTimeout(searchTimer); page.value = 1; searchTimer = setTimeout(load, 250); }
onMounted(() => { resetForm(); void load(); });
</script>

<template>
  <div class="admin-lessons-page">
    <section class="lesson-editor-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">VIDEO LIBRARY</p><h2>{{ editing ? 'Edit lesson' : 'Add lesson' }}</h2></div><button v-if="editing" class="secondary-button" type="button" @click="resetForm">Cancel</button></div>
      <form class="lesson-editor-form" @submit.prevent="save">
        <label>Category<select v-model="form.category_id" required><option value="" disabled>Select category</option><option v-for="category in categories.filter(item => !item.deleted_at)" :key="category.id" :value="String(category.id)">{{ category.name }}</option></select></label>
        <label>Title<input v-model.trim="form.title" maxlength="180" required placeholder="Lesson title"></label>
        <label>Video URL (Uploaded Video)<input v-model.trim="form.video_url" type="url" required placeholder="https://…"></label>
        <label>Description <span class="optional-label">Optional</span><textarea v-model.trim="form.description" rows="3" placeholder="What will students learn?"></textarea></label>
        <div class="lesson-editor-row"><label>Duration in seconds<input v-model="form.duration_seconds" type="number" min="1" step="1" placeholder="Required to publish"></label><label>Position<input v-model="form.sort_order" type="number" min="0" step="1" placeholder="Auto assigned"></label></div>
        <label class="lesson-publish-toggle"><input v-model="form.is_published" type="checkbox"><span><strong>Published</strong><small>Published lessons are visible to students.</small></span></label>
        <p v-if="error" class="student-error" role="alert">{{ error }}</p><p v-if="notice" class="category-notice" role="status">{{ notice }}</p>
        <div class="category-form-actions"><button class="primary" :disabled="saving || !categories.some(category => !category.deleted_at)">{{ saving ? 'Saving…' : editing ? 'Save changes' : 'Add lesson' }}</button></div>
      </form>
    </section>
    <section class="lesson-list-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">CURRICULUM</p><h2>Lessons <span class="category-total">{{ total }}</span></h2></div></div>
      <div class="lesson-filters"><label>Search lessons<input v-model="search" type="search" placeholder="Search by title" @input="searchChanged"></label><label>Category<select v-model="categoryId" @change="onFilter"><option value="">All categories</option><option v-for="category in categories" :key="category.id" :value="String(category.id)">{{ category.name }}{{ category.deleted_at ? ' (Archived)' : '' }}</option></select></label><label>Status<select v-model="status" @change="onFilter"><option value="all">All lessons</option><option value="published">Published</option><option value="draft">Draft</option><option value="archived">Archived</option></select></label></div>
      <p v-if="error && !editing" class="student-error" role="alert">{{ error }}</p>
      <div v-if="loading" class="category-empty-state">Loading lessons…</div><div v-else-if="!lessons.length" class="category-empty-state">No lessons match these filters.</div>
      <div v-else class="managed-lesson-list"><article v-for="lesson in lessons" :key="lesson.id" class="managed-lesson-card" :class="{ 'is-archived': !!lesson.deleted_at }"><div class="managed-lesson-copy"><div class="managed-lesson-heading"><h3>{{ lesson.title }}</h3><span class="category-state" :class="lesson.deleted_at ? 'archived' : lesson.is_published ? 'available' : 'unavailable'">{{ lesson.deleted_at ? 'Archived' : lesson.is_published ? 'Published' : 'Draft' }}</span></div><p>{{ lesson.category?.name || 'Archived category' }} <span>·</span> Order {{ lesson.sort_order }} <span>·</span> {{ lesson.duration_seconds ? `${Math.floor(lesson.duration_seconds / 60)}:${String(lesson.duration_seconds % 60).padStart(2, '0')}` : 'Duration not set' }} <span>·</span> {{ lesson.progress_count }} progress records</p></div><div class="managed-lesson-actions"><template v-if="!lesson.deleted_at"><button v-if="status === 'all'" class="category-action" :aria-label="`Move ${lesson.title} up`" @click="move(lesson, 'up')">↑</button><button v-if="status === 'all'" class="category-action" :aria-label="`Move ${lesson.title} down`" @click="move(lesson, 'down')">↓</button><button class="category-action" :aria-label="`Edit ${lesson.title}`" @click="edit(lesson)">Edit</button><button class="category-action archive" :aria-label="`Archive ${lesson.title}`" @click="archive(lesson)">Archive</button></template><button v-else class="category-action restore" :aria-label="`Restore ${lesson.title}`" @click="restore(lesson)">Restore</button></div></article></div>
      <div v-if="lastPage > 1" class="lesson-pagination"><button class="secondary-button" :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button class="secondary-button" :disabled="page >= lastPage" @click="page++; load()">Next</button></div>
    </section>
  </div>
</template>
