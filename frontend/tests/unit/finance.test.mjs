import assert from 'node:assert/strict'
import test from 'node:test'
import {
  parseEuroCents, formatEuro, splitShare
} from '../../app/utils/money.ts'
import {
  debtCommand, eligibleDebtUsers
} from '../../app/utils/debts.ts'

test('money converts exact decimal text without float and rejects ambiguous or overflowing input', () => {
  for (const [text, expected] of [['0.01', 1], ['10,01', 1001], ['1.2', 120], ['21474836.47', 2147483647]]) assert.equal(parseEuroCents(text), expected)
  for (const text of ['0', '-1', '1e2', '1.001', '1,000.00', 'Infinity', '21474836.48', '', '.1']) assert.equal(parseEuroCents(text), null)
  assert.equal(formatEuro(4294967195), '€42,949,671.95')
  assert.equal(formatEuro(-501), '-€5.01')
  assert.equal(splitShare(1001, 3), 334)
  assert.equal(splitShare(2147483647, 3), 715827883)
})
test('command excludes payer and requires author participation while keeping exact cents', () => {
  const values = {
    title: ' Dinner ', description: '', amount: '10.01', payerId: 'payer', participantIds: ['other', 'author', 'payer'], includePayerInSplit: true
  }
  assert.deepEqual(debtCommand(values, 'author'), {
    title: 'Dinner', description: null, totalAmount: 1001, payerId: 'payer', participantIds: ['author', 'other'], includePayerInSplit: true
  })
  assert.equal(debtCommand({
    ...values, participantIds: ['other']
  }, 'author'), null)
  assert.equal(debtCommand({
    ...values, participantIds: ['payer']
  }, 'payer'), null)
})
test('eligible people use all server pages and current active co-participants, avoiding global lookup', async () => {
  const calls = []
  const brief = (id, isActive = true) => ({
    id, name: id, isActive
  })
  const result = await eligibleDebtUsers({
    protectedRequest: async (path, options) => {
      calls.push(path)
      if (path === '/v1/connects') return options.query.cursor
        ? {
            items: [{
              otherUser: brief('inactive', false)
            }], pagination: {
              nextCursor: null
            }
          }
        : {
            items: [{
              otherUser: brief('connected')
            }], pagination: {
              nextCursor: 'next'
            }
          }
      if (path === '/v1/debts') return {
        items: [{
          id: 'debt'
        }], pagination: {
          nextCursor: null
        }
      }
      return {
        isDeleted: false, participants: [{
          user: brief('co')
        }, {
          user: brief('connected')
        }]
      }
    }
  }, brief('self'), () => true)
  assert.deepEqual(result.map(user => user.id), ['co', 'connected', 'self'])
  assert.deepEqual(calls, ['/v1/connects', '/v1/connects', '/v1/debts', '/v1/debts/debt'])
})
test('eligibility stops before making a request for a changed session', async () => {
  let calls = 0
  await assert.rejects(eligibleDebtUsers({
    protectedRequest: async () => {
      calls++
    }
  }, {
    id: 'self', name: 'Self', isActive: true
  }, () => false), {
    name: 'AbortError'
  })
  assert.equal(calls, 0)
})
