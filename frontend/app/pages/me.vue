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
      <UiText variant="eyebrow">
        Your account
      </UiText>
      <UiText
        id="profile-title"
        variant="heading1"
      >
        My profile
      </UiText>
      <UiText variant="lede">
        This protected page shows the profile confirmed by the backend.
      </UiText>
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

    <UiCard
      v-else-if="user"
      as="section"
      class="stack"
      aria-labelledby="profile-card-title"
    >
      <div>
        <UiText variant="eyebrow">
          Profile
        </UiText>
        <UiText
          id="profile-card-title"
          variant="heading2"
        >
          {{ user.name }}
        </UiText>
      </div>

      <UiDescriptionList>
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
      </UiDescriptionList>
    </UiCard>
    <ProfileNameForm
      v-if="user"
      :user="user"
    />
    <EmailChangeForm v-if="user" />
    <AccountDeactivationForm v-if="user" />
    <UiButton
      v-if="user"
      compact
      variant="secondary"
      to="/account/security"
    >
      Change password
    </UiButton>
    <LogoutActions />
  </section>
</template>
