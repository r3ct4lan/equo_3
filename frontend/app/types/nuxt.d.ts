import type { ApiClient } from './api'
import type { AuthSession } from './session'

declare module '#app' {
  interface NuxtApp {
    $api: ApiClient
    $auth: AuthSession
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $api: ApiClient
    $auth: AuthSession
  }
}

export {}
