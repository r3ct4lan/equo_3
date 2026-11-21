<script setup lang="ts">
import { credentialErrorPresentation, emailConfirmationErrorPresentation } from '~/utils/profile-settings'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

const emit = defineEmits<{ linked: [] }>()
const endpoint = '/v1/account/credentials'
const { $auth } = useNuxtApp()
const { public: config } = useRuntimeConfig()
const { t } = useTranslation()
const route = useRoute()
const settings = ref<{ hasEmail: boolean, hasTelegram: boolean, emailPending: boolean, mergePending: boolean, telegramDeactivationPending: boolean } | null>(null)
const email = ref('')
const password = ref('')
const confirmationLink = ref('')
const isConfirmingEmail = ref(false)
const pending = ref(false)
const error = shallowRef<ApiClientError | null>(null)
const feedback = ref('')
const emailScheduled = ref(false)
const telegramLoginEnabled = computed(() => String(config.telegramLoginEnabled) === 'true')
const showTelegram = computed(() => settings.value?.hasEmail === true && telegramLoginEnabled.value)
const hasPendingEmail = computed(() => emailScheduled.value || settings.value?.emailPending === true)
const telegramLabel = computed(() => settings.value?.hasTelegram ? t('Change Telegram') : t('Link Telegram'))

async function load(): Promise<void> {
  try {
    settings.value = await $auth.protectedRequest(endpoint)
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
}

function confirmationToken(link: string): string | null {
  try {
    const url = new URL(link)
    return url.pathname === '/account/link-email' ? new URLSearchParams(url.hash.slice(1)).get('token') : null
  }
  catch {
    return null
  }
}

async function submit(kind: 'email' | 'confirm-email' | 'telegram' | 'cancel'): Promise<void> {
  if (pending.value) return
  pending.value = true
  error.value = null
  isConfirmingEmail.value = kind === 'confirm-email'
  feedback.value = ''
  try {
    if (kind === 'email') {
      await $auth.protectedRequest(endpoint + '/email/start', { method: 'POST', body: { email: email.value, password: password.value } })
      emailScheduled.value = true
      feedback.value = 'Confirmation email scheduled. Open the link sent to your new address.'
    }
    else if (kind === 'confirm-email') {
      const token = confirmationToken(confirmationLink.value.trim())
      if (!token) {
        feedback.value = 'Paste the confirmation link from your email.'
        return
      }
      const result = await $auth.protectedRequest<{ status: 'linked' | 'merge_required' }>(endpoint + '/email/confirm', {
        method: 'POST', body: { token }
      })
      emailScheduled.value = false
      confirmationLink.value = ''
      if (result.status === 'linked') {
        feedback.value = 'Sign-in method updated.'
        emit('linked')
        await load()
      }
      else {
        await navigateTo('/account/merge')
      }
    }
    else if (kind === 'telegram') {
      const { url } = await $auth.protectedRequest<{ url: string }>(endpoint + '/telegram/start', {
        method: 'POST',
        body: settings.value?.hasTelegram ? { currentPassword: password.value } : {}
      })
      window.location.assign(url)
    }
    else {
      await $auth.protectedRequest(endpoint + '/cancel', { method: 'POST' })
      emailScheduled.value = false
      feedback.value = 'Sign-in method request cancelled.'
      await load()
    }
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    pending.value = false
    password.value = ''
  }
}

onMounted(() => {
  switch (route.query.credentials) {
    case 'linked':
      feedback.value = 'Sign-in method updated.'
      break
    case 'cancelled':
      feedback.value = 'Sign-in method request cancelled.'
      break
    case 'failed':
      feedback.value = 'Telegram sign-in could not be completed'
      break
  }
  void load()
})
</script>

<template>
  <UiCard class="stack">
    <UiText variant="heading2">
      {{ t('Sign-in methods') }}
    </UiText>
    <AppLoadingState
      v-if="!settings && !error"
      :message="t('Checking your session…')"
    />
    <AppErrorState
      v-if="error"
      :title="t('Could not update sign-in methods')"
      :message="t((isConfirmingEmail ? emailConfirmationErrorPresentation(error) : credentialErrorPresentation(error)).message)"
      :retry-label="settings ? undefined : t('Try again')"
      @retry="load"
    />
    <UiText
      v-if="feedback"
      role="status"
    >
      {{ t(feedback) }}
    </UiText>
    <UiNotice
      v-if="settings?.mergePending"
      tone="info"
    >
      {{ t('Access to both accounts confirmed.') }}
    </UiNotice>
    <UiButton
      v-if="settings?.mergePending"
      variant="secondary"
      to="/account/merge"
    >
      {{ t('Review account merge') }}
    </UiButton>
    <form
      v-if="settings && !settings.hasEmail"
      class="stack"
      @submit.prevent="submit('email')"
    >
      <UiField>
        <label for="link-email">{{ t('Email') }}</label>
        <UiInput
          id="link-email"
          v-model="email"
          type="email"
          autocomplete="email"
          required
        />
      </UiField>
      <UiField>
        <label for="link-email-password">{{ t('Password') }}</label>
        <UiInput
          id="link-email-password"
          v-model="password"
          type="password"
          required
        />
        <UiText variant="help">
          {{ t('Enter the password for an existing account or choose a password for a new email address.') }}
        </UiText>
      </UiField>
      <UiButton
        type="submit"
        :pending="pending"
      >
        {{ t('Add email and password') }}
      </UiButton>
    </form>
    <form
      v-if="hasPendingEmail"
      id="link-email-confirmation-form"
      class="stack"
      @submit.prevent="submit('confirm-email')"
    >
      <UiField>
        <label for="link-email-confirmation">{{ t('Email confirmation link') }}</label>
        <UiInput
          id="link-email-confirmation"
          v-model="confirmationLink"
          inputmode="url"
          autocomplete="off"
          required
        />
        <UiText variant="help">
          {{ t('If you opened the email in another browser, paste the confirmation link here.') }}
        </UiText>
      </UiField>
      <UiButton
        type="submit"
        :pending="pending"
      >
        {{ t('Confirm new email') }}
      </UiButton>
    </form>
    <form
      v-if="showTelegram"
      class="stack"
      @submit.prevent="submit('telegram')"
    >
      <UiField v-if="settings?.hasTelegram">
        <label for="replace-telegram-password">{{ t('Current password') }}</label>
        <UiInput
          id="replace-telegram-password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
        />
      </UiField>
      <UiButton
        type="submit"
        variant="secondary"
        :pending="pending"
      >
        {{ telegramLabel }}
      </UiButton>
    </form>
    <UiButton
      v-if="hasPendingEmail || settings?.mergePending"
      variant="secondary"
      :disabled="pending"
      @click="submit('cancel')"
    >
      {{ t('Cancel request') }}
    </UiButton>
    <TelegramDeactivationForm
      v-if="telegramLoginEnabled && settings?.hasTelegram && !settings.hasEmail"
      :confirmed="settings.telegramDeactivationPending === true"
      @cancelled="load"
    />
  </UiCard>
</template>
