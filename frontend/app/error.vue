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
        {{ isNotFound ? '404' : 'Error' }}
      </UiText>
      <UiText
        id="error-title"
        variant="heading1"
      >
        {{ isNotFound ? 'Page not found' : 'Something went wrong' }}
      </UiText>
      <UiText>
        {{ isNotFound
          ? 'The requested page does not exist.'
          : 'The page could not be displayed safely. Please return home and try again.' }}
      </UiText>
      <div>
        <UiButton
          @click="clearError({ redirect: '/' })"
        >
          Return home
        </UiButton>
      </div>
    </UiCard>
  </NuxtLayout>
</template>
