<script setup lang="ts">
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
    <section
      class="panel stack"
      role="alert"
      aria-labelledby="error-title"
    >
      <p class="eyebrow">
        {{ isNotFound ? '404' : 'Error' }}
      </p>
      <h1 id="error-title">
        {{ isNotFound ? 'Page not found' : 'Something went wrong' }}
      </h1>
      <p>
        {{ isNotFound
          ? 'The requested page does not exist.'
          : 'The page could not be displayed safely. Please return home and try again.' }}
      </p>
      <div>
        <button
          class="button"
          type="button"
          @click="clearError({ redirect: '/' })"
        >
          Return home
        </button>
      </div>
    </section>
  </NuxtLayout>
</template>
