# SC-022 — Retention and cleanup

| Field | Value |
|---|---|
| Scenario | `MVP-SC-022` |
| Scope | Stage 7, sections 11.1–11.2 |
| Status | Retention implementation verified; stage 7 remains in progress |
| Normative sources | `BR-IDEM-005`, `BR-SEC-005—007`, ADR-013/014 |

## Retention contract

| Record | Eligible for physical cleanup | Active data protection |
|---|---|---|
| `IdempotencyRecord` | At `expiresAt` (24 hours after creation) | Result remains available through the exact 24-hour guarantee window |
| `UserActionToken` | 30 days after use, invalidation, or expiry | Unused, non-invalidated tokens younger than 30 days after expiry remain |
| `ConnectInvitation` | 30 days after use or expiry | Unused invitations remain until 30 days after expiry |
| `UserSession` | 30 days after revocation or expiry | Unrevoked, unexpired sessions remain |
| `EmailDeliveryOutbox` | 30 days after `SENT` or `FAILED` | `PENDING`/`PUBLISHED` rows remain until terminal; encrypted payload is cleared at terminal state or token expiry |

Outbox rows are removed before their referenced action tokens. Cleanup never
targets financial tables. Every record type is processed in stable timestamp
and UUID order, with an independent batch limit from 1 to 5,000. A PostgreSQL
transaction advisory lock prevents concurrent cleanup runs; row locks use
`SKIP LOCKED`, so cleanup can coexist with application workers.

## Operations

The one-shot command is:

```bash
docker compose exec backend php bin/console app:retention:cleanup --no-interaction
```

Use `--dry-run` to print eligible aggregate counts, or set `--batch-size` to a
value from 1 through 5,000. Output includes only counts and record-type labels.
The Compose `retention` service first waits until Doctrine migrations are
up-to-date, then runs cleanup every 24 hours. If migration status or cleanup
fails, it retries after five minutes. `EQUO_RETENTION_BATCH_SIZE` configures its
per-table batch. The database lock also protects a manually started run or a
second scheduler instance. A skipped run is safe to retry on the next schedule.

The scheduled cleanup terminalizes expired `PENDING`/`PUBLISHED` deliveries,
sets a generic diagnostic, and clears ciphertext. The relay and email consumer
also recheck token deliverability, so an expired capability is never replayed.
`SENT` and `FAILED` transitions clear payload in the normal delivery path; the
cleanup job clears any residual payload left by interrupted or historical work.

## Progress

| Task | Status | Evidence |
|---|---|---|
| `DR-E7-001` contract audit | Done | Retention criteria trace to BR/ADR in this matrix |
| `DR-E7-002` retention matrix | Done | Matrix above |
| `DR-E7-003` bounded cleanup | Done | `app:retention:cleanup`, stable order and bounded batches |
| `DR-E7-004` preserve active rows | Done | Integration test retains active token and pending delivery |
| `DR-E7-005` 24-hour idempotency window | Done | Cleanup uses `expires_at`; existing idempotency tests cover replay window |
| `DR-E7-006` 30-day terminal retention | Done | SQL eligibility predicates and integration fixture |
| `DR-E7-007` immediate encrypted payload removal | Done | terminal transitions, expiry cleanup, integration coverage |
| `DR-E7-008` preserve financial history | Done | Cleanup queries are limited to technical tables |
| `DR-E7-009` scheduled invocation and overlap safety | Done | Daily Compose service plus database advisory lock |
| `DR-E7-010` safe dry-run summary | Done | Counts only; no identifiers or sensitive values |

The remaining SC-022 work is tracked in [roadmap stage 7](../development-roadmap.md#11-этап-7--эксплуатация-hardening-и-release-gate) and the [acceptance record](../acceptance/retention-operations.md). This implementation does not close the stage or declare the MVP ready.
