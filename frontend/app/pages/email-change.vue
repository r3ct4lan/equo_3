<script setup lang="ts">
import { emailConfirmationErrorPresentation } from '~/utils/profile-settings'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

type ConfirmationState = 'preparing' | 'ready' | 'loading' | 'success' | 'missing' | 'error'

useHead({ title: 'Confirm your new email · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const router = useRouter()
const { $api } = useNuxtApp()
const state = ref<ConfirmationState>('preparing')
const confirmationError = ref<ApiClientError | null>(null)
const resultPanel = useTemplateRef<HTMLElement>('resultPanel')
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
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="email-confirmation-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Email confirmation
      </p>
      <h1 id="email-confirmation-title">
        Confirm your new email
      </h1>
      <p class="hero__lede">
        Confirm the one-time link before Equo changes your sign-in address.
      </p>
    </div>
    <section class="panel stack">
      <AppLoadingState
        v-if="state === 'preparing' || state === 'loading'"
        message="Confirming your email…"
      />
      <div
        v-else-if="state === 'ready'"
        ref="resultPanel"
        class="stack"
        tabindex="-1"
      >
        <h2>Ready to confirm</h2>
        <p>Your email has not changed yet.</p>
        <button
          type="button"
          @click="confirmEmail"
        >
          Confirm new email
        </button>
      </div>
      <div
        v-else-if="state === 'success'"
        ref="resultPanel"
        class="status-card status-card--success"
        role="status"
        tabindex="-1"
      >
        <span
          class="status-card__marker"
          aria-hidden="true"
        />
        <div>
          <strong>Email changed</strong>
          <p>Use your new email the next time you sign in. Existing signed-in devices remain active.</p>
          <NuxtLink to="/me">Open your profile</NuxtLink>
        </div>
      </div>
      <div
        v-else-if="state === 'missing'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          title="Confirmation link is missing"
          message="Open the complete one-time link from your confirmation email."
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
          :retry-label="presentation.retryable ? 'Try confirmation again' : undefined"
          @retry="confirmEmail"
        />
        <p
          v-if="confirmationError?.requestId"
          class="support-reference"
        >
          Support reference: <code>{{ confirmationError.requestId }}</code>
        </p>
      </div>
      <NuxtLink
        to="/me"
        class="button button--secondary"
      >Back to profile</NuxtLink>
    </section>
  </section>
</template>
