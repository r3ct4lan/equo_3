<script setup lang="ts">
import type { CurrentUser } from '~/types/api'

const props = defineProps<{ user: CurrentUser }>()
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const { locale } = useLocale()
const selection = ref(props.user.currency ?? 'EUR')
const pending = ref(false)
const saved = ref(false)
const error = ref(false)
async function save(): Promise<void> {
  pending.value = true
  error.value = false
  saved.value = false
  try {
    const user = await $auth.protectedRequest<CurrentUser>('/v1/me/currency', { method: 'PATCH', body: { currency: selection.value } })
    currentUser.setAuthenticated(user)
    saved.value = true
  }
  catch {
    error.value = true
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <UiCard
    as="form"
    class="stack"
    @submit.prevent="save"
  >
    <UiText variant="heading2">
      {{ locale === 'ru' ? 'Валюта профиля' : 'Profile currency' }}
    </UiText>
    <CurrencySelect
      v-model="selection"
      :label="locale === 'ru' ? 'Валюта профиля' : 'Profile currency'"
    />
    <UiText
      v-if="saved"
      role="status"
    >
      {{ locale === 'ru' ? 'Валюта сохранена.' : 'Currency saved.' }}
    </UiText>
    <UiText
      v-if="error"
      role="alert"
    >
      {{ locale === 'ru' ? 'Не удалось сохранить валюту. Попробуйте ещё раз.' : 'Could not save currency. Please try again.' }}
    </UiText>
    <FormSubmitButton
      compact
      :pending="pending"
      :pending-label="locale === 'ru' ? 'Сохранение…' : 'Saving…'"
    >
      {{ locale === 'ru' ? 'Сохранить валюту' : 'Save currency' }}
    </FormSubmitButton>
  </UiCard>
</template>
