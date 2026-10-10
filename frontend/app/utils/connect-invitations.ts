import type { SafeApiError, ErrorPresentation } from './auth-flow'

let pendingInvitation: string | null = null

export function rememberInvitation(token: string): void {
  pendingInvitation = token
}
export function recalledInvitation(): string | null {
  return pendingInvitation
}
export function forgetInvitation(): void {
  pendingInvitation = null
}
export function invitationErrorPresentation(error: SafeApiError): ErrorPresentation {
  switch (error.code) {
    case 'TOKEN_EXPIRED':
      return { title: 'Invitation expired', message: 'Ask the sender for a new invitation.', retryable: false }
    case 'TOKEN_USED':
      return { title: 'Invitation already used', message: 'This invitation has already been accepted. Check your connections or ask for a new link.', retryable: false }
    case 'RESOURCE_NOT_FOUND':
      return { title: 'Invitation unavailable', message: 'Check the invitation link with its sender.', retryable: false }
    case 'VALIDATION_ERROR':
      return { title: 'Your own invitation', message: 'Send this link to the person you want to connect with.', retryable: false }
    case 'INACTIVE_USER':
    case 'ACCOUNT_INACTIVE':
      return { title: 'Connection unavailable', message: 'Both accounts must be active to accept an invitation.', retryable: false }
    case 'RATE_LIMIT_EXCEEDED':
      return { title: 'Please wait before trying again', message: error.retryAfterSeconds ? 'Try again in ' + error.retryAfterSeconds + ' seconds.' : 'Try again a little later.', retryable: false }
    default:
      return { title: 'We could not confirm the connection', message: 'Try again to check the same invitation.', retryable: error.status === null || error.status >= 500 }
  }
}
