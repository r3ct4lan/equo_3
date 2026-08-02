# Equo — реализованная начальная схема PostgreSQL

| Поле | Значение |
|---|---|
| Назначение | Зафиксировать фактически реализованную в E1-08 часть модели данных первого вертикального среза |
| Статус | Accepted |
| Версия | 3 |
| Дата актуальности | 2026-07-31 |
| Владелец | Maksim Smolkov |
| Источник | E1-08—E1-10 и test infrastructure E1-12; модель сущностей; ER-диаграмма; ADR-001, ADR-006, ADR-007, ADR-009, ADR-011—ADR-016 |

## 1. Граница реализации

Для регистрации и активации аккаунта реализованы только четыре записи, без
которых невозможно выполнить первый вертикальный сценарий:

| ER-сущность | Таблица | Doctrine-класс | Владелец |
|---|---|---|---|
| `User` | `app_user` | `App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserRecord` | Identity and Access |
| `UserActionToken` | `user_action_token` | `App\IdentityAccess\Adapter\Persistence\Doctrine\Record\UserActionTokenRecord` | Identity and Access |
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

### `idempotency_record`

| Колонка | PostgreSQL | NULL | Назначение |
|---|---|---:|---|
| `id` | `UUID` | нет | Первичный ключ |
| `scope`, `operation`, `idempotency_key`, `request_hash` | `TEXT` | нет | Пространство, команда, ключ и хэш запроса |
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
- не более одного незавершённого token на `(user_id, purpose)` частичным
  уникальным индексом;
- уникальность `(scope, operation, idempotency_key)`;
- не более одного outbox на `user_action_token_id`;
- допустимые значения `purpose` и `status`;
- непустые `User.name` и `User.email`;
- `expires_at > created_at` для token и idempotency record;
- взаимоисключающие `used_at`/`invalidated_at` и `sent_at`/`failed_at`;
- `publish_attempts >= 0` и `available_at >= created_at`.

Индексы созданы для FK и нормативных путей выборки: user, purpose, expiry и
used token; user и expiry idempotency record; pending outbox по
`(available_at, created_at)`, status и временные метки завершения доставки.

## 4. Что остаётся в Application/Domain

PostgreSQL не дублирует контекстные правила. В следующих задачах должны быть
реализованы:

- нормализация email перед сохранением и проверка уникальности нового email;
- применение PasswordHasher `auto` и ADR-016 при создании пароля/token;
- срок token и idempotency record ровно 24 часа;
- сериализация выдачи token блокировкой `User` и аннулирование предыдущего;
- соответствие JSON payload назначению token;
- согласованность outbox status, timestamp и наличия encrypted payload;
- соответствие recipient/template назначению связанного token;
- атомарная координация User, token, outbox и idempotency result;
- очистка записей согласно ADR-014.

## 5. Сознательно отложенная ER-модель

Не создавались `Connect`, `ConnectInvitation`, `Debt`, `DebtParticipant`,
`Transfer` и `UserSession`: они не нужны для регистрации и активации и относятся
к следующим вертикальным сценариям. Не создавались repositories, mapper-слой,
полные domain entities, repositories, mapper-слой, fixtures и демонстрационные
данные. Добавленные в E1-10 purpose enum и access policy описаны в
[модели доступа](../security/access-model.md) и не меняют схему.

## 6. Миграция и автоматические проверки

Схему создаёт новая миграция
[`Version20260731153000`](../../backend/migrations/Version20260731153000.php).
Предыдущая пустая стартовая миграция не изменялась. `down()` удаляет только четыре
объекта этой миграции в обратном порядке; в рамках E1-08 откат не выполнялся.

Интеграционный тест
[`InitialSchemaTest`](../../backend/tests/Integration/Persistence/InitialSchemaTest.php)
проверяет ORM round trip, `NOT NULL`, уникальность, FK, частичный уникальный индекс
и ключевые `CHECK`. Каждый тест выполняется в транзакции с rollback. Команда
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
- [Первый вертикальный срез](../first-vertical-slice.md) и
  [критерии приёмки](../first-vertical-slice-acceptance.md);
- [ADR-001, ADR-006, ADR-007, ADR-009 и ADR-011—ADR-016](../adr/architecture-decisions.md).
