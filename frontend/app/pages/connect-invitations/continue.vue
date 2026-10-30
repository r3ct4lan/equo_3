<script setup lang="ts">
import { recalledInvitation } from '~/utils/connect-invitations'

useHead({ title: 'Invitation · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const token = ref<string | null>(null)
onMounted(() => {
  token.value = recalledInvitation()
})

const { t } = useTranslation()
</script>

<template>
  <InvitationPreview
    v-if="token"
    :token="token"
  />
  <AppEmptyState
    v-else
    :title="t('Open your invitation again')"
    :message="t('Return to the invitation link to continue connecting.')"
  />
</template>
