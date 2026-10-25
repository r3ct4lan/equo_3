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
