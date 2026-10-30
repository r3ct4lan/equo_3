<script setup lang="ts">
import type { ConnectView } from '~/types/connects'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'New expense · Equo' })

const route = useRoute()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const connection = ref<ConnectView | null>(null)
const pending = ref(false)
const failed = ref(false)
let generation = 0
const connectId = computed(() => typeof route.query.connect === 'string' ? route.query.connect : '')
async function load(): Promise<void> {
  const requestGeneration = ++generation
  connection.value = null
  failed.value = false
  pending.value = false
  if (!connectId.value || state.value.status !== 'authenticated') return
  const viewer = state.value.user?.id
  pending.value = true
  try {
    const result = await $auth.protectedRequest<ConnectView>('/v1/connects/' + encodeURIComponent(connectId.value))
    if (requestGeneration === generation && state.value.user?.id === viewer) {
      if (result.canCreateDebt && result.otherUser.isActive) connection.value = result
      else failed.value = true
    }
  }
  catch {
    if (requestGeneration === generation) failed.value = true
  }
  finally {
    if (requestGeneration === generation) pending.value = false
  }
}
onMounted(() => {
  void load()
})
watch(() => [state.value.status, state.value.user?.id, connectId.value], () => {
  void load()
})
onBeforeUnmount(() => {
  ++generation
})

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack"
    aria-labelledby="new-debt-title"
  >
    <UiText
      id="new-debt-title"
      variant="heading1"
    >
      {{ t('New expense') }}
    </UiText>
    <UiText>{{ t('Record what was paid and share the cost equally.') }}</UiText>
    <AppLoadingState
      v-if="pending || state.status === 'unknown'"
      :message="t('Loading connection…')"
    />
    <template v-else-if="failed">
      <AppErrorState
        :title="t('Connection unavailable')"
        :message="t('Return to your connections or try again.')"
      />
      <UiButton
        variant="secondary"
        @click="load"
      >
        {{ t('Try again') }}
      </UiButton>
    </template>
    <CreateDebtForm
      v-else-if="state.status === 'authenticated' && (!connectId || connection)"
      :key="state.user?.id"
      :initial-person="connection?.otherUser"
    />
    <UiLink to="/debts">
      {{ t('Your expenses') }}
    </UiLink>
  </section>
</template>
