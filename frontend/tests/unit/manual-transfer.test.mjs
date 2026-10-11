import assert from 'node:assert/strict'
import test from 'node:test'
import { manualTransferCommand, manualTransferPreview } from '../../app/utils/manual-transfer.ts'

const connect = { id: 'pair', otherUser: { id: 'other', name: 'Other', isActive: true }, canCreateTransfer: true, balanceAmount: 501 }
test('manual direction uses exactly both sides and predicts overpayment without capping', () => {
  const fromOther = manualTransferCommand(connect, 'self', 'other', '7,00')
  assert.deepEqual(fromOther, { senderId: 'other', receiverId: 'self', amount: 700 })
  assert.equal(manualTransferPreview(501, fromOther, 'self'), -199)
  const fromSelf = manualTransferCommand(connect, 'self', 'you', '7.00')
  assert.deepEqual(fromSelf, { senderId: 'self', receiverId: 'other', amount: 700 })
  assert.equal(manualTransferPreview(-199, fromSelf, 'self'), 501)
  assert.equal(manualTransferPreview(700, fromOther, 'self'), 0)
  assert.equal(manualTransferPreview(Number.MAX_SAFE_INTEGER, fromSelf, 'self'), null)
})
test('invalid amounts, unavailable sides and directions cannot create a command', () => {
  for (const amount of ['0', '-1', '1.001', '21474836.48', '1e3']) assert.equal(manualTransferCommand(connect, 'self', 'you', amount), null)
  for (const direction of ['', 'third']) assert.equal(manualTransferCommand(connect, 'self', direction, '1'), null)
  assert.equal(manualTransferCommand(connect, 'other', 'you', '1'), null)
  assert.equal(manualTransferCommand({ ...connect, canCreateTransfer: false }, 'self', 'you', '1'), null)
  assert.equal(manualTransferCommand({ ...connect, otherUser: { ...connect.otherUser, isActive: false } }, 'self', 'you', '1'), null)
})
