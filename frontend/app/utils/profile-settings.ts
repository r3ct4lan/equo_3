import type { FieldErrorMap } from './api-error'
import type { ErrorPresentation, SafeApiError } from './auth-flow'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u

export function validateProfileName(values: Readonly<{ name: string }>): FieldErrorMap {
  return values.name.trim() === '' ? { name: ['Enter your name.'] } : {}
}

export function validateEmailChange(values: Readonly<{ currentPassword: string, newEmail: string }>): FieldErrorMap {
  const errors: FieldErrorMap = {}
  const email = values.newEmail.trim()
  if (email === '') errors.newEmail = ['Enter your new email address.']
  else if (!EMAIL_PATTERN.test(email)) errors.newEmail = ['Enter an email address in a valid format.']
  if (values.currentPassword === '') errors.currentPassword = ['Enter your current password.']
  return errors
}

export function validateAccountDeactivation(values: Readonly<{ currentPassword: string }>): FieldErrorMap {
  return values.currentPassword === '' ? { currentPassword: ['Enter your current password.'] } : {}
}

export function accountDeactivationErrorPresentation(error: SafeApiError): ErrorPresentation {
  if (error.code === 'INVALID_CREDENTIALS') return { title: 'Current password is incorrect', message: 'Enter your current password and try again.', retryable: false }
  if (error.code === 'VALIDATION_ERROR') return { title: 'Check the form', message: 'Correct the highlighted field.', retryable: false }
  if (error.status === 401 || error.code === 'ACCOUNT_INACTIVE') return { title: 'Sign in again', message: 'Your session ended before the account could be deactivated.', retryable: false }
  return { title: 'Account could not be deactivated', message: 'Check your connection and try again.', retryable: true }
}

export function emailChangeErrorPresentation(error: SafeApiError): ErrorPresentation {
  if (error.code === 'INVALID_CREDENTIALS') return { title: 'Current password is incorrect', message: 'Enter your current password and try again.', retryable: false }
  if (error.code === 'EMAIL_ALREADY_IN_USE') return { title: 'Email is already in use', message: 'Choose another email address.', retryable: false }
  if (error.code === 'RATE_LIMIT_EXCEEDED') return { title: 'Too many requests', message: 'Wait before requesting another confirmation email.', retryable: false }
  if (error.code === 'VALIDATION_ERROR') return { title: 'Check the form', message: 'Correct the highlighted fields.', retryable: false }
  if (error.status === 401 || error.code === 'ACCOUNT_INACTIVE') return { title: 'Sign in again', message: 'Sign in before changing your email.', retryable: false }
  return { title: 'Email change could not be requested', message: 'Check your connection and try again.', retryable: true }
}

export function emailConfirmationErrorPresentation(error: SafeApiError): ErrorPresentation {
  if (error.code === 'EMAIL_ALREADY_IN_USE') return { title: 'Email is already in use', message: 'Return to your profile and choose another email address.', retryable: false }
  if (['INVALID_TOKEN', 'TOKEN_EXPIRED', 'TOKEN_USED', 'TOKEN_INVALIDATED'].includes(error.code)) {
    return { title: 'Confirmation link is unavailable', message: 'Return to your profile and request a new email change.', retryable: false }
  }
  if (error.code === 'RATE_LIMIT_EXCEEDED') return { title: 'Too many attempts', message: 'Wait before trying the confirmation again.', retryable: true }
  return { title: 'Email change could not be confirmed', message: 'Check your connection and try again.', retryable: true }
}
