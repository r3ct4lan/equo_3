# AWS staging deployment — 2026-10-17

Status: **running; operational checks, full CI and owner walkthrough PASS; MVP accepted for test use on 2026-10-18**.

Source: `main` baseline `d113e72` plus deployment commit `b5e81da` from
`codex/aws-staging`, merged through PR #25 as `bba048b`. Full CI evidence is
recorded below. The separate [owner decision](owner-mvp-walkthrough.md) accepts
version `fe5ab40` for test use with the documented limitations.

## Update from main — 2026-10-22

- Deployed exact source `fefba6b67ab298113392bf9d7f835b00ec63b262` from `main`:
  MT-001 through MT-005 and PHP 8.4.27. The earlier owner acceptance below
  remains evidence for its original version; these updates await owner review.
- [CI run 39827129393](https://github.com/r3ct4lan/equo_3/actions/runs/39827129393)
  passed on that exact commit: repository/backend/frontend quality, backend
  tests and migrations, dependency audit, full browser E2E and application smoke.
  No test suite or real test email was run against staging during deployment.
- Current public IP: `16.16.120.166`; current origin:
  **https://equo.16-16-120-166.sslip.io/**. New certificate expires 2027-01-20;
  `certbot.timer` is active. The previous hostname certificate/renewal entry was
  removed after successful HTTPS checks. Old emailed links retain the old host;
  users must sign in again on the new origin.
- Built production images before stopping services. With all staging services
  stopped, saved all four persistent volumes, private environment and previous
  source under root-only `/var/backups/equo/pre-fefba6b`. Previous application
  images retain the `pre-fefba6b` tag. This is a one-time same-server rollback
  backup, not scheduled backup or protection against loss of the EC2 volume.
- Replaced the source directory with a clean Git archive; removed source files
  are not left behind. Runtime source revision is in `/opt/equo/.deployed-revision`.
  Applied `Version20261021120000` before starting application services; the
  subsequent migration check reports up-to-date. Persistent volumes were reused.
- Only hostname/origin/issuer environment values changed; all other settings
  were compared with the private backup and remained identical, including Brevo
  credentials and signing keys. Environment file remains mode 0600.
- Backend reports PHP 8.4.27. Seven services report healthy; worker and retention
  are running without healthchecks. HTTPS home and login return 200; API health
  returns `ok`; HTTP redirects with 308 to the new HTTPS origin. API and login
  were also checked from the workstation with certificate verification enabled.
- Email aggregate diagnostics: zero pending, published, failed, failure-transport,
  relay errors and SMTP failures. External alerts remain unconfigured.
- Temporary source archives and build log removed; rollback backup and image
  tags intentionally retained. No dependency update search was performed.

## Target and observed outcomes

- Owner-provided EC2 public IPv4: `13.50.14.234`.
  On 2026-10-18 the owner reported the new address `13.63.69.69`; SSH access
  and public HTTPS were restored at `https://equo.13-63-69-69.sslip.io/`.
  Updated hostname/origin/issuer settings preserve all credentials and data.
  The replacement certificate expires 2027-01-16; the old certificate and its
  renewal entry were removed after the new HTTPS health response succeeded.
  Before operational tests the host had approximately 3 GiB available RAM,
  22 GiB available disk and two logical CPUs; all staging services were running.
- Ubuntu 24.04 x86_64, approximately 4 GiB RAM. The owner increased EBS from
  8 GiB to 30 GiB; `growpart` and `resize2fs` expanded the mounted root filesystem
  successfully. Approximately 22 GiB remained free after deployment.
- Docker Engine 29.9.1 and Compose 5.6.0 installed from Docker's official Ubuntu
  repository. Docker is enabled at boot; services use `unless-stopped`.
- Built production PHP/Composer, compiled Nuxt and Nginx images on the server.
- Private purpose-specific key rings and database/broker credentials generated
  on the server; environment file is outside the checkout, root-owned, mode 0600.
- PostgreSQL, Redis, RabbitMQ, Mailpit, backend, frontend and Nginx reported
  healthy. Worker and retention reported running (they have no healthcheck).
- Migrations applied before application traffic; subsequent migration status:
  `Up-to-date! No migrations to execute.`
- Public HTTPS `/` returned 200 with successful certificate verification.
- Public `/api/health` returned `{"status":"ok","service":"equo-api"}`.
- Plain HTTP returned 308 to HTTPS.
- Browser showed the Equo home page and `API available equo-api reported ok.`
- Retention scheduler ran successfully; all seven processed record counts were
  zero on the fresh database. This does not demonstrate TTL behavior on aged data.
- Email aggregate diagnostics returned zero pending, published, failed, relay
  errors, SMTP failures and failure-transport records. Alert thresholds remain
  unconfigured; zero counts alone do not demonstrate successful mail delivery.
- Initial worker restart exposed the inherited PHP image `SIGQUIT` stop signal,
  which the worker entrypoint does not handle. The staging Compose now explicitly
  sets `stop_signal: SIGTERM`. After recreation, a restart while Messenger was
  running completed in approximately 0.4 seconds; the latest Docker exit event
  was 0 and the worker resumed. The initial failed stop included exit 137;
  it is not counted as graceful-stop evidence. Queue-drain behavior with actual
  messages remains unverified in this environment.
- Published ports: Nginx 80/443; Mailpit UI only on `127.0.0.1:8025`.
  PostgreSQL, Redis, RabbitMQ, PHP-FPM and Nuxt have no published host ports.
- Let's Encrypt certificate obtained for `equo.13-50-14-234.sslip.io`, expiring
  2027-01-15. Certbot webroot renewal reconfiguration completed successfully
  with its simulated renewal. A deploy hook reloads Nginx; certbot.timer is active.

## SMTP and registration acceptance

1. Owner entered a verified Brevo sender and SMTP credentials directly on the
   host. Backend, worker and retention were recreated with the new settings.
   The initial Mailpit capture is no longer the configured delivery transport.
2. TLS certificate verification and SMTP authentication to Brevo succeeded
   (authentication code 235). With explicit owner permission, one plain test
   email was submitted to the verified sender address; Brevo accepted it. The
   owner confirmed receipt on 2026-10-17. This direct SMTP probe does not exercise
   the Equo transactional outbox or the registration/activation journey.
3. On 2026-10-17, the owner confirmed successful registration through the deployed
   Equo application, receipt of the activation email, activation via its link and
   subsequent login. This is owner-reported manual evidence for the application
   delivery journey; it does not cover delivery failures or retry behavior.

## Owner acceptance and follow-up

The owner confirmed all five [walkthrough steps](owner-mvp-walkthrough.md) and
separately accepted the MVP for test use on 2026-10-18. DR-E7-058 is closed.
Backup/RPO/RTO remain owner decisions; no backup schedule or external alert
delivery has been configured. The temporary hostname and accepted dependency
advisories remain explicit limitations of this decision.

## Operational acceptance — 2026-10-18

Ran `sudo python3 /opt/equo/scripts/test-operations.py` on the same EC2 in the
separate `equo-ops-check` Compose project. The runner asserted identical staging
and probe backend image IDs, independent generated keys/database, an internal
network, no published ports, no staging mounts and per-service resource limits.
It used `APP_ENV=prod`, real PostgreSQL/RabbitMQ and the production worker. The
SMTP peer discarded message contents, recorded counters and injected temporary
421 responses or a delayed acceptance response. No external emails were sent.

| Scenario | Observed outcome |
|---|---|
| Fresh test database | Migrations applied and up-to-date check passed. |
| Temporary SMTP failure | Normal registration created durable intent. Failed handoff retained `PUBLISHED` and encrypted payload. Real retry succeeded after approximately 60 seconds; status `SENT`, payload cleared, one accepted message. |
| Retry policy and exhaustion | All five configured delays asserted: 1m, 5m, 15m, 1h, 6h. An actual queued envelope with fixture retry count 5 reached the real worker's final failure path. `failed_count=1`, `smtp_failure_count=1`, `failure_transport_count=1`; payload cleared. Safe failure listing exposed its delivery ID and replay of the terminal row was refused. |
| Safe replay | Replayed a deliverable `PUBLISHED` intent. Duplicate envelopes did not duplicate users/tokens/outbox rows or SMTP handoff. |
| SIGTERM during work | Stopped the worker while SMTP delayed its acceptance response by 12 seconds. Stop waited approximately 10 seconds, exit code 0, no OOM, delivery `SENT`. Restart did not repeat the completed handoff. |
| Scheduled retention | The normal scheduler deleted 40-day-old terminal token/outbox/session/invitation fixtures and expired idempotency records. Recent/active records remained; expired pending intent became `FAILED` with its payload cleared and token retained. A second scheduler execution preserved these invariants. |
| Cleanup and application availability | Temporary containers/network, test database, keys and fixture files removed. No `equo*` temporary files remained. Staging API returned `ok`; staging services stayed running. |

Limits: the full 7h21m SMTP retry delay chain was not waited in real time; the
first delay was observed, the configured schedule asserted and the last attempt
seeded explicitly. Registration was exercised through the production HTTP kernel
inside the test backend; the owner's separate browser/Brevo evidence covers the
public registration/activation/login journey. Fault scenarios used an isolated
SMTP peer, not a fault injected into Brevo. Alerts remained `unconfigured`;
aggregate failure visibility was verified, not external alert delivery. No host
clock changes, live database edits or financial-data cleanup were performed.

## Full quality gate and merge — 2026-10-18

[Quality gate run 39123689846](https://github.com/r3ct4lan/equo_3/actions/runs/39123689846)
passed on deployment commit `b5e81da`: repository quality, backend quality,
681 backend tests / 8,335 assertions with migrations, frontend quality/build,
dependency audit under the accepted policy, 26 browser E2E scenarios and full
application smoke. Local `make check-full` was not run; GitHub Actions supplied
the documented full suite. No upstream update search or dependency change was
performed. [PR #25](https://github.com/r3ct4lan/equo_3/pull/25) merged the verified
commit into `main` as `bba048b`. Subsequent acceptance/roadmap edits are documentation
only. `DR-E7-054` is closed; the owner subsequently closed `DR-E7-058` through
the separate acceptance recorded above.

Temporary transfer archives, installer copies and deployment capture logs were
removed from the workstation/server after setup. Runtime configuration, private
credentials, certificates and application data remain in their documented paths.

See [AWS staging runbook](../operations/aws-staging.md) for launch, TLS renewal,
private captured-mail access and external SMTP configuration. Operational and
CI evidence closes DR-E7-054; the owner's decision closes DR-E7-058 for test use.
