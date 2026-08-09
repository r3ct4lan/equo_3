# Equo — реализация общего HTTP-слоя

| Поле | Значение |
|---|---|
| Назначение | Описать фактическую Symfony-реализацию HTTP-соглашений API v1 и реализованных auth endpoints |
| Статус | Accepted |
| Версия | 6 |
| Дата актуальности | 2026-08-08 |
| Владелец | Maksim Smolkov |
| Источник | HTTP-контракты v2; ADR-012; ADR-015; OQ-015; фактическая реализация |

## 1. Граница реализации

Общий HTTP-слой реализует transport concerns и остаётся отделён от предметной
логики. Реализованы production routes `/api/v1/auth/register`,
`/api/v1/auth/activate`, `/api/v1/auth/login`, `/api/v1/auth/refresh` и
`/api/v1/me`; их controllers используют общий error/response pipeline и
вызывают application use case. JSON-body endpoints дополнительно используют DTO
mapping. Также доступен `/api/health`.
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

Успешный refresh в одной транзакции находит `UserSession` по digest
предъявленного refresh token, берёт pessimistic write lock, проверяет lifecycle
сессии и актуальное состояние пользователя, атомарно заменяет
`refresh_token_hash`, выпускает новый access JWT и новые refresh/CSRF cookies.
`created_at` и `expires_at` сессии не меняются, поэтому refresh не продлевает
30-дневный срок.

Failure responses `401`, `403` и technical `500` не устанавливают auth cookies.

## 4.3. Реализованный Bearer authenticator и `/me`

`GET /api/v1/me` защищён stateless Symfony firewall. Public routes
`/api/health`, `/api/v1/auth/register`, `/api/v1/auth/activate`,
`/api/v1/auth/login` и `/api/v1/auth/refresh` остаются public на уровне Bearer
firewall; refresh продолжает использовать собственную cookie/CSRF/same-origin
защиту.

Bearer authenticator применяется к `/api/v1/me` и принимает только один
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

## 11. Нормативные источники

- [HTTP-контракты API v1](http-contracts.md);
- [ADR-012 и ADR-015](../adr/architecture-decisions.md);
- [OQ-015](../open-questions.md#oq-015).
