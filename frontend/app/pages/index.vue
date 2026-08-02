<script setup lang="ts">
import type { HealthResponse } from '~/types/api'
import { toApiClientError } from '~/utils/api-error'

useHead({
  title: 'Equo'
})

const { $api } = useNuxtApp()
const {
  data: health,
  error,
  refresh,
  status
} = await useAsyncData('api-health', () => $api<HealthResponse>('/health'), {
  server: false
})

const apiError = computed(() => error.value ? toApiClientError(error.value) : null)
</script>

<template>
  <div class="stack stack--large">
    <section
      class="hero"
      aria-labelledby="home-title"
    >
      <p class="eyebrow">
        Shared expenses, clearly
      </p>
      <h1 id="home-title">
        Welcome to Equo
      </h1>
      <p class="hero__lede">
        The application foundation is ready for registration and account activation.
      </p>
    </section>

    <section
      class="panel stack"
      aria-labelledby="service-title"
    >
      <div>
        <p class="eyebrow">
          System status
        </p>
        <h2 id="service-title">
          API connection
        </h2>
      </div>

      <AppLoadingState
        v-if="status === 'pending' || status === 'idle'"
        message="Checking the Equo API…"
      />

      <AppErrorState
        v-else-if="apiError"
        title="The API is unavailable"
        :message="apiError.message"
        retry-label="Try again"
        @retry="refresh"
      />

      <div
        v-else-if="health"
        class="status-card status-card--success"
        role="status"
      >
        <span
          class="status-card__marker"
          aria-hidden="true"
        />
        <div>
          <strong>API available</strong>
          <p>{{ health.service }} reported {{ health.status }}.</p>
        </div>
      </div>

      <AppEmptyState
        v-else
        title="No service status"
        message="The API did not return a status payload."
      />
    </section>
  </div>
</template>
