export interface ConnectView {
  id: string
  otherUser: { id: string, name: string, isActive: boolean }
  balanceAmount: number
  canCreateDebt: boolean
  canCreateTransfer: boolean
  restriction?: { code: string, message: string }
  createdAt: string
}
export type InvitationOutcome = 'created' | 'already_connected' | 'confirmed'
export interface InvitationAcceptance extends ConnectView {
  invitationOutcome: InvitationOutcome
}
export interface ConnectPage {
  items: ConnectView[]
  pagination: { nextCursor: string | null, hasMore: boolean }
}
export interface InvitationPreview {
  inviter: { id: string, name: string }
  expiresAt: string
  canAccept: boolean
}
export interface InvitationLink {
  token: string
  url: string
  expiresAt: string
  created: boolean
}
