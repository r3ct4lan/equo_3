<template>
  <main>
    <section>
      <p class="eyebrow">Equo 3</p>
      <h1>Nuxt is running</h1>
      <p>The local frontend is ready for development.</p>
    </section>
  </main>
</template>

<style>
:root {
  font-family: Inter, ui-sans-serif, system-ui, sans-serif;
  color: #13213c;
  background: #f4f7fb;
}
body { margin: 0; }
main {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 2rem;
}
section {
  max-width: 42rem;
  padding: 3rem;
  border: 1px solid #d9e2ef;
  border-radius: 1.5rem;
  background: white;
  box-shadow: 0 1.5rem 4rem rgb(27 55 95 / 10%);
}
.eyebrow {
  color: #2870d4;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}
h1 {
  margin: 0.5rem 0;
  font-size: clamp(2.5rem, 8vw, 5rem);
  line-height: 1;
}
</style>
