<script setup lang="ts">
import type { ActivateRequest } from '~/types/api'
import { ACTIVATE_ENDPOINT, activationErrorPresentation } from '~/utils/auth-flow'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

type ActivationState = 'preparing' | 'loading' | 'success' | 'missing' | 'error'

useHead({
  title: 'Activate your account · Equo'
})

const route = useRoute()
const router = useRouter()
const { $api } = useNuxtApp()
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
    await $api<undefined>(ACTIVATE_ENDPOINT, {
      method: 'POST',
      body: {
        token: activationToken
      } satisfies ActivateRequest
    })
    activationToken = null
    state.value = 'success'
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
  await activateAccount()
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
        Equo is checking the one-time activation link. The link value is never displayed or saved.
      </p>
    </div>

    <section class="panel stack">
      <AppLoadingState
        v-if="state === 'preparing' || state === 'loading'"
        message="Activating your account…"
      />

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
          <p>Activation is complete. Sign-in is a separate step and is not part of this screen yet.</p>
        </div>
      </div>

      <div
        v-else-if="state === 'missing'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          title="Activation link is missing"
          message="Open the complete one-time link from your activation email."
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
  </section>
</template>
