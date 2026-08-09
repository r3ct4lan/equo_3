# Login и защищённая сессия

| Поле | Значение |
|---|---|
| Название | Login и защищённая browser-сессия |
| Статус | Implemented and verified / Ready to merge |
| Дата аудита | 2026-08-10 |
| Связанный сценарий | `MVP-SC-002` |
| Диапазон задач | `DR-E1-001—053` |
| Feature-ветка | `feature/login-protected-session` |

## 1. Пользовательский результат

Активный пользователь входит по нормализованному email и паролю, получает
короткоживущий JWT access token, а browser получает защищённую refresh-сессию в
`HttpOnly` cookie. Backend поддерживает refresh rotation: browser может
предъявить refresh/CSRF cookies и получить новый access token без продления
30-дневного срока сессии. Backend также поддерживает Bearer authentication и
`GET /api/v1/me`: защищённый запрос проверяет RS256 JWT, перечитывает текущего
пользователя из БД и блокирует inactive/unknown user единым `401
AUTHENTICATION_REQUIRED`. Frontend реализует login/session lifecycle: вход,
client-only bootstrap `refresh -> /me`, защищённый `/me` route и один refresh
retry для защищённых API-запросов.

В фичу не входят logout, logout-all, reset/change password, изменение профиля,
деактивация и финансовые страницы. Минимальная защищённая `/me`-страница нужна
только как доказательство сессии.

## 2. Матрица нормативных требований

| Требование | Нормативный источник | `DR-E1-*` | Планируемый компонент | Обязательная проверка |
|---|---|---|---|---|
| `UserSession` хранит серверное состояние refresh-сессии 30 дней | MVP-SC-002, BR-SEC-001, `UserSession`, ADR-006/014 | 001—006 | Doctrine record, mapping, migration | Schema/mapping parity; в БД нет открытого refresh token |
| Login проверяет нормализованный email, пароль и `isActive` | BR-USR-003/009, HTTP 8.4, ADR-006 | 008—010, 023, 025, 030 | Login application service, password hasher port, controller | HTTP tests для success, `INVALID_CREDENTIALS`, `ACCOUNT_INACTIVE`, rate limits и отсутствия enumeration |
| Refresh rotation выполняется под lock и атомарно заменяет хэш | BR-SEC-002, `UserSession`, HTTP 8.5 | 007, 011—013, 026, 043 | Refresh application service, transaction boundary | Integration и concurrency tests: ровно один из двух конкурентных refresh успешен |
| JWT соответствует принятому RS256 profile | BR-SEC-001, HTTP 2.3, ADR-017 | 016—018, 041 | JWT signer/verifier adapter | Unit tests claims, headers, key lookup, alg allow-list, issuer/audience и expiry |
| Current user и `/me` загружают актуального пользователя | HTTP 10.1, access model, ADR-006/017 | 014, 015, 019, 027, 028 | Symfony authenticator, current-user boundary, `/me` controller | HTTP tests `200`, единый `401 AUTHENTICATION_REQUIRED`, inactive/unknown user rejection |
| Cookies имеют точные атрибуты | HTTP 2.3/8.4/8.5, ADR-018 | 020, 026, 044 | Cookie issuer/clearer | HTTP tests `Set-Cookie` для refresh и CSRF cookies |
| CSRF и same-origin защищают cookie-authenticated refresh | HTTP 2.3/8.5, ADR-018 | 021, 022, 044 | Origin/Fetch Metadata/CSRF verifier | HTTP tests wrong/missing `Origin`, `Sec-Fetch-Site`, header/cookie mismatch и bad signature |
| Login rate limits используют sliding window | BR-SEC-007, HTTP 17, ADR-014 | 023, 030, 044 | Symfony RateLimiter policies | HTTP tests квот email+IP и IP, точный `Retry-After`, no enumeration |
| Frontend bootstrap выполняет `refresh → /me` после hydration | HTTP 2.3, ADR-018, frontend foundation | 032—037, 045 | In-memory token holder, session bootstrap composable, route middleware | Component tests states `unknown/authenticated/anonymous/error` |
| Межвкладочная координация не передаёт token | ADR-018 | 035, 036, 039, 045 | Web Locks based refresh mutex plus token-free session events | Component/browser tests без token в messages/storage |
| Sensitive-data protection исключает secrets из responses/logs/storage | BR-SEC-001/007, HTTP 3, ADR-017/018, access model | 024, 039, 047 | DTO boundaries, logging redaction, frontend storage discipline | Security regression: нет password, refresh, CSRF secret, JWT, hashes в body/logs/URL/storage |
| Browser E2E доказывает реальный reload-flow | MVP-SC-002, HTTP 8.4/8.5/10.1, ADR-018 | 046, 051, 052 | Playwright scenario | `register → activate → login → reload → authenticated /me` |

## 3. Data contract

Предполагаемая таблица `user_session`:

| Поле | SQL-тип | Null | Назначение |
|---|---|---:|---|
| `id` | `uuid` | нет | PK refresh-сессии |
| `user_id` | `uuid` | нет | FK на `app_user.id` |
| `refresh_token_hash` | `varchar(255)` | нет | Криптографический хэш текущего opaque refresh token |
| `created_at` | `timestamptz` | нет | Время создания |
| `expires_at` | `timestamptz` | нет | `created_at + 30 days` |
| `revoked_at` | `timestamptz` | да | Время явного отзыва |

Ключи и индексы:

- `PRIMARY KEY (id)`;
- `FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE RESTRICT`;
- `UNIQUE (refresh_token_hash)`;
- индекс по `user_id` для пользовательских сессий и массового отзыва;
- индекс по `expires_at` для очистки истёкших записей;
- индекс по `revoked_at` для очистки/поиска отозванных записей.

Нормативный CHECK constraint для `UserSession`: `expires_at > created_at`.
Дополнительный CHECK для `revoked_at` не добавляется. `UserSession` terminal
state определяется доменным lifecycle: сессия недействительна при
`revokedAt IS NOT NULL` либо `expiresAt <= now`; база фиксирует только
структурный инвариант `expires_at > created_at`. См. решённый `OQ-020`.

Правила хранения и транзакций:

- открытый refresh token никогда не хранится в БД, logs, responses, frontend
  state или URL;
- срок refresh-сессии равен 30 дням;
- login создаёт `UserSession`, первый `refresh_token_hash` и response cookies в
  одной бизнес-транзакции до выдачи открытого token клиенту;
- refresh реализован и выполняется в транзакции с pessimistic lock строки `UserSession`,
  проверкой текущего хэша, актуального lifecycle и `User.isActive`, после чего
  атомарно заменяет `refresh_token_hash`;
- старый refresh token после успешной ротации не принимается.

## 4. Session lifecycle

| Состояние/переход | Поведение |
|---|---|
| Создание при login | После успешной проверки email/password и `User.isActive = true` создаётся новая `UserSession` со сроком 30 дней, refresh cookie и CSRF cookie. |
| Успешная ротация | Сервер находит сессию по хэшу предъявленного token, блокирует строку, проверяет `expiresAt`, `revokedAt`, пользователя и CSRF/same-origin, затем заменяет хэш и выдаёт новые cookies/access token. |
| Истечение | `expiresAt <= now` делает сессию недействительной; новый access token не выдаётся. |
| Отзыв | `revokedAt IS NOT NULL` делает сессию недействительной. Logout/logout-all и security changes реализуются в будущих этапах, не здесь. |
| Повтор старого refresh token | Старый token отклоняется как недействительный refresh; silent fallback запрещён. Нормативные документы не требуют автоматический family-wide revoke при replay. |
| Refresh неактивного пользователя | После lookup/lock сервер загружает актуального `User`; `isActive = false` возвращает `403 ACCOUNT_INACTIVE` и не ротирует session. |
| Два конкурентных refresh | Оба используют один старый token; строка сессии сериализуется lock. Первый успешный запрос заменяет хэш, второй после lock видит mismatch/недействительный token и отклоняется. |

## 5. JWT profile

JWT access token:

- подписывается только `RS256`;
- RSA private key имеет не менее 2048 бит;
- header содержит `alg=RS256`, `typ=at+jwt`, `kid=<keyVersion>`;
- verifier использует локальный public-key ring и allow-list ровно `RS256`;
- обязательные claims: `iss`, `aud`, `sub`, `iat`, `exp`, `jti`;
- `sub` является UUID пользователя;
- `exp = iat + 900 seconds`;
- допустимый clock skew не более 30 секунд;
- `iss` и `aud` сравниваются с точными значениями из окружения;
- неизвестный `kid`, отсутствующий claim, неверный subject, неверная подпись,
  истечение или любой alg вне allow-list отклоняются;
- JWT не содержит email, password hash, refresh token, роли или другие
  персональные/изменяемые данные;
- после JWT validation backend загружает актуального `User` по `sub` и повторно
  проверяет `isActive`;
- отсутствующий, malformed, expired или иначе invalid access token, unknown user
  и inactive user на protected endpoint получают единый `401
  AUTHENTICATION_REQUIRED`; `/me` не использует `403`;
- собственная реализация JWT, RSA, ASN.1 или base64url запрещена.

## 6. HTTP contract

Все ответы используют JSON, `camelCase`, единый error envelope и `X-Request-Id`.
Ошибки не раскрывают password, token, cookie, SQL, stack trace или существование
аккаунта сверх нормативного `ACCOUNT_INACTIVE` после успешной проверки пароля.

### `POST /api/v1/auth/login`

Request:

```json
{
  "email": "maxim@example.com",
  "password": "correct horse battery staple"
}
```

Success:

```http
200 OK
Set-Cookie: equo_refresh=<token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
Set-Cookie: __Host-equo_csrf=<signed-session-bound-token>; Secure; SameSite=Lax; Path=/
```

```json
{
  "accessToken": "<jwt>",
  "expiresIn": 900,
  "user": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim",
    "email": "maxim@example.com",
    "isActive": true
  }
}
```

Errors: `401 INVALID_CREDENTIALS`, `403 ACCOUNT_INACTIVE`,
`429 RATE_LIMIT_EXCEEDED`. `ACCOUNT_INACTIVE` is returned only after correct
email/password, so invalid credentials and unknown email remain indistinguishable.

### `POST /api/v1/auth/refresh`

Request:

```http
POST /api/v1/auth/refresh
Origin: <application-origin>
Sec-Fetch-Site: same-origin
X-CSRF-Token: <csrf-token>
Cookie: equo_refresh=<token>; __Host-equo_csrf=<csrf-token>
```

Success:

```http
200 OK
Set-Cookie: equo_refresh=<new-token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
Set-Cookie: __Host-equo_csrf=<new-signed-session-bound-token>; Secure; SameSite=Lax; Path=/
```

```json
{
  "accessToken": "<new-jwt>",
  "expiresIn": 900
}
```

Errors: `401 AUTHENTICATION_REQUIRED`, `401 INVALID_REFRESH_TOKEN`,
`403 ACCOUNT_INACTIVE`, `403 FORBIDDEN`.

Normative distinction:

- missing `equo_refresh` cookie means no refresh authentication is presented:
  `401 AUTHENTICATION_REQUIRED`;
- presented but unknown, mismatched, expired, revoked or already-rotated refresh
  token means `401 INVALID_REFRESH_TOKEN`;
- failed same-origin/CSRF checks before rotation or revocation mean
  `403 FORBIDDEN`.

### `GET /api/v1/me`

Request:

```http
GET /api/v1/me
Authorization: Bearer <access-token>
```

Success:

```json
{
  "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
  "name": "Maxim",
  "email": "maxim@example.com",
  "isActive": true,
  "createdAt": "2026-07-26T18:42:15Z"
}
```

Errors: missing, malformed, expired or invalid access token, unknown user and
inactive current user return the same `401 AUTHENTICATION_REQUIRED`. The
response does not reveal JWT validation details or account state.

## 7. Cookie и CSRF profile

Refresh cookie:

```http
Set-Cookie: equo_refresh=<token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
```

CSRF cookie:

```http
Set-Cookie: __Host-equo_csrf=<signed-session-bound-token>; Secure; SameSite=Lax; Path=/
```

CSRF cookie has no `Domain` and no `HttpOnly`; frontend reads it only to copy the
value into `X-CSRF-Token`. Refresh with refresh cookie requires all checks in
this order:

- refresh cookie `equo_refresh` must be present, otherwise
  `401 AUTHENTICATION_REQUIRED`;
- exact `Origin` match with configured application origin;
- `Sec-Fetch-Site` is either `same-origin` or `none` when present;
- credentialed CORS is not allowed for untrusted origins;
- `X-CSRF-Token` equals the CSRF cookie value;
- CSRF token signature is valid and bound to the concrete refresh session;
- refresh rotation updates both cookies.

Logout is still outside the implemented scope.

`SameSite=Lax` is defense in depth, not a replacement for Origin, Fetch Metadata
or signed session-bound double-submit CSRF.

## 8. Rate limits

Login uses sliding-window limits:

| Key | Limit |
|---|---:|
| Normalized email + IP | 5 requests / 15 minutes |
| IP | 30 requests / 15 minutes |

If either quota is exhausted, response is:

```http
429 Too Many Requests
Retry-After: <exact seconds until quota is available>
```

Body uses `RATE_LIMIT_EXCEEDED`. The email-based key must not disclose whether
the account exists.

## 9. Frontend lifecycle

Implemented frontend session rules:

- access token lives only in client-side memory of the current tab;
- no Web Storage, IndexedDB, persisted store, JS-readable token cookie, Nuxt
  `useState`, SSR payload, URL or browser logs may contain access/refresh/CSRF
  secrets;
- after hydration bootstrap starts from `unknown`, performs single-flight
  `POST /api/v1/auth/refresh`, stores access token in memory, then calls
  `GET /api/v1/me`;
- final states are `unknown`, `authenticated`, `anonymous`, `error`;
- `401` from refresh means `anonymous`; transport/`5xx` means `error`, not
  anonymous;
- protected route middleware waits for bootstrap before redirect;
- after `401` from protected API, client may perform at most one refresh and one
  retry of the original request;
- no automatic refresh on `403`, transport error or unsuitable response;
- minimal protected `/me` screen proves current session only;
- login validates only required email/password and email syntax; password policy
  remains registration-only and backend-authoritative;
- login maps `INVALID_CREDENTIALS`, `ACCOUNT_INACTIVE`,
  `RATE_LIMIT_EXCEEDED`, validation and technical failures to safe UI states.

`frontend/app/plugins/api.ts` no longer mutates current-user state on generic
`401`. Auth-aware handling lives in `frontend/app/utils/auth-session.ts` and
`frontend/app/plugins/auth.client.ts`: bootstrap owns refresh `401`; protected
request retry owns eligible access-token `401`; generic API errors do not bypass
the single refresh/retry budget or collapse transport errors into anonymous
state.

Implemented browser primitive for cross-tab refresh coordination: Web Locks API
with the origin-scoped lock name `equo:auth-refresh`. The CSRF cookie is read
only after the lock is acquired. If Web Locks is unavailable, refresh fails
safely without sending a refresh request. `BroadcastChannel` is used only for
token-free `session-ended` events and never carries access token, refresh token
or CSRF token.

## 10. Security primitives implementation progress

Implemented in the security-primitives stage:

- Application ports and DTO for access token issue/verification, refresh token
  issue/digest and session-bound CSRF token issue/verification;
- RS256 JWT adapter around `lcobucci/jwt`;
- opaque refresh-token codec;
- versioned HMAC-SHA-256 CSRF token codec;
- DI bindings and safe env placeholders;
- unit/container/HTTP regression tests proving the primitives and confirming
  existing public routes remain public.

Implemented in the session-persistence stage:

- `UserSession` domain lifecycle;
- `user_session` migration and Doctrine mapping;
- application repository port and Doctrine adapter;
- pessimistic lock lookup used by implemented refresh rotation;
- hash-only storage, serializer protection, schema parity and rollback tests.

Implemented in the backend-login stage:

- `POST /api/v1/auth/login` controller and typed request DTO;
- normalized-email credential lookup through an Application DTO, not
  `UserRecord`;
- official Symfony password verification with adapter-owned dummy verification
  for unknown users;
- login rate limits by normalized email+IP and by IP;
- atomic `UserSession` creation, access JWT issuance, refresh token issuance and
  session-bound CSRF issuance;
- exact refresh and CSRF cookies via a narrow HTTP cookie factory;
- HTTP, integration, application and sensitive-data tests.

Implemented in the backend-refresh stage:

- `POST /api/v1/auth/refresh` controller;
- refresh-cookie reading, Origin/Fetch Metadata guard and double-submit CSRF
  request verification;
- session-bound CSRF verification inside the refresh application flow;
- pessimistic-lock refresh rotation without extending session expiry;
- replay, inactive-owner, rollback and concurrency tests.

Implemented in the backend-authenticator stage:

- stateless Symfony firewall with public auth routes and protected `/api/v1/me`;
- custom Bearer authenticator accepting only `Authorization: Bearer <access-token>`;
- current-user query returning `UserView` from the current PostgreSQL row;
- small Symfony principal containing only public profile data;
- JSON authentication entry point/failure response using the standard
  `AUTHENTICATION_REQUIRED` envelope;
- `/me` HTTP, current-user persistence, public-regression, sensitive-data and
  architecture tests.

Implemented in the frontend-session stage:

- `$auth` Nuxt plugin with in-memory access token holder;
- login page calling `POST /api/v1/auth/login` and redirecting only to safe
  local paths;
- client bootstrap `refresh -> /me` after hydration;
- Web Locks based refresh serialization and intra-tab single-flight;
- protected request helper with one refresh and one retry after access-token
  `401`;
- protected `/me` page using real `GET /api/v1/me`;
- route middleware that waits for bootstrap before redirecting anonymous users
  to login;
- layout navigation reflecting `unknown`, `authenticated`, `anonymous` and
  `error` states without logout UI;
- unit and component regressions for validation, bootstrap states, retry budget,
  no token persistence and token-free cross-tab events.

Verified in the browser E2E closure stage:

- real HTTPS path `register -> activate -> login -> reload -> authenticated /me`;
- safe activation URL handling with no capability persistence;
- exact refresh and CSRF cookie attributes after login and reload;
- no access token, refresh token, CSRF token or password in Web Storage,
  IndexedDB metadata, SSR payload, URL, body text, JS-readable refresh cookie or
  browser diagnostics;
- anonymous protected navigation gets backend refresh `401` and redirects to a
  safe local login redirect;
- unsafe login redirect values are collapsed to `/me`;
- login errors distinguish only the accepted public states and keep password
  out of UI/storage;
- duplicate login submit sends one request;
- two tabs serialize refresh through Web Locks without token-bearing messages;
- protected request handling performs at most one refresh and one retry after
  eligible access-token `401`;
- `/me` rejects missing and invalid Bearer tokens even when refresh cookies are
  present.

Not implemented in this stage: logout, logout-all, family-wide revoke, rate
limits beyond login and existing public operations.

## 11. Зависимости и конфигурация

Current backend dependencies include Symfony 7.4 components, Doctrine ORM,
Symfony RateLimiter, PasswordHasher, Serializer, Validator, Monolog,
SecurityBundle and `lcobucci/jwt`.

Added dependencies:

| Need | Dependency | Reason |
|---|---|---|
| Symfony production authenticator/firewall foundation | `symfony/security-bundle:7.4.*` | Provides stateless Bearer authentication for `/api/v1/me` while auth/register/login/refresh routes remain public at firewall level. |
| JWT/JWS generation and validation | `lcobucci/jwt:^5.6` | Provides RS256/JWS implementation; project code owns ADR-017 allow-list, exact issuer/audience and local key lookup. |

`web-token/jwt-framework` was checked as an alternative. It is Symfony-bundle
oriented and supports PHP `>=8.2` plus Symfony `^7.0|^8.0`, but it is broader
JOSE/JWE infrastructure than needed for the current minimal RS256 access token.
The implementation therefore uses `lcobucci/jwt`.

Implemented refresh token format:

- public token: `rt.<base64url(32 random bytes)>`;
- storage hash: `sha256:<64 lowercase hex SHA-256 digest of the public token>`;
- malformed public tokens return no digest.

Implemented CSRF token/key-ring format:

- token: `<keyVersion>.<base64url(32 random bytes nonce)>.<base64url(HMAC-SHA-256 signature)>`;
- signature message: `equo.csrf.v1`, key version, nonce and lowercased
  `sessionId`, separated unambiguously with NUL bytes;
- key ring: JSON object `keyVersion -> base64-encoded key`;
- active key signs new tokens; retained old keys verify existing tokens.

Implemented JWT key environment format:

- `EQUO_JWT_SIGNING_PRIVATE_KEY`: base64-encoded PEM RSA private key;
- `EQUO_JWT_PUBLIC_KEY_RING`: JSON object `kid -> base64-encoded PEM RSA public key`;
- active `kid` must be present in the public ring;
- RSA keys must be at least 2048 bits;
- `EQUO_JWT_ACCESS_TTL_SECONDS` must be `900`;
- `EQUO_JWT_CLOCK_SKEW_SECONDS` must be between `0` and `30`.

Environment variables:

| Variable | Purpose |
|---|---|
| `EQUO_JWT_SIGNING_KEY_VERSION` | Active JWT signing `kid` |
| `EQUO_JWT_SIGNING_PRIVATE_KEY` | Base64-encoded PEM RSA private key or deployment-provided equivalent |
| `EQUO_JWT_PUBLIC_KEY_RING` | JSON key ring of base64-encoded PEM RSA public keys |
| `EQUO_JWT_ISSUER` | Exact expected issuer |
| `EQUO_JWT_AUDIENCE` | Exact expected audience |
| `EQUO_JWT_ACCESS_TTL_SECONDS` | Normative `900` |
| `EQUO_JWT_CLOCK_SKEW_SECONDS` | `0—30`, max normative `30` |
| `EQUO_CSRF_SIGNING_KEY_VERSION` | Active CSRF signing key version |
| `EQUO_CSRF_SIGNING_KEY_RING` | JSON key ring of base64-encoded HMAC keys, each at least 256 bits |
| `EQUO_APPLICATION_ORIGIN` | Exact same-origin/Origin comparison value |
| `EQUO_REGISTRATION_IP_LIMIT` | Registration IP sliding-window quota, default `5` |
| `EQUO_REGISTRATION_EMAIL_LIMIT` | Registration email sliding-window quota, default `3` |
| `EQUO_ACTIVATION_IP_LIMIT` | Activation IP sliding-window quota, default `10` |
| `EQUO_ACTIVATION_TOKEN_LIMIT` | Activation token sliding-window quota, default `5` |
| `EQUO_LOGIN_IP_LIMIT` | Login IP sliding-window quota, default `30` |
| `EQUO_LOGIN_EMAIL_IP_LIMIT` | Login email+IP sliding-window quota, default `5` |
| `EQUO_REFRESH_TTL_DAYS` | Defaults to normative `30` if configurable |

Tracked env examples use intentionally invalid placeholders for JWT/CSRF key
material. No real keys or production secrets belong in the repository.

## 12. План реализации и тестовая матрица

| Step | Production components | Unit tests | Integration tests | HTTP tests | Concurrency tests | Frontend/component tests | Browser E2E | Done criteria |
|---|---|---|---|---|---|---|---|---|
| 1. Security primitives | Random token generator, refresh hash, CSRF signer, JWT adapter interfaces | Hashing, CSRF bind/verify, JWT claim validation | Key ring loading from env | Safe error mapping and public-route regression | — | — | — | Implemented; no custom crypto; secrets excluded from logs/serialization |
| 2. Migration и session persistence | `user_session` migration, Doctrine record/mapping, repository | Lifecycle domain model | Schema/mapping parity, hash-only storage | — | DB lock smoke | — | — | Implemented in `Version20260808223000`; constraints match normative model: only `expires_at > created_at` CHECK for `UserSession` |
| 3. Login | Login service, controller, rate limiters, cookie issuer | Credential branch decisions | Active/inactive users, transaction creates session | `200`, `INVALID_CREDENTIALS`, `ACCOUNT_INACTIVE`, `RATE_LIMIT_EXCEEDED` | — | — | — | Implemented for backend only; no account enumeration; cookies correct |
| 4. Refresh | Refresh service, lock/rotation, CSRF/same-origin verifier | Lifecycle decisions | Rotation, expiry, revoked, inactive user | Cookie rotation, CSRF, Origin, errors | Two simultaneous refresh requests | Bootstrap refresh mock states | — | Old token never revives; no silent fallback |
| 5. Authenticator и `/me` | SecurityBundle firewall, custom authenticator, current-user query, `/me` | JWT validation branches | Load current user after JWT validation | `GET /me` success/401/inactive | — | Protected route state hooks | — | Implemented; protected endpoint has real current user boundary |
| 6. Frontend lifecycle | In-memory token holder, bootstrap, API retry, route middleware, `/me` page | Token holder and retry budget | — | — | Single-flight browser logic | `unknown/authenticated/anonymous/error`, no storage | — | Implemented; one refresh and one retry max; no token persistence |
| 7. Browser E2E/security regression | Full stack auth path, HTTPS E2E nginx, ephemeral JWT/CSRF/TLS generation | — | — | — | Two-tab browser refresh coordination | — | `register -> activate -> login -> reload -> authenticated /me`; safe errors, redirects, storage/cookie/token regressions | Implemented; secrets absent from body/logs/URL/storage/artifacts; no source drift |
| 8. Docs и roadmap closure | Update implemented docs/env examples after implementation | — | — | — | — | — | Full check | Implemented docs updated; roadmap checkboxes stay unchecked until merge |

## 13. Решения и открытые вопросы

Подтверждённые решения:

- `MVP-SC-002` implementation is complete and verified on the feature branch.
- JWT profile is ADR-017: RS256, `typ=at+jwt`, versioned `kid`, minimal claims,
  exact `iss/aud`, 900-second TTL, max 30-second skew.
- Frontend profile is ADR-018: in-memory token, bootstrap `refresh → /me`,
  single refresh/retry budget, no token persistence.
- Browser primitive for cross-tab serialization: Web Locks API; token-free
  events may use BroadcastChannel.
- Frontend session lifecycle is implemented and browser-verified with `$auth`;
  `$api` stays a stateless transport boundary and accepts an access token only
  explicitly.
- JWT dependency: `lcobucci/jwt:^5.6`; Symfony auth dependency:
  `symfony/security-bundle:7.4.*`.

Found contradictions:

- `DR-E1-004` mentions CHECK constraints for times and terminal state, while
  normative `UserSession` ER/model sources explicitly define only
  `expiresAt > createdAt` plus indexes by user, expiry and revocation.

Resolved open questions:

- `OQ-020`: no additional `UserSession.revokedAt` DB CHECK is added; terminal
  state is a domain lifecycle rule.

Final verification:

- backend quality and tests cover security primitives, login, refresh,
  authenticator, `/me`, sensitive-data and concurrency regressions;
- frontend unit/component tests cover validation, bootstrap states, retry
  budget, no token persistence and token-free cross-tab events;
- browser E2E covers the real full-stack HTTPS auth/session flow;
- `make check-full` is the merge gate for this feature.

Blockers:

- None.
