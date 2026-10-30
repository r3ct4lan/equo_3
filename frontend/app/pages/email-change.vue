<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { emailConfirmationErrorPresentation } from '~/utils/profile-settings'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

type ConfirmationState = 'preparing' | 'ready' | 'loading' | 'success' | 'missing' | 'error'

useHead({ title: 'Confirm your new email · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const router = useRouter()
const { $api } = useNuxtApp()
const state = ref<ConfirmationState>('preparing')
const confirmationError = ref<ApiClientError | null>(null)
const resultPanel = useTemplateRef<FocusTarget>('resultPanel')
const presentation = computed(() => confirmationError.value ? emailConfirmationErrorPresentation(confirmationError.value) : null)
let capability: string | null = null

onMounted(async () => {
  const raw = route.query.token
  capability = typeof raw === 'string' && raw !== '' ? raw : null
  await router.replace({ path: '/email-change' })
  state.value = capability ? 'ready' : 'missing'
  await nextTick()
  resultPanel.value?.focus()
})

onBeforeUnmount(() => {
  capability = null
})

async function confirmEmail(): Promise<void> {
  if (!capability || state.value === 'loading') return
  state.value = 'loading'
  confirmationError.value = null
  try {
    await $api<undefined>('/v1/auth/email-change-confirmations', {
      method: 'POST',
      body: { token: capability },
      onResponse: ({ response }) => {
        if (response.status >= 200 && response.status < 300 && response.status !== 204) throw new Error('Email change was not confirmed.')
      }
    })
    capability = null
    state.value = 'success'
  }
  catch (error: unknown) {
    confirmationError.value = toApiClientError(error)
    if (!emailConfirmationErrorPresentation(confirmationError.value).retryable) capability = null
    state.value = 'error'
  }
  await nextTick()
  resultPanel.value?.focus()
}

const { t } = useTranslation()
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="email-confirmation-title"
  >
    <div class="auth-intro stack">
      <UiText variant="eyebrow">
        {{ t('Email confirmation') }}
      </UiText>
      <UiText
        id="email-confirmation-title"
        variant="heading1"
      >
        {{ t('Confirm your new email') }}
      </UiText>
      <UiText variant="lede">
        {{ t('Confirm the one-time link before Equo changes your sign-in address.') }}
      </UiText>
    </div>
    <UiCard
      as="section"
      class="stack"
    >
      <AppLoadingState
        v-if="state === 'preparing' || state === 'loading'"
        :message="t('Confirming your email…')"
      />
      <div
        v-else-if="state === 'ready'"
        ref="resultPanel"
        class="stack"
        tabindex="-1"
      >
        <UiText variant="heading2">
          {{ t('Ready to confirm') }}
        </UiText>
        <UiText>{{ t('Your email has not changed yet.') }}</UiText>
        <UiButton
          @click="confirmEmail"
        >
          {{ t('Confirm new email') }}
        </UiButton>
      </div>
      <UiNotice
        v-else-if="state === 'success'"
        ref="resultPanel"
        tone="success"
        role="status"
        tabindex="-1"
      >
        <div>
          <strong>{{ t('Email changed') }}</strong>
          <UiText>{{ t('Use your new email the next time you sign in. Existing signed-in devices remain active.') }}</UiText>
          <UiLink to="/me">
            {{ t('Open your profile') }}
          </UiLink>
        </div>
      </UiNotice>
      <div
        v-else-if="state === 'missing'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          :title="t('Confirmation link is missing')"
          :message="t('Open the complete one-time link from your confirmation email.')"
        />
      </div>
      <div
        v-else-if="state === 'error' && presentation"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          :title="presentation.title"
          :message="presentation.message"
          :request-id="confirmationError?.requestId"
          :retry-label="presentation.retryable ? 'Try confirmation again' : undefined"
          @retry="confirmEmail"
        />
      </div>
      <UiButton
        variant="secondary"
        to="/me"
      >
        {{ t('Back to profile') }}
      </UiButton>
    </UiCard>
  </section>
</template>
