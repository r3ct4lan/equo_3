# Equo — фактическая модель доступа MVP

| Поле | Значение |
|---|---|
| Назначение | Описать реализованную модель доступа регистрации, активации, backend login и refresh rotation |
| Статус | Accepted |
| Версия | 7 |
| Дата актуальности | 2026-08-08 |
| Владелец | Maksim Smolkov |
| Источник | BR-USR-001—003/009; BR-SEC-003—004; ADR-006/007/012/014—018; HTTP-контракты; фактическая реализация |

## 1. Граница реализации

Текущая реализация содержит публичную регистрацию, применение
`ACTIVATE_ACCOUNT`, backend login и refresh rotation. Login создаёт
`UserSession`, выдаёт JWT access token в response body и устанавливает
refresh/CSRF cookies. Refresh endpoint ротирует refresh token и выдаёт новый
access token без продления срока сессии. JWT authenticator, `/me`, logout и
финансовые protected endpoints явно исключены. Поэтому текущий этап не добавляет
production firewall, authenticator, временный bearer header, hardcoded
пользователя или фиктивную роль.

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
| Authenticated user | Protected API | — | Ещё не реализовано | Реализуется на этапе authenticator и `/me` |

## 3. Идентификация и аутентификация

У registration нет текущего пользователя: субъект анонимен. При activation
идентифицируется не HTTP principal, а конкретный `User`, связанный с записью
`UserActionToken`, найденной по хэшу предъявленного секрета. `userId` не должен
приниматься от клиента или заменяться идентификатором из URL.

Login реализует начало production-аутентификации: `RS256` JWT access token на
15 минут, opaque refresh token в cookie и server-side `UserSession` на 30 дней.
Обязательны
`typ=at+jwt`, versioned `kid`, claims `iss/aud/sub/iat/exp/jti`, точная проверка
issuer/audience и clock skew не более 30 секунд. JWT authenticator и current-user
boundary ещё не реализованы, поэтому access token пока только выдаётся и
проверяется тестами.

Refresh rotation реализует backend-часть ADR-018: cookie-authenticated refresh
защищён точной same-origin проверкой, Fetch Metadata и подписанным
session-bound double-submit CSRF token. Сервер находит сессию по digest refresh
token под pessimistic lock, проверяет lifecycle и текущий `User.isActive`,
заменяет только `refresh_token_hash` и выпускает новый access token плюс новые
cookies. `expires_at` не сдвигается.

Оставшаяся часть ADR-018 ещё впереди: access token должен жить только в памяти
вкладки; reload запускает client-side `refresh → /me`; одновременные refresh во
frontend сериализуются; protected navigation ждёт bootstrap.

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
применение в `RegisterUser`/`ActivateAccount`. Token и связанный User
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

- `401 AUTHENTICATION_REQUIRED` применяется к будущему protected endpoint без
  действительной аутентификации;
- `403 FORBIDDEN` применяется к аутентифицированному субъекту, которому
  запрещён тип операции;
- `404 RESOURCE_NOT_FOUND` может объединять отсутствующий и недоступный объект,
  чтобы не раскрывать его существование согласно BR-ACL-005/ADR-012.

Эти статусы уже поддерживает общий error envelope E1-09, но в двух публичных
операциях первого среза не возникают. Отсутствие права activation выражается
`INVALID_TOKEN`/token lifecycle error, а не `401/403/404`.

## 7. Размещение

| Компонент | Расположение |
|---|---|
| Token purpose | `IdentityAccess\Domain\Access\UserActionTokenPurpose` |
| Activation policy и решения | `IdentityAccess\Application\Authorization` |
| Password hashing port | `IdentityAccess\Application\Port\PasswordHashingPort` |
| Symfony PasswordHasher adapter | `IdentityAccess\Adapter\Security\SymfonyPasswordHasher` |
| Login use case | `IdentityAccess\Application\Login\LoginUser` |
| Refresh use case | `IdentityAccess\Application\Refresh\RefreshSession` |
| Login controller/cookies | `IdentityAccess\Adapter\Http\LoginController`, `AuthCookieFactory` |
| Refresh controller/guard/cookies | `IdentityAccess\Adapter\Http\RefreshController`, `RefreshRequestGuard`, `AuthCookieFactory` |
| Login credential lookup | `IdentityAccess\Application\Port\StoredLoginIdentity`, `DoctrineIdentityRepository` |
| Current user lookup for refresh | `IdentityAccess\Application\Port\CurrentUserState`, `DoctrineIdentityRepository` |
| DI binding и `auto` config | `backend/config/services.yaml` |
| HTTP security errors | `Infrastructure\Http\ApiExceptionSubscriber` |

## 8. Сознательно отложено

- current authenticated user и protected JWT authenticator;
- production firewall/entry point/access-control для protected endpoints;
- logout, logout-all, family-wide revoke и refresh revocation endpoints;
- финансовые voters/policies и проверки Connect/Debt/Transfer;
- оставшаяся frontend-реализация принятого ADR-018: in-memory token lifecycle,
  session bootstrap и межвкладочная сериализация refresh.

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
race, где успешен ровно один запрос. Общие HTTP tests продолжают проверять safe
errors, request ID и публичный healthcheck.

## 10. Нормативные источники

- [Бизнес-правила](../business-rules/business-rules.md);
- [HTTP-контракты](../api/http-contracts.md);
- [ADR-006, ADR-007, ADR-012 и ADR-014—ADR-018](../adr/architecture-decisions.md);
- [Границы MVP](../mvp-scope.md).
