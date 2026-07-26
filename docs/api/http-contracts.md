# Equo — HTTP-контракты API v1

## Метаданные артефакта

| Поле | Значение |
|---|---|
| Название | Equo — HTTP-контракты API v1 |
| Назначение | Описать публичный HTTP API первой версии Equo |
| Статус | Финальная согласованная версия этапа проектирования |
| Версия | 1 |
| Дата актуальности | 2026-07-26 |
| Владелец | Maksim Smolkov |
| Источник | `equo-05-http-contracts.md` из приложенного архива `equo-artifacts-final.zip` |

## 1. Назначение документа

**Статус:** финальная согласованная версия этапа проектирования.

Связанные документы: [глоссарий](../glossary/glossary.md), [бизнес-правила](../business-rules/business-rules.md), [модель сущностей](../data-model/entities.md), [ER-диаграмма](../data-model/er-diagram.md), [ADR](../adr/architecture-decisions.md).


Документ описывает публичный HTTP API первой версии Equo:

- маршруты и HTTP-методы;
- аутентификацию;
- форматы запросов и ответов;
- правила идемпотентности;
- оптимистическую блокировку;
- пагинацию;
- коды ошибок;
- контракты регистрации, аккаунта, коннектов, долгов и трансферов.

Базовый префикс:

```text
/api/v1
```

Все примеры используют JSON и денежные значения в целых евроцентах.

---

# 2. Общие соглашения

## 2.1. Формат данных

Запросы и ответы:

```http
Content-Type: application/json
Accept: application/json
```

Имена JSON-полей используют `camelCase`.

Идентификаторы передаются как UUID:

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000"
}
```

Даты передаются в RFC 3339, в UTC:

```json
{
  "createdAt": "2026-07-26T18:42:15Z"
}
```

## 2.2. Денежные значения

Все суммы — целые числа в евроцентах.

```json
{
  "amount": 1250
}
```

означает `12,50 EUR`.

В MVP валюта всегда EUR, поэтому поле `currency` в командах не передаётся.

## 2.3. Аутентификация

Защищённые endpoints требуют access-токен:

```http
Authorization: Bearer <access-token>
```

Access-токен короткоживущий.

Refresh-токен передаётся в cookie:

```http
Set-Cookie: equo_refresh=<token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
```

Параметр `SameSite` может меняться конфигурацией развёртывания, если frontend и API работают на разных сайтах.

Каждая защищённая команда дополнительно проверяет актуальное значение `User.isActive`.

## 2.4. Request ID

Сервер присваивает запросу идентификатор:

```http
X-Request-Id: 01J3M8N8CNQH9Y0G4SKY2GCG4A
```

Клиент может передать собственный `X-Request-Id`; сервер либо сохраняет его, либо возвращает новый согласно инфраструктурной конфигурации.

## 2.5. Пустые ответы

Для успешной команды без тела используется:

```http
204 No Content
```

---

# 3. Стандартная модель ошибки

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "Request validation failed.",
    "details": {
      "violations": [
        {
          "field": "email",
          "code": "INVALID_EMAIL",
          "message": "Email has invalid format."
        }
      ]
    },
    "requestId": "01J3M8N8CNQH9Y0G4SKY2GCG4A"
  }
}
```

Поле `details` необязательно и всегда является JSON-объектом. Для ошибок валидации массив нарушений помещается в `details.violations`.

## 3.1. Основные HTTP-статусы

| Статус | Назначение |
|---:|---|
| `200` | Успешное чтение или идемпотентная команда |
| `201` | Ресурс создан |
| `202` | Запрос принят без раскрытия результата, например запрос сброса пароля |
| `204` | Команда выполнена, тела ответа нет |
| `400` | Некорректный JSON или синтаксис запроса |
| `401` | Нет аутентификации или неверные учётные данные |
| `403` | Пользователь аутентифицирован, но действие запрещено |
| `404` | Ресурс не найден или недоступен текущему пользователю |
| `409` | Конфликт состояния, версии, уникальности или идемпотентности |
| `410` | Токен или приглашение существовало, но истекло, было использовано или аннулировано |
| `422` | Бизнес-валидация запроса не пройдена |
| `428` | Для изменения не передан `If-Match` |
| `429` | Превышен rate limit |
| `500` | Непредвиденная ошибка сервера |

## 3.2. Основные коды ошибок

```text
VALIDATION_ERROR
INVALID_CREDENTIALS
AUTHENTICATION_REQUIRED
INVALID_REFRESH_TOKEN
ACCOUNT_INACTIVE
ACCOUNT_ALREADY_ACTIVE
EMAIL_ALREADY_EXISTS
EMAIL_ALREADY_IN_USE
INVALID_TOKEN
TOKEN_EXPIRED
TOKEN_USED
TOKEN_INVALIDATED
RATE_LIMIT_EXCEEDED
RESOURCE_NOT_FOUND
DEBT_NOT_AVAILABLE
CONNECT_NOT_AVAILABLE
TRANSFER_NOT_AVAILABLE
FORBIDDEN
VERSION_CONFLICT
IDEMPOTENCY_KEY_REQUIRED
IDEMPOTENCY_KEY_REUSED
INVALID_PARTICIPANT
INACTIVE_USER
DEBT_FROZEN
TRANSFER_FROZEN
DEBT_DELETED
TRANSFER_DELETED
INVALID_TRANSFER_RELATION
AUTOMATIC_TRANSFER_IMMUTABLE
```

Для недоступного или несуществующего ресурса API может возвращать одинаковый `404`, чтобы не раскрывать наличие данных.

---

# 4. Идемпотентность

## 4.1. Заголовок

Для команд создания, где сетевой повтор может создать дубликат, обязателен:

```http
Idempotency-Key: <UUID>
```

Обязателен для:

- `POST /auth/register`;
- `POST /debts`;
- `POST /connects`;
- `POST /connects/{connectId}/transfers`.

## 4.2. Поведение

Сервер формирует область уникальности:

```text
scope + operation + idempotencyKey
```

Примеры scope:

```text
user:<userId>  — авторизованная команда
public         — регистрация
```

Правила:

- первая транзакция резервирует `IdempotencyRecord` с пустым результатом;
- конкурентный запрос с тем же ключом ожидает завершения уникальной вставки и затем читает сохранённый результат;
- новый ключ — команда выполняется;
- тот же ключ и тот же нормализованный запрос — возвращается первоначальный статус и тело;
- тот же ключ с другим запросом — `409 IDEMPOTENCY_KEY_REUSED`;
- запись и бизнес-изменения сохраняются в согласованной транзакции;
- срок хранения `IdempotencyRecord` задаётся конфигурацией и определяет окно гарантированной идемпотентности; после физической очистки тот же ключ считается новым.

Если заголовок обязателен, но отсутствует:

```http
400 Bad Request
```

```json
{
  "error": {
    "code": "IDEMPOTENCY_KEY_REQUIRED",
    "message": "Idempotency-Key header is required."
  }
}
```

---

# 5. Оптимистическая блокировка

Изменяемые сущности `Debt` и `Transfer` имеют поле `version`.

Ответы чтения содержат:

```http
ETag: "3"
```

Команды изменения и удаления требуют:

```http
If-Match: "3"
```

При отсутствии заголовка:

```http
428 Precondition Required
```

При несовпадении версии:

```http
409 Conflict
```

```json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "The resource was changed by another request.",
    "details": {
      "expectedVersion": 3,
      "actualVersion": 4
    }
  }
}
```

После успешного изменения сервер возвращает новую версию и новый `ETag`. Для `DEBT_SHARE` версия строки может меняться при перерасчёте, удалении или восстановлении, но публичные команды по-прежнему используют версию корня `Debt`.

---

# 6. Пагинация

Списки используют cursor pagination.

Параметры:

```text
cursor — непрозрачный курсор;
limit — количество элементов, по умолчанию 20, максимум 100.
```

Пример:

```http
GET /api/v1/connects?limit=20&cursor=eyJjcmVhdGVkQXQiOi...
```

Ответ:

```json
{
  "items": [],
  "pagination": {
    "nextCursor": null,
    "hasMore": false
  }
}
```

Если отдельно не указано обратное, сортировка выполняется по:

```text
createdAt DESC, id DESC
```

---

# 7. Общие представления

## 7.1. Краткий пользователь

```json
{
  "id": "25490790-1faa-4c0d-aad3-7c2967fc7751",
  "name": "Boris",
  "isActive": true
}
```

Email других пользователей в финансовых ответах не возвращается.

## 7.2. Баланс коннекта

Баланс возвращается с точки зрения текущего пользователя:

```json
{
  "balanceAmount": 1250
}
```

Интерпретация:

- `balanceAmount > 0` — другой пользователь должен текущему;
- `balanceAmount < 0` — текущий пользователь должен другому;
- `balanceAmount = 0` — баланс равен нулю.

---

# 8. Регистрация и аутентификация

## 8.1. Регистрация

```http
POST /api/v1/auth/register
Idempotency-Key: <UUID>
```

### Запрос

```json
{
  "name": "Maxim",
  "email": "maxim@example.com",
  "password": "correct horse battery staple"
}
```

### Результат

```http
201 Created
```

```json
{
  "user": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim",
    "email": "maxim@example.com",
    "isActive": false,
    "createdAt": "2026-07-26T18:42:15Z"
  },
  "activationRequired": true
}
```

Сессия не создаётся.

Сервер:

1. нормализует email;
2. создаёт `User.isActive = false`;
3. создаёт `ACTIVATE_ACCOUNT`;
4. отправляет письмо.

### Ошибки

- `409 EMAIL_ALREADY_EXISTS`;
- `422 VALIDATION_ERROR`;
- `429 RATE_LIMIT_EXCEEDED`.

---

## 8.2. Запрос ссылки активации

Используется для повторной отправки первичной ссылки и для реактивации после добровольной деактивации. В обоих случаях требуются корректные email и пароль.

```http
POST /api/v1/auth/activation-requests
```

### Запрос

```json
{
  "email": "maxim@example.com",
  "password": "correct horse battery staple"
}
```

### Результат

```http
202 Accepted
```

```json
{
  "status": "activation_email_scheduled"
}
```

Условия:

- email и пароль должны быть верны;
- аккаунт должен быть неактивен;
- предыдущие активные `ACTIVATE_ACCOUNT` токены аннулируются через `invalidatedAt`;
- создаётся новый токен.

### Ошибки

- `401 INVALID_CREDENTIALS`;
- `409` с кодом `ACCOUNT_ALREADY_ACTIVE`;
- `429 RATE_LIMIT_EXCEEDED`.

---

## 8.3. Активация аккаунта

```http
POST /api/v1/auth/activate
```

### Запрос

```json
{
  "token": "raw-one-time-token"
}
```

### Результат

```http
204 No Content
```

Результат:

```text
User.isActive = true
```

Сессия автоматически не создаётся.

### Ошибки

- `400 INVALID_TOKEN`;
- `410 TOKEN_EXPIRED`;
- `410 TOKEN_USED`;
- `410 TOKEN_INVALIDATED`.

---

## 8.4. Вход

```http
POST /api/v1/auth/login
```

### Запрос

```json
{
  "email": "maxim@example.com",
  "password": "correct horse battery staple"
}
```

### Результат

```http
200 OK
Set-Cookie: equo_refresh=<token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
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

### Ошибки

- `401 INVALID_CREDENTIALS`;
- `403 ACCOUNT_INACTIVE`;
- `429 RATE_LIMIT_EXCEEDED`.

`ACCOUNT_INACTIVE` возвращается только после успешной проверки email и пароля.

---

## 8.5. Обновление сессии

```http
POST /api/v1/auth/refresh
Cookie: equo_refresh=<token>
```

### Результат

```http
200 OK
Set-Cookie: equo_refresh=<new-token>; HttpOnly; Secure; SameSite=Lax; Path=/api/v1/auth
```

```json
{
  "accessToken": "<new-jwt>",
  "expiresIn": 900
}
```

Refresh-токен ротируется. Старый токен становится недействительным.

### Ошибки

- `401 AUTHENTICATION_REQUIRED`;
- `401 INVALID_REFRESH_TOKEN`;
- `403 ACCOUNT_INACTIVE`.

---

## 8.6. Выход из текущей сессии

```http
POST /api/v1/auth/logout
```

Требует refresh cookie. Access-токен необязателен.

### Результат

```http
204 No Content
Set-Cookie: equo_refresh=; Max-Age=0; HttpOnly; Secure; Path=/api/v1/auth
```

Повторный вызов также возвращает `204`. Уже выданный access-токен может действовать до короткого `exp`.

---

## 8.7. Выход со всех устройств

```http
POST /api/v1/auth/logout-all
Authorization: Bearer <access-token>
```

### Результат

```http
204 No Content
```

Все `UserSession` пользователя отзываются. Уже выданные access-токены могут действовать до короткого `exp`; новые access-токены получить нельзя.

---

# 9. Пароль и email

## 9.1. Запрос сброса пароля

```http
POST /api/v1/auth/password-reset-requests
```

### Запрос

```json
{
  "email": "maxim@example.com"
}
```

### Результат

Всегда:

```http
202 Accepted
```

```json
{
  "status": "password_reset_email_scheduled"
}
```

Ответ не раскрывает существование аккаунта.

---

## 9.2. Сброс пароля

```http
POST /api/v1/auth/password-reset
```

### Запрос

```json
{
  "token": "raw-one-time-token",
  "newPassword": "new correct horse battery staple"
}
```

### Результат

```http
204 No Content
Set-Cookie: equo_refresh=; Max-Age=0; HttpOnly; Secure; Path=/api/v1/auth
```

Сервер:

- меняет `passwordHash`;
- отзывает все refresh-сессии;
- очищает refresh cookie;
- не меняет `isActive`.

Уже выданный access-токен может технически действовать до короткого `exp`.

---

## 9.3. Смена пароля

```http
POST /api/v1/account/password-change
Authorization: Bearer <access-token>
```

### Запрос

```json
{
  "currentPassword": "old password",
  "newPassword": "new password"
}
```

### Результат

```http
204 No Content
Set-Cookie: equo_refresh=; Max-Age=0; HttpOnly; Secure; Path=/api/v1/auth
```

Все refresh-сессии, включая текущую, отзываются. Ответ очищает refresh cookie, после чего клиент должен войти заново. Уже выданный access-токен может технически действовать до короткого `exp`.

---

## 9.4. Запрос смены email

```http
POST /api/v1/account/email-change-requests
Authorization: Bearer <access-token>
```

### Запрос

```json
{
  "currentPassword": "correct horse battery staple",
  "newEmail": "new@example.com"
}
```

### Результат

```http
202 Accepted
```

```json
{
  "status": "email_confirmation_scheduled"
}
```

В `UserActionToken.payload` сохраняется:

```json
{
  "newEmail": "new@example.com"
}
```

До подтверждения текущий email не изменяется.

---

## 9.5. Подтверждение нового email

```http
POST /api/v1/auth/email-change-confirmations
```

### Запрос

```json
{
  "token": "raw-one-time-token"
}
```

### Результат

```http
204 No Content
```

В момент подтверждения сервер повторно проверяет уникальность нового email.

### Ошибки

- `409 EMAIL_ALREADY_IN_USE`;
- стандартные ошибки токена.

---

# 10. Текущий пользователь и аккаунт

## 10.1. Получение профиля

```http
GET /api/v1/me
Authorization: Bearer <access-token>
```

### Ответ

```http
200 OK
```

```json
{
  "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
  "name": "Maxim",
  "email": "maxim@example.com",
  "isActive": true,
  "createdAt": "2026-07-26T18:42:15Z"
}
```

---

## 10.2. Изменение имени

```http
PATCH /api/v1/me
Authorization: Bearer <access-token>
```

### Запрос

```json
{
  "name": "Max"
}
```

### Ответ

```http
200 OK
```

Возвращается обновлённый профиль.

Email и пароль через этот endpoint не изменяются.

---

## 10.3. Деактивация аккаунта

```http
POST /api/v1/account/deactivate
Authorization: Bearer <access-token>
```

### Запрос

```json
{
  "password": "correct horse battery staple"
}
```

### Результат

```http
204 No Content
Set-Cookie: equo_refresh=; Max-Age=0; HttpOnly; Secure; Path=/api/v1/auth
```

Сервер:

- устанавливает `isActive = false`;
- отзывает все refresh-сессии;
- не изменяет долги, коннекты, трансферы и балансы.

---

# 11. Коннекты

## 11.1. Список коннектов

```http
GET /api/v1/connects?limit=20&cursor=<cursor>
Authorization: Bearer <access-token>
```

### Ответ

```json
{
  "items": [
    {
      "id": "45483aef-17f2-4870-8691-fd93d0687765",
      "otherUser": {
        "id": "25490790-1faa-4c0d-aad3-7c2967fc7751",
        "name": "Boris",
        "isActive": true
      },
      "balanceAmount": 1250,
      "canCreateDebt": true,
      "canCreateTransfer": true,
      "createdAt": "2026-07-20T10:00:00Z"
    }
  ],
  "pagination": {
    "nextCursor": null,
    "hasMore": false
  }
}
```

Все коннекты отображаются, включая коннекты с неактивными пользователями.

Для неактивной второй стороны:

```json
{
  "canCreateDebt": false,
  "canCreateTransfer": false
}
```

---

## 11.2. Получение коннекта

```http
GET /api/v1/connects/{connectId}
Authorization: Bearer <access-token>
```

### Ответ

```json
{
  "id": "45483aef-17f2-4870-8691-fd93d0687765",
  "otherUser": {
    "id": "25490790-1faa-4c0d-aad3-7c2967fc7751",
    "name": "Boris",
    "isActive": false
  },
  "balanceAmount": -800,
  "canCreateDebt": false,
  "canCreateTransfer": false,
  "restriction": {
    "code": "OTHER_USER_INACTIVE",
    "message": "New financial operations are unavailable until the user reactivates the account."
  },
  "createdAt": "2026-07-20T10:00:00Z"
}
```

---

## 11.3. Создание коннекта через общий долг

```http
POST /api/v1/connects
Authorization: Bearer <access-token>
Idempotency-Key: <UUID>
```

### Запрос

```json
{
  "otherUserId": "25490790-1faa-4c0d-aad3-7c2967fc7751",
  "sharedDebtId": "58b07448-a88c-4701-b71d-fe5ffc819687"
}
```

### Результат

Если создан:

```http
201 Created
```

Если уже существовал:

```http
200 OK
```

В обоих случаях возвращается представление коннекта.

### Проверки

- оба пользователя активны;
- пользователи разные;
- оба имеют доступ к указанному долгу;
- долг не удалён.

---

# 12. Приглашения в коннект

## 12.1. Формат токена приглашения

Для `ConnectInvitation` действует особое требование: пока приглашение действительно, владелец должен иметь возможность повторно получить ту же ссылку.

Обычный случайный токен, от которого в базе хранится только необратимый хэш, восстановить невозможно. Поэтому токен приглашения формируется воспроизводимо:

```text
<invitationId>.<signature>
```

где:

```text
signature = HMAC-SHA256(invitationId, invitationSigningKey)
```

Сервер:

1. извлекает `invitationId`;
2. проверяет подпись;
3. загружает `ConnectInvitation`;
4. дополнительно может сверить `tokenHash` полного токена;
5. проверяет `usedAt` и `expiresAt`.

Такой токен:

- можно повторно сформировать для существующего приглашения;
- нельзя подделать без серверного ключа;
- не требует хранения открытого токена или нового поля;
- отличается от случайных одноразовых `UserActionToken`.

Новые ссылки подписываются активным `invitationSigningKey`. Для повторного формирования существующей ссылки сервер перебирает активный и совместимые старые ключи и выбирает вариант, чей полный `tokenHash` совпадает с записью. Старый ключ сохраняется до истечения подписанных им приглашений.

## 12.2. Получить или создать приглашение

```http
POST /api/v1/connect-invitations
Authorization: Bearer <access-token>
```

Тело отсутствует.

### Ответ

```http
200 OK
```

```json
{
  "token": "raw-invitation-token",
  "url": "https://equo.example/connect-invitations/raw-invitation-token",
  "expiresAt": "2026-08-02T18:42:15Z",
  "created": false
}
```

Если действующего приглашения не было, `created = true`.

Публичный токен возвращается владельцу приглашения. В базе хранится только хэш.

---

## 12.3. Информация о приглашении

```http
GET /api/v1/connect-invitations/{token}
```

Авторизация необязательна.

### Ответ

```json
{
  "inviter": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim"
  },
  "expiresAt": "2026-08-02T18:42:15Z",
  "canAccept": true
}
```

### Ошибки

- `404 RESOURCE_NOT_FOUND`;
- `410 TOKEN_EXPIRED`;
- `410 TOKEN_USED`.

---

## 12.4. Принять приглашение

```http
POST /api/v1/connect-invitations/{token}/accept
Authorization: Bearer <access-token>
```

### Результат

Если коннект создан:

```http
201 Created
```

Если `Connect` уже существовал либо это повтор принятия тем же `acceptedById`:

```http
200 OK
```

Возвращается представление коннекта. Если приглашение использовано другим пользователем, возвращается `410 TOKEN_USED`.

### Ошибки

- собственное приглашение — `422 VALIDATION_ERROR`;
- неактивная сторона — `409 INACTIVE_USER`;
- истёкшее приглашение — `410 TOKEN_EXPIRED`.

---

# 13. Долги

## 13.1. Представление долга

```json
{
  "id": "58b07448-a88c-4701-b71d-fe5ffc819687",
  "title": "Dinner",
  "description": "Restaurant bill",
  "totalAmount": 6000,
  "payer": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim",
    "isActive": true
  },
  "createdBy": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim",
    "isActive": true
  },
  "includePayerInSplit": true,
  "shareAmount": 2000,
  "participants": [
    {
      "debtParticipantId": "5997bb40-5e08-4212-ac57-69af341e6662",
      "user": {
        "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
        "name": "Maxim",
        "isActive": true
      },
      "isPayer": true,
      "isAuthor": true,
      "shareAmount": 2000,
      "transferId": null
    },
    {
      "debtParticipantId": "6d98c852-638f-4512-972c-cf2b821f7397",
      "user": {
        "id": "25490790-1faa-4c0d-aad3-7c2967fc7751",
        "name": "Boris",
        "isActive": true
      },
      "isPayer": false,
      "isAuthor": false,
      "shareAmount": 2000,
      "transferId": "860f68dd-4956-498e-a422-26684f4f80ef"
    }
  ],
  "isDeleted": false,
  "canEdit": true,
  "canDelete": true,
  "version": 3,
  "createdAt": "2026-07-26T18:42:15Z"
}
```

`participants` содержит записи текущего состава (`DebtParticipant.isDeleted = false`). У удалённого долга — состав на момент его удаления.

---

## 13.2. Список долгов

```http
GET /api/v1/debts?limit=20&cursor=<cursor>
Authorization: Bearer <access-token>
```

Возвращаются только:

- неудалённые долги;
- доступные текущему пользователю.

Удалённые долги в списке не показываются.

### Ответ

```json
{
  "items": [
    {
      "id": "58b07448-a88c-4701-b71d-fe5ffc819687",
      "title": "Dinner",
      "totalAmount": 6000,
      "payer": {
        "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
        "name": "Maxim",
        "isActive": true
      },
      "participantCount": 3,
      "shareAmount": 2000,
      "canEdit": true,
      "version": 3,
      "createdAt": "2026-07-26T18:42:15Z"
    }
  ],
  "pagination": {
    "nextCursor": null,
    "hasMore": false
  }
}
```

---

## 13.3. Создание долга

```http
POST /api/v1/debts
Authorization: Bearer <access-token>
Idempotency-Key: <UUID>
```

### Запрос

`participantIds` содержит только пользователей, отличных от плательщика.

```json
{
  "title": "Dinner",
  "description": "Restaurant bill",
  "totalAmount": 6000,
  "payerId": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
  "participantIds": [
    "25490790-1faa-4c0d-aad3-7c2967fc7751",
    "03b179bc-cf9c-45dd-9236-6d420a346e19"
  ],
  "includePayerInSplit": true
}
```

### Результат

```http
201 Created
Location: /api/v1/debts/58b07448-a88c-4701-b71d-fe5ffc819687
ETag: "1"
```

Возвращается полное представление долга.

### Проверки

- `totalAmount > 0`;
- есть хотя бы один участник, отличный от плательщика;
- плательщик активен;
- все участники активны;
- автор является плательщиком или присутствует в `participantIds`;
- каждый выбранный пользователь доступен автору: это сам автор, сторона его существующего `Connect` либо текущий соучастник с активным аккаунтом доступного автору долга;
- `participantIds` не содержит плательщика и дублей.

Сервер атомарно:

1. создаёт `Debt`;
2. создаёт `DebtParticipant`;
3. при необходимости создаёт `Connect` плательщика с участниками;
4. рассчитывает доли;
5. создаёт `DEBT_SHARE`.

---

## 13.4. Получение долга

```http
GET /api/v1/debts/{debtId}
Authorization: Bearer <access-token>
```

### Ответ

```http
200 OK
ETag: "3"
```

Возвращается полное представление долга.

Удалённый долг может быть открыт по прямой ссылке или из трансфера, если пользователь имел доступ на момент удаления.

Недоступный или несуществующий долг:

```http
404 Not Found
```

```json
{
  "error": {
    "code": "DEBT_NOT_AVAILABLE",
    "message": "This debt is no longer available."
  }
}
```

---

## 13.5. Изменение долга

```http
PUT /api/v1/debts/{debtId}
Authorization: Bearer <access-token>
If-Match: "3"
```

Тело описывает полное желаемое редактируемое состояние.

```json
{
  "title": "Dinner and drinks",
  "description": null,
  "totalAmount": 7200,
  "participantIds": [
    "25490790-1faa-4c0d-aad3-7c2967fc7751",
    "03b179bc-cf9c-45dd-9236-6d420a346e19"
  ],
  "includePayerInSplit": false
}
```

`payerId` не принимается: плательщик неизменяем.

### Результат

```http
200 OK
ETag: "4"
```

Возвращается обновлённый долг.

Сервер атомарно:

- обновляет поля;
- сравнивает старый и новый состав;
- создаёт, удаляет или восстанавливает `DebtParticipant`;
- создаёт недостающие `Connect`;
- полностью пересчитывает все `DEBT_SHARE`;
- увеличивает версию.

### Ошибки

- не автор — `403 FORBIDDEN`;
- автор или плательщик неактивен — `409 DEBT_FROZEN`;
- долг удалён — `409 DEBT_DELETED`;
- несовпадение версии — `409 VERSION_CONFLICT`.

---

## 13.6. Удаление долга

```http
DELETE /api/v1/debts/{debtId}
Authorization: Bearer <access-token>
If-Match: "4"
```

### Результат

```http
204 No Content
```

Сервер:

- устанавливает `Debt.isDeleted = true`;
- исключает все `DEBT_SHARE` долга из баланса;
- не изменяет ручные трансферы;
- не помечает `DebtParticipant` удалёнными;
- увеличивает версию.

Восстановление долга в MVP отсутствует.

---

# 14. Трансферы

## 14.1. Представление трансфера

```json
{
  "id": "860f68dd-4956-498e-a422-26684f4f80ef",
  "connectId": "45483aef-17f2-4870-8691-fd93d0687765",
  "sender": {
    "id": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
    "name": "Maxim",
    "isActive": true
  },
  "receiver": {
    "id": "25490790-1faa-4c0d-aad3-7c2967fc7751",
    "name": "Boris",
    "isActive": true
  },
  "amount": 2000,
  "type": "DEBT_SHARE",
  "createdBy": null,
  "debtReference": {
    "id": "58b07448-a88c-4701-b71d-fe5ffc819687",
    "title": "Dinner",
    "isAccessible": true,
    "isDeleted": false
  },
  "relatedTransferId": null,
  "isDeleted": false,
  "canEdit": false,
  "canDelete": false,
  "version": 2,
  "createdAt": "2026-07-26T18:42:15Z"
}
```

Для недоступного связанного долга:

```json
{
  "debtReference": {
    "id": "58b07448-a88c-4701-b71d-fe5ffc819687",
    "isAccessible": false
  }
}
```

Карточка долга не открывается. Название, признак удаления и другие текущие данные недоступного долга не возвращаются.

---

## 14.2. История трансферов коннекта

```http
GET /api/v1/connects/{connectId}/transfers?limit=20&cursor=<cursor>
Authorization: Bearer <access-token>
```

Возвращается полная история:

- активные и мягко удалённые `DEBT_SHARE`;
- активные и мягко удалённые `MANUAL`.

Мягко удалённые записи не влияют на баланс, но остаются видимыми обеим сторонам.

### Ответ

```json
{
  "items": [],
  "pagination": {
    "nextCursor": null,
    "hasMore": false
  }
}
```

---

## 14.3. Получение трансфера

```http
GET /api/v1/transfers/{transferId}
Authorization: Bearer <access-token>
```

### Ответ

```http
200 OK
ETag: "2"
```

Трансфер доступен любой стороне его коннекта.

---

## 14.4. Создание ручного трансфера

```http
POST /api/v1/connects/{connectId}/transfers
Authorization: Bearer <access-token>
Idempotency-Key: <UUID>
```

### Запрос

```json
{
  "senderId": "25490790-1faa-4c0d-aad3-7c2967fc7751",
  "receiverId": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
  "amount": 1500,
  "relatedTransferId": "860f68dd-4956-498e-a422-26684f4f80ef"
}
```

### Результат

```http
201 Created
Location: /api/v1/transfers/881cc983-edda-4ea5-9489-27371ab68898
ETag: "1"
```

Возвращается созданный `MANUAL`.

### Проверки

- текущий пользователь является стороной коннекта;
- отправитель и получатель — две стороны этого коннекта;
- текущий пользователь является отправителем или получателем;
- обе стороны активны;
- `amount > 0`;
- при создании `relatedTransferId`, если указан, ссылается на неудалённый `DEBT_SHARE` того же коннекта.

Сумма не ограничивается текущим балансом.

---

## 14.5. Изменение ручного трансфера

```http
PUT /api/v1/transfers/{transferId}
Authorization: Bearer <access-token>
If-Match: "1"
```

### Запрос

```json
{
  "senderId": "25490790-1faa-4c0d-aad3-7c2967fc7751",
  "receiverId": "952adfb3-c5f6-4bd9-87cb-405d456a2f9a",
  "amount": 1700,
  "relatedTransferId": null
}
```

### Результат

```http
200 OK
ETag: "2"
```

Возвращается обновлённый трансфер.

### Ограничения

- только `MANUAL`;
- только автор;
- обе стороны активны;
- трансфер не удалён;
- стороны остаются сторонами исходного коннекта;
- допускается только сохранение или перестановка направления;
- при установке или замене `relatedTransferId` цель должна быть неудалённым `DEBT_SHARE` того же коннекта;
- прежняя неизменённая ссылка может сохраняться при редактировании других полей, даже если цель позднее удалена; ссылку можно очистить.

---

## 14.6. Удаление ручного трансфера

```http
DELETE /api/v1/transfers/{transferId}
Authorization: Bearer <access-token>
If-Match: "2"
```

### Результат

```http
204 No Content
```

Трансфер:

- получает `isDeleted = true`;
- перестаёт влиять на баланс;
- остаётся видимым в истории;
- не восстанавливается.

`DEBT_SHARE` через этот endpoint удалить нельзя.

---

# 15. Автоматические трансферы

Для `DEBT_SHARE` отсутствуют публичные команды создания, изменения и удаления.

Они управляются только через:

```text
POST /debts
PUT /debts/{debtId}
DELETE /debts/{debtId}
```

Попытка изменить `DEBT_SHARE` через `/transfers/{id}` возвращает:

```http
403 Forbidden
```

```json
{
  "error": {
    "code": "AUTOMATIC_TRANSFER_IMMUTABLE",
    "message": "Automatic debt shares can only be changed through the debt."
  }
}
```

---

# 16. Матрица endpoints

## 16.1. Auth и аккаунт

| Метод | Endpoint | Авторизация |
|---|---|---|
| `POST` | `/auth/register` | нет |
| `POST` | `/auth/activation-requests` | нет |
| `POST` | `/auth/activate` | нет |
| `POST` | `/auth/login` | нет |
| `POST` | `/auth/refresh` | refresh cookie |
| `POST` | `/auth/logout` | refresh cookie |
| `POST` | `/auth/logout-all` | access token |
| `POST` | `/auth/password-reset-requests` | нет |
| `POST` | `/auth/password-reset` | нет |
| `POST` | `/auth/email-change-confirmations` | нет |
| `POST` | `/account/password-change` | access token |
| `POST` | `/account/email-change-requests` | access token |
| `POST` | `/account/deactivate` | access token |
| `GET` | `/me` | access token |
| `PATCH` | `/me` | access token |

## 16.2. Connect

| Метод | Endpoint | Назначение |
|---|---|---|
| `GET` | `/connects` | список коннектов |
| `GET` | `/connects/{connectId}` | карточка коннекта |
| `POST` | `/connects` | создать через общий долг |
| `GET` | `/connects/{connectId}/transfers` | история |
| `POST` | `/connects/{connectId}/transfers` | ручной трансфер |

## 16.3. Invitations

| Метод | Endpoint | Назначение |
|---|---|---|
| `POST` | `/connect-invitations` | получить или создать приглашение |
| `GET` | `/connect-invitations/{token}` | информация |
| `POST` | `/connect-invitations/{token}/accept` | принять |

## 16.4. Debt

| Метод | Endpoint | Назначение |
|---|---|---|
| `GET` | `/debts` | список активных долгов |
| `POST` | `/debts` | создать |
| `GET` | `/debts/{debtId}` | получить |
| `PUT` | `/debts/{debtId}` | изменить |
| `DELETE` | `/debts/{debtId}` | мягко удалить |

## 16.5. Transfer

| Метод | Endpoint | Назначение |
|---|---|---|
| `GET` | `/transfers/{transferId}` | получить |
| `PUT` | `/transfers/{transferId}` | изменить `MANUAL` |
| `DELETE` | `/transfers/{transferId}` | удалить `MANUAL` |

---

# 17. Rate limits

Конкретные значения задаются конфигурацией.

Отдельные лимиты должны существовать как минимум для:

- входа;
- регистрации;
- запросов активации;
- сброса пароля;
- смены email;
- использования одноразовых токенов;
- принятия приглашений.

Ответ:

```http
429 Too Many Requests
Retry-After: 60
```

---

# 18. Транзакционность команд

Следующие команды выполняются атомарно:

- регистрация пользователя и создание токена активации;
- создание долга;
- изменение долга;
- удаление долга;
- создание ручного трансфера;
- изменение ручного трансфера;
- принятие приглашения;
- активация аккаунта;
- сброс пароля;
- подтверждение смены email.

Надёжная доставка email является инфраструктурной обязанностью и не изменяет публичный HTTP-контракт. Конкретный механизм — синхронная отправка, очередь или transactional outbox — фиксируется отдельным архитектурным решением.

---

# 19. Что не входит в API v1

- несколько валют;
- отмена или удаление коннекта;
- восстановление удалённого долга;
- восстановление удалённого ручного трансфера;
- прямое редактирование `DEBT_SHARE`;
- статусы долга `open`, `paid`, `closed`;
- автоматическое распределение возвратов по долгам;
- отдельный архивный список долгов;
- пользовательская дата финансовой операции;
- общий поиск пользователей.


---

# 20. Итоговая согласованность

Контракт согласован с [бизнес-правилами](../business-rules/business-rules.md) и [моделью сущностей](../data-model/entities.md). В частности:

- публичная регистрация использует scope `public`, поэтому один idempotency key нельзя применить к другому email;
- повтор принятия приглашения тем же пользователем возвращает существующий `Connect`;
- история содержит мягко удалённые `MANUAL` и `DEBT_SHARE`;
- смена и сброс пароля имеют однозначную семантику отзыва refresh-сессий;
- изменение неизменённой исторической ссылки `relatedTransferId` не требуется, если цель позднее удалена.
