import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveLocale, formatDate } from '../../app/utils/locale.ts'

test('recognises supported device language and falls back to English', () => {
  assert.equal(resolveLocale('ru-RU'), 'ru')
  assert.equal(resolveLocale('RU'), 'ru')
  assert.equal(resolveLocale('en-US'), 'en')
  assert.equal(resolveLocale('de-DE'), 'en')
  assert.equal(resolveLocale(null), 'en')
})

test('formats dates consistently in UTC', () => {
  const date = new Date('2026-10-30T12:00:00Z')
  assert.equal(formatDate(date, 'ru'), '30.10.2026')
  assert.equal(formatDate(date, 'en'), '30 Oct 2026')
})
