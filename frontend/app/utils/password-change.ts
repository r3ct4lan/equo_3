import type { FieldErrorMap } from './api-error'
import { PASSWORD_POLICY_HINT, passwordValidationMessages, type SafeApiError, type ErrorPresentation } from './auth-flow.ts'

export function validatePasswordChange(values: Readonly<{ currentPassword: string, newPassword: string }>): FieldErrorMap {
  const errors: FieldErrorMap = {}
  if (values.currentPassword === '') errors.currentPassword = ['Enter your current password.']
  const messages = passwordValidationMessages(values.newPassword)
  if (messages.length) errors.newPassword = messages
  else if (values.newPassword === values.currentPassword) errors.newPassword = ['Choose a different password.']
  return errors
}

export function passwordChangeErrorPresentation(error: SafeApiError): ErrorPresentation {
  if (error.code === 'PASSWORD_UNCHANGED') return { title: 'Choose a different password', message: 'The new password must differ from the current password.', retryable: false }
  if (error.code === 'INVALID_CREDENTIALS') {
    return { title: 'Current password is incorrect', message: 'Enter your current password and try again.', retryable: false }
  }
  if (error.code === 'VALIDATION_ERROR' || error.code === 'PASSWORD_POLICY_VIOLATION') {
    return { title: 'Check the form', message: `Correct the highlighted fields. ${PASSWORD_POLICY_HINT}`, retryable: false }
  }
  if (error.status === 401 || error.code === 'ACCOUNT_INACTIVE') {
    return { title: 'Sign in again', message: 'Sign in before changing your password.', retryable: false }
  }
  return { title: 'Password change could not be confirmed', message: 'Check your connection, then enter your passwords and try again. If your password was saved, sign in with the new password.', retryable: true }
}
