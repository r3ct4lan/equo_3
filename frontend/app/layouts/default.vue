<template>
  <div class="app-shell">
    <a
      class="skip-link"
      href="#main-content"
    >Skip to content</a>

    <header class="app-header">
      <div class="page-container app-header__inner">
        <NuxtLink
          class="brand"
          to="/"
          aria-label="Equo home"
        >
          Equo
        </NuxtLink>

        <nav aria-label="Primary navigation">
          <NuxtLink
            class="nav-link"
            to="/"
          >Home</NuxtLink>
          <template v-if="session.status === 'authenticated'">
            <NuxtLink
              class="nav-link"
              to="/me"
            >My profile</NuxtLink>
          </template>
          <template v-else-if="session.status === 'anonymous'">
            <NuxtLink
              class="nav-link"
              to="/login"
            >Login</NuxtLink>
            <NuxtLink
              class="nav-link"
              to="/register"
            >Register</NuxtLink>
          </template>
          <template v-else-if="session.status === 'unknown'">
            <span class="nav-status">Checking session</span>
          </template>
          <template v-else>
            <NuxtLink
              class="nav-link"
              to="/login"
            >Login</NuxtLink>
          </template>
        </nav>
      </div>
    </header>

    <main
      id="main-content"
      class="page-container app-main"
      tabindex="-1"
    >
      <slot />
    </main>

    <footer class="app-footer">
      <div class="page-container">
        <small>Equo keeps shared expenses understandable.</small>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { state } = useCurrentUser()
const session = computed(() => state.value)
</script>
