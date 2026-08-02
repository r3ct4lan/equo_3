# Equo — локальный quality gate и CI

| Поле | Значение |
|---|---|
| Назначение | Описать единый набор обязательных проверок E1-12 |
| Статус | Accepted; первый GitHub Actions run ещё не выполнен |
| Версия | 1 |
| Дата актуальности | 2026-07-31 |
| Владелец | Maksim Smolkov |
| Источник | E1-12; ADR-001/011/015; backend E1-07—E1-10; frontend E1-11 |

## 1. Единая точка входа

Полный локальный gate запускается из корня репозитория:

```bash
make check
```

Перед запуском должны быть установлены зависимости и поднят основной Compose
stack:

```bash
make build
make install
make up
```

`make install` выполняется до запуска application containers: `npm ci`
пересоздаёт `node_modules`, поэтому target явно откажется работать параллельно с
Nuxt/backend/worker. Для обновления зависимостей работающего stack сначала
выполняется `make down`, затем `make install` и `make up`.

`make check` последовательно выполняет repository, backend, frontend, audit и
smoke-проверки. Скрипт запоминает исходный `git status` и завершится ошибкой,
если проверки создадут или изменят source/generated-файлы. Исходная рабочая
копия не обязана быть чистой: сравнивается состояние до и после gate.

## 2. Локальные команды

| Команда | Назначение | Изменяет source |
|---|---|---:|
| `make check` | Полный обязательный quality gate | Нет |
| `make check-fast` | Repository checks, backend quality, frontend lint/typecheck | Нет |
| `make check-backend` | Backend quality, пустая test-БД, PHPUnit и audit | Нет |
| `make check-frontend` | Lock, ESLint, typecheck, tests, build и audit | Нет |
| `make test` | Backend tests на изолированной БД и frontend unit tests | Нет |
| `make lint` | PHP и TypeScript/Vue style check | Нет |
| `make format` | Исправить PHP и frontend style | **Да** |
| `make audit` | Composer и npm dependency audit | Нет |
| `make smoke` | Проверить `/` и `/api/health` через Nginx | Нет |

Точечные цели: `lint-backend`, `lint-frontend`, `format-backend`,
`format-frontend`, `test-backend`, `test-frontend`, `build-frontend`,
`audit-backend`, `audit-frontend`, `check-repository`.

## 3. Backend gate

`composer check` является общей основой local и CI backend-quality:

1. `composer validate --strict` проверяет manifest и соответствие lock;
2. optimized `dump-autoload` с `--strict-psr --strict-ambiguous` проверяет
   autoload без выполнения application scripts;
3. PHP-CS-Fixer запускается в read-only `check` mode с Symfony rules и
   обязательным `declare(strict_types=1)` для проверяемого PHP-кода;
4. PHPStan анализирует `src`, `tests` и `migrations` на level 8 без baseline;
5. PHPStan использует Symfony container XML, Doctrine metadata и PHPUnit
   extensions;
6. Symfony проверяет container, YAML и routes;
7. Doctrine проверяет mapping без обращения к dev-схеме.

Flex-generated `config/bundles.php` и `config/reference.php` исключены из
PHP-CS-Fixer. `vendor`, `var`, cache и proxy/generated code не анализируются как
project source.

## 4. Frontend gate

Frontend использует один официальный Nuxt flat ESLint config с включёнными
stylistic rules. Отдельные Prettier, Stylelint, UI preset и lint framework не
добавлены.

`npm run check` выполняет:

1. dry-run `npm ci` как проверку согласованности `package.json`/lock;
2. ESLint для TypeScript/Vue с нулевым допустимым количеством warnings;
3. Nuxt TypeScript typecheck;
4. существующие Node unit tests;
5. production Nuxt build.

`.nuxt`, `.output`, `coverage` и `node_modules` исключены из lint.
Автоматические исправления доступны только через `npm run lint:fix` или
`make format-frontend`.

## 5. PHPUnit и миграции

`make test-backend` не использует dev PostgreSQL и named volume
`postgres_data`. Скрипт:

1. удаляет только прежний контейнер service `postgres-test`, если он остался
   после аварийного прерывания;
2. запускает новый PostgreSQL 17 с test-only credentials и `tmpfs` вместо
   volume;
3. применяет все Doctrine migrations к пустой `equo_test`;
4. проверяет `migrations:up-to-date`, status, mapping и синхронность схемы;
5. запускает PHPUnit в `APP_ENV=test`;
6. удаляет временный контейнер через shell trap при успехе и ошибке.

Команда не выполняет `schema:update --force`, не очищает dev-таблицы и не
удаляет Docker volumes.

## 6. Dependency audit

- `composer audit --locked` блокирует любую известную advisory и abandoned
  package; исключений сейчас нет;
- `npm audit --audit-level=moderate` блокирует `moderate`, `high` и `critical`;
- audit не выполняет автоматическое обновление или `audit fix`;
- исключение допустимо только явным изменением с advisory ID, обоснованием,
  владельцем и датой пересмотра;
- недоступность registry/advisory service является видимой ошибкой gate, а не
  успешной проверкой.

## 7. Repository checks

`scripts/check-secrets.sh` проверяет tracked-файлы на распространённые private
key, GitHub, AWS, Slack и live payment token patterns. Это лёгкая штатная
защита, а не полноценная entropy/history scanner. Реальные secrets запрещено
передавать через repository или CI variables этой задачи.

CI и полный локальный gate дополнительно выполняют `git diff`/status check после
инструментов. Кеши и build output должны находиться только в ignored paths.

## 8. GitHub Actions

Workflow: [`.github/workflows/quality.yml`](../.github/workflows/quality.yml).
Он запускается для pull request и push в `main`, а также вручную. Используются
`contents: read`, cancellation устаревшего run, job timeouts, lock-based Composer
и npm caches и test-only environment values.

| Job | Состав | Инфраструктура |
|---|---|---|
| `Repository quality` | common secret patterns, generated diff | Нет |
| `Backend quality` | `composer check` | PHP 8.4 |
| `Backend tests and migrations` | empty migration chain, schema, PHPUnit | PHP 8.4 + PostgreSQL 17 service |
| `Frontend quality and build` | `npm ci`, `npm run check` | Node.js 22 |
| `Dependency audit` | Composer и npm audit | PHP 8.4 + Node.js 22 |
| `Application smoke` | full Compose build/start, Nginx `/` и `/api/health` | Изолированный CI Compose stack |

CI вызывает те же Composer/npm/Make scripts, что local gate. Различаются только
подъём инфраструктуры, test environment и степень параллелизма. Workflow не
использует `pull_request_target`, production secrets или `continue-on-error`.
Smoke job остаётся в `APP_ENV=test`; test-only `BACKEND_DATABASE_URL` указывает
на base name, к которому Symfony добавляет `_test`, а PostgreSQL service сразу
создаёт соответствующую suffixed database.

Первый CI run возможен только после отдельного commit/push. До него нельзя
считать pipeline зелёным.

## 9. Типичные причины падения

| Симптом | Действие |
|---|---|
| PHP/Frontend style diff | Запустить `make format`, проверить diff, повторить `make lint` |
| PHPStan error | Исправить тип/контракт; не добавлять baseline автоматически |
| Lock mismatch | Выполнить целевую Composer/npm операцию и commit обоих manifest/lock |
| Migration/schema mismatch | Добавить новую migration; не применять `schema:update --force` |
| Audit advisory | Оценить advisory и точечно обновить dependency либо оформить временное исключение |
| Smoke failure | Проверить `docker compose ps` и logs соответствующего service |
| Generated diff | Перенести output в ignored path или сделать generator deterministic |

## 10. Branch protection

Branch protection этой задачей не настраивается. Для `main` рекомендуется
потребовать следующие checks после подтверждения их точных GitHub names первым
run:

- `Repository quality`;
- `Backend quality`;
- `Backend tests and migrations`;
- `Frontend quality and build`;
- `Dependency audit`;
- `Application smoke`.

## 11. Сознательно отложено

- полноценный history/entropy secret scanner;
- coverage threshold и публикация coverage artifact;
- Deptrac вместо существующего ADR-015 PHPUnit test;
- mutation, browser E2E, performance и accessibility automation;
- Dependabot/Renovate и автоматическое обновление dependencies;
- branch protection и required checks до первого успешного CI run.
