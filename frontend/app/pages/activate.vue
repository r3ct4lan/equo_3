<script setup lang="ts">
import { activationErrorPresentation } from '~/utils/auth-flow'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import type { ConnectView } from '~/types/connects'
import { invitationErrorPresentation, setInvitationFeedback } from '~/utils/connect-invitations'

type ActivationState = 'preparing' | 'ready' | 'loading' | 'success' | 'missing' | 'error'

useHead({
  title: 'Activate your account · Equo',
  meta: [{ name: 'referrer', content: 'no-referrer' }]
})

const route = useRoute()
const router = useRouter()
const { $auth } = useNuxtApp()
const state = ref<ActivationState>('preparing')
const activationError = ref<ApiClientError | null>(null)
const resultPanel = useTemplateRef<HTMLElement>('resultPanel')
let activationToken: string | null = null

const errorPresentation = computed(() => activationError.value
  ? activationErrorPresentation(activationError.value)
  : null)

async function focusResult(): Promise<void> {
  await nextTick()
  resultPanel.value?.focus()
}

async function activateAccount(): Promise<void> {
  if (!activationToken || state.value === 'loading') {
    return
  }

  state.value = 'loading'
  activationError.value = null

  try {
    await $auth.activate(activationToken)
    activationToken = null
    try {
      const connected = await $auth.protectedRequest<ConnectView | undefined>('/v1/connect-invitations/pending/accept', { method: 'POST' })
      if (connected) {
        setInvitationFeedback('success', 'Connection created successfully.')
        await navigateTo('/connects')
        return
      }
      state.value = 'success'
    }
    catch (failure) {
      setInvitationFeedback('error', invitationErrorPresentation(toApiClientError(failure)).message)
      await navigateTo('/connects')
      return
    }
  }
  catch (error: unknown) {
    activationError.value = toApiClientError(error)
    const presentation = activationErrorPresentation(activationError.value)

    if (!presentation.retryable) {
      activationToken = null
    }

    state.value = 'error'
  }

  await focusResult()
}

onMounted(async () => {
  const rawToken = route.query.token
  const token = typeof rawToken === 'string' ? rawToken : null

  await router.replace({ path: '/activate' })

  if (!token) {
    state.value = 'missing'
    await focusResult()
    return
  }

  activationToken = token
  state.value = 'ready'
  await focusResult()
})

onBeforeUnmount(() => {
  activationToken = null
})
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="activation-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Account activation
      </p>
      <h1 id="activation-title">
        Activate your account
      </h1>
      <p class="hero__lede">
        Confirm the one-time link to activate your account and sign in on this device. The link value is never displayed or saved.
      </p>
    </div>

    <div class="stack">
      <section class="panel stack">
        <AppLoadingState
          v-if="state === 'preparing' || state === 'loading'"
          message="Activating your account…"
        />

        <div
          v-else-if="state === 'ready'"
          ref="resultPanel"
          class="stack"
          tabindex="-1"
        >
          <h2>Ready to activate</h2>
          <p>Opening the link has not activated your account or signed you in.</p>
          <button
            type="button"
            @click="activateAccount"
          >
            Activate and sign in
          </button>
        </div>

        <div
          v-else-if="state === 'success'"
          ref="resultPanel"
          class="status-card status-card--success"
          role="status"
          aria-live="polite"
          tabindex="-1"
        >
          <span
            class="status-card__marker"
            aria-hidden="true"
          />
          <div>
            <strong>Your account is active</strong>
            <p>You are signed in and can start using Equo.</p>
            <NuxtLink to="/me">Open your account</NuxtLink>
          </div>
        </div>

        <div
          v-else-if="state === 'missing'"
          ref="resultPanel"
          tabindex="-1"
        >
          <AppErrorState
            title="Activation link is missing"
            message="Open the complete one-time link from your activation email or request another link below."
          />
        </div>

        <div
          v-else-if="state === 'error' && errorPresentation"
          ref="resultPanel"
          tabindex="-1"
        >
          <AppErrorState
            :title="errorPresentation.title"
            :message="errorPresentation.message"
            :retry-label="errorPresentation.retryable ? 'Try activation again' : undefined"
            @retry="activateAccount"
          />
          <p
            v-if="activationError?.requestId"
            class="support-reference"
          >
            Support reference: <code>{{ activationError.requestId }}</code>
          </p>
        </div>
      </section>

      <ActivationRequestForm v-if="state === 'missing' || state === 'error'" />
    </div>
  </section>
</template>
