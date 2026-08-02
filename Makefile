COMPOSE := docker compose
BACKEND_EXEC := $(COMPOSE) exec -T -e APP_ENV=test backend
FRONTEND_EXEC := $(COMPOSE) exec -T frontend

.PHONY: audit audit-backend audit-frontend build build-frontend check check-backend check-backend-quality check-fast check-frontend check-frontend-quality check-repository down format format-backend format-frontend install lint lint-backend lint-frontend logs migrate ps restart shell-backend shell-frontend smoke test test-backend test-frontend up

build:
	$(COMPOSE) build

install:
	@if $(COMPOSE) ps --status running --services | grep -Eq '^(backend|frontend|worker)$$'; then echo 'Stop application containers with make down before make install.' >&2; exit 1; fi
	$(COMPOSE) run --rm -T --no-deps backend composer install --no-interaction --prefer-dist --no-scripts
	$(COMPOSE) run --rm -T --no-deps frontend npm ci

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart: down up

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

shell-backend:
	$(COMPOSE) exec backend sh

shell-frontend:
	$(COMPOSE) exec frontend sh

migrate:
	$(COMPOSE) exec backend php bin/console doctrine:migrations:migrate --no-interaction

check:
	./scripts/run-quality-gate.sh

check-fast: check-repository check-backend-quality check-frontend-quality

check-backend: check-backend-quality test-backend audit-backend

check-backend-quality:
	$(BACKEND_EXEC) composer check

check-frontend: check-frontend-quality test-frontend build-frontend audit-frontend

check-frontend-quality:
	$(FRONTEND_EXEC) npm run check:quality

check-repository:
	./scripts/check-secrets.sh
	./scripts/check-ci-config.sh

lint: lint-backend lint-frontend

lint-backend:
	$(BACKEND_EXEC) composer check:style

lint-frontend:
	$(FRONTEND_EXEC) npm run lint

format: format-backend format-frontend

format-backend:
	$(BACKEND_EXEC) composer fix:style

format-frontend:
	$(FRONTEND_EXEC) npm run lint:fix

test: test-backend test-frontend

test-backend:
	./scripts/test-backend.sh

test-frontend:
	$(FRONTEND_EXEC) npm run test:unit

build-frontend:
	$(FRONTEND_EXEC) npm run build

audit: audit-backend audit-frontend

audit-backend:
	$(BACKEND_EXEC) composer audit --locked

audit-frontend:
	$(FRONTEND_EXEC) npm audit --audit-level=moderate

smoke:
	./scripts/smoke.sh
