# Equo — реализованная начальная схема PostgreSQL

| Поле | Значение |
|---|---|
| Назначение | Зафиксировать фактически реализованную часть модели данных текущего MVP |
| Статус | Accepted |
| Версия | 7 |
| Дата актуальности | 2026-08-08 |
| Владелец | Maksim Smolkov |
| Источник | Модель сущностей; ER-диаграмма; ADR-001, ADR-006, ADR-007, ADR-009, ADR-011—ADR-016, ADR-019; фактические migrations и mapping |

## 1. Граница реализации

Для реализованных регистрации, активации аккаунта и серверного состояния будущей
browser-сессии используются пять записей:

| ER-сущность | Таблица | Doctrine-класс | Владелец |
|---|---|---|---|
| `User` | `app_user` | `App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord` | Identity and Access |
| `UserActionToken` | `user_action_token` | `App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord` | Identity and Access |
| `UserSession` | `user_session` | `App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserSessionRecord` | Identity and Access |
| `IdempotencyRecord` | `idempotency_record` | `App\Infrastructure\Idempotency\Persistence\Doctrine\Record\IdempotencyRecord` | Infrastructure |
| `EmailDeliveryOutbox` | `email_delivery_outbox` | `App\Infrastructure\EmailDelivery\Persistence\Doctrine\Record\EmailDeliveryOutboxRecord` | Infrastructure |

Техническое имя `app_user` используется вместо специального SQL-идентификатора
`user`. Имена колонок переведены из camelCase ER-модели в snake_case согласно
настроенной Doctrine naming strategy.

ER-тип `varchar` без заданной максимальной длины реализован как PostgreSQL
`TEXT`. Для PostgreSQL это не вводит ограничения длины и сохраняет требуемую
строковую семантику; одновременно Doctrine может однозначно сравнивать mapping с
фактической схемой. Произвольное ограничение `VARCHAR(255)` не добавлялось.

## 2. Поля и связи

### `app_user`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ |
| `name` | `TEXT` | нет | Отображаемое имя |
| `email` | `TEXT` | нет | Нормализованный email и логин |
| `password_hash` | `TEXT` | нет | Только хэш пароля |
| `is_active` | `BOOLEAN` | нет | Состояние активации |
| `created_at` | `TIMESTAMPTZ` | нет | Время регистрации в UTC |

### `user_action_token`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ |
| `user_id` | `UUID` | нет | FK на `app_user.id` |
| `token_hash` | `TEXT` | нет | Versioned HMAC-SHA-256 digest одноразового токена по ADR-016 |
| `purpose` | `TEXT` | нет | `ACTIVATE_ACCOUNT`, `RESET_PASSWORD` или `CHANGE_EMAIL` |
| `payload` | `JSONB` | да | Данные назначения токена |
| `created_at`, `expires_at` | `TIMESTAMPTZ` | нет | Начало и конец срока действия |
| `used_at`, `invalidated_at` | `TIMESTAMPTZ` | да | Взаимоисключающие конечные события |

В ORM связь token → user является `ManyToOne` внутри одного бизнес-модуля.

### `user_session`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ refresh-сессии |
| `user_id` | `UUID` | нет | FK на `app_user.id` |
| `refresh_token_hash` | `VARCHAR(255)` | нет | Только storage hash текущего opaque refresh token |
| `created_at`, `expires_at` | `TIMESTAMPTZ` | нет | Создание и 30-дневный срок refresh-сессии |
| `revoked_at` | `TIMESTAMPTZ` | да | Время явного отзыва |

В ORM связь session → user является `ManyToOne` внутри `IdentityAccess`.
Открытый refresh token формата `rt.<base64url(32 random bytes)>` не хранится:
в записи сохраняется только `sha256:<64 lowercase hex chars>`.

### `idempotency_record`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ |
| `scope`, `operation`, `idempotency_key`, `request_hash` | `TEXT` | нет | Пространство, команда, ключ и versioned HMAC-SHA-256 fingerprint запроса по ADR-019 |
| `user_id` | `UUID` | да | FK на `app_user.id` для навигации и аудита |
| `response_status` | `INTEGER` | да | Сохранённый HTTP-статус |
| `response_body` | `JSONB` | да | Сохранённый ответ |
| `created_at`, `expires_at` | `TIMESTAMPTZ` | нет | Начало и конец окна идемпотентности |

### `email_delivery_outbox`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ и стабильный delivery id |
| `user_action_token_id` | `UUID` | нет | FK на `user_action_token.id` |
| `recipient_email`, `template_key` | `TEXT` | нет | Получатель и шаблон |
| `encrypted_payload` | `BYTEA` | да | Зашифрованный payload до завершения доставки |
| `status` | `TEXT` | нет | `PENDING`, `PUBLISHED`, `SENT` или `FAILED` |
| `created_at`, `available_at` | `TIMESTAMPTZ` | нет | Создание и минимальное время публикации |
| `published_at`, `sent_at`, `failed_at` | `TIMESTAMPTZ` | да | Этапы доставки |
| `publish_attempts` | `INTEGER` | нет | Число попыток relay |
| `last_error` | `TEXT` | да | Безопасное диагностическое описание |

Межмодульные ссылки `idempotency_record.user_id` и
`email_delivery_outbox.user_action_token_id` представлены в records скалярными
UUID, а не ORM-association. Физические FK добавляет миграция. Listener
`InitialSchemaForeignKeyListener` сообщает об этих двух FK инструменту сравнения
Doctrine SchemaTool, не создавая зависимости Infrastructure от внутренних PHP-
моделей Identity and Access.

Все три FK используют `ON UPDATE NO ACTION ON DELETE RESTRICT`. Каскадное
удаление не применяется.

## 3. Ограничения и индексы PostgreSQL

База обеспечивает:

- PK для каждой таблицы;
- уникальность нормализованного `app_user.email`;
- уникальность `user_action_token.token_hash`;
- уникальность `user_session.refresh_token_hash`;
- не более одного незавершённого token на `(user_id, purpose)` частичным
  уникальным индексом;
- уникальность `(scope, operation, idempotency_key)`;
- не более одного outbox на `user_action_token_id`;
- допустимые значения `purpose` и `status`;
- непустые `User.name` и `User.email`;
- `expires_at > created_at` для token, session и idempotency record;
- взаимоисключающие `used_at`/`invalidated_at` и `sent_at`/`failed_at`;
- `publish_attempts >= 0` и `available_at >= created_at`.

Индексы созданы для FK и нормативных путей выборки: user, purpose, expiry и
used token; user, expiry и revocation session; user и expiry idempotency record; pending outbox по
`(available_at, created_at)`, status и временные метки завершения доставки.

## 4. Что реализовано в Application/Domain

PostgreSQL не дублирует контекстные правила. В Application/Domain реализованы:

- нормализация email перед сохранением и проверка уникальности;
- PasswordHasher `auto` и ADR-016 для password/action token;
- TTL token и idempotency record ровно 24 часа;
- application policy purpose/lifecycle action token;
- lifecycle `UserSession`: активна только пока `revokedAt IS NULL` и
  `expiresAt > now`; ротация меняет только hash активной session;
- согласованность activation payload, recipient/template и outbox lifecycle;
- атомарная координация `User`, token, outbox и idempotency result;
- сериализация конкурентных registration/activation и будущей refresh-rotation
  через advisory lock, уникальные ограничения и pessimistic row locks.

Остаётся отложенным физический retention cleanup по
ADR-014. Выдача replacement token с блокировкой существующего `User` относится
к исключённому resend/reactivation flow; первичная регистрация создаёт первый
token вместе с новой строкой `User`.

## 5. Сознательно отложенная ER-модель

Не создавались `Connect`, `ConnectInvitation`, `Debt`, `DebtParticipant`,
`Transfer`: они не нужны для регистрации, активации и session persistence и
относятся к следующим вертикальным сценариям. Login, refresh endpoint,
authenticator, `/me`, cookies, rate limits и frontend bootstrap пока не
реализованы. Не создавались mapper-слой для полной ER-модели, fixtures и
демонстрационные данные. Минимальные repositories, domain objects и application
use cases регистрации/активации находятся в модуле `IdentityAccess`.
Purpose enum и access policy описаны в
[модели доступа](../security/access-model.md) и не меняют схему.

## 6. Миграция и автоматические проверки

Начальную схему регистрации и активации создаёт миграция
[`Version20260731153000`](../../backend/migrations/Version20260731153000.php).
Session persistence добавляет миграция
[`Version20260808223000`](../../backend/migrations/Version20260808223000.php).
Предыдущая пустая стартовая миграция не изменялась. `down()` каждой миграции
удаляет только свои объекты; в рамках текущего этапа откат в dev-базе не
выполнялся.

Интеграционный тест
[`InitialSchemaTest`](../../backend/tests/Integration/Persistence/InitialSchemaTest.php)
проверяет ORM round trip, `NOT NULL`, уникальность, FK, частичный уникальный индекс
и ключевые `CHECK`. Session persistence дополнительно проверяет
[`UserSessionPersistenceTest`](../../backend/tests/Integration/IdentityAccess/UserSessionPersistenceTest.php):
hash-only storage, serializer hiding, pessimistic lock, rotation persistence and
rollback. Каждый тест выполняется в транзакции с rollback, кроме lock smoke с
явной очисткой committed rows. Команда
`make test-backend` поднимает отдельный PostgreSQL service `postgres-test` с
`tmpfs`, применяет миграции к пустой базе с суффиксом `_test`, проверяет
up-to-date status и синхронность mapping/schema, запускает PHPUnit и удаляет
временный контейнер. Dev-база и named volume `postgres_data` не затрагиваются.

Противоречий между ER-диаграммой, моделью сущностей, бизнес-правилами и ADR для
реализованного среза не обнаружено. Новых открытых вопросов не создано.

## 7. Нормативные источники

- [Модель сущностей](entities.md);
- [ER-диаграмма](er-diagram.md);
- [Бизнес-правила](../business-rules/business-rules.md);
- [Границы MVP](../mvp-scope.md);
- [ADR-001, ADR-006, ADR-007, ADR-009 и ADR-011—ADR-016](../adr/architecture-decisions.md).
