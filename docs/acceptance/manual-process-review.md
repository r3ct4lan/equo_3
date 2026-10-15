# Manual process review — DR-E7-055

| Field | Value |
|---|---|
| Scope | Check of permitted temporary and operator processes in [MVP scope §15](../mvp-scope.md#15-допустимые-временные-и-ручные-процессы) |
| Result | PASS — documented operational processes follow §15; the primary path needs no manual SQL |
| Date | 2026-10-15 |
| Base | `main` at `6ca5b54acf51db81cb9fff5c531f98426ef3124b` |

## Evidence matrix

| §15 allowance or restriction | Evidence and result |
|---|---|
| Connect may use an invitation or be created as part of a valid debt before SC-010 | [Connect acceptance](connect-invitations.md) covers invitation/acceptance; [debt acceptance](create-debt.md) covers Connect creation during valid debt creation. PASS. |
| MANUAL has no `relatedTransferId` before SC-018 | [Manual transfer acceptance](manual-transfer.md) records the MANUAL representation with debt/participant/related links null. The create form and request use only the permitted two parties and amount. PASS. |
| Do not partially expose Should account/debt controls | The implemented `GET /me` is read-only; no profile/email-change or account-deactivation controller is present. [Manual transfer acceptance](manual-transfer.md) confirms edit/delete controls are not exposed for MANUAL. PASS for the reviewed surface. |
| Operators may inspect email backlog/failure transport and use guarded replay | The [operations runbook](../operations/email-delivery.md) documents aggregate status, safe failure IDs, guarded replay and removal of stale transport envelopes; it warns against exception-detail output and manual SQL. PASS. |
| SMTP and environment secrets are configured externally | The [operations runbook](../operations/email-delivery.md) lists production-required values and assigns them to the deployment secret store. `.env.example` is explicitly local-only. PASS. |
| No manual substitute for core identity, access, financial or retention behavior | The [main browser journey](manual-transfer.md) covers registration, activation, invitation, Connect, debt, MANUAL, balances and history without database edits. Email resend/reset, ACL, idempotency, locking, share calculation, history and retention are implemented in application commands/requests and tested; no runbook step substitutes manual token, financial or security-table changes. PASS. |
| No manual financial/security SQL in the normal path | The [email and retention runbook](../operations/email-delivery.md) explicitly forbids manual SQL changes to outbox, tokens and financial tables. User flows go through the documented HTTP/browser journeys; no normal-path `psql` or SQL procedure is documented. PASS. |

## Correction made during the review

The root README, retention feature page and operator runbook previously described
manually invoking a non-dry-run retention cleanup. Section 15 does not list that
as an operator process, and scheduled cleanup already runs through the Compose
`retention` service. The operational instructions now make the scheduler the
only documented non-dry-run caller; operators inspect its logs. Count-only
`--dry-run` remains described only for isolated development/test verification.

The scheduler waits for migrations, processes bounded batches and retries
failures automatically. Retention acceptance covers expiry preservation,
bounded/idempotent cleanup and financial-history exclusion. The change removes
an undocumented manual operation without altering the cleanup implementation or
its schedule.

The full quality workflow [on main](https://github.com/r3ct4lan/equo_3/actions/runs/38665823600)
passed repository, backend and frontend quality, dependency audit, migrations,
backend tests, 26 browser E2E and Compose application smoke.
