<script setup lang="ts">
import { recalledInvitation } from '~/utils/connect-invitations'

const token = ref<string | null>(null)
onMounted(() => {
  token.value = recalledInvitation()
})
useHead({ title: 'Invitation · Equo' })
const { t } = useTranslation()
</script>

<template>
  <InvitationPreview
    v-if="token"
    :token="token"
    explicit
  />
  <AppEmptyState
    v-else
    :title="t('Invitation unavailable')"
    :message="t('Check the invitation link with its sender.')"
  />
</template>
