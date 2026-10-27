import assert from 'node:assert/strict'
import { test } from 'node:test'
import { uiThemeTokens } from '../../app/utils/ui-theme.ts'

test('catalog token values follow CSS cascade for light and dark themes without duplicate keys', () => {
  const source = ':root { --primary: teal; --color-primary: var(--primary); } .dark { --primary: cyan; } :root { --spacing: 0.25rem; } .dark { --extra: active; }'
  const light = Object.fromEntries(uiThemeTokens(source).map(token => [token.name, token.value]))
  const dark = Object.fromEntries(uiThemeTokens(source, true).map(token => [token.name, token.value]))
  assert.equal(light['--primary'], 'teal')
  assert.equal(light['--extra'], undefined)
  assert.equal(dark['--primary'], 'cyan')
  assert.equal(dark['--extra'], 'active')
  assert.equal(dark['--color-primary'], 'var(--primary)')
  assert.equal(dark['--spacing'], '0.25rem')
  assert.equal(uiThemeTokens(source, true).length, 4)
})
