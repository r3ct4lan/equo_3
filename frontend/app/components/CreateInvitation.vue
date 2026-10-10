<script setup lang="ts">
import type { InvitationLink } from '~/types/connects'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { invitationErrorPresentation } from '~/utils/connect-invitations'

const { $auth } = useNuxtApp()
const link = ref<InvitationLink | null>(null)
const pending = ref(false)
const error = ref<ApiClientError | null>(null)
const feedback = ref('')
const canShare = ref(false)
const presentation = computed(() => error.value ? invitationErrorPresentation(error.value) : null)
onMounted(() => {
  canShare.value = typeof navigator.share === 'function'
})

async function create(): Promise<void> {
  if (pending.value) return
  pending.value = true
  error.value = null
  feedback.value = ''
  try {
    link.value = await $auth.protectedRequest<InvitationLink>('/v1/connect-invitations', { method: 'POST' })
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    pending.value = false
  }
}
async function copy(): Promise<void> {
  if (!link.value) return
  try {
    await navigator.clipboard.writeText(link.value.url)
    feedback.value = 'Invitation copied.'
  }
  catch {
    feedback.value = 'Copy is unavailable in this browser. Use Share on a supported device.'
  }
}
async function share(): Promise<void> {
  if (!link.value || !canShare.value) return
  try {
    await navigator.share({ title: 'Connect on Equo', url: link.value.url })
    feedback.value = 'Invitation shared.'
  }
  catch {
    feedback.value = 'Sharing was cancelled or unavailable. You can copy the invitation instead.'
  }
}
</script>

<template>
  <section
    class="panel stack"
    aria-labelledby="invite-title"
  >
    <h2 id="invite-title">
      Invite someone
    </h2>
    <p>Send a one-use invitation to someone you know. It stays valid for seven days.</p>
    <button
      class="button"
      type="button"
      :disabled="pending"
      :aria-busy="pending"
      @click="create"
    >
      {{ pending ? 'Preparing invitation…' : 'Get invitation' }}
    </button>
    <div
      v-if="link"
      class="stack"
    >
      <p>Invitation ready. Valid until {{ new Date(link.expiresAt).toLocaleDateString() }}.</p>
      <div class="form-actions">
        <button
          class="button button--secondary"
          type="button"
          @click="copy"
        >
          Copy invitation
        </button>
        <button
          v-if="canShare"
          class="button button--secondary"
          type="button"
          @click="share"
        >
          Share invitation
        </button>
      </div>
    </div>
    <p
      v-if="feedback"
      role="status"
    >
      {{ feedback }}
    </p>
    <AppErrorState
      v-if="presentation"
      :title="presentation.title"
      :message="presentation.message"
      :request-id="error?.requestId"
    />
  </section>
</template>
