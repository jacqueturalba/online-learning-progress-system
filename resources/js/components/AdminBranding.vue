<script setup lang="ts">
import { onMounted, ref } from 'vue';

type Palette = { primary: string; secondary: string; accent: string; background: string; text: string };
type Branding = { brand_name: string; brand_description: string; colors: Palette; logo_url: string | null; hero_image_url: string; daily_verse_text: string; daily_verse_reference: string };
const defaults: Branding = {
  brand_name: 'Learnwell', brand_description: 'Learn at your own pace.', logo_url: null, hero_image_url: '/storage/branding/portal-login.png', daily_verse_text: '', daily_verse_reference: '',
  colors: { primary: '#087E9B', secondary: '#126B52', accent: '#855F18', background: '#F7FAFC', text: '#17212B' },
};
const branding = ref<Branding>({ ...defaults, colors: { ...defaults.colors } });
const loading = ref(false), saving = ref(false), error = ref(''), notice = ref('');
const logoFile = ref<File | null>(null), logoPreview = ref<string | null>(null), removeLogo = ref(false);
const heroFile = ref<File | null>(null), heroPreview = ref<string | null>(null), removeHero = ref(false);
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

async function loadBranding() {
  loading.value = true; error.value = '';
  try {
    const response = await fetch('/api/admin/branding', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || 'Brand settings could not be loaded.');
    branding.value = body.branding;
    logoPreview.value = body.branding.logo_url;
    heroPreview.value = body.branding.hero_image_url;
  } catch (caught) { error.value = (caught as Error).message; }
  finally { loading.value = false; }
}
function chooseHero(event: Event) { const file = (event.target as HTMLInputElement).files?.[0] ?? null; heroFile.value = file; removeHero.value = false; if (heroPreview.value?.startsWith('blob:')) URL.revokeObjectURL(heroPreview.value); heroPreview.value = file ? URL.createObjectURL(file) : branding.value.hero_image_url; }
function clearHero() { if (heroPreview.value?.startsWith('blob:')) URL.revokeObjectURL(heroPreview.value); heroFile.value = null; heroPreview.value = null; removeHero.value = true; const input = document.querySelector<HTMLInputElement>('#branding-hero'); if (input) input.value = ''; }

function chooseLogo(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0] ?? null;
  logoFile.value = file; removeLogo.value = false;
  if (logoPreview.value?.startsWith('blob:')) URL.revokeObjectURL(logoPreview.value);
  logoPreview.value = file ? URL.createObjectURL(file) : branding.value.logo_url;
  error.value = '';
}

function clearLogo() {
  if (logoPreview.value?.startsWith('blob:')) URL.revokeObjectURL(logoPreview.value);
  logoFile.value = null; logoPreview.value = null; removeLogo.value = true;
  const input = document.querySelector<HTMLInputElement>('#branding-logo');
  if (input) input.value = '';
}

async function saveBranding() {
  if (saving.value || loading.value) return;
  saving.value = true; error.value = ''; notice.value = '';
  const data = new FormData();
  data.append('brand_name', branding.value.brand_name);
  data.append('brand_description', branding.value.brand_description || '');
  for (const [key, value] of Object.entries(branding.value.colors)) data.append('colors[' + key + ']', value);
  data.append('remove_logo', removeLogo.value ? '1' : '0');
  data.append('remove_hero_image', removeHero.value ? '1' : '0');
  data.append('daily_verse_text', branding.value.daily_verse_text || ''); data.append('daily_verse_reference', branding.value.daily_verse_reference || '');
  data.append('_method', 'PUT');
  if (logoFile.value) data.append('logo', logoFile.value);
  if (heroFile.value) data.append('hero_image', heroFile.value);
  try {
    const response = await fetch('/api/admin/branding', {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: data,
    });
    const body = await response.json();
    if (!response.ok) throw new Error(body.message || Object.values(body.errors || {}).flat()[0] as string || 'Brand settings could not be saved.');
    branding.value = body.branding;
    logoFile.value = null; removeLogo.value = false; logoPreview.value = body.branding.logo_url;
    heroFile.value = null; removeHero.value = false; heroPreview.value = body.branding.hero_image_url;
    notice.value = body.message || 'Branding settings saved.';
    window.dispatchEvent(new CustomEvent('branding-updated', { detail: body.branding }));
  } catch (caught) { error.value = (caught as Error).message; }
  finally { saving.value = false; }
}

onMounted(() => void loadBranding());
</script>

<template>
  <section class="branding-panel" aria-labelledby="branding-title">
    <header class="branding-heading"><div><p class="eyebrow">APPEARANCE</p><h2 id="branding-title">Branding settings</h2><p>Update the name, tagline, logo, and colors shown across Learnwell.</p></div></header>
    <p v-if="error" class="student-error" role="alert">{{ error }}</p>
    <p v-if="notice" class="student-success" role="status">{{ notice }}</p>
    <form class="branding-form" @submit.prevent="saveBranding">
      <label>Brand name<input v-model="branding.brand_name" maxlength="80" required autocomplete="organization"></label>
      <label>Brand description or tagline<input v-model="branding.brand_description" maxlength="160" autocomplete="off"></label>
      <fieldset class="branding-logo-field"><legend>Site logo</legend>
        <div class="branding-logo-preview" aria-live="polite">
          <img v-if="logoPreview" :src="logoPreview" alt="Current site logo preview">
          <span v-else>No logo uploaded</span>
        </div>
        <label for="branding-logo">Upload logo (JPG, PNG, or WebP; up to 2 MB)<input id="branding-logo" type="file" accept="image/jpeg,image/png,image/webp" @change="chooseLogo"></label>
        <button v-if="logoPreview" type="button" class="secondary-button" @click="clearLogo">Remove logo</button>
      </fieldset>
      <fieldset class="branding-colors"><legend>Color palette</legend>
        <label v-for="key in (['primary', 'secondary', 'accent', 'background', 'text'] as const)" :key="key">{{ key.charAt(0).toUpperCase() + key.slice(1) }}<span><input v-model="branding.colors[key]" type="color" :aria-label="key + ' brand color'"><code>{{ branding.colors[key] }}</code></span></label>
        <p class="branding-contrast-help">Text and background colors meet WCAG AA contrast. Primary buttons use a contrasting text color automatically.</p>
      </fieldset>
      <fieldset class="branding-logo-field"><legend>Learning dashboard</legend><label>Hero artwork<input id="branding-hero" type="file" accept="image/jpeg,image/png,image/webp" @change="chooseHero"><small>JPG, PNG, or WebP · up to 5 MB</small></label><div v-if="heroPreview" class="branding-logo-preview"><img :src="heroPreview" alt="Dashboard hero preview"></div><button v-if="heroPreview" type="button" class="secondary-button" @click="clearHero">Remove hero artwork</button><label>Daily verse<input v-model="branding.daily_verse_text" maxlength="1000" placeholder="Verse text"></label><label>Verse reference<input v-model="branding.daily_verse_reference" maxlength="120" placeholder="Book 1:1"></label></fieldset>
      <button class="primary" :disabled="saving || loading">{{ saving ? 'Saving…' : 'Save branding' }}</button>
    </form>
  </section>
</template>
