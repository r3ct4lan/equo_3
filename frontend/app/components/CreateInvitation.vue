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
  <UiCard
    as="section"
    class="stack"
    aria-labelledby="invite-title"
  >
    <UiText
      id="invite-title"
      variant="heading2"
    >
      Invite someone
    </UiText>
    <UiText>Send a one-use invitation to someone you know. It stays valid for seven days.</UiText>
    <UiButton
      compact
      :disabled="pending"
      :pending="pending"
      @click="create"
    >
      {{ pending ? 'Preparing invitation…' : 'Get invitation' }}
    </UiButton>
    <div
      v-if="link"
      class="stack"
    >
      <UiText>Invitation ready. Valid until {{ new Date(link.expiresAt).toLocaleDateString() }}.</UiText>
      <div class="form-actions">
        <UiButton
          variant="secondary"
          @click="copy"
        >
          Copy invitation
        </UiButton>
        <UiButton
          v-if="canShare"
          variant="secondary"
          @click="share"
        >
          Share invitation
        </UiButton>
      </div>
    </div>
    <UiText
      v-if="feedback"
      role="status"
    >
      {{ feedback }}
    </UiText>
    <AppErrorState
      v-if="presentation"
      :title="presentation.title"
      :message="presentation.message"
      :request-id="error?.requestId"
    />
  </UiCard>
</template>
