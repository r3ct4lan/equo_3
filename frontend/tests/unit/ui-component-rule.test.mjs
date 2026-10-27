import assert from 'node:assert/strict'
import { test } from 'node:test'
import { ESLint } from 'eslint'

test('Vue lint rejects native duplicates, owned classes, inline and scoped styles', async () => {
  const eslint = new ESLint()
  const [result] = await eslint.lintText('<template><button class="button" style="color: red">Save</button></template>\n<style scoped>.x { color: red; }</style>\n', { filePath: 'app/pages/ui-rule-probe.vue' })
  const messages = result.messages.filter(message => message.ruleId === 'equo-ui/components')
  assert.deepEqual(new Set(messages.map(message => message.messageId)), new Set(['primitive', 'ownedClass', 'inlineStyle', 'localStyle']))
  const [valid] = await eslint.lintText('<template>\n  <UiButton variant="secondary">Save</UiButton>\n</template>\n', { filePath: 'app/pages/ui-rule-probe.vue' })
  assert.equal(valid.messages.filter(message => message.ruleId === 'equo-ui/components').length, 0)
})

test('registry implementations cannot bypass typed UI facades in feature pages', async () => {
  const eslint = new ESLint()
  const source = '<script setup>\nimport { Button as RawButton } from "~/components/ui/button"\n</script>\n<template><RawButton>Save</RawButton></template>\n'
  const [page] = await eslint.lintText(source, { filePath: 'app/pages/ui-rule-probe.vue' })
  assert.equal(page.messages.filter(message => message.ruleId === 'equo-ui/components' && message.messageId === 'registry').length, 1)
  const [facade] = await eslint.lintText(source, { filePath: 'app/components/UiButton.vue' })
  assert.equal(facade.messages.filter(message => message.ruleId === 'equo-ui/components').length, 0)
})
