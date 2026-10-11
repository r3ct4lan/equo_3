# Equo — локальный quality gate и CI

| Поле | Значение |
|---|---|
| Назначение | Описать единый набор обязательных локальных и CI-проверок проекта |
| Статус | Accepted |
| Версия | 13 |
| Дата актуальности | 2026-10-11 |
| Владелец | Maksim Smolkov |
| Источник | ADR-001/011/015; Makefile; Composer/npm scripts; GitHub Actions workflow |

## 1. Единая точка входа

Полный локальный gate запускается из корня репозитория:

```bash
make check
```

Полный gate с реальным browser journey auth-сессии:

```bash
make check-full
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
| `make check-full` | `make check` и изолированный browser E2E auth/session | Нет |
| `make check-fast` | Repository checks, backend quality, frontend lint/typecheck | Нет |
| `make check-backend` | Backend quality, пустая test-БД, PHPUnit и audit | Нет |
| `make check-frontend` | Lock, ESLint, typecheck, tests, build и audit | Нет |
| `make test` | Backend tests на изолированной БД и frontend unit/component tests | Нет |
| `make test-e2e` | Chromium E2E регистрации, активации, login, refresh, reload и защищённого `/me` | Нет |
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

Node.js фиксирован major-веткой 22, npm — точной версией `11.6.2` в
`packageManager`, frontend Dockerfile и CI. Это необходимо, поскольку npm 10 и
npm 11 по-разному проверяют optional transitive dependencies данного lock-файла.

`npm run check` выполняет:

1. dry-run `npm ci` как проверку согласованности `package.json`/lock;
2. ESLint для TypeScript/Vue с нулевым допустимым количеством warnings;
3. Nuxt TypeScript typecheck;
4. Node unit tests и Nuxt component/composable tests в `happy-dom`;
5. production Nuxt build.

Security update 2026-10-09: Vue 3.5.43, simple-git override 4.0.2 и
совместимые transitive updates закреплены lockfile. DevTools 3 использует
удалённый default import simple-git; `postinstall` заменяет его документированной
named factory. Patch идемпотентен и отказывает при неизвестной структуре upstream,
как существующий Nitro/Archiver compatibility patch. Проверяется чистым `npm ci`.

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

## 6. Browser E2E auth/session

`make test-e2e` поднимает отдельный Compose project `equo-3-e2e` и проверяет
полный auth/session путь в Chromium через тот же HTTPS Nginx origin, который
использует пользователь. PostgreSQL, Redis и RabbitMQ работают на `tmpfs`;
Mailpit принимает реальное письмо. E2E build включает backend, worker, frontend и browser image: API и email consumer должны собираться из одной версии исходников. Dev-база, основной Compose stack и named
volumes не затрагиваются.
RabbitMQ запускается и достигает `healthy` до старта остальных временных
зависимостей, чтобы исключить наблюдавшийся resource/startup race Docker Desktop.

Сценарии проверяют полный happy path `register -> activate -> login -> reload
-> authenticated /me`, повторный запрос активации с заменой старой capability,
одинаковый neutral 202 без записей для unknown/active account и UI-поведение
429 `Retry-After` без автоматического retry или persistence write. Также
проверяются client validation и исправление формы, защита от
двойного submit, unknown/used capability, safe redirect после login, login
ошибки без enumeration, refresh координацию двух вкладок через Web Locks, один
refresh/retry budget для protected request, отказ `/me` без valid Bearer token
и отсутствие access/refresh/CSRF/password material в URL, body, storage,
JS-readable cookies и browser diagnostics. При падении сохраняются screenshot и
redacted browser diagnostics; trace и video отключены, чтобы capability или
session material не попали в артефакты. Nginx E2E access log содержит только
`$uri`, без query string.

Скрипт генерирует для каждого запуска временные RSA JWT keys, CSRF signing key
и self-signed TLS certificate в `mktemp` directory, передаёт их только через
environment/mounts временного Compose project и удаляет при завершении. GitHub
Actions не хранит реальные JWT/CSRF/TLS secrets для E2E.

Playwright image переводит Debian package mirror на HTTPS до установки browser
dependencies. Это исключает наблюдавшиеся HTTP proxy/403 ошибки при загрузке
пакетов и не меняет runtime приложения.

E2E Compose project использует увеличенные test-only login/registration quotas,
чтобы полный browser journey не исчерпывал антибрутфорс-лимитер одного
контейнерного IP. Activation-request email quota, напротив, равна нормативным
трём запросам на окно и позволяет проверить четвёртый ответ 429. Production/
default значения не изменяются и проверяются backend HTTP tests.

Для доказательства persistence-инвариантов E2E environment добавляет read-only
test endpoint, возвращающий только агрегаты token/outbox state. Endpoint не
возвращает идентификаторы, hashes, payload или capability и не регистрируется в
обычных `test`, `dev` или `prod` environments.

Скрипт всегда останавливает только свой Compose project через
`down --remove-orphans`, проверяет отсутствие source drift и не вызывает
`down --volumes`.

## 7. Dependency audit

- `composer audit --locked` блокирует любую известную advisory и abandoned
  package; исключений сейчас нет;
- `npm audit --audit-level=moderate` блокирует `moderate`, `high` и `critical`;
- audit не выполняет автоматическое обновление или `audit fix`;
- исключение допустимо только явным изменением с advisory ID, обоснованием,
  владельцем и датой пересмотра;
- недоступность registry/advisory service является видимой ошибкой gate, а не
  успешной проверкой.

На 2026-10-10 после доступных обновлений остаются два корневых advisory:
[braces GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
и [node-forge GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv).
Registry latest 3.0.3 и 1.4.0 соответственно остаются уязвимыми; advisory не
указывает patched version. Nitro 2.13.4 требует globby/fast-glob/micromatch/braces
и listhen/node-forge; @nuxt/nitro-server 4.6.1 также требует Nitro ^2.13.4 и не удаляет эту
цепочку. Audit сообщает 11 high affected packages, но два корневых дефекта,
0 critical и 0 moderate. Исключения/понижение threshold не применены.
Это видимый release blocker; технические проверки поведения выполняются отдельно.

## 8. Repository checks

`scripts/check-secrets.sh` проверяет tracked-файлы на распространённые private
key, GitHub, AWS, Slack и live payment token patterns. Это лёгкая штатная
защита, а не полноценная entropy/history scanner. Реальные secrets запрещено
передавать через repository или CI variables этой задачи.

CI и полный локальный gate дополнительно проверяют полный
`git status --porcelain=v1 --untracked-files=all` после инструментов. Поэтому
проверка обнаруживает изменения tracked-файлов и новые untracked-файлы. Кеши и
build output должны находиться только в ignored paths.

## 9. GitHub Actions

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
| `Auth session browser E2E` | реальная регистрация, доставка email, активация, login, refresh/reload, `/me` и security regressions | Изолированный HTTPS Compose + Chromium + Mailpit |
| `Application smoke` | full Compose build/start, Nginx `/` и `/api/health` | Изолированный CI Compose stack |

CI вызывает те же Composer/npm/Make scripts, что local gate. Различаются только
подъём инфраструктуры, test environment и степень параллелизма. Workflow не
использует `pull_request_target`, production secrets или `continue-on-error`.
Smoke job остаётся в `APP_ENV=test`; test-only `BACKEND_DATABASE_URL` указывает
на base name, к которому Symfony добавляет `_test`, а PostgreSQL service сразу
создаёт соответствующую suffixed database.

CI/Compose передают полный runtime environment и
`APP_RUNTIME_OPTIONS={"disable_dotenv":true}`, а workflow устанавливает
repository npm `11.6.2` до `npm ci`.

При падении E2E job публикует `frontend/test-results/` на 7 дней. В artifact
попадают только screenshots и очищенная диагностика; raw activation token,
JWT, refresh/CSRF token и password намеренно не записываются.

## 10. Типичные причины падения

| Симптом | Действие |
|---|---|
| PHP/Frontend style diff | Запустить `make format`, проверить diff, повторить `make lint` |
| PHPStan error | Исправить тип/контракт; не добавлять baseline автоматически |
| Lock mismatch | Выполнить целевую Composer/npm операцию и commit обоих manifest/lock |
| Migration/schema mismatch | Добавить новую migration; не применять `schema:update --force` |
| Audit advisory | Оценить advisory и точечно обновить dependency либо оформить временное исключение |
| Smoke failure | Проверить `docker compose ps` и logs соответствующего service |
| Browser E2E failure | Скачать redacted artifact, проверить screenshot и safe API paths; локально повторить `make test-e2e` |
| Generated diff | Перенести output в ignored path или сделать generator deterministic |

## 11. Branch protection

Branch protection этой задачей не настраивается. Для `main` рекомендуется
потребовать следующие checks после подтверждения их точных GitHub names первым
run:

- `Repository quality`;
- `Backend quality`;
- `Backend tests and migrations`;
- `Frontend quality and build`;
- `Dependency audit`;
- `Auth session browser E2E`;
- `Application smoke`.

## 12. Сознательно отложено

- полноценный history/entropy secret scanner;
- coverage threshold и публикация coverage artifact;
- Deptrac вместо существующего ADR-015 PHPUnit test;
- mutation, performance и отдельная accessibility automation;
- Dependabot/Renovate и автоматическое обновление dependencies;
- настройка branch protection и required checks для `main`;
- обновление pinned GitHub Actions с Node 20 runtime после отдельной проверки
  новых immutable commit SHA (текущие runs показывают deprecation warning, но
  это не причина падения jobs).

## Дополнение SC-004

Browser suite включает три recovery journeys: активный аккаунт (письмо → reset
→ старый пароль и refresh отвергнуты → login новым паролем), inactive с replaced
reset link и независимой activation, unknown/malformed capability с одинаковым
202 и отсутствием секретов в SSR HTML/storage. Проверяется очистка query,
повторное использование token, завершение другой вкладки и сохранение уже
выданного stateless JWT до exp. Exact quotas проверяет backend HTTP suite.
Текущие числа и результаты полного gate — [акт SC-004](acceptance/password-reset.md).
Audit threshold и политика исключений не менялись.
## Дополнение SC-005

Browser suite включает два password change journeys: новая Unicode password,
отзыв двух independent sessions, завершение sibling tab без refresh после reload,
сохранение stateless JWT до exp, old login failure и new login success; отдельно
client validation, wrong-current и transport failure без automatic retry/logout.
Pending form защищает от double submit. Real DB concurrency проверяет 11 serial
orders change/change, login, refresh, reset, logout-all и current logout через
pg_blocking_pids barrier. Полный make check-full выполнялся и остановился на прежнем
npm audit blocker; отдельные browser E2E и smoke PASS. [Акт SC-005](acceptance/password-change.md).

## Дополнение SC-008

Browser suite включает двухпользовательский путь invite → preview → login →
accept → одна пара у обеих сторон; повтор выдачи сохраняет ссылку и срок.
После реального commit принятия E2E прерывает ответ, затем UI retry получает
тот же Connect с 200. Проверяются чужой Connect/used invitation, safe unknown
preview, copy, отсутствие capability в SSR/storage и no-store/no-referrer.

Скрипт генерирует временный invitation signing key независимо от JWT/CSRF keys.
Invitation paths в Nginx и browser diagnostics очищаются; trace/video отключены.
Текущие результаты и ограничения — [акт SC-008](acceptance/connect-invitations.md).
Audit threshold и политика исключений сохраняются.

Изолированный backend test container получает COMPOSER_PROCESS_TIMEOUT=900,
чтобы полный suite не прерывался стандартным лимитом Composer в 300 секунд.
Runtime приложения и audit policy этим не меняются.

## Дополнение SC-011

Финансовые PHPUnit проверяют Money/aggregate, constraints/round trip,
rollback на каждом шаге и пять real-process DB races с pg_blocking_pids barrier.
HTTP suite покрывает шесть endpoint groups, ETag, 201 replay, pagination ties,
independent Transfer ACL, safe Debt reference и integer balance properties.

Browser suite расширен путём A+B connected → expense create → обе карточки,
shares/history и opposite balances → third-user denial. E2E прерывает ответ
после реального commit, затем explicit retry той же команды получает исходные
201/Location/ETag; список и history содержат ровно одну запись.
Фикстуры явно выходят из прежнего account перед входом третьей стороной,
исключая гонку с автоматическим переходом signed-in login страницы.

Audit policy/threshold не менялись. Полный gate остаётся blocked на прежних
braces/node-forge advisories; отдельные financial/browser tests не заменяют
release gate. [Текущие результаты](acceptance/create-debt.md).
