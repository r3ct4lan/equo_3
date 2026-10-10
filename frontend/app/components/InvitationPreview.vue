<script setup lang="ts">
import type { InvitationPreview, ConnectView } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { forgetInvitation, invitationErrorPresentation, rememberInvitation } from '~/utils/connect-invitations'

const props = defineProps<{ token: string | null }>()
const { $api, $auth } = useNuxtApp()
const { state } = useCurrentUser()
const preview = ref<InvitationPreview | null>(null)
let generation = 0
const pending = ref(false)
const accepting = ref(false)
const error = ref<ApiClientError | null>(null)
const presentation = computed(() => error.value ? invitationErrorPresentation(error.value) : null)
const own = computed(() => preview.value?.inviter.id === state.value.user?.id)
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
async function accept(): Promise<void> {
  if (accepting.value || own.value || !preview.value?.canAccept || !props.token) return
  accepting.value = true
  error.value = null
  try {
    const connect = await $auth.protectedRequest<ConnectView>(endpoint.value + '/accept', { method: 'POST' })
    forgetInvitation()
    await navigateTo('/connects/' + connect.id)
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    accepting.value = false
  }
}
onMounted(async () => {
  await load()
  await $auth.bootstrap()
})
watch(() => props.token, () => {
  void load()
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
      v-if="pending"
      message="Loading invitation…"
    />
    <template v-if="preview">
      <p>{{ preview.inviter.name }} invited you to connect.</p>
      <p>Valid until {{ new Date(preview.expiresAt).toLocaleDateString() }}.</p>
      <p v-if="own">
        This is your own invitation. Send it to someone you want to connect with.
      </p>
      <p v-else-if="!preview.canAccept">
        The sender's account must be active before you can connect.
      </p>
      <button
        v-else-if="state.status === 'anonymous'"
        class="button"
        type="button"
        @click="signIn"
      >
        Sign in to accept
      </button>
      <button
        v-else-if="state.status === 'authenticated'"
        class="button"
        type="button"
        :disabled="accepting"
        :aria-busy="accepting"
        @click="accept"
      >
        {{ accepting ? 'Connecting…' : 'Accept invitation' }}
      </button>
      <AppLoadingState
        v-else-if="state.status === 'unknown'"
        message="Checking your session…"
      />
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
