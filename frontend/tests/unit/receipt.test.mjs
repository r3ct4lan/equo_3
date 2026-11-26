import test from 'node:test'
import assert from 'node:assert/strict'
import { editableLine, prepareReceipt } from '../../app/utils/receipt.ts'

const row = (amount, assignments, quantity = '1') => ({ ...editableLine(), amount, quantity, assignments })
const draft = (lines, discount = 0, remainder = {}) => ({ uploadId: 'receipt', lines, discount, remainder })
test('previews final individual shares and requires every unit and the printed difference to be allocated', () => {
  const people = ['anna', 'boris', 'vera']
  const preview = (lines, total, discount = 0, remainder = {}) => prepareReceipt(draft(lines, discount, remainder), total, 'EUR', people)?.shares
  assert.deepEqual(preview([row('8', { anna: null }), row('12', { boris: null }), row('4', { anna: null, boris: null })], '24'), { anna: 1000, boris: 1400 })
  assert.deepEqual(preview([row('12', { anna: '1', boris: '2' }, '3')], '12'), { anna: 400, boris: 800 })
  assert.equal(preview([row('12', { anna: '1' }, '3')], '12'), undefined)
  assert.equal(preview([row('12', { anna: null })], '13'), undefined)
  assert.deepEqual(preview([row('12', { anna: null })], '13', 0, { boris: null }), { anna: 1200, boris: 100 })
  const all = { anna: null, boris: null, vera: null }
  assert.deepEqual(preview([row('1', all), row('2', all)], '3'), { anna: 100, boris: 100, vera: 100 })
  assert.deepEqual(preview([row('20', { anna: null }), row('10', { boris: null })], '24', 600), { anna: 1600, boris: 800 })
})
