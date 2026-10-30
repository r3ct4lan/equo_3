import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveLocale, formatDate } from '../../app/utils/locale.ts'
import { formatEuro } from '../../app/utils/money.ts'

test('recognises supported device language and falls back to English', () => {
  assert.equal(resolveLocale('ru-RU'), 'ru')
  assert.equal(resolveLocale('RU'), 'ru')
  assert.equal(resolveLocale('en-US'), 'en')
  assert.equal(resolveLocale('de-DE'), 'en')
  assert.equal(resolveLocale(null), 'en')
})

test('formats exact euro amounts using the selected language', () => {
  assert.equal(formatEuro(123456, 'en'), '€1,234.56')
  assert.equal(formatEuro(123456, 'ru'), '1\u00a0234,56\u00a0€')
  assert.equal(formatEuro(-105, 'ru'), '-1,05\u00a0€')
})

test('formats dates consistently in UTC', () => {
  const date = new Date('2026-10-30T12:00:00Z')
  assert.equal(formatDate(date, 'ru'), '30.10.2026')
  assert.equal(formatDate(date, 'en'), '30 Oct 2026')
})
