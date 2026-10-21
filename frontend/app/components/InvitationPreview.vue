<script setup lang="ts">
import type { InvitationPreview, ConnectView } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { forgetInvitation, invitationErrorPresentation, rememberInvitation, setInvitationFeedback } from '~/utils/connect-invitations'

const props = defineProps<{ token: string | null }>()
const { $api, $auth } = useNuxtApp()
const { state } = useCurrentUser()
const preview = ref<InvitationPreview | null>(null)
let generation = 0
const pending = ref(false)
const accepting = ref(false)
const error = ref<ApiClientError | null>(null)
const presentation = computed(() => error.value ? invitationErrorPresentation(error.value) : null)
const endpoint = computed(() => '/v1/connect-invitations/' + encodeURIComponent(props.token ?? 'invalid'))

async function load(): Promise<void> {
  const requestGeneration = ++generation
  pending.value = true
  error.value = null
  preview.value = null
  try {
    const result = await $api<InvitationPreview>(endpoint.value)
    if (requestGeneration === generation) preview.value = result
  }
  catch (failure) {
    if (requestGeneration === generation) error.value = toApiClientError(failure)
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
async function signIn(): Promise<void> {
  if (!props.token) return
  rememberInvitation(props.token)
  await navigateTo('/login?redirect=/connect-invitations/continue')
}
async function register(): Promise<void> {
  if (!props.token) return
  rememberInvitation(props.token)
  await navigateTo('/register')
}
async function accept(): Promise<void> {
  if (accepting.value || !props.token) return
  accepting.value = true
  error.value = null
  try {
    await $auth.protectedRequest<ConnectView>(endpoint.value + '/accept', { method: 'POST' })
    forgetInvitation()
    setInvitationFeedback('success', 'Connection created successfully.')
    await navigateTo('/connects')
  }
  catch (failure) {
    forgetInvitation()
    const normalized = toApiClientError(failure)
    setInvitationFeedback('error', invitationErrorPresentation(normalized).message)
    await navigateTo('/connects')
  }
  finally {
    accepting.value = false
  }
}
onMounted(async () => {
  await $auth.bootstrap()
  if (state.value.status === 'authenticated') {
    await accept()
  }
  else {
    await load()
  }
})
watch(() => props.token, () => {
  if (state.value.status === 'authenticated') void accept()
  else void load()
})
</script>

<template>
  <section
    class="panel stack"
    aria-labelledby="invitation-title"
  >
    <h1 id="invitation-title">
      Connect on Equo
    </h1>
    <AppLoadingState
      v-if="pending || accepting || state.status === 'unknown'"
      message="Loading invitation…"
    />
    <template v-if="preview">
      <p>{{ preview.inviter.name }} invited you to connect.</p>
      <p>Valid until {{ new Date(preview.expiresAt).toLocaleDateString() }}.</p>
      <p v-if="!preview.canAccept">
        The sender's account must be active before you can connect.
      </p>
      <div
        v-else-if="state.status === 'anonymous'"
        class="form-actions"
      >
        <button
          class="button"
          type="button"
          @click="signIn"
        >
          Sign in to connect
        </button>
        <button
          class="button button--secondary"
          type="button"
          @click="register"
        >
          Create an account
        </button>
      </div>
      <button
        v-else
        class="button button--secondary"
        type="button"
        @click="$auth.retryBootstrap()"
      >
        Try session again
      </button>
    </template>
    <AppErrorState
      v-if="presentation"
      :title="presentation.title"
      :message="presentation.message"
      :request-id="error?.requestId"
    />
    <button
      v-if="presentation?.retryable"
      class="button button--secondary"
      type="button"
      :disabled="pending || accepting"
      @click="preview ? accept() : load()"
    >
      Try again
    </button>
    <NuxtLink
      v-if="state.status === 'authenticated'"
      class="button button--secondary"
      to="/connects"
    >Your connections</NuxtLink>
  </section>
</template>
