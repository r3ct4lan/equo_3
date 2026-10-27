<script setup lang="ts">
import type { InvitationPreview, InvitationAcceptance } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { forgetInvitation, invitationErrorPresentation, rememberInvitation, setInvitationAcceptanceFeedback, setInvitationFeedback } from '~/utils/connect-invitations'

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
    const acceptance = await $auth.protectedRequest<InvitationAcceptance>(endpoint.value + '/accept', { method: 'POST' })
    forgetInvitation()
    setInvitationAcceptanceFeedback(acceptance)
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
  <UiCard
    as="section"
    class="stack"
    aria-labelledby="invitation-title"
  >
    <UiText
      id="invitation-title"
      variant="heading1"
    >
      Connect on Equo
    </UiText>
    <AppLoadingState
      v-if="pending || accepting || state.status === 'unknown'"
      message="Loading invitation…"
    />
    <template v-if="preview">
      <UiText>{{ preview.inviter.name }} invited you to connect.</UiText>
      <UiText>Valid until {{ new Date(preview.expiresAt).toLocaleDateString() }}.</UiText>
      <UiText v-if="!preview.canAccept">
        The sender's account must be active before you can connect.
      </UiText>
      <div
        v-else-if="state.status === 'anonymous'"
        class="form-actions"
      >
        <UiButton
          @click="signIn"
        >
          Sign in to connect
        </UiButton>
        <UiButton
          variant="secondary"
          @click="register"
        >
          Create an account
        </UiButton>
      </div>
      <UiButton
        v-else
        variant="secondary"
        @click="$auth.retryBootstrap()"
      >
        Try session again
      </UiButton>
    </template>
    <AppErrorState
      v-if="presentation"
      :title="presentation.title"
      :message="presentation.message"
      :request-id="error?.requestId"
    />
    <UiButton
      v-if="presentation?.retryable"
      variant="secondary"
      :disabled="pending || accepting"
      @click="preview ? accept() : load()"
    >
      Try again
    </UiButton>
    <UiButton
      v-if="state.status === 'authenticated'"
      variant="secondary"
      to="/connects"
    >
      Your connections
    </UiButton>
  </UiCard>
</template>
