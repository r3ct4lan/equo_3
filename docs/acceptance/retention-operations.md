# SC-022 — Retention implementation evidence

| Field | Value |
|---|---|
| Scope | Retention and email operations slices (`DR-E7-001—018`) and protected-route auth/ACL/privacy/consistency audits (`DR-E7-021—027`) |
| Result | PASS through DR-E7-027; full SC-022 and release gate remain open |
| Date | 2026-10-14 |
| Branches | `codex/sc-022-operations-hardening`, `codex/sc-022-email-alert-thresholds`, `codex/dr-e7-022-resource-privacy`, `codex/dr-e7-023-email-privacy`, `codex/dr-e7-024-inactive-user-commands`, `codex/dr-e7-025-idempotency-matrix`, `codex/dr-e7-026-etag-if-match-audit`, `codex/dr-e7-027-writer-rollback-audit` |

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

For `DR-E7-021`, [quality workflow run #27](https://github.com/r3ct4lan/equo_3/actions/runs/38463042164)
for PR #4 passed the dependency audit, repository checks, backend quality and
tests/migrations, frontend quality/build, isolated full-MVP browser E2E and
application smoke. The local `make check-full` could not run because Docker CLI
is unavailable in WSL; `DR-E7-042` therefore remains open until that exact full
gate is executed successfully.

For `DR-E7-022`, [quality workflow run #31](https://github.com/r3ct4lan/equo_3/actions/runs/38623785512)
passed dependency audit, repository checks, backend quality and tests/migrations,
frontend quality/build, full-MVP browser E2E and application smoke. A local
`make check-full` attempt stopped in `check-repository` because Docker CLI is not
installed/integrated into this WSL distribution; CI supplied the isolated full
backend, E2E and smoke jobs. `DR-E7-042` stays open until the exact local full
gate is executable and passes.

`DR-E7-013` now supports deployment-provided warning and critical thresholds;
each deployment must still choose its own values because no cross-environment
SLA has been accepted. This record is not the complete stage-7 acceptance.
Endpoint/security/consistency audit, full-product release gate, deployment
review, staging SMTP, operational policy decisions and Product Owner release
decision remain open. No external SMTP delivery or production service was
exercised.

## Threshold configuration addendum — 2026-10-13

`DR-E7-013` is implemented with deployment-provided JSON thresholds for each
email outbox metric. The status command retains aggregate metrics, reports
`unconfigured` when no thresholds are supplied, and returns warning/critical
status when configured inclusive limits are reached. No numeric threshold is
declared as a product-wide SLA. Unit coverage verifies configuration validation,
healthy/warning/critical boundaries and unconfigured behavior. Production alert
values still need to be selected by each deployment.

At 2026-10-13, the WSL environment had no Docker CLI, so a fresh
`make check-full` could not proceed beyond repository checks. The PHP unit test
runner also could not start because WSL provides PHP 8.3.19 while the pinned
PHPUnit requires PHP >=8.4.1. PHP syntax checks and direct evaluator checks for
healthy/warning/critical/unconfigured and invalid configuration passed. The
npm audit helper passed directly against the current lockfile; these checks do
not replace the full local gate or its browser E2E run.

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

## Protected route authentication and ACL audit — DR-E7-021

The protected API surface has 14 operations. Bearer authentication is required
for each protected read and writer below; missing, malformed or invalid tokens
are rejected as `401 AUTHENTICATION_REQUIRED` before the operation can mutate
state. The HTTP test suites also exercise the per-resource authorization rules:

| Protected operation | ACL boundary | Evidence |
|---|---|---|
| `GET /me` | Returns only the active identity from a valid Bearer subject | `MeHttpTest::testMeAuthenticationFailuresUseOneSafe401`, `testMeRejectsValidTokensForUnknownAndInactiveUsers` |
| `GET /connects`, `GET /connects/{connectId}` | Only a side of the Connect can list/read it; other users receive the same hidden-resource response | `InvitationHttpTest::testBearerIsRequired`; `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances`; `InvitationFlowTest::testConnectListPaginationPrivacyAndInactiveSideRemainVisible` |
| `POST /connect-invitations`, `POST /connect-invitations/{token}/accept` | Active authenticated author/acceptor; self-acceptance is rejected | `InvitationHttpTest::testBearerIsRequired`, `testOwnInvitationHasSafeValidationError`; `InvitationFlowTest::testOwnInvitationIsRejectedWithoutWrites`, `testInactiveAcceptorIsFreshlyChecked` |
| `GET /debts`, `GET /debts/{debtId}` | Reads are limited to users included in the debt; non-participant detail is hidden | `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances`, `testExcludedParticipantRetainsTransferWithoutLeakingDebtAndDeletionFreezesAccess` |
| `POST /debts` | The author must be part of the command; selected users must be eligible. HTTP auth is tested with an otherwise valid command and idempotency key | `FinancialHttpTest::testRequiredHeadersProtectedPathsAndInvalidPagination`; `FinancialCreationTest::testInvalidSelectionsOrInactiveUsersHaveNoPartialEffect` |
| `PUT /debts/{debtId}` | Only the author may revise; an existing participant is forbidden and an outsider gets hidden-resource behavior | `DebtUpdateHttpTest::testAclFrozenDeletedAndAuthorCannotExcludeSelf`; `DebtUpdateIntegrationTest::testDeniedUpdatesLeaveAllRowsUnchanged` |
| `GET /transfers/{transferId}`, `GET /connects/{connectId}/transfers` | Only a side of the associated Connect can read the transfer/history | `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances`, `testExcludedParticipantRetainsTransferWithoutLeakingDebtAndDeletionFreezesAccess` |
| `POST /connects/{connectId}/transfers` | Author must belong to the Connect and be one of the exact sender/receiver sides | `ManualTransferHttpTest::testHiddenConnectActiveSidesAndKeyRequirements`, `testWrongPairSelfTransferMissingFieldAndCrossTargetKey`; `ManualTransferCreationTest::testAclSidesAndFreshActiveAccounts` |
| `POST /auth/logout-all`, `POST /account/password-change` | Operates only on the valid active Bearer subject; missing/invalid identity does not revoke sessions | `LogoutHttpTest::testLogoutAllUniformAuthenticationFailure`; `PasswordChangeHttpTest::testAuthenticationRequiresValidBearerAndActiveSubject` |

The explicit debt-create auth regression sends a valid body without Bearer,
expects `401 AUTHENTICATION_REQUIRED`, and confirms the debt table remains
empty. Public-by-contract routes are excluded from this Bearer matrix: account
bootstrap/login/reset operations use their documented public capability or
credential checks, invitation preview is token-capability based, and current
logout uses its refresh-cookie/CSRF guard (`LogoutHttpTest`); these boundaries
have their own HTTP coverage.

## Nested financial read-model privacy audit — DR-E7-023

`FinancialHttpTest::testForeignEmailsAreAbsentFromEveryNestedFinancialReadModel`
scans the complete JSON response for every fixture email except the current
viewer. It covers `GET /connects` and `GET /connects/{id}` for each side,
`GET /debts` and `GET /debts/{id}` for all debt participants,
`GET /transfers/{id}` for both Connect sides, and
`GET /connects/{id}/transfers` for both sides, including `MANUAL` rows with a
nested `createdBy`. Debt creation is also checked because it returns the full
nested debt view. Together these responses cover
all financial read shapes specified in HTTP sections 11.1—11.2, 13.1—13.2 and
14.1—14.3. Each nested user is additionally shaped by the existing DTOs to
`id`, `name` and `isActive` only.

Direct local PHPUnit could not run because the WSL host has PHP 8.3.19 while
the locked PHPUnit requires PHP >= 8.4.1, and Docker Desktop integration is
unavailable in WSL. Pull request quality workflow #34 passed: backend quality,
all **670 PHPUnit tests / 8,231 assertions**, migrations, frontend
quality/build, dependency audit, application smoke, and all **26 browser E2E**
tests. CI also confirmed checks left no generated workspace changes.

## Inactive-user command audit — DR-E7-024

`FinancialHttpTest::testInactiveBearerCannotIssueAnyImplementedProtectedCommand`
reuses a previously valid access token after deactivating its account. It
checks the protected `logout-all`, password-change, invitation create/accept,
Debt create/update, and MANUAL create commands all return
`401 AUTHENTICATION_REQUIRED`. It also verifies the pending invitation,
existing debt/transfers, and account profile remain unchanged.

`FinancialHttpTest::testDebtCreationRejectsInactivePayerOrParticipantWithoutPartialWrites`
checks inactive payer and participant cases independently; both return
`409 INACTIVE_USER` with no Debt, participant, Transfer, or Connect writes.
Additional HTTP coverage verifies that an inactive invitation acceptor cannot
consume the token. Existing regressions cover an inactive invitation author,
inactive MANUAL counterparty, an inactive payer freezing Debt update, and
reactivation being required to restore an excluded inactive participant.

Scope follows actually implemented writer routes. Debt deletion is deferred
under `SH-014`; account deactivation/email-change and MANUAL update/delete
routes are not currently implemented. Pull request quality workflow #38
passed: backend quality, **673 PHPUnit tests / 8,285 assertions**, migrations,
frontend quality/build, dependency audit, application smoke, and all **26
browser E2E** tests. CI confirmed there were no generated workspace changes.

## Idempotency-Key scope audit — DR-E7-025

The implemented commands that require `Idempotency-Key` are registration,
Debt creation and MANUAL creation. Existing HTTP tests cover missing/invalid
keys, exact retry returning the saved response, and same-scope/same-operation
body changes returning `409 IDEMPOTENCY_KEY_REUSED`. Registration also checks
that a retry preserves its original response and does not consume more rate
limit.

`FinancialHttpTest::testIdempotencyKeyIsScopedByUserAndOperation` verifies
that a key used for Debt creation by one user does not collide with the same
key/operation used by another user, and that the same user/key is independent
between `debt.create` and `manual_transfer.create`. It replays the first Debt
command and checks that it returns the first Debt's saved response. Exactly
three idempotency records are expected for these distinct scope/operation
pairs.

`POST /connects` is listed in HTTP 4.1 but is not implemented in the current
controllers. Invitation creation has a different repeat-safe contract and
does not accept an `Idempotency-Key`; both are outside this key matrix. Quality
workflow #42 passed: **674 PHPUnit tests / 8,299 assertions**, migrations,
backend/frontend quality, dependency audit, application smoke, and **26
browser E2E** tests. Workspace drift checks passed.

## ETag and If-Match audit — DR-E7-026

The only implemented writer accepting `If-Match` is
`PUT /debts/{debtId}`. `DebtUpdateHttpTest` covers a successful version
increment, a no-op retaining version/ETag, stale update conflict (including a
stale no-op), missing and empty headers returning 428, and weak, wildcard,
list, zero, leading-zero, out-of-range and malformed values returning 400. It
also confirms those rejected writes leave the version unchanged.

Debt creation/detail and MANUAL creation/detail expose the expected quoted
ETag for their versioned read/response representation; tests assert the Debt
versions through 1→2→3, automatic Transfer detail ETag, and MANUAL detail ETag.
Debt deletion is deferred under `SH-014`; the documented Transfer update/delete
routes are not implemented, so they have no current `If-Match` handling to
audit. Quality workflow #43 passed: **674 PHPUnit tests / 8,299 assertions**,
migrations, backend/frontend quality, dependency audit, application smoke and
**26 browser E2E** tests. Workspace drift checks passed.

## Transaction rollback and partial-state audit — DR-E7-027

Failure-path tests force a database or outbox failure after earlier writes and
verify the entire operation rolls back. Coverage includes registration and
activation token state, activation-request token replacement plus email outbox,
password reset and password change with sessions, logout-all, Debt creation
across Debt/participants/Connect/Transfer shares/idempotency state, Debt
recalculation at each aggregate write stage, MANUAL Transfer plus idempotency
state, and invitation acceptance after Connect creation. The invitation test
confirms a failed invitation consume leaves neither a Connect nor a used
invitation marker. All implemented cross-table writers found in the route and
application audit have rollback evidence; single-row writers and deferred
routes are outside this audit.

Quality workflow [#48](https://github.com/r3ct4lan/equo_3/actions/runs/38639948181)
passed: **675 PHPUnit tests / 8,314 assertions**, migrations,
backend/frontend quality, dependency audit, application smoke and **26 browser
E2E** tests. Workspace drift checks passed.

## Hidden resource 404 privacy audit — DR-E7-022

For an authenticated user who lacks access, the API returns the same HTTP
status, public error code and message as it does for a valid but nonexistent
resource ID. Tests compare the public error fields directly; request IDs are
allowed to differ per request.

| Resource and route | Hidden-versus-missing case | Evidence |
|---|---|---|
| `GET /connects/{connectId}` | Non-side cannot distinguish another pair's Connect from an absent UUID | `InvitationHttpTest::testPublicPreviewProtectedAcceptanceAndBothReadModels` |
| `GET /connects/{connectId}/transfers` | Non-side cannot distinguish existing history from a nonexistent Connect UUID | `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances` |
| `POST /connects/{connectId}/transfers` | Outsider receives the same `404 RESOURCE_NOT_FOUND` for an existing hidden pair and an absent/invalid Connect ID | `ManualTransferHttpTest::testHiddenConnectActiveSidesAndKeyRequirements` |
| `GET /debts/{debtId}` | Outsider receives the same `404 DEBT_NOT_AVAILABLE` for an existing debt and an absent UUID | `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances` |
| `PUT /debts/{debtId}` | Outsider receives `404 DEBT_NOT_AVAILABLE` with no details for an existing debt and absent/invalid IDs | `DebtUpdateHttpTest::testAclFrozenDeletedAndAuthorCannotExcludeSelf` |
| `GET /transfers/{transferId}` | Non-side receives the same `404 TRANSFER_NOT_AVAILABLE` for an existing transfer and absent UUID | `FinancialHttpTest::testCreateReplayReadAndPerspectiveBalances` |

These 404 checks preserve the specific visibility rules in `BR-ACL-001—006`:
excluded participants lose access to the debt but keep access to their own
transfers, and users with historical access can still view deleted debts.
An accessible transfer whose debt is hidden exposes only the debt ID and
`isAccessible: false`; it does not reveal debt title or deletion state.

`make test-e2e`: **26 tests passed** on the isolated Compose project, and its
workspace-drift guard passed. Scenarios include two-user registration/activation
and invitation, debt creation and visibility, manual payment, debt
recalculation, third-user privacy, lost-response retries and stale editor
conflict. An earlier run also completed all 26 tests but its wrapper detected
the documentation edits made during that run; the clean rerun passed.

`make check-full` ran repository checks, backend quality, the isolated backend
suite (**659 tests, 8,086 assertions**), Composer audit, frontend lint/typecheck,
unit/component tests (**62 + 87**) and production build. It stopped at
`npm audit --audit-level=moderate` with **11 high affected packages** rooted in
`braces` (`GHSA-vfj7-8cjw-p6xm`) and `node-forge`
(`GHSA-86w9-cpqp-85rv`). No patched versions were available in the registry.
The owner accepted these two risks temporarily; the audit helper allows only
these exact advisory IDs and continues to fail on any other moderate/high/
critical advisory. `DR-E7-043` is complete under this policy; `DR-E7-042` stays
open until the full gate is rerun. `make smoke` passed separately.
