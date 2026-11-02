<script setup lang="ts">
import { ref } from 'vue';

defineProps<{ user: { name: string; email: string } }>();
const saving = ref(false), error = ref(''), notice = ref('');
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

async function changePassword(event: Event) {
  if (saving.value) return;
  const form = event.currentTarget as HTMLFormElement;
  const values = new FormData(form);
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const response = await fetch('/api/profile/password', {
      method: 'PUT', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify({
        current_password: values.get('current_password'),
        password: values.get('password'),
        password_confirmation: values.get('password_confirmation'),
      }),
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const errors = Object.values(body.errors || {}).flat() as string[];
      throw new Error(errors[0] || 'Your password could not be changed. Check the entries and try again.');
    }
    form.reset();
    notice.value = body.message || 'Your password has been changed.';
  } catch (caught) {
    error.value = (caught as Error).message;
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="student-profile-page">
    <section class="student-info-card profile-summary">
      <h2>Profile</h2>
      <dl><div><dt>Name</dt><dd>{{ user.name }}</dd></div><div><dt>Email</dt><dd>{{ user.email }}</dd></div></dl>
    </section>
    <section class="student-info-card profile-password-card" aria-labelledby="profile-password-title">
      <h2 id="profile-password-title">Change password</h2>
      <p class="student-password-help">Enter your current password, then choose a new password that meets the application’s password requirements.</p>
      <form class="profile-password-form" @submit.prevent="changePassword">
        <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>New password<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
        <p v-if="error" class="student-error" role="alert">{{ error }}</p>
        <p v-if="notice" class="student-success" role="status">{{ notice }}</p>
        <button class="primary" :disabled="saving">{{ saving ? 'Updating…' : 'Change password' }}</button>
      </form>
    </section>
  </div>
</template>
