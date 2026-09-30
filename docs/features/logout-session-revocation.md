# Выход и отзыв сессий

| Поле | Значение |
|---|---|
| Название | Выход и отзыв refresh-сессий |
| Статус | Planned |
| Дата контрактного аудита | 2026-09-30 |
| Связанный сценарий | `MVP-SC-003` |
| Задача аудита | `SC3-01` |
| Реализованный этап | `SC3-02` — 2026-09-30 |
| Диапазон реализации | `SC3-02—SC3-08`; `DR-E6-011—018` |
| Владелец | Maksim Smolkov |

## 1. Пользовательский результат

Пользователь сможет завершить текущую browser refresh-сессию либо атомарно
отозвать все свои незавершённые refresh-сессии. После успешной команды новые
access tokens по отозванным сессиям не выдаются, текущая вкладка удаляет
in-memory access token и public current-user state, а вкладки того же browser
profile получают token-free событие завершения сессии.

Сценарий не вводит blacklist JWT. Уже выданный access token остаётся
криптографически действительным до `exp` (не более 15 минут плюс допустимый
clock skew) и для активного пользователя может продолжать проходить protected
API до истечения. Logout прекращает долгоживущий refresh-доступ, но не обещает
мгновенную недействительность уже выданного access token.

Контрактный аудит завершён со статусом `READY`. Решения владельца от
2026-09-30 закрыли `OQ-022—024`: refresh token получает stable session locator,
current logout скрывает unusable credential за идемпотентным `204`, `403` не
очищает cookies, успешный logout-all очищает обе cookies, а confirmation нужен
только для logout-all.

## 2. Нормативные источники и фактическая база

Нормативные источники:

- [`MVP-SC-003`](../mvp-scope.md#mvp-sc-003);
- [HTTP 2.3—2.5, 3, 8.6—8.7 и 18](../api/http-contracts.md);
- [ADR-006](../adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий),
  [ADR-012](../adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp),
  [ADR-014](../adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp),
  [ADR-015](../adr/architecture-decisions.md#adr-015-слои-backend-и-направления-зависимостей-модулей),
  [ADR-017](../adr/architecture-decisions.md#adr-017-профиль-jwt-access-token) и
  [ADR-018](../adr/architecture-decisions.md#adr-018-frontend-lifecycle-access-token-session-bootstrap-и-csrf);
- [`UserSession`](../data-model/entities.md#9-usersession), транзакционные
  границы и решённый `OQ-020`;
- [фактическая модель доступа](../security/access-model.md),
  [login/session contract](login-protected-session.md) и
  [этап 6B roadmap](../development-roadmap.md#104-feature-6b--logout-и-logout-all).

Аудит кода подтвердил:

- `UserSession` уже имеет `revokedAt`, `isActive()` и идемпотентный `revoke()`;
- таблица `user_session` уже имеет `user_id`, `refresh_token_hash`,
  `expires_at`, `revoked_at`, unique hash и индексы по user/expiry/revocation;
- refresh token codec выдаёт только
  `rt2.<session-uuid>.<base64url(32 random bytes)>`, строго разбирает locator и
  вычисляет digest полного credential без диагностического раскрытия token;
- repository находит session по primary-key locator под `PESSIMISTIC_WRITE` и
  сохраняет domain state; lookup по refresh hash удалён из application port;
- refresh разбирает credential до transaction, блокирует session row по ID,
  сверяет stored hash через `hash_equals`, проверяет session-bound CSRF и
  ротирует credential с тем же locator;
- application use case `LogoutCurrentSession` реализует idempotent no-op для
  malformed/unknown credential и под row lock отзывает active session после
  CSRF binding; stale rotated credential намеренно не сравнивается со stored
  hash;
- Bearer authenticator проверяет JWT, перечитывает актуального `User` и
  отклоняет unknown/inactive user единым `401 AUTHENTICATION_REQUIRED`, но пока
  поддерживает только `/api/v1/me`;
- `AuthCookieFactory` умеет только выдавать cookies, метода очистки пока нет;
- frontend `$auth` уже имеет private in-memory token, `useCurrentUser`, Web
  Locks refresh coordination и token-free `BroadcastChannel` event
  `session-ended`, но публичных logout actions пока нет.

## 3. Подтверждённые HTTP-контракты

Каждый API response, включая `204` и ошибки, обязан иметь `X-Request-Id`.
Ошибки используют единый JSON envelope. Успешный `204` не имеет response body
и не должен объявлять JSON body.

### 3.1. `POST /api/v1/auth/logout`

Назначение — завершить refresh-сессию, представленную cookie текущего browser.

Подтверждённые правила:

- Bearer access token не требуется и не используется для идентификации session;
- request body и `Content-Type` не требуются;
- без `equo_refresh` команда идемпотентно возвращает `204`, CSRF и Origin не
  проверяются, обе auth cookies отправляются на удаление;
- при наличии `equo_refresh` до отзыва обязательны exact `Origin`, допустимый
  `Sec-Fetch-Site`, совпадение CSRF header/cookie, подпись и привязка CSRF token
  к найденной `UserSession`;
- current session идентифицируется stable locator из versioned refresh token;
  после strict parsing row блокируется по `UserSession.id`, а stored hash
  полного token сверяется для refresh, но не мешает stale rotated token
  завершить ту же session через logout;
- найденная session блокируется `PESSIMISTIC_WRITE` внутри transaction;
- активной session идемпотентно устанавливается `revokedAt = now` и состояние
  сохраняется; уже заполненный `revokedAt` не переписывается;
- успешный `204`, повтор без refresh cookie и unusable refresh credential
  очищают `equo_refresh` и `__Host-equo_csrf`;
- неверная same-origin/CSRF-проверка возвращает `403 FORBIDDEN` до отзыва;
- `403` не очищает и не изменяет cookies;
- уже выданный access token не отзывается и может действовать до `exp`.

Успешный response:

```http
204 No Content
Set-Cookie: equo_refresh=; Max-Age=0; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
Set-Cookie: __Host-equo_csrf=; Max-Age=0; Secure; SameSite=Lax; Path=/
X-Request-Id: <request-id>
```

После прошедших request-level Origin/Fetch Metadata и double-submit equality
checks malformed либо unknown refresh credential возвращает тот же `204` и
clear-cookie headers без DB mutation. Если locator указывает на существующую
session, cryptographic CSRF signature/binding обязательно проверяется до
revoke; failure даёт `403` без cookie clearing. Already-revoked, expired и
stale rotated token с valid session-bound CSRF также возвращают `204`; expired
и already-revoked row не переписываются, stale rotated token отзывает session.

### 3.2. `POST /api/v1/auth/logout-all`

Назначение — завершить все незавершённые refresh-сессии authenticated user.

Подтверждённые правила:

- обязателен `Authorization: Bearer <access-token>`;
- JWT проходит профиль ADR-017, затем сервер перечитывает актуального `User` по
  `sub` и требует `isActive = true`;
- отсутствующий, malformed, invalid, expired Bearer token, unknown user и
  inactive user дают единый `401 AUTHENTICATION_REQUIRED`, `WWW-Authenticate:
  Bearer` и standard error envelope;
- CSRF не требуется: refresh cookie не является аутентификатором команды, а
  браузер не может добавить Bearer header через cross-site form;
- `currentUserId` поступает из проверенного `AuthenticatedUser`, не из body,
  query, cookie или route parameter;
- transaction сначала блокирует `User` через `SELECT FOR UPDATE`, повторно
  проверяет его существование/активность, затем одним application repository
  operation отзывает все его незавершённые sessions;
- незавершённая session: `revokedAt IS NULL AND expiresAt > now`; already
  revoked и expired rows не переписываются;
- весь массовый отзыв атомарен; rollback не оставляет частично отозванный набор;
- при отсутствии незавершённых sessions команда всё равно возвращает `204`;
- response body отсутствует;
- access tokens во всех вкладках и на других устройствах не blacklist-ятся и
  могут действовать до `exp`; после этого refresh отозванных sessions даёт
  отказ и новый access token не выдаётся.

Успешный response:

```http
204 No Content
X-Request-Id: <request-id>
```

Успешный logout-all очищает обе auth cookies текущего browser теми же
clear-cookie headers, что current logout. Ошибка Bearer authentication не
очищает cookies.

## 4. Session lifecycle

| Состояние/переход | Нормативное поведение |
|---|---|
| Active | `revokedAt IS NULL` и `expiresAt > now`; допускает refresh rotation. |
| Current logout | Под lock заполняет `revokedAt`, если session ещё active. |
| Logout-all | Под User lock атомарно заполняет одинаковым `now` все строки `revokedAt IS NULL AND expiresAt > now`. |
| Already revoked | Terminal; не ротируется, `revokedAt` не переписывается, current logout возвращает `204` и очищает cookies. |
| Expired | Terminal при `expiresAt <= now`; не ротируется и не переписывается, current logout возвращает `204` и очищает cookies. |
| Rotated old token | Не допускает refresh из-за hash mismatch, но stable locator позволяет current logout заблокировать и отозвать ту же session при valid session-bound CSRF. |
| Malformed/unknown token | DB mutation отсутствует; current logout возвращает `204` и очищает cookies после request-level anti-CSRF checks. |
| Issued access token | Не связан с `revokedAt` lookup и живёт до `exp`; blacklist не вводится. |

Retention не меняется: revoked session хранится 30 дней после `revokedAt`,
expired session — 30 дней после `expiresAt`.

Versioned refresh credential для новых sessions имеет профиль:

```text
rt2.<session-uuid>.<base64url(32 cryptographically random bytes)>
```

`session-uuid` является locator, а не доказательством аутентификации. В БД
по-прежнему хранится только `sha256` digest полного credential. Refresh после
lock требует exact hash match; logout после lock требует valid session-bound
CSRF, но допускает hash mismatch старого rotated credential, чтобы гонка не
оставляла session активной. Production-сессий старого формата нет, поэтому
legacy compatibility не вводится: прежние локальные/test cookies становятся
недействительными при rollout нового формата. Migration БД не требуется.

## 5. Cookies, CSRF и same-origin

| Случай | Origin/Fetch Metadata | CSRF | Cookie response |
|---|---|---|---|
| `logout`, refresh cookie отсутствует | Не требуются | Не требуется | Обе cookies очищаются |
| `logout`, refresh cookie есть | Exact Origin; `Sec-Fetch-Site` при наличии только `same-origin`/`none` | Header = cookie; valid signed token bound to найденной session | При `204` обе cookies очищаются |
| `logout`, guard отклоняет запрос | Ошибка одной из обязательных проверок | Не пройдена | Не изменяются |
| `logout`, credential unusable после request-level checks | Exact Origin/Fetch Metadata и header/cookie equality пройдены | Session binding невозможен, DB mutation нет | Обе очищаются с `204` |
| `logout-all` success | Bearer-команда; cookie auth не используется | Не требуется | Обе очищаются |
| `logout-all` authentication failure | Bearer invalid/absent | Не требуется | Не изменяются |

`SameSite=Lax` остаётся defense in depth. Credentialed CORS для недоверенных
origins запрещён. Cookie clearing обязано повторить исходные имена и Path;
refresh cookie остаётся `HttpOnly`, обе — `Secure`, `SameSite=Lax`, без
`Domain`, с `Max-Age=0` (допустим также истёкший `Expires`).

Existing `RefreshRequestGuard` следует переиспользовать через узкий общий
same-origin/double-submit механизм или logout-specific optional-cookie entry
point. Controller не должен дублировать cryptographic CSRF verification.

## 6. Backend design

### 6.1. Current logout

Состояние `SC3-02`: domain/application/persistence core реализован. В scope
этапа вошли новый refresh credential profile, locator lookup под lock,
адаптация login/refresh и `LogoutCurrentSession`. HTTP adapter, cookie clearing
и frontend lifecycle остаются последующими этапами `SC3-04—SC3-05`.

Минимальная структура:

- input/command: raw refresh credential и CSRF token только после HTTP
  request-level guard; refresh codec port строго разбирает optional locator;
- application service: `LogoutCurrentSession`;
- repository operations: новый `findByIdForUpdate(sessionId)` и существующий
  `save()`; refresh также переходит на stable-locator lookup с обязательной
  stored-hash verification;
- transaction boundary: malformed/unknown locator даёт application no-op
  success без DB mutation; для найденной row locator lookup, CSRF
  binding/lifecycle/revoke/save проходят внутри одной `TransactionPort::run`;
- domain operation: существующий идемпотентный `UserSession::revoke(now)`;
- HTTP adapter: `LogoutController`, optional-cookie guard и
  `AuthCookieFactory::clearSessionCookies()`;
- result: `204` без body; controller прикрепляет clear-cookie headers к
  подтверждённым успешным веткам.

Application не знает Request/Response/Cookie. Session-bound CSRF verification
остаётся application port call после server-side session lookup.

### 6.2. Logout-all

Минимальная структура:

- input/command: только `currentUserId`;
- source: verified `AuthenticatedUser::getUserIdentifier()`;
- application service: `LogoutAllSessions`;
- identity repository operation: `currentUserForUpdate(userId)` с
  `PESSIMISTIC_WRITE` и актуальным `isActive`;
- session repository operation:
  `revokeAllUnfinishedForUser(userId, revokedAt, now): int`, где один bulk
  update меняет только `revokedAt IS NULL AND expiresAt > now`;
- transaction boundary: lock/recheck User и bulk revoke в одной
  `TransactionPort::run`;
- HTTP adapter: protected `LogoutAllController` с `204` result;
- security config/authenticator: расширить protected path так, чтобы endpoint
  использовал тот же strict Bearer profile и JSON entry point, что `/me`.

Порядок блокировок для mass-revoke security commands: сначала `User`, затем
затронутые `UserSession`. Refresh продолжает блокировать только одну session и
не должен затем брать `User FOR UPDATE`; актуальный `isActive` читается без
добавления обратного lock order. Это исключает цикл `session -> User` против
`User -> sessions`.

### 6.3. Dependency boundaries

- Domain не зависит от Symfony/Doctrine и не получает cookie/JWT понятия;
- Application не зависит от HTTP и координирует transaction через ports;
- controller только извлекает verified input, вызывает use case и формирует
  HTTP result/cookies;
- Doctrine adapter реализует application ports и скрывает records/query details;
- `UserSessionRecord` и `UserRecord` не выходят в HTTP;
- новый общий repository/mapper framework, session version и JWT blacklist не
  вводятся.

## 7. Persistence assessment

Текущая схема достаточна. Новая migration не нужна:

| Возможность | Состояние |
|---|---|
| `revokedAt` | Есть в domain, record и table |
| Идемпотентный `revoke` | Уже реализован; повтор не меняет timestamp |
| Lookup по refresh hash | Удалён из application repository port |
| Pessimistic session lock | Есть |
| Сохранение session | Есть |
| Индекс `user_id` | Есть |
| Transaction port/Doctrine transaction | Есть |
| Выборка/mass revoke unfinished sessions | Нет; нужен repository port method и Doctrine bulk update |
| User lock для logout-all | Нет в текущем identity port; нужен узкий `FOR UPDATE` method |
| Cookie clearing factory | Нет; нужен HTTP-only method, не schema change |
| Stable locator lookup | Реализован `findByIdForUpdate` с `PESSIMISTIC_WRITE`; существующего PK достаточно |

Bulk update не должен менять expired или already-revoked rows. Возвращаемый
count нужен только для тестов/observability и не влияет на public `204`.

## 8. Frontend lifecycle

### 8.1. Current logout

Подтверждённый технический flow:

1. UI вызывает отдельный `$auth.logout()`; generic `$api` не управляет auth
   state.
2. Action использует тот же `AUTH_REFRESH_LOCK`, что refresh, и читает CSRF
   cookie только после получения lock.
3. Отправляется cookie-authenticated `POST /v1/auth/logout` без Bearer token и
   без automatic refresh/retry.
4. Local access token и current user очищаются при завершении logout action в
   соответствии с ADR-018, включая safe error outcome.
5. Только подтверждённый server `204` публикует token-free
   `{ type: 'session-ended' }`; token/cookie/user data в message не входят.
6. Другие вкладки очищают private token и current user, не вызывают logout
   повторно, переходят в explicit signed-out state и не пытаются автоматически
   восстановить revoked session.

Network/`5xx`/`403` означает: local auth state очищен, но server revocation не
подтверждён; UI обязан сообщить это без утечки деталей и предложить явный
retry. Нельзя показывать успешный server logout или автоматически запускать
bootstrap. Current logout не требует confirmation. После server `204` frontend
переходит на `/login` в explicit signed-out mode; при safe error остаётся на
dedicated result state до retry или явной навигации пользователя.

### 8.2. Logout-all

Подтверждённый технический flow:

1. Отдельный UI action требует явного confirmation и вызывает
   `$auth.logoutAll()` через protected request с текущим in-memory Bearer token.
2. Если access token истёк, допускается ровно один существующий
   refresh-and-retry budget; бесконечный retry запрещён.
3. После `204` local token/current user очищаются и рассылается то же token-free
   session-ended event.
4. Вкладки того же browser profile очищают локальные tokens. Другие devices не
   получают browser event: их текущие access tokens живут до `exp`, а следующий
   refresh не проходит.
5. После успешного mass revoke frontend не выполняет bootstrap/refresh,
   способный восстановить старую session.

После успешного `204` применяется тот же `/login` explicit signed-out mode. При
transport/`403`/`5xx` используется тот же dedicated safe-error state без
автоматического bootstrap; success event не рассылается.

## 9. Конкурентные сценарии

| Операция A | Операция B | Locks и transaction | Требуемый результат |
|---|---|---|---|
| Refresh current session | Logout current session | Обе операции извлекают stable locator и берут одну `UserSession FOR UPDATE`; browser дополнительно сериализует их одним Web Lock. | Logout-first отзывает session, refresh получает `401 INVALID_REFRESH_TOKEN`. Refresh-first может один раз успешно rotate/выдать access token; ожидающий logout затем находит row по locator, проверяет CSRF и отзывает session независимо от старого hash. |
| Два logout одной session | Друг с другом | Одна session row, два transactions, один pessimistic lock за другим. | Первый заполняет `revokedAt`, второй не переписывает его; оба получают `204` и clear-cookie headers, DB transition ровно один. |
| Refresh | Logout-all | Refresh: session lock. Logout-all: User lock, затем matching sessions в bulk update. | Если refresh завершается первым, может быть выдан один access token, затем session отзывается; token живёт до `exp`. Если mass revoke блокирует/обновляет session первым, refresh после ожидания получает `401 INVALID_REFRESH_TOKEN`. |
| Два logout-all | Друг с другом | Оба сначала lock того же User; второй ждёт. | Первый отзывает unfinished rows, второй изменяет 0 rows; оба `204`, partial state отсутствует. |
| Login | Logout-all | Logout-all сериализуется по User; login создаёт новую session в своей transaction. | Logout-all отзывает sessions, существующие в его serial order. Login, сериализованный после него, может создать новую active session: повторная успешная аутентификация не запрещена. Login до него создаёт session, подлежащую отзыву. Нужен integration race test, подтверждающий выбранный DB order. |
| Session expiry | Logout | Expiry — lifecycle predicate, logout — session transaction/lock при найденной row. | Expired session не оживает и не получает новый `revokedAt`; logout возвращает `204` и очищает cookies. |

Deadlock prevention:

- mass commands всегда берут `User` раньше sessions;
- current logout и refresh не берут User lock после session lock;
- bulk revoke использует один statement и одинаковый predicate;
- application не смешивает произвольный per-session lock order;
- проигравшая refresh operation получает публичный `401 INVALID_REFRESH_TOKEN`;
  повторные revoke commands не должны раскрывать session presence.

## 10. Матрица ошибок и публичных ответов

`Local state` ниже описывает frontend action. Серверные клиенты без frontend не
имеют local state.

| Ситуация | Endpoint | HTTP | Public code/body | Cookies | Local state |
|---|---|---:|---|---|---|
| Refresh cookie отсутствует | logout | `204` | Нет body | Обе очищаются | Очищается; success event |
| Active current session | logout | `204` | Нет body | Обе очищаются | Очищается; success event |
| Повторный logout/terminal session | logout | `204` | Нет body; session presence не раскрывается | Обе очищаются | Очищается; success event |
| Wrong/missing Origin или disallowed Fetch Metadata | logout | `403` | `FORBIDDEN` envelope | Не изменяются | Очищается локально; safe error; event нет |
| Wrong/missing/mismatched/bad-bound CSRF для найденной session | logout | `403` | `FORBIDDEN` envelope | Не изменяются | Очищается локально; safe error; event нет |
| Malformed/unknown refresh token | logout | `204` | Нет body; DB mutation отсутствует | Обе очищаются | Очищается; success event |
| Stale rotated token с known locator и valid CSRF | logout | `204` | Session отзывается без раскрытия race | Обе очищаются | Очищается; success event |
| Bearer отсутствует | logout-all | `401` | `AUTHENTICATION_REQUIRED`; `WWW-Authenticate: Bearer` | Не изменяются | При UI action очищается; error state |
| Bearer invalid/expired, user unknown/inactive | logout-all | `401` | `AUTHENTICATION_REQUIRED`; один refresh/retry допустим frontend | Не изменяются | Очищается после исчерпания flow; без loop |
| Active user, unfinished sessions есть | logout-all | `204` | Нет body | Обе очищаются | Очищается; success event |
| Все sessions terminal | logout-all | `204` | Нет body | Обе очищаются | Очищается; success event |
| Transport error | Оба | Нет HTTP | Client-safe transport error | Browser state неизвестно | Local state очищается; server success не заявляется; event нет |
| Unexpected backend failure | Оба | `500` | `INTERNAL_SERVER_ERROR` envelope | Не считать очищенными без явного contract | Local state очищается; safe retry UI; event нет |

Ни один response, log или UI error не содержит refresh/CSRF token, token hash,
session ID, JWT, SQL, stack trace или различие unknown/foreign session.

## 11. Матрица тестов

| Уровень | Обязательное покрытие |
|---|---|
| Domain unit | `UserSession::revoke` ставит время один раз; повтор сохраняет первый timestamp; revoked/expired session не active и не rotates. |
| Application unit | Current revoke active/revoked/expired/unknown/stale-rotated branches; session-bound CSRF; rollback. Logout-all locks active User, передаёт один `now`, revokes only unfinished, zero-row result остаётся success. |
| Persistence/integration | Stable locator lookup действительно `FOR UPDATE`; refresh требует hash match, logout допускает stale hash после valid CSRF; bulk predicate исключает revoked/expired; User lock берётся первым; all-or-nothing rollback; mapping/schema parity; migration не появляется. |
| HTTP logout | `204` без cookie и без CSRF; `204` для malformed/unknown/stale/terminal credential; обе exact clear cookies; no body; access token необязателен; Origin/Fetch Metadata/header-cookie/signature/binding failures; `403` не меняет cookies; exact envelope и `X-Request-Id`; sensitive-data absence. |
| HTTP logout-all | Missing/malformed/invalid/expired Bearer и inactive/unknown user дают единый `401` без cookie mutation; valid user и zero-session user получают `204` и обе clear cookies; no CSRF requirement; `X-Request-Id`. |
| Concurrency | Refresh vs current logout; two current logouts; refresh vs logout-all in both lock orders; two logout-all; login vs logout-all; no deadlock/timeouts; exact loser outcome. |
| Frontend unit | `logout` uses refresh Web Lock and latest CSRF; no Bearer/no retry; `logoutAll` uses Bearer and one refresh/retry; local token/current user cleared; token-free event only after `204`; no token in storage/message. |
| Frontend component | Pending disables repeat submit; confirmation только для logout-all; success redirect в explicit signed-out mode; dedicated safe-error state; no retry/bootstrap loop; other tab clears state и не вызывает refresh автоматически. |
| Browser E2E 1 | Login → current logout → protected route unavailable → reload does not restore session. |
| Browser E2E 2 | Two independent browser contexts → logout-all in one → second may use already-issued access only within documented `exp`, then refresh fails and protected session ends. Test may use controllable clock/short test TTL; it must not claim instant JWT blacklist. |
| Browser E2E 3 | Two tabs same profile → success event clears both without transferring token and without redirect/bootstrap loop. |
| Security regression | Cookies exact; secrets absent from response/logs/URL/storage/artifacts; CSRF failure precedes revoke; standard request ID/error envelope; public auth endpoints unchanged. |

Покрытие `SC3-02` добавлено на codec, domain, login, refresh, current logout и
Doctrine persistence. Оно включает strict/legacy parsing, hash-only storage,
ID-based row lock, malformed/unknown no-op, CSRF failure, idempotent terminal
states, stale-token revoke после rotation, refresh-after-logout failure и
rollback без сохранённого `revokedAt`. HTTP logout/logout-all, mass revoke и
frontend tests намеренно не входят в этот этап.

## 12. Границы фичи

В `SC-003` не входят:

- password reset и password change;
- активация, реактивация и деактивация аккаунта;
- список устройств и просмотр активных sessions;
- выборочный отзыв произвольной либо чужой session;
- мгновенный blacklist JWT, global session version и изменение JWT profile;
- MFA;
- email-уведомления о logout;
- финансовые сценарии;
- изменение 30-дневного session TTL или retention profile.

## 13. Решённые вопросы

| ID | Принятое решение | Статус |
|---|---|---|
| [`OQ-022`](../open-questions.md#oq-022) | Unusable refresh credential получает `204` и clear cookies; `403` cookies не меняет; успешный logout-all очищает обе cookies | Resolved 2026-09-30 |
| [`OQ-023`](../open-questions.md#oq-023) | Versioned refresh credential содержит stable session locator; production legacy sessions отсутствуют | Resolved 2026-09-30 |
| [`OQ-024`](../open-questions.md#oq-024) | Current logout без confirmation, logout-all с confirmation; explicit signed-out success и dedicated safe-error state | Resolved 2026-09-30 |

Активных вопросов или блокеров реализации `SC3-02—SC3-08` нет.

## 14. Декомпозиция `SC3-02—SC3-08`

| Этап | Статус | Содержание | Входной gate | Выходной результат |
|---|---|---|---|---|
| `SC3-02` | Implemented 2026-09-30 | Current logout domain/application/persistence | Stable-locator и unusable-token contracts приняты | Idempotent revoke service и repository semantics без HTTP |
| `SC3-03` | Planned | Logout-all application/persistence | Подтверждённый User-first lock order | Atomic bulk revoke и active-user recheck |
| `SC3-04` | Planned | Controllers, security route, guards, cookie clearing | HTTP/cookie branches приняты | Оба exact HTTP contracts и safe errors |
| `SC3-05` | Planned | `$auth` actions, UI и cross-tab lifecycle | UX decision принят; backend contracts стабильны | Local cleanup, token-free event, no restore/redirect loop |
| `SC3-06` | Planned | Unit/application/integration/HTTP/component tests | `SC3-02—05` | Матрица happy/error/security branches |
| `SC3-07` | Planned | Process/DB concurrency tests | Stable-locator contract принят | Доказаны lock order, race outcomes и отсутствие deadlock |
| `SC3-08` | Planned | Browser E2E, security regression, docs и quality gate | `SC3-06—07` | Три browser journeys, docs synced, full gate green |

Roadmap checkboxes не отмечаются на `SC3-02`: общий end-to-end slice ещё не
завершён.

## 15. Exit criteria

`SC3-01` считается документально завершённым, когда:

- подтверждённые контракты обоих endpoints, lifecycle, locks, transaction
  boundaries и JWT limitation записаны в этом документе;
- persistence assessment однозначно фиксирует отсутствие migration;
- backend/frontend boundaries и полная test matrix определены;
- все ранее недоопределённые обязательные решения приняты владельцем и
  зафиксированы в закрытых `OQ-022—024`;
- `docs/README.md` ссылается на этот Planned artifact;
- production code, tests, migrations, dependencies, CI и environment не
  изменены;
- commit, push, merge, rebase и pull request не выполнялись.

`SC3-02` реализован и проверен в своей domain/application/persistence границе.
Этапы `SC3-03—SC3-08` остаются contract-ready, а feature — `Planned` до
завершения всего end-to-end slice.
