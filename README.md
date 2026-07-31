# Equo 3

Equo 3 is a modular monolith with a Symfony API and a Nuxt frontend. Local
development runs in WSL through Docker Compose, with Nginx as the single entry
point.

## Requirements

- WSL 2
- Docker Desktop with WSL integration enabled for Ubuntu
- Docker Compose v2
- GNU Make (optional; every Make target wraps a Docker Compose command)

## First start

```bash
cd /home/r3ct4lan/projects/equo_3
cp .env.example .env
make build
make up
make migrate
```

The committed example values are development-only. Change them in `.env`
without committing real credentials. If port 80 is already occupied, set
`APP_PORT=8080` and use `http://localhost:8080`.

## Local services

| Service | Address |
| --- | --- |
| Application (Nuxt through Nginx) | http://localhost |
| API healthcheck | http://localhost/api/health |
| RabbitMQ management | http://localhost:15672 |
| Mailpit | http://localhost:8025 |
| PostgreSQL (host access) | localhost:5432 |
| Redis (host access) | localhost:6379 |

## Commands

```bash
make build          # build application images
make up             # start services in the background
make down           # stop services
make restart        # restart the stack
make logs           # follow all logs
make ps             # show container state
make shell-backend  # open a shell in PHP-FPM
make shell-frontend # open a shell in Nuxt
make migrate        # run Doctrine migrations
make test           # run backend and frontend checks
```

Use `docker compose logs <service>` to inspect one service. Run
`docker compose down` (or `make down`) to stop the project without deleting
named data volumes.

## Frontend runtime configuration

The browser calls the API through the same Nginx origin. The committed defaults
are safe development values:

```text
NUXT_PUBLIC_API_BASE=/api
NUXT_API_INTERNAL_BASE=http://nginx/api
```

`NUXT_PUBLIC_API_BASE` is included in the client bundle and must not contain
secrets. `NUXT_API_INTERNAL_BASE` is server-only and lets Nuxt SSR reach the API
inside the deployment network. Override both values through the deployment
environment when the network topology differs.

Frontend checks run inside the existing container:

```bash
docker compose exec frontend npm run test:unit
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run build
```

Implementation and placement rules are documented in
[docs/frontend-foundation.md](docs/frontend-foundation.md).

## Repository structure

```text
backend/       Symfony 7.4 API and Messenger configuration
frontend/      Nuxt 4 TypeScript application
docker/nginx/  Nginx routing configuration
docker/php/    PHP 8.4 FPM image
docs/          Project documentation
compose.yaml   Local development stack
```

## Documentation

The project artifacts and documentation index are available in [docs/README.md](docs/README.md).
