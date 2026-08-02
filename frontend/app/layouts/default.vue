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
