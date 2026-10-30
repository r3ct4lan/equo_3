<script setup lang="ts">
import type { CurrentUser } from '~/types/api'

const props = defineProps<{ user: CurrentUser }>()
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const { locale } = useLocale()
const selection = ref(props.user.locale)
const pending = ref(false)
const saved = ref(false)
const error = ref(false)
const options = [{ label: 'English', value: 'en' }, { label: 'Русский', value: 'ru' }]

async function save(): Promise<void> {
  pending.value = true
  error.value = false
  saved.value = false
  try {
    const user = await $auth.protectedRequest<CurrentUser>('/v1/me/locale', { method: 'PATCH', body: { locale: selection.value } })
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
      {{ locale === 'ru' ? 'Язык интерфейса' : 'Interface language' }}
    </UiText>
    <UiSelect
      v-model="selection"
      :label="locale === 'ru' ? 'Язык' : 'Language'"
      :options="options"
    />
    <UiText
      v-if="saved"
      role="status"
    >
      {{ locale === 'ru' ? 'Язык сохранён.' : 'Language saved.' }}
    </UiText>
    <UiText
      v-if="error"
      role="alert"
    >
      {{ locale === 'ru' ? 'Не удалось сохранить язык. Попробуйте ещё раз.' : 'Could not save language. Please try again.' }}
    </UiText>
    <FormSubmitButton
      compact
      :pending="pending"
      :pending-label="locale === 'ru' ? 'Сохранение…' : 'Saving…'"
    >
      {{ locale === 'ru' ? 'Сохранить язык' : 'Save language' }}
    </FormSubmitButton>
  </UiCard>
</template>
