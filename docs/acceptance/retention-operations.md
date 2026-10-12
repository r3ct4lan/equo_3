# SC-022 — Retention implementation evidence

| Field | Value |
|---|---|
| Scope | Retention and cleanup slice (`DR-E7-001—010`) |
| Result | PASS for this slice; full SC-022 and release gate remain open |
| Date | 2026-10-12 |
| Branch | `codex/sc-022-operations-hardening` |

## Evidence

- `make test-backend`: **655 tests, 8,047 assertions passed**. This includes
  retention dry-run, deletion order, active-record preservation, bounded batches
  and repeatability.
- The same command migrated a fresh disposable PostgreSQL database through all
  five migrations; Doctrine mapping and schema synchronization passed.
- Follow-up focused run after adding the dangling outbox/token case:
  **4 retention tests, 27 assertions passed** on another fresh disposable DB.
- `make check-backend-quality`: **PASS** (Composer validation/autoload,
  PHP-CS-Fixer, PHPStan level 8, Symfony container/YAML/routes and Doctrine
  mapping).
- `git diff --check`: **PASS**.
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

This record is not the complete stage-7 acceptance. Email backlog/failure
transport monitoring and safe replay procedure, endpoint/security/consistency
audit, full-product E2E and CI job, frontend/npm gate, deployment review, staging
SMTP, operational policy decisions and the final Product Owner release decision
remain open. No external SMTP delivery or production service was exercised.
