# Equo — backend первого вертикального среза (E1-13)

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-001` — первичная регистрация с активацией по первому email |
| Статус | Implemented; backend tests and local quality gate verified |
| Дата | 2026-08-02 |
| Область | Symfony backend, PostgreSQL, rate limiting, outbox/RabbitMQ/Mailer и backend-тесты |

## 1. Предварительная проверка готовности

Инициатор, предусловия, входные поля, успешные состояния, правила доступа,
ошибки, HTTP method/URL, schemas, статусы, идемпотентность и конкурентное
поведение однозначно определены документами E1-05/E1-06, HTTP-контрактом,
бизнес-правилами и ADR-006/007/009/011—016. Оба endpoint публичны; отдельные
`401` и `403` к ним неприменимы. Capability активации — действующий
purpose-bound token. Схема E1-08 содержит все четыре необходимые таблицы,
поэтому изменение схемы не требуется.

Подтверждённых противоречий и новых открытых вопросов до начала реализации не
обнаружено. Неописанные ограничения длины `name`/`email` не добавляются.

## 2. Матрица трассировки до реализации

| Критерий приёмки | HTTP endpoint | Application operation | Бизнес-правило | Persistence | Проверка доступа | Тест |
|---|---|---|---|---|---|---|
| AC-001—005 | `POST /auth/register`, `POST /auth/activate` | Register, Activate | BR-USR-001—003, BR-SEC-003/006 | User, token, outbox, idempotency; затем User+token | Public; valid activation capability | Functional + integration + smoke |
| AC-006—011, AC-018—019 | Оба endpoint | Transport validation before operation | BR-USR-001, BR-TXN-002 | No writes on error | Public | HTTP validation tests |
| AC-012—017 | Register | Register | BR-USR-009 | Password hash only | Public | Domain boundary + HTTP tests |
| AC-020—021, AC-027 | Register | Register | BR-USR-001, BR-TXN-002 | Normalized unique email | Public | Application/persistence/HTTP tests |
| AC-022—026, AC-056 | Register | Register with idempotency | BR-IDEM-001—005 | IdempotencyRecord + transaction lock | Public scope only | Application/integration/HTTP tests |
| AC-028—032 | Оба endpoint | Register, Activate | BR-TXN-001—002, BR-SEC-003/006 | Atomic transactions and constraints | Token-derived user only | Integration + safe-error HTTP tests |
| AC-033—040 | Activate | Activate | BR-USR-002, BR-SEC-003—005 | Locked token and user update | Hash, purpose, expiry, used/invalidated | Domain/application/integration/HTTP tests |
| AC-041—045 | Оба endpoint | Rate-limit guard | BR-SEC-007 | Redis-backed sliding windows | IP + normalized email/token digest | Rate-limit unit/HTTP tests |
| AC-046—054 | Register; background flow | Register, relay, send email | BR-SEC-005—006 | Outbox states and encrypted payload | Recheck current token before send | Outbox/consumer integration tests |
| AC-055, AC-057—060 | Не реализуется в E1-13 | API behavior consumed by future UI | Contract error semantics | No extra persistence | Public/capability behavior | Backend HTTP cases here; UI tests in E1-14 |
| AC-061 | Оба endpoint | Register, Activate | BR-IDEM-003—004, BR-TXN-001, BR-SEC-006 | Full commit or rollback | No secret disclosure | Fault-injection + HTTP safe error |

## 3. Фактический поток

```text
POST /api/v1/auth/register
→ RegisterRequest + Validator
→ RegisterController
→ RegisterUser
→ EmailAddress / PasswordPolicy / User / UserActionToken
→ idempotency lock + Doctrine transaction
→ UserRecord + UserActionTokenRecord + encrypted EmailDeliveryOutboxRecord
→ RegisterResult/UserView
→ 201 JSON

POST /api/v1/auth/activate
→ ActivateRequest + Validator
→ ActivateController
→ ActivateAccount
→ capability hash + ActivationAccessPolicy
→ pessimistic token/user lock + Doctrine transaction
→ User activation + token usedAt
→ 204 empty response
```

Контроллеры не обращаются к `EntityManager` и вызывают по одной application
operation. Application не зависит от HTTP и не возвращает Doctrine records.

Основные точки реализации:

- HTTP: `IdentityAccess/Adapter/Http/RegisterController.php`,
  `ActivateController.php` и DTO в `Adapter/Http/Request`;
- use cases: `IdentityAccess/Application/Register/RegisterUser.php` и
  `Application/Activate/ActivateAccount.php`;
- domain: `IdentityAccess/Domain/User/*` и
  `Domain/Access/UserActionToken.php`;
- persistence: `DoctrineIdentityRepository`, `DoctrineIdempotency`,
  `DoctrineEmailOutbox` и `DoctrineTransaction`;
- фон доставки: `OutboxRelay`, `SendUserActionEmailHandler`, retry strategy и
  `app:email-outbox:relay`.

## 4. Реализованные правила и доступ

- email перед сравнением и сохранением нормализуется через `trim` и lowercase;
- пароль содержит 12—128 Unicode code points и хотя бы один непробельный
  символ; password не нормализуется и сохраняется только как Symfony hash;
- новый User неактивен; активация — однонаправленный переход `false → true`;
- activation token содержит 32 случайных байта, versioned HMAC-SHA-256 hash,
  purpose `ACTIVATE_ACCOUNT` и TTL ровно 24 часа;
- raw token отсутствует в API и token-таблице, временно хранится только в
  зашифрованном outbox payload и очищается в конечном состоянии;
- регистрация, token, outbox и сохранённый idempotent response фиксируются одной
  транзакцией; окно replay — 24 часа;
- одинаковый конкурентный key сериализуется PostgreSQL advisory lock, а
  конкурентный email окончательно защищён unique constraint;
- активация блокирует token и User; только одна конкурентная команда применяет
  переход;
- registration rate limits: IP `5/hour`, normalized email `3/day`; activation:
  IP `10/15 min`, token fingerprint/hash `5/15 min`; ответ содержит
  `Retry-After`;
- outbox публикуется с `FOR UPDATE SKIP LOCKED`, RabbitMQ confirm, delivery
  повторно проверяет актуальность token и выполняется at-least-once с безопасным
  duplicate handling и задержками `1m, 5m, 15m, 1h, 6h`.

Оба endpoint по контракту публичны, поэтому `401/403` для них неприменимы.
Право активации задаёт только server-verified capability token: клиентские
`userId`, owner/tenant/role и произвольный Bearer header не используются.
Token связан с User серверной записью, проверяются hash, key version, purpose,
expiry, `usedAt`, `invalidatedAt` и текущее состояние. Это исключает IDOR и не
раскрывает существование чужого аккаунта.

## 5. Persistence и транзакционность

Схема E1-08 оказалась достаточной; новых миграций нет и существующие миграции
не изменялись. Добавлены только необходимые операции: scoped idempotency,
проверка email, атомарное добавление registration set, locked lookup token/User,
фиксация activation state и lifecycle outbox. Raw SQL ограничен механизмами,
которых нет в ORM API: PostgreSQL advisory transaction lock и выборкой outbox
`FOR UPDATE SKIP LOCKED`.

## 6. Соответствие HTTP-контракту

| Элемент контракта | Ожидалось | Реализовано | Тест |
|---|---|---|---|
| Registration URL/method | `POST /api/v1/auth/register` | Точно | `FirstVerticalSliceHttpTest` |
| Registration auth | Public | Security context не требуется | functional + capability test |
| Registration request | `name`, `email`, `password`; UUID `Idempotency-Key` | Строгий DTO, неизвестные поля отклоняются | validation provider, malformed JSON |
| Registration success | `201`, `activationRequired`, public User | Точная схема; UUID и RFC3339 UTC | complete flow/schema test |
| Registration replay | 24h, исходные status/body | Сохранённый response; новый set не создаётся | replay, expiry и process race |
| Duplicate email/key reuse | `409 EMAIL_ALREADY_EXISTS` / `IDEMPOTENCY_KEY_REUSED` | Точно, без DB details | HTTP + process race |
| Password policy | `422 PASSWORD_POLICY_VIOLATION` | Точно | domain boundary + HTTP |
| Activation URL/method | `POST /api/v1/auth/activate` | Точно | complete flow test |
| Activation auth/request | Public capability; body `token` | Token-derived server context | lifecycle/capability tests |
| Activation success | `204`, пустой body, без session | Точно | complete flow + race |
| Invalid token | `400 INVALID_TOKEN` | Unknown/wrong-purpose одинаковы | lifecycle tests |
| Expired/used/invalidated | `410` с точным code | Точно | lifecycle tests |
| Rate limit | `429 RATE_LIMIT_EXCEEDED`, `Retry-After` | Точно для четырёх normative keys | rate-limit functional test |
| Error envelope | E1-09 standard error + requestId | Application failures mapped; unexpected failures safe | functional/infrastructure tests |
| Side effects | Атомарный User/token/outbox/idempotency; activation User+token | Точно | DB assertions, rollback and race tests |

## 7. Автоматизированные проверки

Добавлены:

- domain tests для email, password boundaries и перехода User;
- application tests успешной координации, отказов и отсутствия write после
  ошибки;
- token codec и payload encryption tests;
- HTTP functional suite для точных schema/status/error, malformed JSON,
  validation, secrets, idempotency, lifecycle, rate limits, capability/IDOR и
  `/api/health`;
- persistence/outbox integration tests для связей, шифрования, publish/send,
  rollback, stale token, retry/final failure и cleanup payload;
- реальные двухпроцессные PostgreSQL races для `AC-025`, `AC-027` и `AC-038`.

Проверочные команды:

```bash
docker compose run --rm -T backend composer check
sh scripts/test-backend.sh
make check
```

`scripts/test-backend.sh` поднимает отдельный disposable `postgres-test`,
применяет миграции с нуля, проверяет mapping/schema и запускает полный PHPUnit.
Финальный запуск: `93 tests, 722 assertions`; `composer check`, Composer/npm
audits, frontend regression/build, `/api/health` smoke и полный E1-12 gate
завершились с exit code `0`. Новый route дополнительно проверен через Nginx
неизменяющим запросом: отсутствие `Idempotency-Key` вернуло ожидаемый
`400 IDEMPOTENCY_KEY_REQUIRED`.

## 8. Ограничения и открытые вопросы

Новых противоречий и открытых вопросов не обнаружено. Сознательно оставлены за
границей E1-13:

- UI регистрации/активации и frontend-тесты `AC-055`, `AC-057—060` — E1-14;
- resend activation, session issuance после activation и другие сценарии;
- production provider/секреты и эксплуатационный запуск постоянных worker;
- retention cleanup как отдельная эксплуатационная задача.

Frontend не изменялся. Dev-база и Docker volumes не очищались; изолированные
тесты удаляли только собственный disposable container `postgres-test`.
