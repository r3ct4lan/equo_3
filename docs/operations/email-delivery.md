# Email delivery and retention runbook

This runbook applies to the outbox and Messenger transports in the repository.
Production deployment has not been exercised; production credentials, SMTP
provider, alert thresholds, backup policy and RPO/RTO remain deployment or owner
decisions.

## Start and inspect services

The service set is Nginx, Nuxt frontend, Symfony backend, PostgreSQL, Redis,
RabbitMQ, the email worker, and the retention scheduler. Apply Doctrine
migrations before enabling traffic. The scheduler waits until migrations report
up-to-date; a failed check or cleanup is retried after five minutes.

```bash
docker compose up -d --build --wait
make migrate
make ps
docker compose logs --tail=100 backend worker retention rabbitmq
```

The commands above use the development Compose configuration. Its sample
credentials and signing keys are development-only. A deployment must supply
unique values for `APP_SECRET`, JWT private/public keys, CSRF key ring, action
token HMAC key ring, invitation signing key ring, idempotency fingerprint key
ring, email payload encryption key, database/Redis/RabbitMQ credentials,
application origins and SMTP DSN/from address. Do not copy example keys or
development passwords into a deployed environment. The exact variable names
and profiles are defined in `compose.yaml`, `docs/security/access-model.md` and
ADR-008/016/017/018/019.

## Backlog and delivery diagnostics

Read aggregate outbox and failure-transport metrics:

```bash
docker compose exec backend php bin/console app:email-outbox:status --no-interaction
```

The JSON includes pending/published counts and oldest ages, terminal failures,
RabbitMQ publish failures, exhausted SMTP retries, and failure-transport size.
It contains no message body, recipient, token, payload, exception or stack trace.
It also includes `alert_status` (`unconfigured`, `ok`, `warning` or `critical`)
and an `alerts` list. With no configured thresholds, metrics are still returned
and status is `unconfigured`. A warning returns process exit code 1; critical
returns 2; an unconfigured or healthy snapshot returns 0.

Set `EQUO_EMAIL_OUTBOX_THRESHOLDS` in the deployment environment to a JSON object
keyed by any metric from the status response. Each configured metric must have
non-negative integer `warning` and `critical` limits, with warning no greater
than critical. The limit is reached inclusively. For example, these
deployment-chosen values illustrate the format only; they are not Equo SLAs or
defaults:

```dotenv
EQUO_EMAIL_OUTBOX_THRESHOLDS={"pending_oldest_age_seconds":{"warning":900,"critical":3600},"failure_transport_count":{"warning":1,"critical":5}}
```

Invalid JSON, unknown metric names, and invalid limits fail closed. The
repository does not prescribe deployment values or periodically check whether
they remain appropriate.

For RabbitMQ queue health and sizes, use the management console or:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages consumers
```

For Messenger failures, list only the safe IDs needed for triage:

```bash
docker compose exec backend php bin/console app:email-outbox:failed --limit=20 --no-interaction
```

The output contains only `transport_message_id` and `delivery_id`. Do not use
`messenger:failed:show` for operator triage because it may display exception
details. Do not copy an action URL or token into logs, tickets, chat, or a shell
history.

## Safe replay and terminal failure

The system guarantees durable intent and at-least-once email delivery. A rare
duplicate email is possible after the SMTP relay accepted a message but before
the database recorded `SENT`; this does not create another user, token, or
financial operation.

Use the two safe IDs from `app:email-outbox:failed` to replay only a still
`PUBLISHED`, non-terminal outbox row whose action token remains deliverable:

```bash
docker compose exec backend php bin/console app:email-outbox:replay <delivery_id> --no-interaction
```

The command takes a row lock and checks current token deliverability and
encrypted payload before publishing the existing delivery ID. It does not
change token state, create business state, reset a terminal row, or print token
material. It refuses a terminal, missing, expired, used, invalidated or
payload-free delivery. The email handler repeats the deliverability check when
it consumes the message.

After a successful replay, remove the obsolete failure-transport copy by its
listed transport ID:

```bash
docker compose exec backend php bin/console messenger:failed:remove <transport_message_id> --transport=failed --force --no-interaction
```

For a terminal `FAILED` row, do not use `messenger:failed:retry`. The outbox row
is terminal and the consumer intentionally ignores it. Remove only the stale
failure-transport envelope after recording the failure for operational follow
up. Recovery for the user proceeds through the normal activation/reset request
flow, which issues a new capability and outbox row.

If a pending delivery is delayed, first inspect `app:email-outbox:status`,
RabbitMQ queue depth and worker logs. The relay retains its retry schedule while
the token is deliverable. The worker and outbox relay recheck the token before
email handoff. Do not update outbox status, encrypted payload, token rows or
financial tables manually in SQL.

## Retention and key rotation

Preview cleanup counts without changing rows:

```bash
docker compose exec backend php bin/console app:retention:cleanup --dry-run --no-interaction
```

Run one bounded cleanup batch manually if needed:

```bash
docker compose exec backend php bin/console app:retention:cleanup --batch-size=500 --no-interaction
```

The scheduled service retries failed cleanup after five minutes and otherwise
runs every 24 hours. Each table is limited independently; a large backlog is
drained by later scheduled runs. Cleanup does not touch financial history.

Rotate keys by adding a new random key and making its version active while
keeping old verification keys in the corresponding ring for the accepted
overlap period. Action-token HMAC keys remain for at least 31 days after their
last issuance; invitation keys remain through the seven-day maximum invitation
TTL; JWT public keys remain for at least 15 minutes and 30 seconds after their
last signature; idempotency fingerprint keys remain for at least 24 hours after
their last use. Keep email payload encryption keys available until all
encrypted outbox payloads using them are terminal or expired. Keys must remain
separate by purpose, outside the repository and database. If a key is lost or
compromised, assess affected in-flight records before removing old verification
material.

There is no accepted backup schedule, RPO, RTO or numeric email SLA in the MVP
requirements. Establish those before a production release if the owner requires
them.
