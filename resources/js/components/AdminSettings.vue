<script setup lang="ts">
import { ref } from 'vue';
import AdminBranding from './AdminBranding.vue';
import AdminAiIntegrations from './AdminAiIntegrations.vue';

const active = ref<'branding' | 'ai'>('branding');
</script>

<template>
  <section class="admin-settings-page">
    <nav class="settings-tabs" aria-label="Settings sections">
      <button :class="{ active: active === 'branding' }" @click="active = 'branding'">Branding</button>
      <button :class="{ active: active === 'ai' }" @click="active = 'ai'">AI integrations</button>
    </nav>
    <AdminBranding v-if="active === 'branding'" />
    <AdminAiIntegrations v-else />
  </section>
</template>
