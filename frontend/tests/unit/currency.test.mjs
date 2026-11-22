import assert from 'node:assert/strict'
import test from 'node:test'
import { formatMoney, parseMinorUnits, moneyInput, settlementPreview } from '../../app/utils/money.ts'

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
