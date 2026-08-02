# Сверка «контракт — реализация» первого вертикального среза (E1-16)

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-001` — первичная регистрация с активацией по первому email |
| Статус | `CONDITIONAL PASS`: AC-001—061 согласованы; локальный gate зелёный, CI rerun требует repository event |
| Дата | 2026-08-02 |
| Ветка / база | `main` / `origin/main` |
| Проверенный диапазон | `a35944b..7b189d3` (`1520825` E1-13, `8419809` E1-14, `7b189d3` E1-15) |
| Рабочая копия до аудита | Чистая; незакоммиченных пользовательских изменений не было |

## 1. Объём и источники истины

Проверены изменения backend/application/domain/persistence/email delivery,
frontend registration/activation, component/browser tests, Compose/CI и
производная документация E1-13—E1-15. В репозитории нет применимого
`AGENTS.md`; найденный файл другого проекта не использовался.

| Аспект | Источник истины |
|---|---|
| Терминология | `docs/glossary/glossary.md` |
| Пользовательский результат | `docs/first-vertical-slice-acceptance.md` |
| Бизнес-поведение | `docs/business-rules/business-rules.md` |
| Данные | ER, модель сущностей и принятые ADR |
| HTTP | `docs/api/http-contracts.md` и решение OQ-015 |
| Архитектура | ADR-001—019 со статусом `Accepted` |
| Доступ | `docs/security/access-model.md` |
| Фактическое состояние | Код, миграции, schema test и автоматические тесты |

## 2. Матрица трассировки AC-001—061

Обозначения тестов: `HTTP` — `FirstVerticalSliceHttpTest`; `APP` — application
tests; `DOM` — domain tests; `DB` — schema/transaction/concurrency tests;
`MAIL` — `EmailDeliveryFlowTest`; `FE-U`/`FE-C` — frontend unit/component;
`E2E-01—04` — Playwright. `Public/capability` означает отсутствие RBAC и
tenant: регистрация публична, activation авторизуется purpose-bound token.

| Критерий | UI | HTTP | Application | Domain | DB | Security | Tests | Статус |
|---|---|---|---|---|---|---|---|---|
| AC-001 полный путь | register → email → activate | `POST register` 201; `POST activate` 204 | `RegisterUser`; `ActivateAccount`; relay/consumer | USR-001—003; SEC-003/006 | U+T+I+O, затем active/used | Public/capability; no session | HTTP, DB, MAIL, E2E-01 | MATCH |
| AC-002 публичная регистрация | activation-required state | register 201, exact body | `RegisterUser` | USR-001/003 | U+T+I+O atomic | Public; no bearer/cookie needed | HTTP, APP, DB | MATCH |
| AC-003 первое письмо | не ждёт SMTP; link | async после 201 | relay + sync consumer | SEC-006 | O: PENDING→PUBLISHED→SENT | encrypted payload; current token | MAIL, E2E-01 | MATCH |
| AC-004 применение token | activation success | activate 204/no body | `ActivateAccount` | USR-002; SEC-003 | U active + T used atomically | capability determines user | APP, HTTP, DB | MATCH |
| AC-005 без сессии | предлагает отдельный sign-in | нет token/cookie | activation не создаёт session | USR-002/003 | `user_session` отсутствует | no implicit auth | HTTP, FE-C, E2E-01 | MATCH |
| AC-006 missing name | field error | 422 `REQUIRED` | не вызывается | — | no write | Public validation | HTTP, FE-U/C, E2E-02 | MATCH |
| AC-007 blank name | field error | 422 `REQUIRED` | не вызывается | `User` rejects blank | DB CHECK | Public validation | DOM, HTTP, FE-U/C | MATCH |
| AC-008 missing email | field error | 422 `REQUIRED` | не вызывается | — | no write | Public validation | HTTP, FE-U/C, E2E-02 | MATCH |
| AC-009 invalid email | field error | 422 `INVALID_EMAIL` | не вызывается | `EmailAddress` | no write | Public validation | DOM, HTTP, FE-U/C | MATCH |
| AC-010 normalize email | показывает server email | register 201 normalized | `RegisterUser` | `EmailAddress` trim/lowercase | normalized UNIQUE | email rate key normalized | DOM, HTTP, DB | MATCH |
| AC-011 missing password | field error | 422 `REQUIRED` | не вызывается | — | no write | plaintext не логируется | HTTP, FE-U/C, E2E-02 | MATCH |
| AC-012 password 12 | submit | register 201 | `RegisterUser` | `PasswordPolicy` min boundary | Symfony hash | raw value не trim | DOM, HTTP | MATCH |
| AC-013 password 128 | submit | register 201 | `RegisterUser` | max boundary | Symfony hash | raw value не trim | DOM, HTTP | MATCH |
| AC-014 password 11 | semantic error | 422 `PASSWORD_POLICY_VIOLATION` | failure before persistence | policy rejects | no write | safe error | DOM, HTTP, FE-U | MATCH |
| AC-015 password 129 | semantic error | 422 `PASSWORD_POLICY_VIOLATION` | failure before persistence | policy rejects | no write | safe error | DOM, HTTP, FE-U | MATCH |
| AC-016 whitespace password | semantic error | 422 `PASSWORD_POLICY_VIOLATION` | failure before persistence | non-space rule | no write | safe error | DOM, HTTP, FE-U | MATCH |
| AC-017 password не trim | raw input | register 201 | hashes original value | code-point policy only | hash verifies only original | plaintext not returned | DOM, HTTP/security | MATCH |
| AC-018 malformed JSON | safe general error | 400 `INVALID_JSON` | не вызывается | — | no write | requestId, no leak | shared HTTP + HTTP | MATCH |
| AC-019 missing token | missing-link/field state | 422 `VALIDATION_ERROR` | не вызывается | — | no change | no fabricated identity | HTTP, FE-U/C | MATCH |
| AC-020 duplicate email | semantic conflict | 409 `EMAIL_ALREADY_EXISTS` | stops before hashing/write | normalized email | UNIQUE fallback | no internal constraint leak | APP, HTTP, DB, FE-C | MATCH |
| AC-021 normalized uniqueness | conflict | 409 after trim/lower | repository + tx mapping | `EmailAddress` | `uniq_app_user_email` | same result for variants | DOM, HTTP, DB | MATCH |
| AC-022 required key | UI generates UUIDv4 | 400 `IDEMPOTENCY_KEY_REQUIRED` | controller guard | — | no write | client cannot set scope | HTTP, FE-U | MATCH |
| AC-023 same key/body | same attempt key | original 201/body | idempotency before rate limit | IDEM-003/005 | one I/U/T/O | scope fixed `public` | HTTP regression, DB, E2E-04 | MATCH |
| AC-024 key/different body | new attempt after edit | 409 `IDEMPOTENCY_KEY_REUSED` | canonical request compare | IDEM-003 | no second set | no request body leak | HTTP, DB, FE-U | MATCH |
| AC-025 concurrent same key | one UI attempt | both obtain original result | advisory xact lock | IDEM-004 | one record/set | server scope/operation | DB process race | MATCH |
| AC-026 exact 24h | — | replay before; new at equality | `DoctrineIdempotency` restart | IDEM-005 | created/expires reset | server clock | DB boundary | MATCH |
| AC-027 concurrent email | — | winner 201, loser 409 | unique exception mapping | USR-001 | email UNIQUE | no SQL/constraint name | DB process race | MATCH |
| AC-028 registration rollback | technical retry state | safe 500 | transaction port | TXN-001 | zero partial rows | no secret leak | DB fault injection, HTTP common | MATCH |
| AC-029 minimal ER fragment | — | 201 only after commit | registration coordination | TXN-001/002 | exact U/T/I/O tables/FK/checks | user IDs server-generated | schema, HTTP, DB | MATCH |
| AC-030 secrets | password cleared; token URL scrubbed | no secret fields | hashing/versioned HMAC/encryption | USR-009; SEC-003/006 | passwordHash, tokenHash, request HMAC, encrypted payload | separate key rings; raw secrets only memory/ciphertext | codec/hasher/payload, HTTP, E2E | MATCH |
| AC-031 UUID/UTC | consumes typed strings | UUID; RFC3339 UTC | `UserView` | immutable createdAt | UUID/TIMESTAMPTZ | request ID separate | HTTP, schema | MATCH |
| AC-032 unrelated data | only auth UI | only two routes | only first-slice services | scoped rules | only four tables | no role/session backdoor | architecture/schema tests | MATCH |
| AC-033 unknown token | invalid-link state | 400 `INVALID_TOKEN` | access denial | SEC-003 | no change | digest lookup, no userId input | APP, HTTP, E2E-03 | MATCH |
| AC-034 expired token | expired state | 410 `TOKEN_EXPIRED` | policy `expiresAt <= now` | SEC-003/005 | no change | server UTC clock | APP boundary, HTTP, FE-U | MATCH |
| AC-035 used token | used state | 410 `TOKEN_USED` | policy denies | one-time token | no second transition | capability consumed | APP, HTTP, DB, E2E-01 | MATCH |
| AC-036 invalidated token | replaced-link state | 410 `TOKEN_INVALIDATED` | policy denies | SEC-004 | no change | current capability only | APP, HTTP, FE-U | MATCH |
| AC-037 wrong purpose | generic invalid | 400 `INVALID_TOKEN` | purpose policy | purpose-bound enum | no change | no existence/purpose leak | APP, HTTP | MATCH |
| AC-038 concurrent token use | one success/one used | 204 + 410 | pessimistic token/user locks | one-time | one atomic transition | same capability serialized | DB process race | MATCH |
| AC-039 activation rollback | technical retry | safe 500 | transaction port | TXN-001 | user/token both rollback | no partial authorization | DB fault injection | MATCH |
| AC-040 exact activation state | success | 204 | `activate(tokenId,userId,now)` | USR-002 | only active/used change | userId from stored token | DOM, HTTP, DB, E2E-01 | MATCH |
| AC-041 registration IP limit | rate message | sixth → 429 + Retry-After | `SymfonyRateLimit` | SEC-007 | no business write | hashed IP key | HTTP boundary | MATCH |
| AC-042 registration email limit | rate message | fourth → 429 | normalized key | SEC-007 | no new set | existence not varied | HTTP isolated key | MATCH |
| AC-043 activation IP limit | rate message | eleventh → 429 | limiter | SEC-007 | token/user unchanged | hashed IP key | HTTP isolated key | MATCH |
| AC-044 activation token limit | rate message | sixth → 429 | limiter | SEC-007 | token/user unchanged | versioned token digest key | HTTP across IPs | MATCH |
| AC-045 all limit keys | one semantic state | reject if any exhausted | combines both decisions | SEC-007 | no domain write | no raw key in error | four HTTP boundaries, FE-U | MATCH |
| AC-046 atomic durable intent | success before email | 201 after commit | `RegisterUser` | SEC-006 | U+T+I+O one tx | ciphertext only | APP, DB rollback, HTTP | MATCH |
| AC-047 publisher confirm | waits asynchronously | response unaffected | `OutboxRelay` dispatch then mark | SEC-006 | PUBLISHED after accepted dispatch | message has deliveryId only | MAIL + real E2E broker | MATCH |
| AC-048 relay retry | no UI loop | response unaffected | exponential defer | SEC-006 | PENDING, attempts, availableAt | safe lastError | MAIL boundaries | MATCH |
| AC-049 stale token not sent | stale link terminal | response unaffected | delivery state query | SEC-004—006 | FAILED + payload clear | current lifecycle recheck | MAIL | MATCH |
| AC-050 SMTP handoff | receives real Mailpit email | original 201 | sync transport handler | SEC-005/006 | SENT/sentAt/payload null | token only decrypted in memory | MAIL, E2E-01 | MATCH |
| AC-051 SMTP retry/failure | no false success | original 201 | retry strategy/subscriber | SEC-006 | PUBLISHED→FAILED, payload clear | safe final error | MAIL | MATCH |
| AC-052 duplicate terminal delivery | no duplicate UI action | — | terminal guard | SEC-006 | SENT/FAILED unchanged | no second decrypt/send | MAIL provider cases | MATCH |
| AC-053 rare duplicate allowed | may receive duplicate | — | at-least-once retry | SEC-006 | business set not duplicated | secrets still protected | MAIL uncertain handoff | MATCH |
| AC-054 no second async loop | one email flow | — | direct `TransportInterface` | SEC-006 | one outbox | no `SendEmailMessage` dispatch | MAIL | MATCH |
| AC-055 initial/loading | fields + disabled pending | exact register call | frontend state only | USR-001 | no pre-submit write | no local authorization | FE-C, E2E-02 | MATCH |
| AC-056 double submit | one request/key | idempotent register | guard + `RegisterUser` | IDEM-003/004 | one set | UUIDv4 attempt key | FE-C, HTTP, DB, E2E-02 | MATCH |
| AC-057 registration result | check-email state | typed 201 response | — | USR-001/002 | durable intent committed | no login/session claim | FE-C, E2E-01/02 | MATCH |
| AC-058 registration errors | field/business/rate states | 422/409/429 envelope | stable failures | USR-009/IDEM/SEC-007 | error-specific no writes | no server text/secrets | FE-U/C, HTTP | MATCH |
| AC-059 activation states | loading/success/sign-in separate | typed `undefined` 204 | `ActivateAccount` | USR-002 | active/used | token scrubbed before POST | FE-C, E2E-01 | MATCH |
| AC-060 token errors | semantic terminal/rate states | 400/410/429 | stable denials | SEC-003/004/007 | no change | no resend, no token display | FE-U/C, HTTP, E2E-03 | MATCH |
| AC-061 safe technical retry | safe message + same key | safe 500/requestId | atomic failure/replay | IDEM-003/004; SEC-006 | full commit or rollback | redacted diagnostics | shared HTTP, DB, FE-C, E2E-04 | MATCH |

## 3. Расхождения и решения

| ID | Уровень | Категория | Источник истины / факт | Влияние и действие | Статус |
|---|---|---|---|---|---|
| E1-16-001 | HIGH | A | AC-023/BR-IDEM-003 требуют original replay; rate limit выполнялся раньше idempotency lookup | replay после нескольких попыток становился 429; idempotency lookup перенесён до rate limit, добавлен HTTP regression | RESOLVED |
| E1-16-002 | HIGH | A | CI должен работать на clean checkout; Symfony требовал ignored `backend/.env` | полный runtime env и `disable_dotenv` заданы CI/Compose | RESOLVED LOCALLY; CI rerun pending |
| E1-16-003 | HIGH | A | CI использует Node 22, Docker — npm 11.6.2; bundled npm 10 отклонял lock | npm 11.6.2 зафиксирован в package metadata/workflow; clean npm test проходит | RESOLVED LOCALLY; CI rerun pending |
| E1-16-004 | MEDIUM | A | HTTP contract использует `AUTHENTICATION_REQUIRED/FORBIDDEN/RESOURCE_NOT_FOUND`, activation response пуст | удалены устаревшие frontend aliases; activation вызов типизирован `undefined` | RESOLVED |
| E1-16-005 | MEDIUM | B | Фактические E1-13 use cases/repositories уже существуют, schema doc называл их будущими | производная схема актуализирована | RESOLVED |
| E1-16-006 | MEDIUM | B | GitHub run уже был и упал, docs говорили «не запускался» | quality/index/test docs отражают run и локальные fixes | RESOLVED |
| E1-16-007 | LOW | C | E1-06 sections 16/18 называли extra-field policy неопределённой, HTTP 2.6/OQ-015 требуют 400 | после решения OQ-020 E1-06 редакционно синхронизирован с HTTP 2.6 | RESOLVED |
| E1-16-008 | HIGH | D | AC-030 требует безопасное password storage; обычный SHA-256 request body создавал fast verifier | принят ADR-019; новые записи используют versioned HMAC отдельным key ring, legacy comparison ограничен TTL старых записей | RESOLVED |
| E1-16-009 | LOW | E | ER camelCase/`User`/varchar против snake_case/`app_user`/TEXT | технические имена и PostgreSQL mapping явно задокументированы, семантика совпадает | ACCEPTED |
| E1-16-010 | LOW | E | Общий checklist access содержит RBAC/tenant/401/403/404 | два endpoint публичны; object access — capability, остальные состояния вне сценария | ACCEPTED |

## 4. Данные, Doctrine и миграции

Реализованы ровно четыре требуемые таблицы: `app_user`,
`user_action_token`, `idempotency_record`, `email_delivery_outbox`. Поля,
UUID PK, TIMESTAMPTZ, nullable-состояния, три FK с `RESTRICT/NO ACTION`, UNIQUE,
CHECK и индексы совпадают с ER-фрагментом. Межмодульные FK представлены в PHP
скалярными UUID и добавляются SchemaTool listener — это принятое техническое
отклонение без изменения кардинальности. Миграция
`Version20260731153000` в E1-13—E1-16 не переписывалась; новых миграций нет.

Итог пустой test schema и Doctrine validation: **см. финальную таблицу проверок
ниже**.

## 5. HTTP и backend/frontend

| Endpoint | Auth | Request | Success | Ошибки |
|---|---|---|---|---|
| `POST /api/v1/auth/register` | Public; rate/idempotency | JSON `name,email,password`; UUID `Idempotency-Key` | 201 JSON exact `user` + `activationRequired:true` | 400 JSON/key/unknown field; 409 email/key conflict; 422 validation/password; 429 + Retry-After; safe 500 |
| `POST /api/v1/auth/activate` | Public capability token | JSON `token` | 204, no body/session | 400 invalid; 410 expired/used/invalidated; 422 missing/type; 429 + Retry-After; safe 500 |
| `GET /api/health` | Public | no body | 200 JSON | standard safe API behavior |

Frontend вызывает `/v1/auth/register` и `/v1/auth/activate` через base `/api`,
передаёт только контрактные поля, JSON headers, credentials и точный
idempotency key. `RegisterResponse`, field violations, error envelope,
`Retry-After`, empty activation response и success/error states согласованы.
Capability удаляется из URL до API-вызова, хранится только в component memory и
не попадает в DOM/cookie/Web Storage. Frontend не принимает решения об
авторизации по локальной роли.

## 6. Выполненные исправления

- `RegisterUser` и HTTP test: replay больше не расходует registration limits и
  всегда возвращает исходный результат в 24-часовом окне.
- CI/Compose/package metadata: clean runtime больше не зависит от ignored
  `backend/.env`, npm одинаков в Docker и GitHub Actions.
- Frontend: точные public error codes и `undefined` для 204.
- Производные schema/quality/test/index документы приведены к фактическому
  состоянию.
- OQ-019 закрыт принятием ADR-019 и реализацией versioned request HMAC;
  OQ-020 закрыт разрешённой редакционной синхронизацией E1-06.

## 7. Результаты проверок

| Проверка | Команда | Exit | Результат |
|---|---|---:|---|
| Regression idempotency/rate ordering | `php bin/phpunit --filter testIdempotentReplayKeepsOriginalResultWithoutConsumingRegistrationRateLimits tests/IdentityAccess/Adapter/Http/FirstVerticalSliceHttpTest.php` | 0 | 1 test, 23 assertions |
| HMAC security + HTTP | targeted `VersionedRequestFingerprintTest` + `FirstVerticalSliceHttpTest` | 0 | 36 tests, 312 assertions; versioning, rotation, legacy cutover, fail-closed и stored HMAC проверены |
| Domain | `php bin/phpunit tests/IdentityAccess/Domain` | 0 | 10 tests, 12 assertions |
| Application | `php bin/phpunit tests/IdentityAccess/Application` | 0 | 9 tests, 48 assertions |
| Persistence integration | `php bin/phpunit tests/Integration/Persistence` | 0 | 11 tests, 21 assertions |
| Frontend unit | `npm run test:unit` | 0 | 16 passed |
| Frontend component | `npm run test:component` | 0 | 9 passed in 3 files |
| Frontend typecheck | `npm run typecheck` | 0 | passed |
| Compose definitions | `docker compose config --quiet`; E2E equivalent | 0 / 0 | both valid |
| Clean Node 22/npm 10 lock reproduction | isolated read-only `npm ci --dry-run` | 1 | expected reproduction: lock rejected because `@emnapi/runtime@1.11.3` was absent |
| Clean Node 22/npm 11 lock check | isolated read-only `npm ci --dry-run` | 0 | 808 packages resolved |
| Первый полный локальный gate | `make check` | 2 | frontend lint found invalid `$api<void>`; changed to `$api<undefined>` |
| Повторный локальный gate | `make check` | 0 | style/static/container/routes/mapping; empty DB; 107 PHPUnit / 862 assertions; frontend lint/typecheck/16 unit/9 component/build/audit; Composer audit; smoke |
| Изолированный browser E2E | `make test-e2e` | 0 | migrations on empty DB; 4 Playwright scenarios passed; source unchanged |
| Первый post-ADR-019 полный gate | `make check-full` | 1 | backend 110/880 и frontend прошли; временный RabbitMQ дважды завершился при одновременном startup до запуска приложения |
| E2E после сериализации startup | `make test-e2e` | 0 | чистая инфраструктура; 4/4 Playwright scenarios; source unchanged |
| Финальный полный gate | `make check-full` | 0 | backend 111/880; frontend 16 unit + 9 component; audits/build/smoke; empty DB/schema; 4/4 E2E; source unchanged |
| Git whitespace check | `git diff --check` | 0 | ошибок нет |
| CI YAML validation | `./scripts/check-ci-config.sh` | 0 | workflow valid |

## 8. Изменённые области E1-16

- backend: idempotency/rate-limit ordering и versioned request fingerprint;
- frontend: exact error identifiers and 204 response type;
- tests: HTTP replay regression;
- CI/configuration: complete clean-checkout runtime env and pinned npm;
- E2E infrastructure: RabbitMQ достигает healthy до параллельного старта
  остальных временных зависимостей;
- documentation: this review, E1-06, implemented schema, quality/test/index
  status, закрытые OQ-019/OQ-020 и accepted ADR-019;
- migrations: none.

## 9. Вывод

Runtime-поведение AC-001—061 согласовано и автоматизировано; AC-030 закрыт
versioned HMAC-профилем ADR-019, а E1-06 согласован с HTTP 2.6. Локальный
`make check-full` проходит. Сценарий готов к E1-17 с единственным внешним
условием: локально исправленный CI должен получить новый repository event после
отдельно разрешённых commit/push.

Dev-база не удалялась и не очищалась; Docker volumes не удалялись. Принятые
бизнес-правила, критерии, HTTP-контракт, ER и accepted ADR не менялись молча.
Commit, push и pull request в E1-16 не выполнялись.
