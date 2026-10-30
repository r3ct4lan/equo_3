import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveLocale, formatDate, formatDateTime, formatPeople } from '../../app/utils/locale.ts'
import { formatEuro } from '../../app/utils/money.ts'
import { translate, translateError } from '../../app/utils/translations.ts'

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
  assert.equal(formatDateTime(date, 'ru'), '30.10.2026, 12:00')
})

test('translates interface messages and uses a safe fallback for unknown errors', () => {
  assert.equal(translate('ru', 'My profile'), 'Мой профиль')
  assert.equal(translate('en', 'My profile'), 'My profile')
  assert.equal(translateError('ru', 'Try again in 10 seconds.'), 'Повторите попытку через 10 сек.')
  assert.equal(translateError('ru', translate('ru', 'Try again to load the history.')), 'Попробуйте снова загрузить историю.')
  assert.doesNotMatch(translateError('ru', 'private error details'), /private/)
})

test('uses Russian plural forms for participant counts', () => {
  assert.equal(formatPeople(1, 'ru'), '1 человек')
  assert.equal(formatPeople(2, 'ru'), '2 человека')
  assert.equal(formatPeople(11, 'ru'), '11 человек')
  assert.equal(formatPeople(21, 'ru'), '21 человек')
})
