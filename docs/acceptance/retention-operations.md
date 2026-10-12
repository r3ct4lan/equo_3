# SC-022 — Retention implementation evidence

| Field | Value |
|---|---|
| Scope | Retention and email operations slices (`DR-E7-001—018`, except `DR-E7-013`) |
| Result | PASS for implemented slices; full SC-022 and release gate remain open |
| Date | 2026-10-12 |
| Branch | `codex/sc-022-operations-hardening` |

## Evidence

- `make test-backend`: **659 tests, 8,086 assertions passed** on the isolated
  PostgreSQL test environment. Coverage includes retention, outbox monitoring,
  failure transport listing and guarded replay.
- Fresh-database migration check applied all six migrations; Doctrine mapping
  and schema synchronization passed.
- Upgrade check migrated the prior schema through
  `DoctrineMigrations\\Version20261011120000`, then applied
  `DoctrineMigrations\\Version20261012140000`; migration status, mapping and
  schema synchronization passed.
- `make check-backend-quality`: **PASS** (Composer validation/autoload,
  PHP-CS-Fixer, PHPStan level 8, Symfony container/YAML/routes and Doctrine
  mapping).
- `git diff --check`: **PASS**.
- `app:email-outbox:status` emitted aggregate-only zero metrics on an isolated
  test database; `app:email-outbox:failed` returned an empty safe list.
- `messenger:failed:show --transport=failed` successfully read the configured
  Doctrine failure transport (zero messages) against the isolated test database.
- `app:retention:cleanup --dry-run` emitted only aggregate counts on the
  isolated test database.
- The retention command processes only `email_delivery_outbox`,
  `idempotency_record`, `user_session`, `connect_invitation` and
  `user_action_token`; no business/financial table is targeted.
- Cleanup is bounded per table, stable ordered, FK ordered, repeatable, protected
  by a transaction advisory lock, and has a count-only dry-run.
- An action token remains until its outbox row can be deleted; an expired
  pending/published delivery is first marked `FAILED` and its ciphertext cleared.
- The scheduled Compose service invokes cleanup daily. Its command is in the
  application repository; it has not been deployed to an external staging or
  production environment.

## Remaining SC-022 / release evidence

Guarded replay is covered by integration tests: only a still-deliverable,
non-terminal `PUBLISHED` row is requeued; terminal and expired rows are refused.
Failure transport listing emits message and delivery identifiers only, and the
monitoring test checks that a marker in failure details is absent from output.

`DR-E7-013` remains open because deployments must choose numeric warning and
critical thresholds; no cross-environment SLA has been accepted. This record is
not the complete stage-7 acceptance. Endpoint/security/consistency audit,
full-product E2E/CI, frontend/npm gate, deployment review, staging SMTP,
operational policy decisions and Product Owner release decision remain open.
No external SMTP delivery or production service was exercised.

## API surface audit

On 2026-10-12, `php bin/console debug:router --env=dev --format=json` and the
controller `#[Route]` attributes reported 23 `/api/v1` application routes. The
HTTP 16 matrix contains 31 operations. All 23 registered operations are in
Must scope; the eight missing operations classify as Should/Later:

| HTTP operation | Contract category | Runtime state |
|---|---|---|
| `POST /auth/register` | Must | Implemented, `RegisterController` |
| `POST /auth/activation-requests` | Must | Implemented, `ActivationRequestController` |
| `POST /auth/activate` | Must | Implemented, `ActivateController` |
| `POST /auth/login` | Must | Implemented, `LoginController` |
| `POST /auth/refresh` | Must | Implemented, `RefreshController` |
| `POST /auth/logout` | Must | Implemented, `LogoutController` |
| `POST /auth/logout-all` | Must | Implemented, `LogoutAllController` |
| `POST /auth/password-reset-requests` | Must | Implemented, `PasswordResetRequestController` |
| `POST /auth/password-reset` | Must | Implemented, `ResetPasswordController` |
| `POST /account/password-change` | Must | Implemented, `ChangePasswordController` |
| `GET /me` | Must | Implemented, `MeController` |
| `GET /connects` | Must | Implemented, `ConnectController::list` |
| `GET /connects/{connectId}` | Must | Implemented, `ConnectController::detail` |
| `POST /connect-invitations` | Must | Implemented, `InvitationController::create` |
| `GET /connect-invitations/{token}` | Must | Implemented, `InvitationController::preview` |
| `POST /connect-invitations/{token}/accept` | Must | Implemented, `InvitationController::accept` |
| `GET /debts` | Must | Implemented, `DebtController::list` |
| `POST /debts` | Must | Implemented, `DebtController::create` |
| `GET /debts/{debtId}` | Must | Implemented, `DebtController::detail` |
| `PUT /debts/{debtId}` | Must | Implemented, `DebtController::update` |
| `GET /transfers/{transferId}` | Must | Implemented, `TransferController::detail` |
| `GET /connects/{connectId}/transfers` | Must | Implemented, `TransferController::history` |
| `POST /connects/{connectId}/transfers` | Must | Implemented, `TransferController::create` |
| `POST /auth/email-change-confirmations` | Should | Absent; SC-006 deferred |
| `POST /account/email-change-requests` | Should | Absent; SC-006 deferred |
| `POST /account/deactivate` | Should | Absent; SC-007 deferred |
| `PATCH /me` | Should | Absent; SC-006 deferred |
| `DELETE /debts/{debtId}` | Should | Absent; SC-014 deferred |
| `PUT /transfers/{transferId}` | Should | Absent; SC-017 deferred |
| `DELETE /transfers/{transferId}` | Should | Absent; SC-017 deferred |
| `POST /connects` | Later | Absent; SC-010 deferred |

The route inventory has no placeholder routes for the deferred methods. API
authorization and financial privacy evidence is in `FinancialHttpTest`,
`DebtUpdateHttpTest`, `ManualTransferHttpTest`, `InvitationHttpTest`, and the
`financial expense ... stays private` browser journey. These checks verify
unauthenticated denial, participant/author access, non-participant 404 privacy,
version conflicts, idempotent retries and redacted responses on the relevant
operations. Transaction rollback, uniqueness constraints and concurrency are
covered by the corresponding `Integration/{Debts,Invitations,Transfers}` suites.

`make test-e2e`: **26 tests passed** on the isolated Compose project, and its
workspace-drift guard passed. Scenarios include two-user registration/activation
and invitation, debt creation and visibility, manual payment, debt
recalculation, third-user privacy, lost-response retries and stale editor
conflict. An earlier run also completed all 26 tests but its wrapper detected
the documentation edits made during that run; the clean rerun passed.

`make check-full` ran repository checks, backend quality, the isolated backend
suite (**659 tests, 8,086 assertions**), Composer audit, frontend lint/typecheck,
unit/component tests (**62 + 87**) and production build. It stopped at
`npm audit --audit-level=moderate` with **11 high** findings rooted in `braces`
(`GHSA-vfj7-8cjw-p6xm`) and `node-forge` (`GHSA-86w9-cpqp-85rv`); npm reports no
fix for `node-forge`. The audit threshold was not lowered and no exception was
added. `make smoke` passed separately. Therefore the full gate and
`DR-E7-042/043` remain open despite the independently passing browser run.
