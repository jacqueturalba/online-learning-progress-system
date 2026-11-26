<script setup lang="ts">
import { ref } from 'vue';
import AdminBranding from './AdminBranding.vue';
import AdminAiIntegrations from './AdminAiIntegrations.vue';
import AdminSubtitleProcessing from './AdminSubtitleProcessing.vue';

const active = ref<'branding' | 'ai' | 'subtitle'>('branding');
</script>

<template>
  <section class="admin-settings-page">
    <nav class="settings-tabs" aria-label="Settings sections">
      <button :class="{ active: active === 'branding' }" @click="active = 'branding'">Branding</button>
      <button :class="{ active: active === 'ai' }" @click="active = 'ai'">AI integrations</button>
      <button :class="{ active: active === 'subtitle' }" @click="active = 'subtitle'">Subtitle processing</button>
    </nav>
    <AdminBranding v-if="active === 'branding'" />
    <AdminAiIntegrations v-else-if="active === 'ai'" />
    <AdminSubtitleProcessing v-else />
  </section>
</template>
