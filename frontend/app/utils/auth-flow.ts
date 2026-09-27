import type { FieldErrorMap } from './api-error'

export const REGISTER_ENDPOINT = '/v1/auth/register'
export const ACTIVATE_ENDPOINT = '/v1/auth/activate'
export const ACTIVATION_REQUEST_ENDPOINT = '/v1/auth/activation-requests'

export interface RegistrationValues extends Record<string, unknown> {
  name: string
  email: string
  password: string
}

export interface ActivationRequestValues extends Record<string, unknown> {
  email: string
  password: string
}

export interface SafeApiError {
  code: string
  status: number | null
  requestId: string | null
  retryAfterSeconds?: number | null
  fieldErrors: FieldErrorMap
}

export interface ErrorPresentation {
  title: string
  message: string
  retryable: boolean
}

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u

export function validateRegistration(values: Readonly<RegistrationValues>): FieldErrorMap {
  const errors: Record<string, string[]> = {}

  if (values.name.trim() === '') {
    errors.name = ['Enter your name.']
  }

  if (values.email.trim() === '') {
    errors.email = ['Enter your email address.']
  }
  else if (!EMAIL_PATTERN.test(values.email.trim())) {
    errors.email = ['Enter an email address in a valid format.']
  }

  if (values.password === '') {
    errors.password = ['Enter a password.']
  }
  else {
    const passwordLength = Array.from(values.password).length

    if (passwordLength < 12) {
      errors.password = ['Use at least 12 characters.']
    }
    else if (passwordLength > 128) {
      errors.password = ['Use no more than 128 characters.']
    }
    else if (!/\S/u.test(values.password)) {
      errors.password = ['Use at least one non-space character.']
    }
  }

  return errors
}

export function validateActivationRequest(values: Readonly<ActivationRequestValues>): FieldErrorMap {
  const errors: Record<string, string[]> = {}

  if (values.email.trim() === '') {
    errors.email = ['Enter your email address.']
  }
  else if (!EMAIL_PATTERN.test(values.email.trim())) {
    errors.email = ['Enter an email address in a valid format.']
  }

  if (values.password === '') {
    errors.password = ['Enter your current password.']
  }

  return errors
}

export function createRegistrationAttemptKeys(
  generateKey: () => string = generateIdempotencyKey
) {
  let currentKey: string | null = null

  return {
    forSubmission(): string {
      currentKey ??= generateKey()
      return currentKey
    },
    formChanged(): void {
      currentKey = null
    },
    complete(): void {
      currentKey = null
    },
    peek(): string | null {
      return currentKey
    }
  }
}

export function generateIdempotencyKey(
  randomBytes: () => Uint8Array = () => crypto.getRandomValues(new Uint8Array(16))
): string {
  const bytes = Uint8Array.from(randomBytes())

  if (bytes.length !== 16) {
    throw new Error('UUID generation requires exactly 16 random bytes.')
  }

  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80

  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0'))

  return [
    hex.slice(0, 4).join(''),
    hex.slice(4, 6).join(''),
    hex.slice(6, 8).join(''),
    hex.slice(8, 10).join(''),
    hex.slice(10, 16).join('')
  ].join('-')
}

export function registrationErrorPresentation(error: SafeApiError): ErrorPresentation {
  switch (error.code) {
    case 'EMAIL_ALREADY_EXISTS':
      return {
        title: 'Email already registered',
        message: 'Use another email address or return later when sign-in is available.',
        retryable: false
      }
    case 'PASSWORD_POLICY_VIOLATION':
      return {
        title: 'Password does not meet the requirements',
        message: 'Use 12–128 characters and include at least one non-space character.',
        retryable: false
      }
    case 'VALIDATION_ERROR':
      return {
        title: 'Check the form',
        message: Object.keys(error.fieldErrors).length > 0
          ? 'Correct the highlighted fields and submit again.'
          : 'Check the entered values and submit again.',
        retryable: false
      }
    case 'IDEMPOTENCY_KEY_REUSED':
      return {
        title: 'Registration attempt changed',
        message: 'Review the form and submit it again as a new attempt.',
        retryable: false
      }
    case 'RATE_LIMIT_EXCEEDED':
      return {
        title: 'Too many attempts',
        message: error.retryAfterSeconds !== null && error.retryAfterSeconds !== undefined
          ? `Wait ${error.retryAfterSeconds} seconds before trying to register again.`
          : 'Wait a little before trying to register again.',
        retryable: false
      }
    case 'AUTHENTICATION_REQUIRED':
    case 'FORBIDDEN':
    case 'RESOURCE_NOT_FOUND':
      return {
        title: 'Registration is unavailable',
        message: 'This registration request could not be completed.',
        retryable: false
      }
    default:
      if (error.status !== null && error.status < 500) {
        return {
          title: 'Registration is unavailable',
          message: 'This registration request could not be completed. Review the form before trying again.',
          retryable: false
        }
      }

      return {
        title: 'We could not confirm your registration',
        message: 'Your details are safe. Try again to check the same registration attempt.',
        retryable: true
      }
  }
}

export function activationErrorPresentation(error: SafeApiError): ErrorPresentation {
  switch (error.code) {
    case 'INVALID_TOKEN':
      return {
        title: 'Activation link is invalid',
        message: 'This link cannot be used to activate an account.',
        retryable: false
      }
    case 'TOKEN_EXPIRED':
      return {
        title: 'Activation link has expired',
        message: 'This link is no longer valid.',
        retryable: false
      }
    case 'TOKEN_USED':
      return {
        title: 'Activation link was already used',
        message: 'This one-time link cannot be used again.',
        retryable: false
      }
    case 'TOKEN_INVALIDATED':
      return {
        title: 'Activation link was replaced',
        message: 'This link is no longer the current activation link.',
        retryable: false
      }
    case 'RATE_LIMIT_EXCEEDED':
      return {
        title: 'Too many activation attempts',
        message: error.retryAfterSeconds !== null && error.retryAfterSeconds !== undefined
          ? `Wait ${error.retryAfterSeconds} seconds before trying this link again.`
          : 'Wait a little before trying this link again.',
        retryable: false
      }
    case 'AUTHENTICATION_REQUIRED':
    case 'FORBIDDEN':
    case 'RESOURCE_NOT_FOUND':
      return {
        title: 'Account could not be activated',
        message: 'This activation request could not be completed.',
        retryable: false
      }
    default:
      if (error.status !== null && error.status < 500) {
        return {
          title: 'Account could not be activated',
          message: 'This activation request could not be completed.',
          retryable: false
        }
      }

      return {
        title: 'Activation could not be confirmed',
        message: 'Try this activation again. No new activation link is required.',
        retryable: true
      }
  }
}

export function activationRequestErrorPresentation(error: SafeApiError): ErrorPresentation {
  switch (error.code) {
    case 'VALIDATION_ERROR':
      return {
        title: 'Check the form',
        message: Object.keys(error.fieldErrors).length > 0
          ? 'Correct the highlighted fields and submit again.'
          : 'Check the entered values and submit again.',
        retryable: false
      }
    case 'RATE_LIMIT_EXCEEDED':
      return {
        title: 'Please wait before trying again',
        message: error.retryAfterSeconds !== null && error.retryAfterSeconds !== undefined
          ? `Try again in ${error.retryAfterSeconds} seconds.`
          : 'Try again a little later.',
        retryable: false
      }
    case 'INVALID_CREDENTIALS':
    case 'ACCOUNT_ALREADY_ACTIVE':
    case 'AUTHENTICATION_REQUIRED':
    case 'FORBIDDEN':
    case 'RESOURCE_NOT_FOUND':
      return {
        title: 'Activation request is unavailable',
        message: 'This activation request could not be completed.',
        retryable: false
      }
    default:
      if (error.status !== null && error.status < 500) {
        return {
          title: 'Activation request is unavailable',
          message: 'This activation request could not be completed. Review the form before trying again.',
          retryable: false
        }
      }

      return {
        title: 'We could not complete the request',
        message: 'Your details are safe. Try the request again when you are ready.',
        retryable: true
      }
  }
}
