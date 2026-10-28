import type { FieldErrorMap } from './api-error'
import { PASSWORD_POLICY_HINT, emailValidationMessages, passwordValidationMessages, type SafeApiError, type ErrorPresentation } from './auth-flow.ts'

export const PASSWORD_RESET_REQUEST_ENDPOINT = '/v1/auth/password-reset-requests'

export function validateRecoveryEmail(values: Readonly<{ email: string }>): FieldErrorMap {
  const errors = emailValidationMessages(values.email)
  return errors.length ? { email: errors } : {}
}

export function validateNewPassword(values: Readonly<{ newPassword: string }>): FieldErrorMap {
  const errors = passwordValidationMessages(values.newPassword)
  return errors.length ? { newPassword: errors } : {}
}

export function passwordResetErrorPresentation(error: SafeApiError): ErrorPresentation {
  if (error.code === 'PASSWORD_UNCHANGED') return { title: 'Choose a different password', message: 'The new password must differ from your current password.', retryable: false }
  const terminalTitles: Record<string, string> = {
    INVALID_TOKEN: 'Recovery link is invalid',
    TOKEN_EXPIRED: 'Recovery link has expired',
    TOKEN_USED: 'Recovery link was already used',
    TOKEN_INVALIDATED: 'Recovery link was replaced'
  }
  const terminalTitle = terminalTitles[error.code]
  if (terminalTitle) {
    return { title: terminalTitle, message: 'Request a new recovery link to choose your password.', retryable: false }
  }
  if (error.code === 'VALIDATION_ERROR' || error.code === 'PASSWORD_POLICY_VIOLATION') {
    return { title: 'Check the form', message: PASSWORD_POLICY_HINT, retryable: false }
  }
  if (error.code === 'RATE_LIMIT_EXCEEDED') {
    return { title: 'Please wait before trying again', message: error.retryAfterSeconds ? `Try again in ${error.retryAfterSeconds} seconds.` : 'Try again a little later.', retryable: false }
  }
  return { title: 'Recovery could not be confirmed', message: 'Try again when the service is reachable.', retryable: error.status === null || error.status >= 500 }
}

export function isTerminalRecoveryError(code: string): boolean {
  return ['INVALID_TOKEN', 'TOKEN_EXPIRED', 'TOKEN_USED', 'TOKEN_INVALIDATED'].includes(code)
}
