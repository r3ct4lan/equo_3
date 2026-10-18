# MVP readiness evidence — DR-E7-053

| Field | Value |
|---|---|
| Scope | Evidence review for the 13 launch-readiness criteria in [MVP scope §16](../mvp-scope.md#16-критерии-готовности-mvp-к-запуску) |
| Result | PASS — evidence is documented for all 13 criteria |
| Date | 2026-10-15 |
| Base | `main` at `c66ad745583ae4caef79e8b00c979380db0701be` |

This is a technical evidence review. It does not decide whether the listed
launch blockers are closed, substitute for a production deployment exercise, or
record Product Owner acceptance. Those are tracked separately by DR-E7-054 and
DR-E7-058.

## Evidence matrix

| # | Criterion | Evidence and result |
|---:|---|---|
| 1 | Roles | [Connect](connect-invitations.md), [debt](create-debt.md) and [manual transfer](manual-transfer.md) acceptance cover visitor registration/activation and active users acting as Connect parties, debt authors/payers/participants and MANUAL authors. PASS. |
| 2 | Main path | [Manual transfer acceptance](manual-transfer.md) records the browser journey for two new users from registration and activation through Connect, expense, MANUAL and both refreshed balance/history views. PASS. |
| 3 | Persistence | [Debt acceptance](create-debt.md) covers financial schema, keys/relations, atomic writes and non-deletion behavior; [retention acceptance](retention-operations.md) verifies migrations/schema parity and limits physical cleanup to the documented security/outbox entities. PASS. |
| 4 | Calculation | [Debt acceptance](create-debt.md) covers equal shares, ceiling-to-cent rounding, one share per current non-payer, and balances derived from active transfers. PASS. |
| 5 | Debt lifecycle | [Debt update acceptance](update-debt.md) covers matching ETag, atomic participant/share recalculation, rollback, stale writers, and immutable payer. PASS. |
| 6 | History | [Debt/history acceptance](create-debt.md), [manual transfer acceptance](manual-transfer.md) and [resource privacy audit](retention-operations.md#hidden-resource-404-privacy-audit--dr-e7-022) cover active/deleted transfer history, balance exclusion and hidden debt data. PASS. |
| 7 | Protection | [Connect](connect-invitations.md), [logout/session](logout-session-revocation.md), [debt](create-debt.md) and [security audits](retention-operations.md#protected-route-authentication-and-acl-audit--dr-e7-021) cover authentication, ACL, inactive users, credential lifecycle, cookies, rate limits and secret redaction. PASS. |
| 8 | Retries/conflicts | [Debt](create-debt.md), [manual transfer](manual-transfer.md), [debt update](update-debt.md) and [password recovery](password-reset.md) acceptance cover idempotent retries, changed-body conflicts, ETag/If-Match conflicts and no partial writes. PASS. |
| 9 | Error model | The focused [Connect](connect-invitations.md), [password reset](password-reset.md), [logout/session](logout-session-revocation.md), [debt](create-debt.md), [manual transfer](manual-transfer.md) and [debt update](update-debt.md) matrices exercise documented validation, credential, availability, ACL, lifecycle, token, rate-limit, idempotency and version-conflict responses, including privacy and rollback checks. PASS. |
| 10 | Email | [Password recovery acceptance](password-reset.md) covers atomic token/outbox intent and invalidation; [retention/email operations acceptance](retention-operations.md) covers outbox relay, retries, redaction, terminal failure, aggregate monitoring and guarded replay. PASS. |
| 11 | Operations | [Retention acceptance](retention-operations.md) covers bounded scheduled cleanup, TTL preservation, backlog/failure monitoring and safe replay. The [operations runbook](../operations/email-delivery.md) documents start/inspect/replay. Quality workflow [#18](https://github.com/r3ct4lan/equo_3/actions/runs/38661471862) passed its full Compose application smoke and 26 browser E2E jobs; smoke runs `docker compose up -d --build --wait` for the committed service set. This verifies the isolated development Compose stack, not a production deployment. PASS within that scope. |
| 12 | Manual processes | [MVP scope §15](../mvp-scope.md#15-допустимые-временные-и-ручные-процессы) enumerates the temporary processes; the [operations runbook](../operations/email-delivery.md) limits operator work to aggregate inspection and guarded replay. The primary financial path is covered end to end in [manual transfer acceptance](manual-transfer.md). No normal-path manual financial/security SQL is specified. PASS. |
| 13 | Boundaries | The [Must-have traceability matrix](../development-roadmap.md#11-финальный-этап-приёмка-и-release-gate) links all 16 Must scenarios to focused evidence or current CI. [MVP scope](../mvp-scope.md) keeps Should, Later and Out of scope scenarios separate; no partial Should UI or hidden Later prerequisite is included in the covered main-path journeys. PASS. |

## Verification reference and limits

Quality workflow [#18](https://github.com/r3ct4lan/equo_3/actions/runs/38661471862)
on PR #18 completed successfully across dependency audit, repository quality,
backend tests/migrations, backend quality, frontend quality/build, full MVP
browser E2E and application smoke. The run reported 681 backend tests / 8,335
assertions and 26 passing browser scenarios. Focused acceptance records link the
feature-specific checks and their historical run details.

Docker Desktop reports that Docker is not integrated into this WSL distribution,
so the required local `make check-full` could not be executed here. CI supplied
the full isolated Compose, smoke and browser checks. External SMTP, production secret-store wiring, production deployment,
backup/RPO/RTO choices and Product Owner launch acceptance remain unverified or
open as separately tracked release decisions; this evidence review does not
close them.

Update 2026-10-18: [AWS staging acceptance](aws-staging.md) now records the
production-image deployment, owner-confirmed Brevo activation delivery and
isolated target-host failure/retry, worker shutdown and retention checks.
[Full CI](https://github.com/r3ct4lan/equo_3/actions/runs/39123689846) passed on
`b5e81da`, merged via PR #25 as `bba048b`. This closes DR-E7-054. The owner
subsequently confirmed all five [walkthrough](owner-mvp-walkthrough.md) steps
and separately accepted version `fe5ab40` for test use on 2026-10-18 with the
documented limitations. DR-E7-058 and DR-E7-057 are closed.
