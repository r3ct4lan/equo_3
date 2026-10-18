# AWS staging deployment

This runbook uses a dedicated Ubuntu 24.04 x86_64 EC2 instance with 4 GiB RAM
and a 30 GiB gp3 root volume. Usage consumes AWS promotional credits; the
instance is not permanently free. Keep the account on its Free Plan if the
owner requires no paid usage. EC2 Security Group permits 22 only from the
operator's IP and 80/443 for HTTP/HTTPS. No other inbound ports are required.

## Configuration

`compose.staging.yaml` is standalone: invoke it with `scripts/staging-compose.sh`,
not by merging it over `compose.yaml`. It inherits the service definitions but
removes development bind mounts and published database/broker ports. PHP uses
`APP_ENV=prod`, `APP_DEBUG=0` and Composer's locked production dependencies.
Nuxt runs its compiled Node output. Nginx serves TLS and routes SSR internally
through port 8080 (not published). Docker logs are rotated.
The worker explicitly uses `SIGTERM`, overriding the PHP base image's `SIGQUIT`,
so its shell entrypoint can forward shutdown to the active Messenger process.

Keep source files in `/opt/equo`, private settings in `/etc/equo/staging.env`
(root-owned, mode 0600), and TLS material in `/etc/letsencrypt`. Transfer only
Git source files, never workstation `.env`, SSH keys, vendor/node_modules or
local data. Production credentials must not enter a Docker build context.

On the dedicated server, install Docker from its official Ubuntu repository:

```sh
sudo sh /opt/equo/scripts/provision-staging-host.sh
sudo python3 /opt/equo/scripts/init-staging-env.py --host YOUR_HOSTNAME
sudo sh /opt/equo/scripts/staging-compose.sh config --quiet
sudo sh /opt/equo/scripts/staging-compose.sh build backend frontend nginx
```

The generator refuses to overwrite credentials. Never run `compose config`
without `--quiet`: the resolved output includes secrets.

## HTTPS without a purchased domain

A name such as `equo.13-63-69-69.sslip.io` resolves to its embedded public IP.
This external DNS service is suitable for a temporary stand; it is not a domain
owned by the operator. If EC2 changes the public IP after a stop/start, update
the hostname, all origin settings, and the certificate before resuming use.
An EC2 reboot normally preserves the public IP. Certificate names are public.

When the public IP changes, first obtain the new hostname's certificate using
the existing HTTP challenge webroot. Update only `APP_HOST`, `DEFAULT_URI`,
`FRONTEND_BASE_URL`, `EQUO_APPLICATION_ORIGIN` and `EQUO_JWT_ISSUER` in the
private environment; preserve credentials/key rings. Recreate backend, worker,
retention and Nginx, then reload Nginx and check public HTTPS. Remove the old
certificate renewal entry once the new hostname works. Users must log in again
on the new origin; previously issued links still contain the old hostname.

Before Nginx starts, request a certificate with port 80 available:

```sh
sudo certbot certonly --standalone --non-interactive --agree-tos \
  --register-unsafely-without-email --preferred-challenges http -d YOUR_HOSTNAME
```

## First launch

Start infrastructure and apply migrations before opening the application:

```sh
sudo sh /opt/equo/scripts/staging-compose.sh up -d --wait postgres redis rabbitmq mailpit
sudo sh /opt/equo/scripts/staging-compose.sh run --rm -T --no-deps backend \
  php bin/console doctrine:migrations:migrate --no-interaction
sudo sh /opt/equo/scripts/staging-compose.sh up -d --wait
sudo sh /opt/equo/scripts/staging-compose.sh ps
```

After Nginx is healthy, switch certificate renewal to webroot (so renewal does
not compete with Nginx for port 80) and install the reload hook:

```sh
sudo certbot reconfigure --cert-name YOUR_HOSTNAME --webroot \
  --webroot-path /var/www/letsencrypt --non-interactive
sudo install -m 0755 /opt/equo/docker/staging/renew-certificate.sh \
  /etc/letsencrypt/renewal-hooks/deploy/equo-nginx
sudo systemctl enable --now certbot.timer
```

Validate public HTTPS, `/api/health`, container health and the scheduler's safe
summary. Do not run destructive test suites against this stack. `make check-full`
uses its separate disposable E2E environment and is a separate verification.

## Email: capture first, external SMTP separately

Initial settings use Mailpit deliberately: no email leaves the server. The UI
is bound only to the host loopback address and mail is persisted in a named
volume. Registration requires activation, so the ordinary external mailbox
journey is unavailable until SMTP is configured. Do not mark external delivery
or complete MVP acceptance as passed based on capture.

For private access to captured staging mail, run locally:

```sh
ssh -i ~/.ssh/equo-staging.pem -N -L 8025:127.0.0.1:8025 ubuntu@SERVER_IP
```

Then open `http://localhost:8025`. Treat messages and action links as sensitive;
do not publish the Mailpit port or paste messages/tokens into logs or reports.

Brevo is a possible free SMTP provider. After owner registration, account
activation and sender verification, connect with an interactive SSH terminal:

```sh
sudo python3 /opt/equo/scripts/configure-staging-brevo.py
sudo sh /opt/equo/scripts/staging-compose.sh up -d --wait backend worker retention
sudo sh /opt/equo/scripts/staging-compose.sh exec -T nginx nginx -s reload
```

This prompts for the verified sender, SMTP login and hidden SMTP key. Credentials
are URL-encoded, written atomically to the existing private environment, and
never printed. It configures TLS on port 465. Existing captured messages are not
automatically resent; request a fresh activation/reset using the normal UI.
Sending real messages and recording delivery evidence remain separate steps.
Reload Nginx after replacing backend or frontend containers so it resolves their
current container IPs.

## Updates and recovery

Record the source revision and any uncommitted deployment patch. Back up the
private environment and persistent volumes to an owner-approved location before
updates involving state. A backup schedule and RPO/RTO have not been agreed.
Retain the prior source/images for rollback; never edit applied migrations.

```sh
sudo sh /opt/equo/scripts/staging-compose.sh build backend frontend nginx
sudo sh /opt/equo/scripts/staging-compose.sh run --rm -T --no-deps backend \
  php bin/console doctrine:migrations:migrate --no-interaction
sudo sh /opt/equo/scripts/staging-compose.sh up -d --wait
```

Avoid `down -v`: it destroys persisted data. Cleanup runs only through the
retention scheduler. See [email delivery runbook](email-delivery.md) for safe
queue diagnostics, replay and key rotation.

## Disposable operational acceptance on the same host

With the staging images already built, run:

```sh
sudo python3 /opt/equo/scripts/test-operations.py
```

The runner refuses an existing `equo-ops-check` project. It generates independent
temporary credentials, uses database `equo_ops_test`, an internal-only Docker
network, no host ports and no staging volume/env mounts. Service memory limits
total approximately 1.9 GiB; run scenarios sequentially and check host headroom
first. It verifies that the backend image ID equals the running staging image.

The SMTP sink discards message content and records counters only. Checks cover a
real first SMTP retry, terminal failure and safe replay, SIGTERM during a delayed
SMTP handoff, and retention via the normal scheduler. Fixtures write only to the
guarded test database. The terminal-attempt fixture carries retry count 5 to
exercise the real worker's exhaustion path without waiting the full 7h21m retry
schedule; all five configured delays are asserted separately. No production
retry intervals or server clock are changed.

The runner removes its containers/network and temporary keys/fixtures in cleanup.
It never removes staging volumes. After an interrupted host/process, inspect the
exact `equo-ops-check` resources before removing leftovers; the next run refuses
to adopt them. These operational checks do not replace `make check-full`.
