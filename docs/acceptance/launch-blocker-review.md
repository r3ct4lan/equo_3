# Launch blocker review — DR-E7-054

| Field | Value |
|---|---|
| Scope | Review of the launch blockers listed in [MVP scope §16](../mvp-scope.md#16-критерии-готовности-mvp-к-запуску) |
| Result | PASS — reviewed implementation, target-environment operational evidence and deployment full quality gate; no open launch blocker found |
| Date | 2026-10-18 |
| Base | `main` at `12b68882906d636bd59f318374e558568529460e` |

DR-E7-054 is closed after review of the deployment branch full quality gate
and target-environment evidence below. Product Owner release acceptance remains
the separate DR-E7-058 decision, now recorded as accepted for test use on
2026-10-18 in the [owner acceptance](owner-mvp-walkthrough.md).

Update 2026-10-17: an owner-provided AWS staging server now runs the production
builds with HTTPS, applied migrations, healthy services and a running worker and
retention scheduler. See [AWS staging evidence](aws-staging.md). This partially
addresses the target-environment gap below. Brevo SMTP is now configured and
delivered an owner-authorized test message (receipt confirmed by the owner on
2026-10-17). The owner also confirmed registration through Equo, receipt of the
activation email, activation via its link and login on 2026-10-17. Failure/retry
and retention/worker scenarios were subsequently exercised on 2026-10-18 in an
isolated project on the same EC2, using the exact staging backend image. All
operational checks passed; see the evidence and explicit retry-timing limits in
[AWS staging acceptance](aws-staging.md#operational-acceptance--2026-10-18).
[Full Quality gate](https://github.com/r3ct4lan/equo_3/actions/runs/39123689846)
passed on `b5e81da` (681 backend tests, 26 browser scenarios and application
smoke). PR #25 merged it as `bba048b`; DR-E7-054 is closed.

## Blocker matrix

| Launch-blocker condition | Current evidence | Finding |
|---|---|---|
| Financial loss, duplicate, partial write or incorrect calculation | [Debt](create-debt.md), [manual transfer](manual-transfer.md) and [debt update](update-debt.md) acceptance cover atomicity, rollback, retries, races, calculations and ledger behavior; latest [main quality workflow](https://github.com/r3ct4lan/equo_3/actions/runs/38663790332) passed backend tests/migrations and 26 browser E2E. | No open implementation defect found in the reviewed evidence. |
| Authentication/authorization bypass, cross-user disclosure or clear secret storage | [DR-E7-021—030 security audits](retention-operations.md#protected-route-authentication-and-acl-audit--dr-e7-021) cover protected routes, inactive users, privacy, token/cookie behavior and redaction; current backend/browser gate passed. | No open implementation defect found in the reviewed evidence. |
| Required role cannot complete the primary user path | [MVP readiness evidence](mvp-readiness.md) and [manual transfer acceptance](manual-transfer.md) cover two users from signup through updated balances; latest browser E2E passed. | No open implementation defect found in the reviewed evidence. |
| Durable email intent is lost or the failure backlog is unobservable/unprocessable | [Password recovery acceptance](password-reset.md) and [retention operations acceptance](retention-operations.md) cover transactional intent, retries, secret clearing, terminal failure, aggregate monitoring and guarded replay. [AWS staging evidence](aws-staging.md) records owner-confirmed Brevo delivery and isolated SMTP failure/retry, terminal failure visibility and safe replay on the target host. | PASS with the documented accelerated final-attempt fixture and successful deployment full gate. |
| Required TTL, retention, rate limit, idempotency or optimistic locking is violated | [Retention operations acceptance](retention-operations.md) and the focused scenario acceptance matrices cover cleanup limits, expiry, rate limits, idempotency, ETags and locking. The latest main quality workflow passed. | No open implementation defect found in the reviewed evidence. |
| An unhandled error leaves partial state | Atomic rollback and failure-path matrices are documented in [debt](create-debt.md), [manual transfer](manual-transfer.md), [debt update](update-debt.md), [password recovery](password-reset.md) and [retention operations](retention-operations.md). | No open implementation defect found in the reviewed evidence. |
| Required services or background processes cannot start in the target environment | CI [full Compose smoke](https://github.com/r3ct4lan/equo_3/actions/runs/39123689846) and browser E2E pass against isolated test/development stacks. [AWS staging evidence](aws-staging.md) records startup, health checks, scheduler cleanup of aged fixtures and SIGTERM during an actual queued SMTP handoff. | PASS. |

## Closure evidence checklist (completed)

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
`DR-E7-058` decision. The owner explicitly accepted the MVP for test use with
these limitations on 2026-10-18; see [owner acceptance](owner-mvp-walkthrough.md).
