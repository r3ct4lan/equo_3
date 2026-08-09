<script setup lang="ts">
import type { CurrentUser } from '~/types/api'
import { ME_ENDPOINT } from '~/utils/auth-session'

definePageMeta({
  middleware: 'auth'
})

useHead({
  title: 'My profile · Equo'
})

const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const isLoadingProfile = ref(false)

const user = computed(() => currentUser.state.value.user)
const createdDate = computed(() => {
  if (!user.value?.createdAt) {
    return 'Not available'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(new Date(user.value.createdAt))
})

async function loadProfile(): Promise<void> {
  if (currentUser.state.value.status !== 'authenticated') {
    return
  }

  isLoadingProfile.value = true

  try {
    const profile = await $auth.protectedRequest<CurrentUser>(ME_ENDPOINT, {
      method: 'GET'
    })
    currentUser.setAuthenticated(profile)
  }
  catch (error: unknown) {
    if (error && typeof error === 'object' && 'code' in error) {
      const apiError = error as Parameters<typeof currentUser.setError>[0] & { status?: number | null }
      if (apiError.status !== 401) {
        currentUser.setError(apiError)
      }
    }
  }
  finally {
    isLoadingProfile.value = false
  }
}

onMounted(() => {
  void loadProfile()
})
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="profile-title"
  >
    <div class="hero">
      <p class="eyebrow">
        Your account
      </p>
      <h1 id="profile-title">
        My profile
      </h1>
      <p class="hero__lede">
        This protected page shows the profile confirmed by the backend.
      </p>
    </div>

    <AppLoadingState
      v-if="currentUser.state.value.status === 'unknown' || isLoadingProfile"
      message="Checking your session…"
    />

    <AppErrorState
      v-else-if="currentUser.state.value.status === 'error'"
      title="We could not check your session"
      :message="currentUser.state.value.error?.message ?? 'Try again to check your session.'"
      retry-label="Try again"
      @retry="$auth.retryBootstrap"
    />

    <section
      v-else-if="user"
      class="panel stack"
      aria-labelledby="profile-card-title"
    >
      <div>
        <p class="eyebrow">
          Profile
        </p>
        <h2 id="profile-card-title">
          {{ user.name }}
        </h2>
      </div>

      <dl class="profile-list">
        <div>
          <dt>Email</dt>
          <dd>{{ user.email }}</dd>
        </div>
        <div>
          <dt>Account</dt>
          <dd>{{ user.isActive ? 'Active' : 'Inactive' }}</dd>
        </div>
        <div>
          <dt>Created</dt>
          <dd>{{ createdDate }}</dd>
        </div>
      </dl>
    </section>
  </section>
</template>
