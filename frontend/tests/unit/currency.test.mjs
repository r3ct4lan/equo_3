import assert from 'node:assert/strict'
import test from 'node:test'
import { displayExchangeRate, formatMoney, parseMinorUnits, moneyInput, settlementPreview } from '../../app/utils/money.ts'

test('editable money uses the original currency precision', () => {
  assert.equal(parseMinorUnits('1200', 'JPY'), 1200)
  assert.equal(parseMinorUnits('1200.5', 'JPY'), null)
  assert.equal(parseMinorUnits('1,234', 'KWD'), 1234)
  assert.equal(moneyInput(1234, 'KWD'), '1.234')
  assert.equal(formatMoney(960, 'EUR', 'en'), '€9.60')
  assert.match(formatMoney(120050, 'RSD', 'ru'), /200,50/u)
})

test('the preview closes exactly at the rounded repayment threshold', () => {
  assert.deepEqual(settlementPreview(961, 120050, 'EUR', 'RSD', '125'), { remaining: 0, excess: 0 })
  assert.deepEqual(settlementPreview(962, 120050, 'EUR', 'RSD', '125'), { remaining: 0, excess: 1 })
})

test('snapshot rates are displayed as localized decimals without changing settlement precision', () => {
  assert.equal(displayExchangeRate('2944/25', 'ru'), '117,76')
  assert.equal(displayExchangeRate('2944/25', 'en'), '117.76')
  assert.equal(displayExchangeRate('2/3', 'en'), '0.666666666666666667')
  assert.equal(displayExchangeRate('120.0', 'ru'), '120,0')
})
