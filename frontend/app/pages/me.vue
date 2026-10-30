<script setup lang="ts">
import type { CurrentUser } from '~/types/api'
import { ME_ENDPOINT } from '~/utils/auth-session'
import { formatDateTime } from '~/utils/locale'

definePageMeta({
  middleware: 'auth'
})

useHead({
  title: 'My profile · Equo'
})

const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const { locale } = useLocale()
const isLoadingProfile = ref(false)

const user = computed(() => currentUser.state.value.user)
const createdDate = computed(() => {
  if (!user.value?.createdAt) {
    return t('Not available')
  }

  return formatDateTime(new Date(user.value.createdAt), locale.value)
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

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="profile-title"
  >
    <div class="hero">
      <UiText variant="eyebrow">
        {{ t('Your account') }}
      </UiText>
      <UiText
        id="profile-title"
        variant="heading1"
      >
        {{ t('My profile') }}
      </UiText>
      <UiText variant="lede">
        {{ t('This protected page shows the profile confirmed by the backend.') }}
      </UiText>
    </div>

    <AppLoadingState
      v-if="currentUser.state.value.status === 'unknown' || isLoadingProfile"
      :message="t('Checking your session…')"
    />

    <AppErrorState
      v-else-if="currentUser.state.value.status === 'error'"
      :title="t('We could not check your session')"
      :message="currentUser.state.value.error?.message ?? 'Try again to check your session.'"
      :retry-label="t('Try again')"
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
          {{ t('Profile') }}
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
          <dt>{{ t('Email') }}</dt>
          <dd>{{ user.email }}</dd>
        </div>
        <div>
          <dt>{{ t('Account') }}</dt>
          <dd>{{ user.isActive ? t('Active') : t('Inactive') }}</dd>
        </div>
        <div>
          <dt>{{ t('Created') }}</dt>
          <dd>{{ createdDate }}</dd>
        </div>
      </UiDescriptionList>
    </UiCard>
    <ProfileNameForm
      v-if="user"
      :user="user"
    />
    <ProfileLanguageForm
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
      {{ t('Change password') }}
    </UiButton>
    <LogoutActions />
  </section>
</template>
