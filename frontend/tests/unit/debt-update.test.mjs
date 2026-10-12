import assert from 'node:assert/strict'
import test from 'node:test'
import { euroInput, updateDebtCommand, validDebtEtag } from '../../app/utils/debt-update.ts'

const debt = { payer: { id: 'payer' }, createdBy: { id: 'author' }, canEdit: true, isDeleted: false }
const values = { title: ' Dinner ', description: '', amount: '12,01', participantIds: ['member', 'author'], includePayerInSplit: true }
test('edit command uses exact cents and complete desired composition without payer or version fields', () => {
  assert.deepEqual(updateDebtCommand(debt, values, 'author'), { title: 'Dinner', description: null, totalAmount: 1201, participantIds: ['author', 'member'], includePayerInSplit: true })
  assert.equal(euroInput(2147483647), '21474836.47')
  assert.equal(euroInput(1), '0.01')
})
test('author cannot leave a non-payer split and inactive/deleted/invalid edits are rejected', () => {
  assert.equal(updateDebtCommand(debt, { ...values, participantIds: ['member'] }, 'author'), null)
  assert.equal(updateDebtCommand(debt, { ...values, participantIds: ['author', 'payer'] }, 'author'), null)
  assert.equal(updateDebtCommand(debt, { ...values, amount: '1.001' }, 'author'), null)
  assert.equal(updateDebtCommand(debt, values, 'member'), null)
  assert.equal(updateDebtCommand({ ...debt, canEdit: false }, values, 'author'), null)
  assert.equal(updateDebtCommand({ ...debt, isDeleted: true }, values, 'author'), null)
})
test('only the actual strong version ETag can be submitted', () => {
  for (const value of [null, '', '1', '"0"', 'W/"1"', '"1","2"', '*', '"2147483648"', '"01"']) assert.equal(validDebtEtag(value), false)
  for (const value of ['"1"', '"7"', '"2147483647"']) assert.equal(validDebtEtag(value), true)
})
