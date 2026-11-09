<script setup lang="ts">
import { ref } from 'vue';
import StudentEnrollment from './StudentEnrollment.vue';

const props = defineProps<{ user: { name: string; email: string; country?: string|null; address?: string|null; phone_country_code?: string|null; phone_number?: string|null }; enrollmentEligible: boolean }>();
const emit = defineEmits<{ enrollmentSubmitted: [status: string] }>();
const saving = ref(false), error = ref(''), notice = ref('');
const contact = ref({country:props.user.country||'',address:props.user.address||'',phone_country_code:props.user.phone_country_code||'',phone_number:props.user.phone_number||''});
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
async function saveContact(){saving.value=true;error.value='';notice.value='';try{const r=await fetch('/api/profile/contact',{method:'PUT',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify(contact.value)}),b=await r.json();if(!r.ok)throw new Error(Object.values(b.errors||{}).flat()[0] as string||b.message||'Contact information could not be saved.');notice.value=b.message}catch(e){error.value=(e as Error).message}finally{saving.value=false}}

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
    <details class="student-info-card profile-summary profile-collapsible">
      <summary><h2>Profile</h2><span class="collapse-action">Collapse</span></summary>
      <div class="profile-collapsible-content">
      <dl><div><dt>Name</dt><dd>{{ user.name }}</dd></div><div><dt>Email</dt><dd>{{ user.email }}</dd></div></dl>
      <form class="profile-password-form" @submit.prevent="saveContact"><label>Country<input v-model="contact.country" list="profile-countries" required><datalist id="profile-countries"><option>Philippines</option><option>Singapore</option><option>United States</option><option>United Kingdom</option><option>Canada</option><option>Australia</option><option>Malaysia</option><option>Japan</option></datalist></label><label>Complete address<textarea v-model="contact.address" required rows="2"></textarea></label><label>Phone number<div class="phone-input"><select v-model="contact.phone_country_code" required><option value="">Calling code</option><option value="+63">Philippines +63</option><option value="+65">Singapore +65</option><option value="+1">US/Canada +1</option><option value="+44">UK +44</option><option value="+61">Australia +61</option><option value="+60">Malaysia +60</option><option value="+91">India +91</option></select><input v-model="contact.phone_number" inputmode="tel" required></div></label><p v-if="error" class="student-error" role="alert">{{ error }}</p><p v-if="notice" class="student-success" role="status">{{ notice }}</p><button class="primary" :disabled="saving">{{ saving?'Saving…':'Save contact information' }}</button></form>
      </div>
    </details>
    <details class="student-info-card profile-password-card profile-collapsible" aria-labelledby="profile-password-title">
      <summary><h2 id="profile-password-title">Change password</h2><span class="collapse-action">Expand</span></summary>
      <div class="profile-collapsible-content">
      <p class="student-password-help">Enter your current password, then choose a new password that meets the application’s password requirements.</p>
      <form class="profile-password-form" @submit.prevent="changePassword">
        <label>Current password<input name="current_password" type="password" autocomplete="current-password" required></label>
        <label>New password<input name="password" type="password" autocomplete="new-password" minlength="8" required></label>
        <label>Confirm new password<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></label>
        <p v-if="error" class="student-error" role="alert">{{ error }}</p>
        <p v-if="notice" class="student-success" role="status">{{ notice }}</p>
        <button class="primary" :disabled="saving">{{ saving ? 'Updating…' : 'Change password' }}</button>
      </form>
      </div>
    </details>
    <section v-if="enrollmentEligible" class="profile-enrollment-section" aria-labelledby="profile-enrollment-title">
      <header><p class="eyebrow">STUDENT ADMISSION</p><h2 id="profile-enrollment-title">Official Enrollment Form</h2><p>Complete or review your enrollment information and supporting documents.</p></header>
      <StudentEnrollment @submitted="emit('enrollmentSubmitted', $event)" />
    </section>
  </div>
</template>
