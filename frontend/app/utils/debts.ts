import type {
  AuthSession
} from '../types/session'
import type {
  BriefUser, CreateDebtBody, DebtSummary, DebtView, Page
} from '../types/finance'
import type {
  ConnectView
} from '../types/connects'
import {
  toApiClientError
} from './api-error.ts'
import {
  parseEuroCents
} from './money.ts'

export interface DebtFormValues {
  title: string
  description: string
  amount: string
  payerId: string
  participantIds: string[]
  includePayerInSplit: boolean
}
export function debtCommand(values: DebtFormValues, viewerId: string): CreateDebtBody | null {
  const totalAmount = parseEuroCents(values.amount)
  const ids = [...new Set(values.participantIds)].filter(id => id !== values.payerId).sort()
  if (!values.title.trim() || totalAmount === null || !values.payerId || ids.length === 0 || (viewerId !== values.payerId && !ids.includes(viewerId))) return null
  return {
    title: values.title.trim(), description: values.description || null, totalAmount, payerId: values.payerId, participantIds: ids, includePayerInSplit: values.includePayerInSplit
  }
}
export function uncertainDebtResult(failure: unknown): boolean {
  const error = toApiClientError(failure)
  return error.status === null || error.status >= 500
}
export async function eligibleDebtUsers(auth: Pick<AuthSession, 'protectedRequest'>, self: BriefUser, current: () => boolean): Promise<BriefUser[]> {
  const users = new Map<string, BriefUser>([[self.id, self]])
  const check = (): void => {
    if (!current()) throw new DOMException('Session changed.', 'AbortError')
  }
  async function all<T>(path: string): Promise<T[]> {
    const rows: T[] = []
    let cursor: string | null = null
    do {
      check()
      const page: Page<T> = await auth.protectedRequest<Page<T>>(path, {
        query: {
          limit: 100, ...(cursor
            ? {
                cursor
              }
            : {})
        }
      })
      check()
      rows.push(...page.items)
      cursor = page.pagination.nextCursor
    } while (cursor)
    return rows
  }
  for (const connect of await all<ConnectView>('/v1/connects')) users.set(connect.otherUser.id, connect.otherUser)
  for (const debt of await all<DebtSummary>('/v1/debts')) {
    check()
    try {
      const card = await auth.protectedRequest<DebtView>('/v1/debts/' + encodeURIComponent(debt.id))
      check()
      if (!card.isDeleted) for (const participant of card.participants) users.set(participant.user.id, participant.user)
    }
    catch (failure) {
      if (toApiClientError(failure).code !== 'DEBT_NOT_AVAILABLE') throw failure
    }
  }
  check()
  return [...users.values()].filter(user => user.isActive).sort((a, b) => a.name.localeCompare(b.name) || a.id.localeCompare(b.id))
}
