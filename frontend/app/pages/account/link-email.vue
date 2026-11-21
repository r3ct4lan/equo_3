<script setup lang="ts">
import { emailConfirmationErrorPresentation } from '~/utils/profile-settings'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

useHead({ title: 'Confirm new email · Equo' })
const route = useRoute()
const { t } = useTranslation()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const token = shallowRef<string | null>(null)
const pending = ref(false)
const copyFeedback = ref('')
const error = shallowRef<ApiClientError | null>(null)

onMounted(async () => {
  token.value = new URLSearchParams(window.location.hash.slice(1)).get('token')
  if (window.location.hash) history.replaceState(history.state, '', route.path)
  await $auth.bootstrap()
})

async function confirm(): Promise<void> {
  if (!token.value || pending.value) return
  pending.value = true
  error.value = null
  try {
    const result = await $auth.protectedRequest<{ status: 'linked' | 'merge_required' }>('/v1/account/credentials/email/confirm', {
      method: 'POST', body: { token: token.value }
    })
    token.value = null
    await navigateTo({ path: '/me', query: { credentials: result.status } })
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    pending.value = false
  }
}

async function copyLink(): Promise<void> {
  if (!token.value) return
  const url = new URL(route.path, window.location.origin)
  url.hash = new URLSearchParams({ token: token.value }).toString()
  try {
    await navigator.clipboard.writeText(url.href)
    copyFeedback.value = 'Confirmation link copied.'
  }
  catch {
    copyFeedback.value = 'Copy the confirmation link directly from your email.'
  }
}

async function cancel(): Promise<void> {
  if (pending.value) return
  pending.value = true
  try {
    await $auth.protectedRequest('/v1/account/credentials/cancel', { method: 'POST' })
    token.value = null
    await navigateTo('/me?credentials=cancelled')
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <section class="stack">
    <UiText variant="heading1">
      {{ t('Confirm new email') }}
    </UiText>
    <AppLoadingState
      v-if="state.status === 'unknown'"
      :message="t('Checking your session…')"
    />
    <AppErrorState
      v-else-if="error"
      :title="t('Could not update sign-in methods')"
      :message="t(emailConfirmationErrorPresentation(error).message)"
    />
    <AppEmptyState
      v-else-if="!token || state.status !== 'authenticated'"
      :title="t('Open the confirmation link again')"
      :message="t('Use the browser session that requested this email.')"
    />
    <UiText
      v-if="copyFeedback"
      role="status"
    >
      {{ t(copyFeedback) }}
    </UiText>
    <UiButton
      v-if="token && state.status !== 'unknown'"
      variant="secondary"
      :disabled="pending"
      @click="copyLink"
    >
      {{ t('Copy confirmation link') }}
    </UiButton>
    <div
      v-if="token && state.status === 'authenticated'"
      class="form-actions"
    >
      <UiButton
        :pending="pending"
        @click="confirm"
      >
        {{ t('Confirm new email') }}
      </UiButton>
      <UiButton
        variant="secondary"
        :disabled="pending"
        @click="cancel"
      >
        {{ t('Cancel request') }}
      </UiButton>
    </div>
  </section>
</template>
