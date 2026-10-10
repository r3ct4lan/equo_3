<script setup lang="ts">
import { recalledInvitation } from '~/utils/connect-invitations'

useHead({ title: 'Invitation · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const token = ref<string | null>(null)
onMounted(() => {
  token.value = recalledInvitation()
})
</script>

<template>
  <InvitationPreview
    v-if="token"
    :token="token"
  />
  <AppEmptyState
    v-else
    title="Open your invitation again"
    message="Return to the invitation link to continue connecting."
  />
</template>
