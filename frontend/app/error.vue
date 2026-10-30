<script setup lang="ts">
import { clearError } from '#imports'

interface AppError {
  statusCode?: number
}

const props = defineProps<{
  error: AppError
}>()

const isNotFound = computed(() => props.error.statusCode === 404)

useHead({
  title: computed(() => isNotFound.value ? 'Page not found · Equo' : 'Unexpected error · Equo')
})

const { t } = useTranslation()
</script>

<template>
  <NuxtLayout>
    <UiCard
      as="section"
      class="stack"
      role="alert"
      aria-labelledby="error-title"
    >
      <UiText variant="eyebrow">
        {{ isNotFound ? '404' : t('Error') }}
      </UiText>
      <UiText
        id="error-title"
        variant="heading1"
      >
        {{ isNotFound ? t('Page not found') : t('Something went wrong') }}
      </UiText>
      <UiText>
        {{ isNotFound
          ? t('The requested page does not exist.')
          : t('The page could not be displayed safely. Please return home and try again.') }}
      </UiText>
      <div>
        <UiButton
          @click="clearError({ redirect: '/' })"
        >
          {{ t('Return home') }}
        </UiButton>
      </div>
    </UiCard>
  </NuxtLayout>
</template>
