# Launch blocker review — DR-E7-054

| Field | Value |
|---|---|
| Scope | Review of the launch blockers listed in [MVP scope §16](../mvp-scope.md#16-критерии-готовности-mvp-к-запуску) |
| Result | OPEN — no source-level failure found in reviewed evidence; two target-environment checks remain unverified |
| Date | 2026-10-15 |
| Base | `main` at `12b68882906d636bd59f318374e558568529460e` |

DR-E7-054 stays unchecked. An unverified deployment is not evidence of a code
failure, but it is not evidence that the target environment can launch and
operate the required services either.

## Blocker matrix

| Launch-blocker condition | Current evidence | Finding |
|---|---|---|
| Financial loss, duplicate, partial write or incorrect calculation | [Debt](create-debt.md), [manual transfer](manual-transfer.md) and [debt update](update-debt.md) acceptance cover atomicity, rollback, retries, races, calculations and ledger behavior; latest [main quality workflow](https://github.com/r3ct4lan/equo_3/actions/runs/38663790332) passed backend tests/migrations and 26 browser E2E. | No open implementation defect found in the reviewed evidence. |
| Authentication/authorization bypass, cross-user disclosure or clear secret storage | [DR-E7-021—030 security audits](retention-operations.md#protected-route-authentication-and-acl-audit--dr-e7-021) cover protected routes, inactive users, privacy, token/cookie behavior and redaction; current backend/browser gate passed. | No open implementation defect found in the reviewed evidence. |
| Required role cannot complete the primary user path | [MVP readiness evidence](mvp-readiness.md) and [manual transfer acceptance](manual-transfer.md) cover two users from signup through updated balances; latest browser E2E passed. | No open implementation defect found in the reviewed evidence. |
| Durable email intent is lost or the failure backlog is unobservable/unprocessable | [Password recovery acceptance](password-reset.md) and [retention operations acceptance](retention-operations.md) cover transactional intent, retries, secret clearing, terminal failure, aggregate monitoring and guarded replay. | No code-level failure is open. Actual external SMTP configuration and delivery have not been exercised; staging delivery remains an operational release check. |
| Required TTL, retention, rate limit, idempotency or optimistic locking is violated | [Retention operations acceptance](retention-operations.md) and the focused scenario acceptance matrices cover cleanup limits, expiry, rate limits, idempotency, ETags and locking. The latest main quality workflow passed. | No open implementation defect found in the reviewed evidence. |
| An unhandled error leaves partial state | Atomic rollback and failure-path matrices are documented in [debt](create-debt.md), [manual transfer](manual-transfer.md), [debt update](update-debt.md), [password recovery](password-reset.md) and [retention operations](retention-operations.md). | No open implementation defect found in the reviewed evidence. |
| Required services or background processes cannot start in the target environment | CI [full Compose smoke](https://github.com/r3ct4lan/equo_3/actions/runs/38663790332) and browser E2E pass against isolated test/development stacks. The [operations runbook](../operations/email-delivery.md) explicitly says production deployment has not been exercised. | **Open verification gap.** No target-environment deployment/startup evidence is available for backend, frontend, PostgreSQL, RabbitMQ, Redis, email worker and retention scheduler. |

## Required evidence to close DR-E7-054

1. In an owner-approved staging or production-like environment, supply secrets
   through its deployment secret store and start the documented stack, including
   the email worker and retention scheduler. Verify health, scheduler execution,
   cleanup TTL protection, email relay and graceful worker restart. Record only
   sanitized service status and command outcomes; do not commit secrets or logs
   containing personal data.
2. Configure the deployment SMTP transport and verify a registration or password
   recovery delivery through that environment. Exercise a temporary delivery
   failure and confirm retry/observable failure handling using the documented
   aggregate diagnostics and safe replay procedure.

The user has temporarily accepted the two named npm advisories under the
existing narrow audit allowlist until fixed package versions are available;
they are not reopened as blockers by this review. `DR-E7-060` remains the
follow-up to update packages and remove that allowlist after fixes are published.

Backup policy and RPO/RTO are recorded by `DR-E7-050` as owner/deployment
decisions that are required before production only if the owner treats them as a
release requirement. Product Owner launch acceptance remains the separate
`DR-E7-058` decision. Neither is silently inferred by this blocker review.
