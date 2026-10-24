<script setup lang="ts">
import type { CurrentUser } from '~/types/api'
import { validateProfileName } from '~/utils/profile-settings'

const props = defineProps<{ user: CurrentUser }>()
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ name: props.user.name })
const saved = ref(false)
const summary = computed(() => formError.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted field.' : null))

async function saveName(): Promise<void> {
  saved.value = false
  const outcome = await submit(
    submitted => $auth.protectedRequest<CurrentUser>('/v1/me', { method: 'PATCH', body: { name: submitted.name } }),
    validateProfileName
  )
  if (outcome.ok && outcome.data) {
    currentUser.setAuthenticated(outcome.data)
    values.name = outcome.data.name
    saved.value = true
  }
}
</script>

<template>
  <form
    class="panel stack"
    novalidate
    aria-label="Change profile name"
    @submit.prevent="saveName"
  >
    <div>
      <p class="eyebrow">
        Profile details
      </p>
      <h2>Display name</h2>
    </div>
    <FormErrorSummary
      title="Check the name"
      :message="summary"
    />
    <div class="form-field">
      <label for="profile-name">Name</label>
      <input
        id="profile-name"
        v-model="values.name"
        name="name"
        autocomplete="name"
        :disabled="isSubmitting"
        required
        :aria-invalid="errorsFor('name').length > 0"
        :aria-describedby="errorsFor('name').length ? 'profile-name-error' : undefined"
        @input="clearErrors(); saved = false"
      >
      <FormFieldError
        id="profile-name-error"
        :messages="errorsFor('name')"
      />
    </div>
    <p
      v-if="saved"
      role="status"
    >
      Name saved.
    </p>
    <FormSubmitButton
      :pending="isSubmitting"
      pending-label="Saving name…"
    >
      Save name
    </FormSubmitButton>
  </form>
</template>
