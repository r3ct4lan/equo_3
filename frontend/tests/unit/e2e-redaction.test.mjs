import assert from 'node:assert/strict'
import test from 'node:test'
import { redact, safePath } from '../e2e/redaction.ts'

test('redacts capability tokens from browser URLs before diagnostics are attached', () => {
  const invitation = `00000000-0000-4000-8000-000000000001.${'a'.repeat(43)}`
  const input = `Navigation failed: https://equo.test/connect-invitations/${invitation}?token=v1.${'b'.repeat(43)}`
  const output = redact(input)

  assert.equal(output.includes(invitation), false)
  assert.equal(output.includes(`v1.${'b'.repeat(43)}`), false)
  assert.equal(safePath(`https://equo.test/connect-invitations/${invitation}`), 'https://equo.test/connect-invitations/[redacted]')
})

test('redacts JWT, refresh/CSRF tokens, cookies, passwords, and ciphertext in diagnostics', () => {
  const jwt = `eyJ${'a'.repeat(20)}.${'b'.repeat(20)}.${'c'.repeat(20)}`
  const refresh = `rt2.00000000-0000-4000-8000-000000000001.${'d'.repeat(43)}`
  const input = [
    `authorization=Bearer ${jwt}`,
    `cookie: equo_refresh=${refresh}; __Host-equo_csrf=csrf-secret`,
    'X-CSRF-Token: csrf-secret',
    'password=correct-horse',
    'ciphertext=encrypted-secret',
    'accessToken="opaque access secret" refresh_token=opaque-refresh passwordHash=hidden-hash',
    `password hash=${'e'.repeat(64)}`
  ].join('\n')
  const output = redact(input)

  for (const secret of [jwt, refresh, 'csrf-secret', 'correct-horse', 'encrypted-secret', 'opaque access secret', 'opaque-refresh', 'hidden-hash', 'e'.repeat(64)]) {
    assert.equal(output.includes(secret), false, `diagnostic leaked ${secret}`)
  }
})

test('redacts database errors, SQL statements, stack frames, and filesystem paths', () => {
  const input = [
    'SQLSTATE[23505]: duplicate key value',
    'SQLSTATE password=secret detail',
    'SELECT password_hash FROM app_user WHERE email = secret@example.test',
    '    at handler (/var/www/equo/backend/src/Handler.php:42:7)',
    'C:\\workspace\\equo\\backend\\src\\Handler.php'
  ].join('\n')
  const output = redact(input)

  for (const secret of ['SQLSTATE[23505]', 'SQLSTATE password=secret', 'password_hash', 'secret@example.test', '/var/www', 'C:\\workspace']) {
    assert.equal(output.includes(secret), false, `diagnostic leaked ${secret}`)
  }
})
