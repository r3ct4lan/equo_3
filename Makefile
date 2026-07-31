.PHONY: build up down restart logs ps shell-backend shell-frontend migrate test

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart: down up

logs:
	docker compose logs -f

ps:
	docker compose ps

shell-backend:
	docker compose exec backend sh

shell-frontend:
	docker compose exec frontend sh

migrate:
	docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction

test:
	docker compose exec -e APP_ENV=test backend php bin/console doctrine:database:create --if-not-exists --no-interaction
	docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction
	docker compose exec -e APP_ENV=test backend php bin/phpunit
	docker compose exec frontend npm audit --audit-level=moderate
	docker compose exec frontend npm run typecheck
