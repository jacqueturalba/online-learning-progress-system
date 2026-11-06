<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Archive, Check, ChevronRight, CirclePlus, FolderTree, Pencil, RotateCcw, X } from '@lucide/vue';

type Category = {
  id: number; name: string; description: string | null; sort_order: number; is_active: boolean; thumbnail_url: string | null; background_color: string | null;
  lessons_count: number; enable_first_lesson_access: boolean; unlock_all_lessons: boolean; deleted_at: string | null;
};

const categories = ref<Category[]>([]), loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const editingId = ref<number | null>(null);
const form = ref({ name: '', description: '', sort_order: 1, is_active: true, enable_first_lesson_access: false, unlock_all_lessons: false });
const thumbnail = ref<File | null>(null), thumbnailPreview = ref<string | null>(null), removeThumbnail = ref(false), categoryColor = ref('#e8f2ea');
const isEditing = computed(() => editingId.value !== null);

const headers = () => ({
  Accept: 'application/json',
  'Content-Type': 'application/json',
  'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
});

async function loadCategories() {
  loading.value = true;
  error.value = '';
  try {
    const response = await fetch('/api/admin/categories', { credentials: 'same-origin', headers: headers() });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Categories could not be loaded.');
    categories.value = body.categories;
  } catch (caught) {
    error.value = (caught as Error).message;
  } finally {
    loading.value = false;
  }
}

function startCreate() {
  editingId.value = null;
  form.value = {
    name: '', description: '',
    sort_order: categories.value.reduce((largest, category) => Math.max(largest, category.sort_order), 0) + 1,
    is_active: true, enable_first_lesson_access: false, unlock_all_lessons: false,
  };
  thumbnail.value = null; thumbnailPreview.value = null; removeThumbnail.value = false; categoryColor.value = '#e8f2ea';
  error.value = '';
  notice.value = '';
}

function startEdit(category: Category) {
  if (category.deleted_at) return;
  editingId.value = category.id;
  form.value = { name: category.name, description: category.description ?? '', sort_order: category.sort_order, is_active: category.is_active, enable_first_lesson_access: category.enable_first_lesson_access, unlock_all_lessons: category.unlock_all_lessons };
  thumbnail.value = null; thumbnailPreview.value = category.thumbnail_url; removeThumbnail.value = false; categoryColor.value = category.background_color || '#e8f2ea';
  error.value = '';
  notice.value = '';
}
function chooseThumbnail(event: Event) { const file = (event.target as HTMLInputElement).files?.[0] ?? null; thumbnail.value = file; removeThumbnail.value = false; if (thumbnailPreview.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreview.value); thumbnailPreview.value = file ? URL.createObjectURL(file) : null; }
function clearThumbnail() { if (thumbnailPreview.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreview.value); thumbnail.value = null; thumbnailPreview.value = null; removeThumbnail.value = true; }

function cancelEdit() {
  editingId.value = null;
  form.value = { name: '', description: '', sort_order: 1, is_active: true, enable_first_lesson_access: false, unlock_all_lessons: false };
  error.value = '';
}

async function saveCategory() {
  saving.value = true;
  error.value = '';
  notice.value = '';
  try {
    const editing = editingId.value !== null;
    const payload = new FormData();
    Object.entries({ ...form.value, sort_order: String(form.value.sort_order), background_color: categoryColor.value, remove_thumbnail: removeThumbnail.value ? '1' : '0' }).forEach(([key, value]) => {
      const serialized = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
      payload.append(key, serialized);
    });
    if (thumbnail.value) payload.append('thumbnail', thumbnail.value);
    if (editing) payload.append('_method', 'PUT');
    const response = await fetch(editing ? `/api/admin/categories/${editingId.value}` : '/api/admin/categories', {
      method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '' },
      body: payload,
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Category could not be saved.');
    await loadCategories();
    cancelEdit();
    notice.value = editing ? 'Category updated.' : 'Category added.';
  } catch (caught) {
    error.value = (caught as Error).message;
  } finally {
    saving.value = false;
  }
}

async function archiveCategory(category: Category) {
  if (!window.confirm(`Archive “${category.name}”? Its lessons and student history will be preserved.`)) return;
  error.value = '';
  notice.value = '';
  try {
    const response = await fetch(`/api/admin/categories/${category.id}`, { method: 'DELETE', credentials: 'same-origin', headers: headers() });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Category could not be archived.');
    if (editingId.value === category.id) cancelEdit();
    await loadCategories();
    notice.value = 'Category archived. Its student history is preserved.';
  } catch (caught) {
    error.value = (caught as Error).message;
  }
}

async function restoreCategory(category: Category) {
  error.value = '';
  notice.value = '';
  try {
    const response = await fetch(`/api/admin/categories/${category.id}/restore`, { method: 'POST', credentials: 'same-origin', headers: headers() });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Category could not be restored.');
    await loadCategories();
    notice.value = 'Category restored. Its progression availability setting has been preserved.';
  } catch (caught) {
    error.value = (caught as Error).message;
  }
}

onMounted(() => {
  startCreate();
  void loadCategories();
});
</script>

<template>
  <div class="admin-categories-page">
    <section class="category-editor-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">CATEGORY SETUP</p><h2>{{ isEditing ? 'Edit category' : 'Add category' }}</h2></div><button v-if="isEditing" class="student-close-button" aria-label="Cancel category edit" @click="cancelEdit"><X :size="17" /></button></div>
      <form class="category-editor-form" @submit.prevent="saveCategory">
        <label>Category name<input v-model.trim="form.name" name="name" type="text" maxlength="120" required placeholder="Enter a category name"></label>
        <label>Description <span class="optional-label">Optional</span><textarea v-model.trim="form.description" name="description" rows="3" placeholder="Describe this learning category"></textarea></label>
        <label>Category thumbnail<input type="file" accept="image/jpeg,image/png,image/webp" @change="chooseThumbnail"><small>JPG, PNG, or WebP · up to 5 MB</small></label><div v-if="thumbnailPreview" class="catalog-image-preview"><img :src="thumbnailPreview" alt="Category thumbnail preview"><button type="button" class="secondary-button" @click="clearThumbnail">Remove thumbnail</button></div><label>Category card color<input v-model="categoryColor" type="color" aria-label="Category card background color"></label>
        <div class="category-editor-row">
          <label>Display order<input v-model.number="form.sort_order" name="sort_order" type="number" min="0" step="1" required></label>
          <label class="category-availability"><input v-model="form.is_active" name="is_active" type="checkbox"><span><strong>Available for student progression</strong><small>Students can access published lessons in this category.</small></span></label>
        </div>
        <label class="category-availability"><input v-model="form.enable_first_lesson_access" name="enable_first_lesson_access" type="checkbox"><span><strong>Enable first lesson access</strong><small>Let students begin this category without completing the preceding category. The first available category is accessible by default.</small></span></label>
        <label class="category-availability"><input v-model="form.unlock_all_lessons" name="unlock_all_lessons" type="checkbox"><span><strong>Unlock all lessons</strong><small>Make every published lesson accessible as soon as the student can access this category.</small></span></label>
        <p v-if="error" class="student-error category-form-error" role="alert">{{ error }}</p>
        <div class="category-form-actions"><button class="primary" :disabled="saving">{{ saving ? 'Saving…' : isEditing ? 'Save changes' : 'Add category' }}</button><button v-if="isEditing" type="button" class="secondary-button" @click="cancelEdit">Cancel</button></div>
      </form>
    </section>

    <section class="category-list-panel">
      <div class="category-editor-heading"><div><p class="eyebrow">CURRICULUM STRUCTURE</p><h2>Categories <span class="category-total">{{ categories.length }}</span></h2></div><button class="primary category-add-button" @click="startCreate"><CirclePlus :size="16" /> Add category</button></div>
      <p v-if="notice" class="category-notice" role="status"><Check :size="15" /> {{ notice }}</p>
      <p v-if="error && !isEditing" class="student-error" role="alert">{{ error }}</p>
      <div v-if="loading" class="category-empty-state">Loading categories…</div>
      <div v-else-if="!categories.length" class="category-empty-state"><FolderTree :size="23" /><span>No categories yet. Add one to structure the learning path.</span></div>
      <div v-else class="managed-category-list">
        <article v-for="category in categories" :key="category.id" class="managed-category-card" :class="{ 'is-archived': !!category.deleted_at }">
          <div class="managed-category-main"><img v-if="category.thumbnail_url" class="admin-lesson-thumb" :src="category.thumbnail_url" :alt="`${category.name} thumbnail`"><span v-else class="managed-category-icon"><FolderTree :size="18" /></span><div class="managed-category-copy"><div class="managed-category-title"><h3>{{ category.name }}</h3><span v-if="category.deleted_at" class="category-state archived">Archived</span><span v-else-if="category.is_active" class="category-state available">Available</span><span v-else class="category-state unavailable">Unavailable</span></div><p>{{ category.description || 'No description provided.' }}</p><div class="managed-category-meta"><span>Order {{ category.sort_order }}</span><span>{{ category.lessons_count }} {{ category.lessons_count === 1 ? 'lesson' : 'lessons' }}</span></div></div></div>
          <div class="managed-category-actions"><button v-if="category.deleted_at" class="category-action restore" :aria-label="`Restore ${category.name}`" @click="restoreCategory(category)"><RotateCcw :size="15" /><span>Restore</span></button><template v-else><button class="category-action" :aria-label="`Edit ${category.name}`" @click="startEdit(category)"><Pencil :size="15" /><span>Edit</span></button><button class="category-action archive" :aria-label="`Archive ${category.name}`" @click="archiveCategory(category)"><Archive :size="15" /><span>Archive</span></button></template></div>
        </article>
      </div>
    </section>
  </div>
</template>
