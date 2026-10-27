import assert from 'node:assert/strict'
import { test } from 'node:test'
import { auditCatalog, auditStyles } from '../../scripts/check-ui-system.mjs'

test('styles guard rejects new literals and undefined tokens while allowing layout and clipping', () => {
  const tokens = new Set(['--space-4', '--color-primary'])
  assert.deepEqual(auditStyles('.x { padding: var(--space-4); color: var(--color-primary); width: 100%; } .visually-hidden { width: 1px; margin: -1px; }', tokens), [])
  assert.equal(auditStyles('.x { color: #abc; padding: 13px; border-color: red; gap: var(--missing); }', tokens).length, 4)
  assert.equal(auditStyles('@media (max-width: 47rem) { .x { display: grid; } }', tokens).length, 1)
  assert.deepEqual(auditStyles('@media (max-width: 36rem) { .x { display: grid; } } @media (width <= 36rem) { .x { display: grid; } }', tokens), [])
})

test('catalog guard follows composed specimens and detects a shared primitive missing from the catalog', () => {
  const components = new Map([
    ['UiCard', '<section><slot /></section>'],
    ['UiButton', '<button><slot /></button>'],
    ['UiMissing', '<div />'],
    ['AppErrorState', '<UiCard><UiButton>Retry</UiButton></UiCard>']
  ])
  assert.deepEqual(auditCatalog(components, '<AppErrorState />'), ['UiMissing'])
})

test('catalog guard follows aliased registry components and requires specimens for their subcomponents', () => {
  const components = new Map([
    ['UiButton', '<script>import { Button as RegistryButton } from \'~/components/ui/button\'</script><RegistryButton />'],
    ['Button', '<div data-slot="button" />'],
    ['CardTitle', '<div data-slot="card-title" />']
  ])
  assert.deepEqual(auditCatalog(components, '<UiButton />'), ['CardTitle'])
  assert.deepEqual(auditCatalog(components, '<script>import { CardTitle as PresetTitle } from \'~/components/ui/card\'</script><UiButton /><PresetTitle />'), [])
})
