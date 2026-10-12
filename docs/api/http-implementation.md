# Equo — реализация общего HTTP-слоя

| Поле | Значение |
|---|---|
| Назначение | Описать фактическую Symfony-реализацию HTTP-соглашений API v1 и реализованных auth/financial endpoints |
| Статус | Accepted |
| Версия | 13 |
| Дата актуальности | 2026-10-11 |
| Владелец | Maksim Smolkov |
| Источник | HTTP-контракты v2; ADR-012; ADR-015; OQ-015; фактическая реализация |

## 1. Граница реализации

Общий HTTP-слой реализует transport concerns и остаётся отделён от предметной
логики. Реализованы production routes `/api/v1/auth/register`,
`/api/v1/auth/activation-requests`, `/api/v1/auth/activate`,
`/api/v1/auth/login`, `/api/v1/auth/refresh`, `/api/v1/auth/logout`,
`/api/v1/auth/logout-all`, `/api/v1/auth/password-reset-requests`,
`/api/v1/auth/password-reset`, `/api/v1/account/password-change` и `/api/v1/me`; их controllers
используют общий error/response pipeline и вызывают application use case.
JSON-body endpoints дополнительно используют DTO mapping. Также доступен
`/api/health`. Финансовые create/read endpoints SC-011 перечислены в разделе
«Финансовые endpoints SC-011» ниже; Connect теперь возвращает ledger balance.
Fixture routes с префиксом `/api/v1/_test/http` и их controller загружаются
только при `APP_ENV=test`.

OpenAPI bundle не добавлялся: ADR не выбирает машинно-читаемый формат, а
нормативным источником остаются [HTTP-контракты](http-contracts.md).

## 2. Компоненты

| Компонент | Ответственность |
|---|---|
| `RequestIdSubscriber` | Принимает безопасный `X-Request-Id` или создаёт ULID; добавляет итоговый ID в каждый `/api/*` response |
| `JsonContentTypeSubscriber` | До DTO mapping допускает для отмеченных JSON-body endpoints только точный media type `application/json` |
| Symfony `MapRequestPayload` | Декодирует JSON и создаёт typed transport DTO до вызова controller |
| Symfony Validator | Проверяет attributes transport DTO и формирует violation list |
| `ValidationViolationNormalizer` | Преобразует violations в стабильные `field/code/message` и сортирует их |
| `ApiExceptionSubscriber` | Классифицирует transport exceptions, журналирует неожиданные ошибки и создаёт standard envelope |
| `ApiResponseSubscriber` | Сериализует возвращённый controller object/array без зависимости controller от Infrastructure |
| `ApiJsonResponder` | Применяет общий Serializer context и создаёт `application/json` response |

## 3. Поток запроса

```text
HTTP request
  → RequestIdSubscriber
  → routing/controller resolution
  → JsonContentTypeSubscriber(exact application/json)
  → MapRequestPayload(JSON, strict extra fields)
  → transport request DTO
  → Symfony Validator
  → controller
  → application command/use case
```

Future transport DTO располагается в `<Module>\Adapter\Http\Request`, потому что
его Validator attributes являются частью Symfony adapter. Controller преобразует
его в чистую application command/DTO. `Application` и `Domain` не импортируют
Symfony, `Request`, `JsonResponse` или Validator constraints.

Контроллер использует стандартный атрибут:

```php
#[MapRequestPayload(acceptFormat: 'json')]
RegisterHttpRequest $request
```

`JsonContentTypeSubscriber` на `kernel.controller` автоматически находит
параметры с `MapRequestPayload` и выполняет проверку до
разрешения аргументов. Guard сравнивает точный media type без регистрозависимости.
Параметры `application/json`, например `charset=utf-8`, допустимы;
`application/x-json` и `application/*+json` возвращают
`415 UNSUPPORTED_MEDIA_TYPE` согласно публичному контракту.

Serializer global context задаёт `allow_extra_attributes: false`.

## 4. Поток ответа

Controller может вернуть response DTO или array. `ApiResponseSubscriber`
сериализует результат централизованно:

```text
controller/application response DTO
  → ApiResponseSubscriber
  → Symfony Serializer
  → ApiJsonResponder
  → JSON response
  → RequestIdSubscriber добавляет X-Request-Id
```

Правила сериализации:

- имена PHP-свойств response DTO остаются camelCase;
- `DateTimeInterface` переводится в UTC и форматируется как
  `Y-m-d\TH:i:sZ`, например `2026-07-31T12:15:16Z`;
- UUID остаётся строкой;
- backed enum сериализуется строковым value;
- `null` не удаляется;
- пустая collection остаётся `[]`;
- вложенные response DTO сериализуются рекурсивно;
- circular reference не получает публичного fallback и превращается в
  безопасный `500`, поэтому случайный обход графа Doctrine не раскрывается.

Doctrine records не являются response DTO и не передаются из controller.
Architecture test запрещает production controllers зависеть от Doctrine records.
Чувствительные persistence accessors дополнительно помечаются Symfony `#[Ignore]`
как защита от случайной сериализации.
Успешный ответ без тела по-прежнему создаётся обычным Symfony `Response` со
статусом `204` и не проходит object serialization.

`LoginController` и `RefreshController` строят response body явно из allow-list
полей. Refresh token и CSRF token остаются private internal values result DTO;
controller получает их только через callback-метод с обязательным аргументом,
который generic serializer не вызывает.

## 4.1. Реализованный login endpoint

`POST /api/v1/auth/login` принимает `email` и `password`, нормализует email в
Application через `EmailAddress`, проверяет обе login-квоты и затем выполняет
credential lookup. Для отсутствующего пользователя password adapter всё равно
выполняет одну реальную verification по adapter-owned dummy hash; неверный
пароль и неизвестный email возвращают одинаковый `401 INVALID_CREDENTIALS`.
`ACCOUNT_INACTIVE` возвращается только после успешной проверки пароля.

Успешный login в одной PostgreSQL-транзакции создаёт новую `UserSession`,
выпускает access JWT, opaque refresh token и session-bound CSRF token. Каждый
успешный login создаёт отдельную session; предыдущие sessions не переиспользуются
и не отзываются.

Cookies:

- `equo_refresh`: `HttpOnly`, `Secure`, `SameSite=Lax`,
  `Path=/api/v1/auth`, без `Domain`, expires соответствует session expiry;
- `__Host-equo_csrf`: not `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`,
  без `Domain`, expires соответствует session expiry.

Failure responses `401`, `403`, `422`, `429` и technical `500` не устанавливают
auth cookies.

## 4.2. Реализованный refresh endpoint

`POST /api/v1/auth/refresh` не принимает JSON body и не требует
`Content-Type`. Controller вручную читает только cookies/headers через
`RefreshRequestGuard`, затем вызывает application use case.

Guard выполняет проверки в фиксированном порядке:

- missing/empty `equo_refresh` cookie возвращает `401 AUTHENTICATION_REQUIRED`;
- `Origin` должен точно совпасть с `EQUO_APPLICATION_ORIGIN`;
- `Sec-Fetch-Site`, если присутствует, должен быть `same-origin` или `none`;
- CSRF cookie `__Host-equo_csrf` и header `X-CSRF-Token` должны существовать и
  совпасть;
- application use case затем проверяет подпись CSRF token и его привязку к
  найденной refresh-сессии.

Успешный refresh в одной транзакции находит `UserSession` по stable locator
строго разобранного rt2 credential, берёт pessimistic write lock только session
row, сверяет digest полного token через `hash_equals`, проверяет lifecycle
сессии и актуальное состояние пользователя, атомарно заменяет
`refresh_token_hash`, выпускает новый access JWT и новые refresh/CSRF cookies.
`created_at` и `expires_at` сессии не меняются, поэтому refresh не продлевает
30-дневный срок.

Failure responses `401`, `403` и technical `500` не устанавливают auth cookies.

## 4.3. Реализованные logout endpoints

`LogoutController` переиспользует optional-cookie entry point
`RefreshRequestGuard::validateForLogout`: absent refresh cookie пропускает
guard, предъявленная пустая cookie проходит обязательные request checks.
Application проверяет найденную session и её signed CSRF binding под row lock.
`LogoutAllController` принимает ID из `AuthenticatedUser`; application повторно
проверяет active User под User lock и атомарно отзывает unfinished sessions.
Оба controllers возвращают обычный пустой `Response(204)` и прикрепляют
`AuthCookieFactory::clearSessionCookies()` с исходными names/flags/paths.
`403` current logout и `401` logout-all cookies не меняют. Подробная матрица —
в [SC-003](../features/logout-session-revocation.md).

## 4.4. Реализованный Bearer authenticator и `/me`

`GET /api/v1/me` защищён stateless Symfony firewall. Public routes
`/api/health`, `/api/v1/auth/register`, `/api/v1/auth/activation-requests`,
`/api/v1/auth/activate`, `/api/v1/auth/login`, `/api/v1/auth/refresh` и
`/api/v1/auth/logout` остаются
public на уровне Bearer firewall; refresh продолжает использовать собственную
cookie/CSRF/same-origin защиту.

Bearer authenticator применяется к `/api/v1/me` и `/api/v1/auth/logout-all` и принимает только один
`Authorization` header точного вида `Bearer <access-token>`. Token передаётся в
`AccessTokenVerifierPort`; если verification возвращает `null`, дальнейшая
причина не раскрывается. После successful JWT verification backend загружает
текущий профиль пользователя из PostgreSQL через current-user query и проверяет
`isActive = true`. Unknown user, inactive user, malformed header и любая JWT
ошибка возвращают один публичный результат:

```json
{
  "error": {
    "code": "AUTHENTICATION_REQUIRED",
    "message": "Authentication is required.",
    "requestId": "<request-id>"
  }
}
```

Response имеет status `401`, `Content-Type: application/json`,
`X-Request-Id` и `WWW-Authenticate: Bearer`. Raw `AuthenticationException`
message, Authorization header, JWT validation detail, claims and token values не
попадают в response или log context.

Успешный `/me` response строится из текущего server-side `UserView`:

```json
{
  "id": "<uuid>",
  "name": "<name>",
  "email": "<normalized-email>",
  "isActive": true,
  "createdAt": "2026-07-26T18:42:15Z"
}
```

`/me` не устанавливает cookies, не читает refresh cookie, не ротирует
`UserSession`, не выпускает новый access token и не принимает token из query
string или cookie.

## 4.4. Реализованный повторный запрос активации

`POST /api/v1/auth/activation-requests` принимает `email` и `password`.
Application сначала нормализует email, затем до account lookup одновременно
расходует две Symfony RateLimiter sliding-window квоты:

- `activation_request_email`: ключ — SHA-256 от нормализованного email,
  лимит `3` за `1 hour`;
- `activation_request_ip`: ключ — SHA-256 от IP, полученного через
  `Request::getClientIp()`, лимит `20` за `1 hour`.

Email-key применяется одинаково к существующему и неизвестному аккаунту, поэтому
выбор ключа не раскрывает наличие пользователя. IP учитывает forwarded headers
только от `framework.trusted_proxies`; произвольный `X-Forwarded-For` от
недоверенного клиента игнорируется. При исчерпании любой квоты application
возвращает `429 RATE_LIMIT_EXCEEDED` до lookup и транзакции; `Retry-After`
равен максимальному времени ожидания среди отклонённых квот.

После успешной проверки лимитов use case блокирует найденный `User` через
`SELECT FOR UPDATE`. Только неактивный пользователь с верным паролем получает
replacement `ACTIVATE_ACCOUNT`: прежние незавершённые токены инвалидируются,
новый token с TTL 24 часа и зашифрованный outbox intent сохраняются в одной
PostgreSQL-транзакции. Unknown email, wrong password и active account ничего не
изменяют, но имеют тот же `202 activation_email_scheduled`, body и публичные
headers, что допустимый пользователь.

Consumer перед SMTP повторно проверяет token state. Outbox старого replacement,
чей token уже invalidated, переводится в `FAILED`, payload очищается, Mailer не
вызывается.

## 5. Поток ошибки

```text
exception
  → ApiExceptionSubscriber
  → classification по безопасному типу/status
  → structured logging для неожиданного 500 (exceptionClass + requestId)
  → error{code,message,details?,requestId}
  → ApiJsonResponder
  → X-Request-Id header
```

Subscriber применяется только к `/api/*`. Исходное сообщение exception никогда
не используется как публичный message или log field. Production использует
Monolog с JSON output в `stderr`; diagnostic context содержит только ULID запроса
и класс исключения. Raw exception message, stack trace, SQL, filesystem paths,
connection details и secrets не передаются logger.

## 6. Поддержанные технические ошибки

| Условие | HTTP | `error.code` |
|---|---:|---|
| Пустое обязательное тело или malformed JSON | 400 | `INVALID_JSON` |
| Неизвестное JSON-поле или иной bad request | 400 | `INVALID_REQUEST` |
| Нет аутентификации | 401 | `AUTHENTICATION_REQUIRED` |
| Операция запрещена | 403 | `FORBIDDEN` |
| Route/resource отсутствует | 404 | `RESOURCE_NOT_FOUND` |
| Метод не поддерживается route | 405 | `METHOD_NOT_ALLOWED` |
| `Content-Type` не поддерживается | 415 | `UNSUPPORTED_MEDIA_TYPE` |
| DTO/type/constraint validation | 422 | `VALIDATION_ERROR` |
| Нет `If-Match` | 428 | `PRECONDITION_REQUIRED` |
| Rate limit | 429 | `RATE_LIMIT_EXCEEDED` |
| Неверные login credentials | 401 | `INVALID_CREDENTIALS` |
| Refresh cookie отсутствует | 401 | `AUTHENTICATION_REQUIRED` |
| Refresh token malformed/unknown/expired/revoked/replayed | 401 | `INVALID_REFRESH_TOKEN` |
| Bearer token отсутствует, malformed, invalid, expired, unknown/inactive user | 401 | `AUTHENTICATION_REQUIRED` |
| Неактивный аккаунт после верного пароля | 403 | `ACCOUNT_INACTIVE` |
| Непредвиденная или неклассифицированная ошибка | 500 | `INTERNAL_SERVER_ERROR` |

Business-specific `409`/`410`, password, rate-limit и точные
token/idempotency codes первого среза отображаются из стабильного
`ApplicationFailureCode`. Общая инфраструктура не угадывает бизнес-код по
техническому exception message.

## 7. Validation violations

`details.violations` всегда является массивом объектов `field/code/message`.
Ошибки сортируются по этим трём полям, поэтому порядок детерминирован.

- DTO constraint задаёт публичный code через `payload: ['code' => '...']`;
- type mismatch получает `INVALID_TYPE`;
- constraint без отдельного публичного code получает безопасный
  `VALIDATION_ERROR`;
- для обязательных полей используется `REQUIRED`.

Внутреннее имя PHP-класса и UUID-код Symfony constraint в response не попадают.

## 8. Request ID

Входящий `X-Request-Id` сохраняется при соответствии
`[A-Za-z0-9._-]{1,64}`. Пустое, слишком длинное или небезопасное значение
заменяется ULID. Итоговое значение присутствует одновременно в response header и
в `error.requestId`.

## 9. Зависимости

HTTP-фундамент использует минимальный набор Symfony-компонентов и стандартную
Symfony-интеграцию Monolog:

- `symfony/serializer` — object/array/enum/date JSON serialization;
- `symfony/validator` — DTO validation;
- `symfony/uid` — ULID request IDs;
- `symfony/property-access` — необходимый `ObjectNormalizer` для typed DTO;
  он транзитивно добавляет `property-info` и `type-info`.
- `symfony/monolog-bundle` и `monolog/monolog` — production-capable structured
  logging с отдельным каналом `api`.

NelmioApiDocBundle, API Platform и другие API framework/bundle не добавлялись.

## 10. Автоматические проверки

`HttpInfrastructureTest` использует test-only controller и проверяет successful
mapping, malformed/empty JSON, strict content type, missing fields, type mismatch,
несколько violations, unknown fields, UUID/date/enum/null/collection/nested
serialization, request ID, method/route errors, безопасный internal error и
диагностический logging context без raw exception data.
`HealthControllerTest` дополнительно проверяет `X-Request-Id` существующего
healthcheck.

`FirstVerticalSliceHttpTest` проверяет production register/activate routes,
точные response schemas/statuses, validation и malformed JSON, idempotency,
password policy, token lifecycle/capability, rate limits, отсутствие secrets и
фактические изменения PostgreSQL. Процессные integration tests отдельно
проверяют конкурентный replay, unique email и одноразовую активацию.
`LoginHttpTest` проверяет production login route, точный body, JWT, cookies,
validation, safe credential/inactive errors, login rate limits и отсутствие
cookies/session на failure. `RefreshHttpTest`,
`RefreshSessionPersistenceTest` и `RefreshConcurrencyTest` проверяют production
refresh route, отсутствие body/content-type requirement, same-origin/CSRF guard,
cookie rotation, неизменный session expiry, rollback, replay rejection и ровно
одну успешную ротацию при двух конкурентных refresh requests. `MeHttpTest`,
`CurrentUserPersistenceTest` и security unit tests проверяют Bearer parsing,
единый auth failure envelope, fresh current-user lookup, inactive/unknown user
rejection, отсутствие cookies/session rotation на `/me`, token-source discipline
и public endpoint regressions после включения firewall.

`ActivationRequestHttpTest` проверяет единый публичный `202` для четырёх account
веток, `422`, email/IP quotas `3/20`, нормализацию limiter key, недоверенный
`X-Forwarded-For`, `429/Retry-After`, TTL, replacement и отсутствие открытых
секретов. `FirstVerticalSliceTransactionTest` подтверждает rollback token и
`invalidatedAt` при отказе outbox; `FirstVerticalSliceConcurrencyTest` запускает
два процесса против PostgreSQL и проверяет единственный незавершённый token и
полный набор outbox intents; `EmailDeliveryFlowTest` проверяет отказ от отправки
по invalidated token.

Узкий набор запускается командой:

```bash
php bin/phpunit \
  tests/IdentityAccess/Application/ActivationRequest/RequestActivationTest.php \
  tests/IdentityAccess/Adapter/Http/ActivationRequestHttpTest.php \
  tests/Integration/IdentityAccess/FirstVerticalSliceTransactionTest.php \
  tests/Integration/IdentityAccess/FirstVerticalSliceConcurrencyTest.php \
  tests/Integration/EmailDelivery/EmailDeliveryFlowTest.php
```

## 11. Нормативные источники

- [HTTP-контракты API v1](http-contracts.md);
- [ADR-012 и ADR-015](../adr/architecture-decisions.md);
- [OQ-015](../open-questions.md#oq-015).

## Реализованные reset contracts (SC-004)

POST `/api/v1/auth/password-reset-requests` принимает JSON `{email}` и возвращает
одинаковый 202 `{status: "password_reset_email_scheduled"}` для любого корректного
email в пределах quotas. POST `/api/v1/auth/password-reset` принимает
`{token,newPassword}` и возвращает empty 204 с очисткой обеих auth cookies через
AuthCookieFactory. Оба routes публичны и используют общий JSON/validation/request
ID/error pipeline. Request quota — email 3/hour и trusted IP 20/hour; apply делит
IP 10/15min и token hash 5/15min с activation. 429 включает Retry-After.

Новая password policy возвращает 422 VALIDATION_ERROR с violation поля
newPassword и кодом PASSWORD_POLICY_VIOLATION. Неверный/malformed/unknown purpose
даёт 400 INVALID_TOKEN; invalidated/used/expired — соответствующий 410.
Неправильный JSON, media type, DTO fields и неизвестные технические ошибки
обрабатываются существующей safe error model. Failure не очищает cookies.

Reset delivery использует существующий encrypted transactional outbox и relay;
consumer допускает только соответствующие purpose/template пары и генерирует
`/password-reset?token=...`. HTTP privacy tests проверяют отсутствие raw token,
password/hash и внутренних диагностик; [акт SC-004](../acceptance/password-reset.md)
фиксирует exact contracts и сквозную доставку.
## SC-005: защищённая смена пароля

POST /api/v1/account/password-change принимает JSON currentPassword/newPassword
через MapRequestPayload и ChangePasswordRequest. Bearer проверяет active subject;
handler повторно проверяет active и credential после User FOR UPDATE. Success —
пустой 204 и clearSessionCookies; failure не меняет cookies. Missing/invalid/expired
Bearer или unknown/inactive User дают 401 AUTHENTICATION_REQUIRED с challenge;
wrong currentPassword — 401 INVALID_CREDENTIALS без challenge. Validation — 422
с safe field violations; media/JSON/extra fields — общая HTTP error model.
Новые request fingerprints, idempotency rows или email messages не создаются.
[Contract audit](../features/password-change.md), [акт](../acceptance/password-change.md).

## SC-008: пять endpoints

| Endpoint | Доступ | Успех |
|---|---|---|
| POST `/api/v1/connect-invitations` | Bearer | 200: token/url/expiresAt/created |
| GET `/api/v1/connect-invitations/{token}` | Public capability | 200: inviter id/name, expiresAt, canAccept |
| POST `/api/v1/connect-invitations/{token}/accept` | Bearer + capability | 201 новая пара, 200 существующая/повтор; Connect view |
| GET `/api/v1/connects` | Bearer | 200: items и pagination |
| GET `/api/v1/connects/{connectId}` | Bearer + membership | 200: Connect view |

POST endpoints требуют отсутствующее body. Ссылка строится из настроенного
EQUO_APPLICATION_ORIGIN. Cursor — Base64URL JSON createdAt/id, порядок
createdAt DESC/id DESC, limit по умолчанию 20, максимум 100; invalid cursor/limit
дают INVALID_REQUEST. Чужой/отсутствующий Connect — одинаковый 404.
View содержит otherUser id/name/isActive без email, balanceAmount из active ledger (SC-011),
capability flags активности и restriction неактивной стороны.

Ошибки идут через общий envelope/request ID: own — 422 VALIDATION_ERROR,
inactive — 409 INACTIVE_USER, used/expired — 410 TOKEN_USED/TOKEN_EXPIRED,
invalid/unknown capability — 404 RESOURCE_NOT_FOUND. Bearer denial и rate limits
используют существующие 401/429 conventions. Invitation responses имеют
no-store/no-referrer. Nginx exact collection location предотвращает slash redirect POST.

## Финансовые endpoints SC-011

| Endpoint | Доступ и результат |
|---|---|
| POST /api/v1/debts | Active Bearer; Idempotency-Key UUID; JSON title/description/totalAmount/payerId/participantIds/includePayerInSplit; 201 full Debt, Location и ETag версии 1 |
| GET /api/v1/debts | Active Bearer; только active доступные Debt; limit/cursor |
| GET /api/v1/debts/{debtId} | Author/payer/current participant; full Debt и ETag; deleted по прямой ссылке |
| GET /api/v1/connects и /{connectId} | Membership; реальный balance из active Transfer |
| GET /api/v1/connects/{connectId}/transfers | Membership; paginated active/deleted history |
| GET /api/v1/transfers/{transferId} | Membership независимо от Debt ACL; full Transfer и ETag |

Controllers используют общий JSON mapping, error envelope и request ID.
Money и includePayerInSplit проверяются как integer/bool до coercion;
decimal, numeric string, null, zero, overflow и malformed участники отклоняются.
Extra JSON fields запрещены общим serializer context. CreateDebtCommand
нормализует title, UUID и sorted participantIds; author берётся из Bearer.

Idempotency scope user:<subject>, operation debt.create; same retry возвращает
сохранённые status/body/Location/ETag, изменённая команда — 409
IDEMPOTENCY_KEY_REUSED. JSONB может переставить object keys, значения и порядок
массивов сохраняются. Logical TTL не зависит от физического retention cleanup.

Debt inaccessible/missing — одинаковый 404 DEBT_NOT_AVAILABLE; Transfer —
404 TRANSFER_NOT_AVAILABLE; чужая история Connect — 404 RESOURCE_NOT_FOUND.
Invalid selection — 422 INVALID_PARTICIPANT; inactivity — 409 INACTIVE_USER.
Никакие ошибки не оставляют часть финансовой команды в БД.

Все financial responses no-store. Brief users содержат только id/name/isActive.
Safe недоступная debtReference содержит только id/isAccessible=false.
Пагинация createdAt DESC/id DESC, default 20/max 100, opaque Base64URL cursor.
Edit/delete/MANUAL writers на этом этапе не опубликованы.
[Доказательства](../acceptance/create-debt.md).

## SC-016: создание MANUAL

POST /api/v1/connects/{connectId}/transfers принимает senderId/receiverId
(UUID) и amount (JSON integer, 1..2147483647), Bearer и UUID Idempotency-Key.
201 возвращает полный Transfer, Location, ETag "1" и no-store. Чужой или
отсутствующий Connect — 404 RESOURCE_NOT_FOUND; неверная пара/сумма —
422 VALIDATION_ERROR; неактивная сторона — 409 INACTIVE_USER. Ключ обязателен
(400), изменённая команда с тем же ключом — 409 IDEMPOTENCY_KEY_REUSED.
relatedTransferId отложен до SC-018 по mvp-scope §15, неизвестные поля — 400.
Edit/delete маршруты не экспонируются. Денежные DTO не допускают coercion
из numeric string, bool или float. Atomic writer использует существующую
схему без новой миграции. Проверки — features/manual-transfer.md.

## SC-013 — PUT Debt

PUT /api/v1/debts/{id} принимает UpdateDebtRequest с полным редактируемым
состоянием, без payerId и version в body. Strict If-Match защищает aggregate
version; No-op возвращает текущий 200/ETag без записи. Missing/stale — 428/409,
version conflict содержит только expectedVersion/actualVersion. Resource ACL
проверяется до раскрытия versions; неавтор-читатель — 403, outsider — hidden 404.
Frozen/deleted — 409. Response reuse DebtViews и no-store; тело всегда содержит
только текущих participants. Новых migrations и альтернативного API client нет.
