# Equo — фактическая модель доступа MVP

| Поле | Значение |
|---|---|
| Назначение | Описать реализованную модель доступа регистрации, активации, login, refresh rotation, browser session lifecycle, logout, password reset/change и `/me` |
| Статус | Accepted |
| Версия | 14 |
| Дата актуальности | 2026-10-11 |
| Владелец | Maksim Smolkov |
| Источник | BR-USR-001—003/007/009; BR-SEC-003—007; ADR-006/007/012/014—018; HTTP-контракты; фактическая реализация |

## 1. Граница реализации

Текущая реализация содержит публичную регистрацию, применение
`ACTIVATE_ACCOUNT`, password recovery, login, refresh rotation, logout и
минимальную защищённую `/me`-страницу. Login создаёт `UserSession`, выдаёт JWT
access token в response body и устанавливает refresh/CSRF cookies. Frontend
хранит access token только в памяти вкладки, после hydration выполняет
`refresh -> /me`, сериализует refresh через Web Locks и делает один
refresh/retry для подходящего `401` защищённого запроса. Refresh endpoint
ротирует refresh token и выдаёт новый access token без продления срока сессии.
Stateless Bearer firewall защищает `GET /api/v1/me`,
`POST /api/v1/auth/logout-all`, `POST /api/v1/account/password-change`,
создание/принятие приглашения и чтение Connect. Current logout использует optional refresh
cookie с same-origin/CSRF guard. Финансовые protected endpoints ещё не реализованы.
Текущий этап не добавляет временный bearer header, hardcoded пользователя,
фиктивную роль или RBAC-модель.

В срезе нет RBAC-ролей. Право активации является capability: его даёт только
действующий purpose-bound token. Успешная активация не создаёт аутентификацию и
не определяет current user для последующего запроса.

## 2. Матрица доступа

| Субъект | Операция | Объект | Условие | Результат отказа |
|---|---|---|---|---|
| Незарегистрированный посетитель | `POST /api/v1/auth/register` | Новый `User` | Публично; далее validation, idempotency и rate limit | Контрактная ошибка операции, не `401/403` |
| Предъявитель token | `POST /api/v1/auth/activate` | `User`, связанный с найденным token | Purpose `ACTIVATE_ACCOUNT`; token не истёк, не использован и не аннулирован | `400 INVALID_TOKEN` либо соответствующий `410` |
| Любой клиент с credentials | `POST /api/v1/auth/login` | `User` и новая `UserSession` | Публично; normalized email/password, login rate limits, `isActive = true` | `401 INVALID_CREDENTIALS`, `403 ACCOUNT_INACTIVE`, `429 RATE_LIMIT_EXCEEDED` |
| Browser с refresh cookies | `POST /api/v1/auth/refresh` | Существующая `UserSession` | `equo_refresh`, exact `Origin`, allowed `Sec-Fetch-Site`, double-submit CSRF, signed CSRF bound to session, active session и active user | `401 AUTHENTICATION_REQUIRED`, `401 INVALID_REFRESH_TOKEN`, `403 FORBIDDEN`, `403 ACCOUNT_INACTIVE` |
| Любой клиент | `GET /api/health` | Health state | Публично | — |
| Authenticated user | `GET /api/v1/me` | Собственный public profile | Valid `Authorization: Bearer <access-token>`; verified JWT `sub`; current DB user exists and `isActive = true` | `401 AUTHENTICATION_REQUIRED` |
| Browser | `POST /api/v1/auth/logout` | Current refresh session | Без cookie: no-op; с cookie: request guards, stable locator lock и valid CSRF binding для known session; stale credential допускается | `403 FORBIDDEN` без cookie mutation; unusable после guard → `204` |
| Authenticated user | `POST /api/v1/auth/logout-all` | Свои unfinished sessions | Strict Bearer, active User recheck под User lock, atomic bulk revoke; CSRF не нужен | `401 AUTHENTICATION_REQUIRED`, `WWW-Authenticate: Bearer`, cookies не меняются |
| Любой клиент | `POST /api/v1/auth/password-reset-requests` | Запрос recovery | Public email validation и quotas; neutral 202 | `422 VALIDATION_ERROR`, `429 RATE_LIMIT_EXCEEDED` |
| Предъявитель token | `POST /api/v1/auth/password-reset` | Password и sessions владельца | Purpose RESET_PASSWORD, current lifecycle; active/inactive допускаются | `400 INVALID_TOKEN`, соответствующий `410`, `422`, `429` |
| Authenticated user | `POST /api/v1/account/password-change` | Собственные password и sessions | Strict Bearer, fresh active User/currentPassword под User lock; общая новая policy | `401 AUTHENTICATION_REQUIRED` с Bearer challenge, `401 INVALID_CREDENTIALS`, `422 VALIDATION_ERROR`; cookies только на успехе |
| Authenticated user | Future protected API | — | Ещё не реализовано | Будущие policy/voter этапы |

## 3. Идентификация и аутентификация

У registration нет текущего пользователя: субъект анонимен. При activation
идентифицируется не HTTP principal, а конкретный `User`, связанный с записью
`UserActionToken`, найденной по хэшу предъявленного секрета. `userId` не должен
приниматься от клиента или заменяться идентификатором из URL.

Login реализует начало production-аутентификации: `RS256` JWT access token на
15 минут, opaque refresh token в cookie и server-side `UserSession` на 30 дней.
Обязательны
`typ=at+jwt`, versioned `kid`, claims `iss/aud/sub/iat/exp/jti`, точная проверка
issuer/audience и clock skew не более 30 секунд. JWT authenticator реализован
как Symfony custom authenticator. Он принимает только Authorization Bearer,
передаёт token в `AccessTokenVerifierPort` и берёт principal UUID только из
полностью verified `sub`.

После JWT validation backend всегда загружает текущего пользователя из
PostgreSQL через current-user query. Principal содержит только UUID и public
`UserView`; он не содержит JWT, refresh token, CSRF token, password hash,
Doctrine record, `UserSession` или бизнес-роли. `getUserIdentifier()` возвращает
UUID пользователя, не email.

Refresh rotation реализует backend-часть ADR-018: cookie-authenticated refresh
защищён точной same-origin проверкой, Fetch Metadata и подписанным
session-bound double-submit CSRF token. Сервер строго разбирает
`rt2.<session-uuid>.<base64url(32 random bytes)>`, блокирует только session по
stable locator, сверяет current digest через `hash_equals`, проверяет lifecycle и текущий `User.isActive`,
заменяет только `refresh_token_hash` и выпускает новый access token плюс новые
cookies. `expires_at` не сдвигается.

Frontend-часть ADR-018 реализована: access token живёт только в памяти вкладки;
reload запускает client-side `refresh -> /me`; одновременные refresh во
frontend сериализуются Web Locks; protected navigation ждёт bootstrap.

Для `/me` и logout-all invalid/absent authentication, malformed Bearer header, invalid JWT,
unknown user и inactive user возвращают единый `401 AUTHENTICATION_REQUIRED`.
`403` и `ACCOUNT_INACTIVE` здесь не используются, чтобы protected data не
подтверждала состояние аккаунта.

Current logout не использует Bearer. Без refresh cookie возвращает `204` и
очищает обе cookies; при cookie guard проверяет exact Origin, Fetch Metadata
и CSRF equality, а application проверяет binding после session lock. Malformed/
unknown credential после request guard получает `204`; known session требует
valid binding, даже если terminal или credential stale. Logout-all блокирует
User до sessions и атомарно отзывает только `revoked_at IS NULL AND expires_at > now`.
Login также сериализуется по User перед созданием новой session. Повторные
revoke сохраняют первый timestamp; rollback атомарен. Successful `204` очищает
обе cookies, ошибки их не меняют. JWT blacklist отсутствует: выданный access
token действует до `exp`. Полный контракт — [SC-003](../features/logout-session-revocation.md).

## 4. Object authorization activation token

`ActivationAccessPolicy` получает только уже найденный token candidate и
серверное время. Решение имеет один из результатов:

- allow с `userId`, взятым исключительно из token candidate;
- invalid token для отсутствующего token или другого purpose;
- expired token при `expiresAt <= now`;
- used token при заполненном `usedAt`;
- invalidated token при заполненном `invalidatedAt`.

Policy находится в `App\IdentityAccess\Application\Authorization` и не зависит
от Symfony или Doctrine. Канонический enum purpose находится в чистом Domain,
а Doctrine record использует его только из Adapter.

ADR-016 задаёт token как
`<keyVersion>.<base64url(32 cryptographically random bytes)>`, а сохранённый
`tokenHash` как versioned HMAC-SHA-256 digest полного token. Key ring находится
вне БД/репозитория, старый verification key хранится минимум 31 день. Реализация
включает generation, digest lookup, purpose/lifecycle policy и атомарное
применение в `RegisterUser`/`ActivateAccount`. Связанный User, затем token
блокируются в транзакции; `userId` всегда берётся из server-side token record.

## 5. Пароли

`PasswordHashingPort` объявлен в Application, а
`Adapter\Security\SymfonyPasswordHasher` реализует его официальным Symfony
PasswordHasher с алгоритмом `auto`. Открытый пароль передаётся адаптеру только
для хэширования, не сохраняется и не журналируется.

Doctrine `UserRecord` хранит только `passwordHash`. Он не является response DTO
или Symfony security principal и не должен сериализоваться HTTP-слоем.
Дополнительно `passwordHash`, `tokenHash` и token `payload` помечены Serializer
`Ignore`, поэтому случайная передача record не раскрывает эти значения.

Для login adapter выполняет password verification и для существующего, и для
отсутствующего пользователя. Unknown-user ветка использует adapter-owned dummy
hash и всегда возвращает `false`, поэтому Application не знает формат dummy hash
и не может аутентифицировать отсутствующего пользователя.

## 6. Ответы 401, 403 и 404

- `401 AUTHENTICATION_REQUIRED` применяется к `/me` и future protected endpoint
  без действительной аутентификации;
- `403 FORBIDDEN` применяется к аутентифицированному субъекту, которому
  запрещён тип операции;
- `404 RESOURCE_NOT_FOUND` может объединять отсутствующий и недоступный объект,
  чтобы не раскрывать его существование согласно BR-ACL-005/ADR-012.

Эти статусы поддерживает общий error envelope E1-09. Отсутствие права activation
выражается `INVALID_TOKEN`/token lifecycle error, а не `401/403/404`.

## 7. Размещение

| Компонент | Расположение |
|---|---|
| Token purpose | `IdentityAccess\Domain\Access\UserActionTokenPurpose` |
| Activation policy и решения | `IdentityAccess\Application\Authorization` |
| Password hashing port | `IdentityAccess\Application\Port\PasswordHashingPort` |
| Symfony PasswordHasher adapter | `IdentityAccess\Adapter\Security\SymfonyPasswordHasher` |
| Login use case | `IdentityAccess\Application\Login\LoginUser` |
| Refresh use case | `IdentityAccess\Application\Refresh\RefreshSession` |
| Current-user query | `IdentityAccess\Application\CurrentUser\GetCurrentUser` |
| Login controller/cookies | `IdentityAccess\Adapter\Http\LoginController`, `AuthCookieFactory` |
| Refresh controller/guard/cookies | `IdentityAccess\Adapter\Http\RefreshController`, `RefreshRequestGuard`, `AuthCookieFactory` |
| `/me` controller | `IdentityAccess\Adapter\Http\MeController` |
| Bearer authenticator/principal | `IdentityAccess\Adapter\Security\BearerAccessTokenAuthenticator`, `AuthenticatedUser` |
| Bearer JSON entry point | `Infrastructure\Http\BearerAuthenticationEntryPoint` |
| Login credential lookup | `IdentityAccess\Application\Port\StoredLoginIdentity`, `DoctrineIdentityRepository` |
| Current user lookup for refresh | `IdentityAccess\Application\Port\CurrentUserState`, `DoctrineIdentityRepository` |
| DI binding и `auto` config | `backend/config/services.yaml` |
| HTTP security errors | `Infrastructure\Http\ApiExceptionSubscriber` |

## 8. Сознательно отложено

- остальные security commands аккаунта;
- финансовые policies и проверки Debt/Transfer; Connect membership уже проверяется;
- финансовые frontend-разделы; Connections и смена пароля уже защищены.

## 9. Проверки безопасности

Unit, application и HTTP tests покрывают valid/wrong-purpose/expired/used/
invalidated/unknown token и подтверждают, что разрешённый `userId` берётся из
token. Отдельная process race доказывает один переход при двух конкурентных
activation requests. PasswordHasher test проверяет отсутствие plaintext в
результате, успешную проверку правильного пароля и отказ для неправильного.
Login tests покрывают credential enumeration boundary, inactive account,
hash-only session storage, cookie attributes, rate limits, rollback и отсутствие
secrets в response/serializer. Refresh tests покрывают same-origin/CSRF guard,
session-bound CSRF, inactive owner, malformed/unknown/expired/revoked/replayed
refresh tokens, hash-only rotation, неизменный expiry, rollback и конкурентный
race, где успешен ровно один запрос. Bearer/current-user tests покрывают strict
Authorization parsing, JWT failure collapse into `AUTHENTICATION_REQUIRED`,
fresh DB profile lookup, inactive/unknown user rejection, отсутствие cookies и
refresh rotation на `/me`, token-source discipline и public endpoint regression.
Browser E2E дополнительно проверяет реальный HTTPS путь `register -> activate
-> login -> reload -> authenticated /me`, cookie attributes, storage discipline,
safe redirects, login errors, двухвкладочную refresh-координацию, retry budget и
отказ `/me` без valid Bearer token. Общие HTTP tests продолжают проверять safe
errors, request ID и публичный healthcheck.

SC-003 дополнительно проверяет exact logout/clear-cookie contracts, owner
recheck и atomic bulk revoke, terminal/stale credential branches, rollback,
реальные DB/process races, local cleanup и signed-out lifecycle. Свежий набор:
322 backend, 40 unit, 27 component и 16 browser E2E PASS; полный release gate
блокирует dependency audit. [Акт проверки](../acceptance/logout-session-revocation.md)
фиксирует доказательства и ограничения.

## 10. Нормативные источники

- [Бизнес-правила](../business-rules/business-rules.md);
- [HTTP-контракты](../api/http-contracts.md);
- [ADR-006, ADR-007, ADR-012 и ADR-014—ADR-018](../adr/architecture-decisions.md);
- [Границы MVP](../mvp-scope.md).

## Восстановление пароля (SC-004)

Оба reset endpoints публичны. Authority apply — purpose-bound RESET_PASSWORD,
не cookie/Bearer. Request нормализует email и ограничивает email/IP 3/20 в час;
unknown/active/inactive получают одинаковый 202, unknown не создаёт token/outbox.
Для существующего пользователя User lock сериализует replacement всех unfinished
reset token (включая expired); новый HMAC token живёт 30 минут, raw capability
хранится только в encrypted outbox до terminal delivery.

Apply использует общие с активацией IP/hash quotas 10/5 на 15 минут, общую
Unicode password policy и строгую lifecycle/purpose validation. Owner lookup
выполняется без lock, затем User → token → sessions. Activation adapter использует
тот же порядок, включая wrong-purpose token. Под lock обновляются ORM identity и
lifecycle; login повторно сверяет изменившийся credential. Это исключает успешный
login со старым паролем после reset и инверсию row locks с активацией.

Password hash + token.usedAt + отзыв всех owned unfinished sessions атомарны;
rollback сохраняет прежнее состояние. isActive не меняется, auto-login нет.
Пустой 204 очищает обе cookies через общий AuthCookieFactory; ошибки cookies не
меняют. Уже выданный stateless JWT действует до exp по ADR-018. Backend не
раскрывает token/password/hash/SQL в public errors или logs. UI дополнительно
очищает query/SSR payload/private secrets и local session после 204.
[Матрица проверки](../acceptance/password-reset.md) включает реальные DB races,
HTTP privacy и browser lifecycle; dependency audit остаётся release blocker.
## Реализованная смена пароля SC-005

ChangePassword выполняет User → sessions: проверка fresh credentials, hash update
и bulk revoke атомарны. Для cookie authority/CSRF endpoint не используется; новый
rate profile не добавляется. Новый пароль проверяется общей Domain policy и HTTP
DTO. Успешный empty 204 очищает обе cookies через общую factory; JWT до exp,
frontend очищает auth и требует повторный login. Неверный currentPassword не
вызывает refresh/retry или local logout. [Акт](../acceptance/password-change.md).

## SC-008: capability и membership

Public preview проверяет HMAC и полный storage hash; Bearer не требуется.
Автор раскрывается только как id/name. Create/accept проверяют fresh activity
под lock, own acceptance запрещён. Connect читают только стороны; чужой ресурс
скрыт через 404, email другой стороны никогда не возвращается.

Отдельные EQUO_INVITATION_SIGNING_VERSION/KEYS задают HMAC-SHA256 key ring:
минимум 32 bytes, максимум восемь ключей; совместимые ключи сохраняются минимум
до истечения подписанных ссылок. TTL семь дней, signature Base64URL без padding,
в БД только SHA-256 полного token. Accept limits: IP 10/15 min и invitation 5/15 min.
Лимиты применяются и к повторам; invalid capability потребляет только IP quota.

Nginx access logs заменяют invitation path на redacted, исключают query/referrer.
Capability locations подавляют raw upstream error URLs; безопасная application
диагностика сохраняет request ID. Monolog processor скрывает token/hash,
SQL/parameters и exception detail. Browser fixtures очищают invitation paths,
trace/video отключены. Capability не сохраняется в SSR payload/storage; login
continuation использует память вкладки. [Акт проверки](../acceptance/connect-invitations.md).

## Финансовый доступ SC-011

Financial endpoints требуют active Bearer. Create author — subject; выбираемые
payer/participants заново проверяются под sorted User locks: self, собственный
Connect либо текущий active co-participant доступного active Debt.
Неактивный/недоступный выбор не даёт частичного эффекта; публичные UUID не дают права.

Debt читают author/payer/current participants. Исключённый ordinary participant
теряет Debt, но сохраняет свой Transfer/Connect history. Deleted Debt исключён
из списка; по прямой ссылке доступен сохранённому составу. Debt flags учитывают
author и activity payer/author, automatic Transfer flags всегда false.

Transfer ACL проверяется отдельно по Connect membership. Если Debt недоступен,
debtReference только id/isAccessible=false, без title/isDeleted/current состава.
Email других участников не возвращается. Недоступные и отсутствующие ресурсы
имеют одинаковые safe 404; общий subscriber исключает SQL/exception детали.

Backend validates activity/eligibility перед commit; replay возвращает прежний
результат только active автору. UI ограничивает выбор server-returned brief users,
очищает прежний account state и не показывает прежние balances во время нового load.
[Матрица ACL/privacy](../acceptance/create-debt.md).
