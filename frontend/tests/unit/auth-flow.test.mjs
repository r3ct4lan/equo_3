import assert from 'node:assert/strict'
import test from 'node:test'
import {
  ACTIVATE_ENDPOINT,
  activationErrorPresentation,
  createRegistrationAttemptKeys,
  generateIdempotencyKey,
  REGISTER_ENDPOINT,
  registrationErrorPresentation,
  validateRegistration
} from '../../app/utils/auth-flow.ts'

function apiError(code, overrides = {}) {
  return {
    code,
    status: null,
    requestId: null,
    fieldErrors: {},
    ...overrides
  }
}

test('uses the exact first-slice API endpoint paths', () => {
  assert.equal(REGISTER_ENDPOINT, '/v1/auth/register')
  assert.equal(ACTIVATE_ENDPOINT, '/v1/auth/activate')
})

test('validates every required registration field without echoing values', () => {
  const errors = validateRegistration({ name: '   ', email: '', password: '' })

  assert.deepEqual(errors, {
    name: ['Enter your name.'],
    email: ['Enter your email address.'],
    password: ['Enter a password.']
  })
})

test('validates email format for usability while keeping backend authoritative', () => {
  const errors = validateRegistration({
    name: 'Alex',
    email: 'not-an-email',
    password: 'correct horse battery staple'
  })

  assert.deepEqual(errors.email, ['Enter an email address in a valid format.'])
})

test('enforces password length in Unicode code points and the non-space rule', () => {
  assert.deepEqual(validateRegistration({
    name: 'Alex',
    email: 'alex@example.test',
    password: 'a'.repeat(11)
  }).password, ['Use at least 12 characters.'])

  assert.deepEqual(validateRegistration({
    name: 'Alex',
    email: 'alex@example.test',
    password: '😀'.repeat(129)
  }).password, ['Use no more than 128 characters.'])

  assert.deepEqual(validateRegistration({
    name: 'Alex',
    email: 'alex@example.test',
    password: ' '.repeat(12)
  }).password, ['Use at least one non-space character.'])

  assert.deepEqual(validateRegistration({
    name: 'Alex',
    email: 'alex@example.test',
    password: ` ${'a'.repeat(10)} `
  }), {})
})

test('reuses one idempotency key for unchanged retries and rotates after edits', () => {
  const generated = ['first-key', 'second-key']
  const keys = createRegistrationAttemptKeys(() => generated.shift())

  assert.equal(keys.forSubmission(), 'first-key')
  assert.equal(keys.forSubmission(), 'first-key')
  assert.equal(keys.peek(), 'first-key')

  keys.formChanged()
  assert.equal(keys.peek(), null)
  assert.equal(keys.forSubmission(), 'second-key')

  keys.complete()
  assert.equal(keys.peek(), null)
})

test('generates an RFC 4122 UUID v4 without requiring crypto.randomUUID', () => {
  const key = generateIdempotencyKey(() => new Uint8Array(16))

  assert.equal(key, '00000000-0000-4000-8000-000000000000')
  assert.match(generateIdempotencyKey(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
})

test('maps registration validation, business, rate and technical failures semantically', () => {
  assert.deepEqual(registrationErrorPresentation(apiError('VALIDATION_ERROR', {
    fieldErrors: { email: ['Email has invalid format.'] }
  })), {
    title: 'Check the form',
    message: 'Correct the highlighted fields and submit again.',
    retryable: false
  })

  assert.equal(registrationErrorPresentation(apiError('EMAIL_ALREADY_EXISTS')).title, 'Email already registered')
  assert.equal(registrationErrorPresentation(apiError('PASSWORD_POLICY_VIOLATION')).retryable, false)
  assert.equal(registrationErrorPresentation(apiError('IDEMPOTENCY_KEY_REUSED')).retryable, false)
  assert.equal(registrationErrorPresentation(apiError('RATE_LIMIT_EXCEEDED', {
    retryAfterSeconds: 47
  })).message, 'Wait 47 seconds before trying to register again.')
  assert.equal(registrationErrorPresentation(apiError('INTERNAL_ERROR', { status: 500 })).retryable, true)
  assert.equal(registrationErrorPresentation(apiError('NETWORK_ERROR')).retryable, true)
})

test('maps every activation token state and avoids an unavailable resend action', () => {
  const expectedTitles = new Map([
    ['INVALID_TOKEN', 'Activation link is invalid'],
    ['TOKEN_EXPIRED', 'Activation link has expired'],
    ['TOKEN_USED', 'Activation link was already used'],
    ['TOKEN_INVALIDATED', 'Activation link was replaced'],
    ['RATE_LIMIT_EXCEEDED', 'Too many activation attempts']
  ])

  for (const [code, title] of expectedTitles) {
    const presentation = activationErrorPresentation(apiError(code))
    assert.equal(presentation.title, title)
    assert.equal(presentation.retryable, false)
    assert.doesNotMatch(presentation.message, /resend/i)
  }

  assert.equal(activationErrorPresentation(apiError('INTERNAL_ERROR')).retryable, true)
})

test('handles unexpected 401, 403 and 404 responses without exposing server text', () => {
  for (const [code, status] of [
    ['AUTHENTICATION_REQUIRED', 401],
    ['FORBIDDEN', 403],
    ['RESOURCE_NOT_FOUND', 404]
  ]) {
    const registration = registrationErrorPresentation(apiError(code, { status }))
    const activation = activationErrorPresentation(apiError(code, { status }))

    assert.equal(registration.retryable, false)
    assert.equal(activation.retryable, false)
    assert.doesNotMatch(registration.message, /stack|sql|token|password|hash/i)
    assert.doesNotMatch(activation.message, /stack|sql|token|password|hash/i)
  }
})

test('never includes raw secrets from arbitrary backend messages in presentations', () => {
  const malicious = apiError('UNKNOWN', {
    message: 'password=secret token=raw stack trace SQL constraint'
  })

  const messages = [
    registrationErrorPresentation(malicious).message,
    activationErrorPresentation(malicious).message
  ]

  for (const message of messages) {
    assert.doesNotMatch(message, /secret|raw|stack|sql|constraint/i)
  }
})
