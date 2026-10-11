export interface BriefUser { id: string, name: string, isActive: boolean }
export interface Pagination { nextCursor: string | null, hasMore: boolean }
export interface Page<T> { items: T[], pagination: Pagination }
export interface DebtSummary {
  id: string
  title: string
  totalAmount: number
  payer: BriefUser
  participantCount: number
  shareAmount: number
  canEdit: boolean
  version: number
  createdAt: string
}
export interface DebtView extends DebtSummary {
  description: string | null
  createdBy: BriefUser
  includePayerInSplit: boolean
  participants: { debtParticipantId: string, user: BriefUser, isPayer: boolean, isAuthor: boolean, shareAmount: number, transferId: string | null }[]
  isDeleted: boolean
  canDelete: boolean
}
export interface CreateDebtBody extends Record<string, unknown> {
  title: string
  description: string | null
  totalAmount: number
  payerId: string
  participantIds: string[]
  includePayerInSplit: boolean
}
export interface TransferView {
  id: string
  connectId: string
  sender: BriefUser
  receiver: BriefUser
  amount: number
  type: 'DEBT_SHARE' | 'MANUAL'
  createdBy: BriefUser | null
  debtReference: { id: string, isAccessible: boolean, title?: string, isDeleted?: boolean } | null
  relatedTransferId: string | null
  isDeleted: boolean
  canEdit: boolean
  canDelete: boolean
  version: number
  createdAt: string
}
