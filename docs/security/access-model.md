# Equo — фактическая модель доступа первого вертикального среза

| Поле | Значение |
|---|---|
| Назначение | Описать реализованную в E1-10 модель доступа регистрации и активации |
| Статус | Accepted |
| Версия | 4 |
| Дата актуальности | 2026-08-02 |
| Владелец | Maksim Smolkov |
| Источник | E1-10—E1-11; E1-13; BR-USR-001—003/009; BR-SEC-003—004; ADR-006/007/012/014—018; первый вертикальный срез |

## 1. Граница реализации

Первый вертикальный срез содержит только публичную регистрацию и применение
`ACTIVATE_ACCOUNT`. Login, JWT access token, refresh cookie, `UserSession`,
logout и финансовые protected endpoints явно исключены. Поэтому E1-10 не
добавляет production firewall, authenticator, временный bearer header,
hardcoded пользователя или фиктивную роль.

В срезе нет RBAC-ролей. Право активации является capability: его даёт только
действующий purpose-bound token. Успешная активация не создаёт аутентификацию и
не определяет current user для последующего запроса.

## 2. Матрица доступа

| Субъект | Операция | Объект | Условие | Результат отказа |
|---|---|---|---|---|
| Незарегистрированный посетитель | `POST /api/v1/auth/register` | Новый `User` | Публично; далее validation, idempotency и rate limit | Контрактная ошибка операции, не `401/403` |
| Предъявитель token | `POST /api/v1/auth/activate` | `User`, связанный с найденным token | Purpose `ACTIVATE_ACCOUNT`; token не истёк, не использован и не аннулирован | `400 INVALID_TOKEN` либо соответствующий `410` |
| Любой клиент | `GET /api/health` | Health state | Публично | — |
| Authenticated user | Protected API | — | Не входит в первый срез | Реализуется вместе с `MVP-SC-002` |

## 3. Идентификация и аутентификация

У registration нет текущего пользователя: субъект анонимен. При activation
идентифицируется не HTTP principal, а конкретный `User`, связанный с записью
`UserActionToken`, найденной по хэшу предъявленного секрета. `userId` не должен
приниматься от клиента или заменяться идентификатором из URL.

ADR-006/017 определяют будущую production-аутентификацию как `RS256` JWT access
token на 15 минут и rotating refresh session на 30 дней. Обязательны
`typ=at+jwt`, versioned `kid`, claims `iss/aud/sub/iat/exp/jti`, точная проверка
issuer/audience и clock skew не более 30 секунд. Она не реализована в E1-10,
потому что login и `UserSession` отсутствуют в границе и начальной схеме.

ADR-018 фиксирует browser lifecycle этой будущей аутентификации: access token
находится только в памяти вкладки; reload запускает client-side
`refresh → /me`; одновременные refresh сериализуются; protected navigation
ожидает bootstrap. Cookie-authenticated refresh/logout защищаются точной
same-origin проверкой и подписанным session-bound double-submit CSRF token.
Это принятое решение, но его production-реализация остаётся частью
`MVP-SC-002`, а не E1-10/E1-11.

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
вне БД/репозитория, старый verification key хранится минимум 31 день. E1-13
реализует generation, digest lookup, purpose/lifecycle policy и атомарное
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
| DI binding и `auto` config | `backend/config/services.yaml` |
| HTTP security errors | `Infrastructure\Http\ApiExceptionSubscriber` |

## 8. Сознательно отложено

- JWT generation/validation и current authenticated user;
- refresh cookie, rotation, revocation и `UserSession`;
- production firewall/entry point/access-control для protected endpoints;
- password login verification;
- финансовые voters/policies и проверки Connect/Debt/Transfer;
- реализация принятого ADR-018: in-memory token lifecycle, session bootstrap,
  межвкладочная сериализация refresh и CSRF/CORS checks; cookie-authenticated
  command в первом срезе отсутствует.

## 9. Проверки безопасности

Unit, application и HTTP tests покрывают valid/wrong-purpose/expired/used/
invalidated/unknown token и подтверждают, что разрешённый `userId` берётся из
token. Отдельная process race доказывает один переход при двух конкурентных
activation requests. PasswordHasher test проверяет отсутствие plaintext в
результате, успешную проверку правильного пароля и отказ для неправильного.
Общие HTTP tests продолжают проверять safe errors, request ID и публичный
healthcheck.

## 10. Нормативные источники

- [Бизнес-правила](../business-rules/business-rules.md);
- [HTTP-контракты](../api/http-contracts.md);
- [ADR-006, ADR-007, ADR-012 и ADR-014—ADR-018](../adr/architecture-decisions.md);
- [первый вертикальный срез](../first-vertical-slice.md#13-права-доступа-и-безопасность);
- [критерии приёмки](../first-vertical-slice-acceptance.md#66-права-token-lifecycle-и-переход-состояния).
