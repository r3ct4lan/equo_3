<script setup lang="ts">
import type { CurrentUser } from '~/types/api'
import { validateProfileName } from '~/utils/profile-settings'

const props = defineProps<{ user: CurrentUser }>()
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ name: props.user.name })
const saved = ref(false)
const summary = computed(() => formError.value ? 'Your name could not be saved. Please try again.' : (Object.keys(fieldErrors.value).length ? 'Correct the highlighted field.' : null))

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

const { t } = useTranslation()
</script>

<template>
  <UiCard
    as="form"
    class="stack"
    novalidate
    :aria-label="t('Change profile name')"
    @submit.prevent="saveName"
  >
    <div>
      <UiText variant="eyebrow">
        {{ t('Profile details') }}
      </UiText>
      <UiText variant="heading2">
        {{ t('Display name') }}
      </UiText>
    </div>
    <FormErrorSummary
      :title="t('Check the name')"
      :message="summary"
      :request-id="formError?.requestId"
    />
    <UiField>
      <label for="profile-name">{{ t('Name') }}</label>
      <UiInput
        id="profile-name"
        v-model="values.name"
        name="name"
        autocomplete="name"
        :disabled="isSubmitting"
        required
        :aria-invalid="errorsFor('name').length
          > 0"
        :aria-describedby="errorsFor('name').length ? 'profile-name-error' : undefined"
        @input="clearErrors(); saved = false"
      />
      <FormFieldError
        id="profile-name-error"
        :messages="errorsFor('name')"
      />
    </UiField>
    <UiText
      v-if="saved"
      role="status"
    >
      {{ t('Name saved.') }}
    </UiText>
    <FormSubmitButton
      compact
      :pending="isSubmitting"
      :pending-label="t('Saving name…')"
    >
      {{ t('Save name') }}
    </FormSubmitButton>
  </UiCard>
</template>
