# Техническая проверка выхода и отзыва сессий

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-003`: current logout, logout-all, browser lifecycle и гонки |
| Дата проверки | 2026-10-09 |
| Ветка | `codex/sc-003-logout-session-revocation` |
| Исходная main | `ee7df538a6dc104f837483ee3222cef76b56d8a8` |
| Проверяемый snapshot | Коммит этого акта и его ancestors; implementation SC3-03—07: `bf01e7e`—`f0cd870`; dependency update: `c3bf7d7` |
| Среда | WSL2 Ubuntu, Docker Desktop/Compose, PHP 8.4.24, PostgreSQL 17, Node.js 22, npm 11.6.2, Playwright Chromium, HTTPS Nginx и Mailpit |
| Функциональная проверка | **PASS** — все пользовательские и security/lifecycle branches проверены |
| Итоговая техническая приёмка / SC3-08 | **BLOCKED** — обязательный dependency audit не прошёл |
| Release gate | **NO-GO**; исключения audit не применены |
| Решение Product Owner | Функциональный результат принят 2026-10-09; выпуск и исключения audit не разрешены этим решением |

## 1. Основание и сверка

Пакет передачи сверён с нормативными HTTP 8.6—8.7/18, `UserSession`,
ADR-006/014/015/017/018, OQ-020/022—024, кодом, tests и Git.
[Продуктовый контекст](../product-context.md) содержит provenance пакета и
различие нумерации этапов. SC3-01 и current-revoke core SC3-02 уже существовали;
первым незавершённым этапом оказался SC3-03 репозитория: atomic bulk revoke.
Выполненное повторно не реализовано, продуктовых противоречий не обнаружено.

Нужные поля и индексы уже были в БД. Новых migrations нет. User-first lock
используется login/logout-all; current logout/refresh блокируют только session,
без locking JOIN к User. Проверенные этапы имеют отдельные коммиты.
Остальная разработка MVP и внешняя эксплуатационная приёмка не входят в PASS.

## 2. Матрица критериев

| Критерий | Доказательство | Результат |
|---|---|---|
| Current logout идемпотентен для absent/malformed/unknown/stale/terminal credential | `LogoutCurrentSessionTest`, persistence tests и `LogoutHttpTest` | PASS |
| Origin/Fetch Metadata/double-submit/signature/session binding проверяются до revoke; 403 cookies не меняет | HTTP branches, включая empty refresh и missing CSRF; rollback tests | PASS |
| Exact 204 без body/JSON Content-Type, request ID и обе clear cookies с исходными flags/paths | 24 logout HTTP tests / 466 assertions; browser cookie checks | PASS |
| Logout-all идентифицирует только verified active User; единый 401 для всех auth failures | HTTP missing/malformed/invalid/expired JWT, unknown/inactive User; owner recheck tests | PASS |
| Bulk revoke атомарен и меняет только owned unfinished rows; один timestamp, terminal/foreign rows сохранены | `LogoutAllSessionsTest`, `LogoutAllSessionsPersistenceTest`, rollback и zero-session branches | PASS |
| Refresh после revoke не выдаёт token; stale rotated token может завершить ту же session | HTTP/persistence и оба порядка конкурентных операций | PASS |
| Реальные race outcomes и lock order, без resurrection/deadlock | 9 `LogoutConcurrencyTest`: separate PHP processes, `pg_blocking_pids`, обе очередности refresh/current, refresh/all, login/all, два current и два all, отдельная User-lock probe | PASS |
| Local token/user удаляются, late responses не восстанавливают auth, success event содержит только type | Frontend unit tests: shared lock, latest CSRF после lock, generation guard, exact 204, one refresh/retry budget для all, duplicate-action guard | PASS |
| Current logout без confirmation; all с confirmation, pending/focus/Escape; safe retry и explicit signed-out | Component tests и четыре новых browser journeys | PASS |
| Login → current logout → protected route → reload не восстанавливает session | Browser current-logout journey: cookies удалены, persistent secrets отсутствуют, refresh count 0 | PASS |
| Независимые устройства теряют refresh-доступ после logout-all, JWT не blacklist-ится | Browser independent contexts: прежний JWT получает `/me` 200, refresh получает 401; reload отправляет к login. Expired JWT проверен отдельным HTTP test | PASS |
| Другие вкладки завершают local state без повторного logout/refresh | Browser same-profile tabs: signed-out redirect/reload, оба request counters 0, storage без auth material | PASS |
| Неуспешный server logout остаётся честным unconfirmed state после reload, manual retry подтверждает revoke | Browser simulated 403 → dedicated result → reload без refresh → real retry 204 и clear cookies | PASS |
| Auth/activation regressions, secrets не попадают в storage/URL/diagnostics | Все прежние 12 browser journeys + общие fixtures, rt2 redaction и backend privacy assertions | PASS |
| Обязательный full gate green | npm audit сообщает два корневых advisory без patched release, 11 high affected packages | BLOCKED |

## 3. Фактические проверки

| Проверка | Результат |
|---|---|
| Repository secret patterns / CI config | PASS; workflow YAML валиден |
| Backend Composer manifest/autoload, style, PHPStan, Symfony container/YAML/routes, Doctrine mapping | PASS; 177 PHP files, PHPStan без baseline |
| Пустая изолированная test-БД, migrations и schema parity, полный PHPUnit | PASS: 322 tests / 3362 assertions |
| Focused logout HTTP suite | PASS: 24 tests / 466 assertions (входит в полный PHPUnit) |
| npm ci и повторный postinstall | PASS; lockfile согласован, compatibility patches идемпотентны |
| Frontend lock dry-run, ESLint, Nuxt typecheck | PASS |
| Frontend unit / component | PASS: 40 / 27 tests |
| Production frontend build | PASS |
| `make test-e2e` | PASS: 16/16, 1.5 min; source drift отсутствует |
| Composer audit | PASS: advisories не найдены |
| npm audit, threshold moderate | FAIL: 11 high, 0 critical, 0 moderate; два корневых дефекта |
| `make smoke` | PASS: frontend и `/api/health` через Nginx |
| Финальный `make check-full` | FAIL на `audit-frontend`, после успешных repository/backend/frontend/tests/build; E2E и smoke выполнены отдельно и прошли |

Первый browser запуск дал 15/16: Chromium `Network.getResponseBody` не возвращает
body для 204 после navigation. Browser assertion проверяет status/headers/cookies;
пустое body точно проверяется HTTP suite. После этой test correction весь набор
прошёл. Замечания ESLint к compatibility script исправлены; финальный lint PASS.
Продуктовое поведение для исправления browser assertion не менялось.

Test/E2E PostgreSQL работает на tmpfs в отдельных окружениях. Dev-данные и
named volumes не очищались. JWT/CSRF/TLS keys генерируются временно;
trace/video отключены, diagnostics redacted. В storage сохраняется только
boolean `equo:signed-out`, без tokens/user data.

## 4. Оставшийся блокер и следующий шаг

Доступные security fixes закреплены: Vue 3.5.43, simple-git 4.0.2,
argv-parser 2.0.1, postcss-selector-parser 7.1.6, seroval 1.6.9,
shell-quote 1.12.0 и source-map-js 1.2.2. DevTools использует named factory
simple-git 4 через проверяемый postinstall compatibility patch.

На дату проверки npm registry latest и authoritative advisories не дают
исправленной версии для:

- [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm):
  braces 3.0.3, stack exhaustion на deeply nested patterns;
  цепочка Nitro → globby → fast-glob/micromatch → braces.
- [GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv):
  node-forge 1.4.0, RSA PKCS#1 v1.5 signature verification;
  цепочка Nitro/Nuxt CLI → listhen → node-forge.

Это два корневых дефекта и их affected wrappers, а не 11 независимых дефектов.
Nuxt 4.6.0 также требует Nitro ^2.13.4, который сохраняет обе цепочки;
предложение `npm audit fix --force` не доказывает их устранение. Blind downgrade,
подмена package versions, advisory exemptions и снижение threshold не применены.
Node-forge не используется PHP-профилем JWT Equo; это не отменяет обязательный
audit policy. Следующий шаг — patched upstream releases/проверяемая замена
цепочек либо явное решение владельца о временном исключении по policy
[quality gate](../quality-gate.md#7-dependency-audit), затем повтор full gate.
До этого SC3-08 и общий сценарий не отмечены полностью принятыми.

Уже выданный JWT остаётся пригодным до `exp` (15 минут плюс допустимый skew),
как требует контракт. Browser проверен в Chromium с HTTPS/Web Locks; внешняя
SMTP/staging/production проверка и решение Product Owner не подтверждены.

## Обновление интеграции — 2026-10-10

SC-003/004/005 последовательно merged в main по поручению пользователя,
без конфликтов; snapshot b24fb4f совпадает с проверенной SC-005. Описания
отсутствия merge выше относятся к дате исходного акта и заменены этой записью.
Общая регрессия SC-005: 413 backend / 4340 assertions, 53 unit, 41 component,
21 browser E2E и smoke PASS. Dependency audit остаётся BLOCKED; выпуск,
исключение audit и продуктовая приёмка SC-004/005 этим merge не подтверждены.
