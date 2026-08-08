# Equo — дорожная карта разработки MVP

| Поле | Значение |
|---|---|
| Назначение | Превратить принятые границы MVP в последовательный, отслеживаемый и готовый к реализации план |
| Статус | Active |
| Версия | 1 |
| Дата актуальности | 2026-08-08 |
| Владелец | Maksim Smolkov |
| Источник | Границы MVP; бизнес-правила; модель сущностей; HTTP-контракты; ADR-001—019; фактическая реализация первого вертикального среза |

## 1. Назначение и статус документа

Этот документ является рабочей дорожной картой разработки. Он отвечает на
вопросы:

- какой пользовательский результат должен появиться на каждом этапе;
- какие сущности, endpoints, backend- и frontend-компоненты нужно реализовать;
- какие security, consistency и operational требования относятся к этапу;
- какие проверки доказывают готовность;
- какие задачи завершены и какие ещё предстоят.

Документ не заменяет нормативные источники:

- [границы MVP](mvp-scope.md) определяют обязательность сценариев и критерии
  запуска;
- [бизнес-правила](business-rules/business-rules.md) определяют поведение и
  инварианты;
- [модель сущностей](data-model/entities.md) и
  [ER-диаграмма](data-model/er-diagram.md) определяют данные и связи;
- [HTTP-контракты](api/http-contracts.md) определяют публичный API;
- [ADR](adr/architecture-decisions.md) определяют архитектурные решения.

Если roadmap расходится с нормативным источником, действует нормативный
источник. Расхождение нельзя исправлять только в roadmap: нужно завести вопрос в
[журнале решений](open-questions.md) и синхронно обновить затронутые артефакты
после принятия решения.

## 2. Как вести прогресс

### 2.1. Значение checkbox

- `[ ]` — задача не завершена;
- `[x]` — задача завершена, проверена и включена в основную ветку;
- родительский пункт отмечается только после завершения всех обязательных
  дочерних пунктов;
- частично выполненная задача остаётся `[ ]`, а фактическое состояние
  поясняется вложенными пунктами или примечанием.

Checkbox является единицей трекинга. Статус этапа не определяется субъективным
процентом: этап завершён, только когда отмечены все его обязательные задачи и
exit criteria.

### 2.2. Правила изменения roadmap

1. Каждая реализационная задача имеет стабильный идентификатор `DR-E<этап>-<номер>`.
2. Идентификатор не переиспользуется после удаления или переноса задачи.
3. Новая обязательная задача добавляется в тот этап, чьи exit criteria без неё
   недостижимы.
4. Изменение категории `Must/Should/Later/Out of scope` выполняется сначала в
   `mvp-scope.md`.
5. В PR с реализацией отмечаются только реально проверенные пункты.
6. Если задача сознательно переносится, рядом указываются новый этап, причина и
   ссылка на решение.
7. Этап нельзя закрыть при падающем quality gate, незафиксированной миграции,
   частично опубликованном endpoint или документации, описывающей несуществующее
   поведение.

### 2.3. Общая Definition of Ready для feature

Перед началом реализации feature должны быть выполнены условия:

Этот список является шаблоном проверки для каждой отдельной feature и не
участвует в глобальном прогрессе roadmap. При необходимости его копируют в
issue/PR и отмечают там.

- `[ ]` сценарий и категория подтверждены в `mvp-scope.md`;
- `[ ]` относящиеся `BR-*` и `ADR-*` найдены и не противоречат друг другу;
- `[ ]` HTTP request/response/error contract определён;
- `[ ]` поля, связи, ограничения и транзакционная граница определены;
- `[ ]` роли, ACL и поведение недоступного ресурса определены;
- `[ ]` зависимости от предыдущих этапов доступны;
- `[ ]` известен минимальный end-to-end результат;
- `[ ]` перечислены обязательные unit, integration, HTTP, concurrency и browser
  проверки;
- `[ ]` нерешённые вопросы либо отсутствуют, либо зарегистрированы как блокеры.

Для сценариев текущего MVP нормативная часть в основном уже готова. Перед
реализацией всё равно нужно провести короткий contract audit на случай изменения
документов предыдущими этапами.

### 2.4. Общая Definition of Done для feature

Feature считается завершённой, когда:

Как и Definition of Ready, этот список применяется заново к каждой feature.
Глобально отслеживаются задачи со стабильными `DR-*` и exit criteria этапов.

- `[ ]` Domain/Application реализуют все относящиеся бизнес-правила;
- `[ ]` миграция создаёт только необходимую схему и проходит на пустой БД;
- `[ ]` Doctrine mapping совпадает со схемой;
- `[ ]` публичный HTTP-контракт реализован без временных полей и кодов;
- `[ ]` frontend предоставляет завершённый пользовательский путь, если feature
  имеет UI;
- `[ ]` authorization выполняется server-side;
- `[ ]` sensitive data не попадает в response, URL, logs, queue message или
  browser storage;
- `[ ]` команда атомарна, идемпотентна и/или versioned там, где это требуется;
- `[ ]` unit, integration, HTTP и browser tests проходят;
- `[ ]` конкурентные ветки проверены отдельным тестом для критических writers;
- `[ ]` документация фактической реализации обновлена;
- `[ ]` `make check` проходит;
- `[ ]` соответствующий этап E2E либо расширен, либо создан;
- `[ ]` не осталось частично доступных endpoint или элементов UI.

## 3. Исходное состояние

Первый ограниченный вертикальный срез уже реализован:

- [x] `DR-B-001` — публичная регистрация нового пользователя;
- [x] `DR-B-002` — первый `ACTIVATE_ACCOUNT` и активация по email;
- [x] `DR-B-003` — таблицы `app_user`, `user_action_token`,
  `idempotency_record` и `email_delivery_outbox`;
- [x] `DR-B-004` — общий JSON/error/request ID HTTP pipeline;
- [x] `DR-B-005` — идемпотентность, rate limits и конкурентные проверки
  регистрации/активации;
- [x] `DR-B-006` — зашифрованный outbox, relay, RabbitMQ consumer, Mailer,
  retry и terminal failure;
- [x] `DR-B-007` — страницы `/register` и `/activate`;
- [x] `DR-B-008` — component, integration, concurrency и browser E2E первого
  среза;
- [ ] `DR-B-009` — повторный запрос ссылки активации; входит в этап 6;
- [ ] `DR-B-010` — physical retention cleanup; входит в этап 7.

Текущая реализация не содержит production-аутентификацию, `UserSession`,
финансовые сущности или protected endpoints.

## 4. Сводная карта семи этапов

| Этап | Результат | Сценарии | Зависит от | Статус |
|---:|---|---|---|---|
| 1 | Login, refresh и защищённая browser-сессия | `MVP-SC-002`, часть `020` | исходный срез | `[ ]` |
| 2 | Два пользователя создают постоянный `Connect` по приглашению | `MVP-SC-008`, часть `009/020/021` | этап 1 | `[ ]` |
| 3 | Общий расход создаёт доли, историю и объяснимый баланс | `MVP-SC-009/011/012/015`, части `020/021` | этапы 1—2 | `[ ]` |
| 4 | Ручной трансфер изменяет баланс | `MVP-SC-016`, части `020/021` | этап 3 | `[ ]` |
| 5 | Автор исправляет долг с полным перерасчётом | `MVP-SC-013`, части `020/021` | этап 3 | `[ ]` |
| 6 | Завершён обязательный security lifecycle аккаунта | завершение `001`, `003/004/005`, части `019/020/021` | этап 1 | `[ ]` |
| 7 | MVP эксплуатационно готов и полностью проверен | завершение `019/020/021/022` | этапы 1—6 | `[ ]` |

Порядок оптимизирован для ранней проверки бизнес-гипотезы. После минимальной
production-сессии и `Connect` сначала строится финансовый happy path. Этап 6 не
является необязательным: он может выполняться раньше этапов 3—5, но обязан
завершиться до release gate этапа 7.

### 4.1. Счётчик отслеживаемых задач

| Область | Готово | Всего | Текущее состояние |
|---|---:|---:|---|
| Реализованный baseline | 8 | 10 | Первый ограниченный срез работает |
| Этап 1 | 0 | 53 | Не начат |
| Этап 2 | 0 | 45 | Не начат |
| Этап 3 | 0 | 70 | Не начат |
| Этап 4 | 0 | 28 | Не начат |
| Этап 5 | 0 | 37 | Не начат |
| Этап 6 | 0 | 53 | Не начат |
| Этап 7 | 0 | 58 | Не начат |
| Should have | 0 | 24 | Отложено до решения владельца |
| Later | 0 | 9 | Не входит в обязательный MVP |

Счётчик служит только навигационной сводкой и обновляется вместе с checkbox.
Источником истины остаются задачи `DR-*`: при расхождении нужно пересчитать
сводку, а не менять статус задачи без подтверждения.

---

## 5. Этап 1 — Login и защищённая сессия

### 5.1. Цель и пользовательский результат

Активированный пользователь вводит email и пароль, получает короткоживущий
access token и защищённую refresh-сессию, может перезагрузить страницу и
продолжить работу без повторного ввода пароля.

После этапа впервые появляется корректно определённый current user для всех
последующих protected features.

### 5.2. Входит в этап

- `POST /auth/login`;
- `POST /auth/refresh`;
- `GET /me` только для чтения текущего профиля;
- `UserSession` и rotation refresh token;
- RS256 JWT access token по ADR-017;
- in-memory access token lifecycle, session bootstrap и protected navigation по
  ADR-018;
- CSRF и same-origin защита refresh;
- password credential verification;
- `401/403` infrastructure для реального protected endpoint.

Не входят: logout, logout-all, reset/change password, изменение профиля,
деактивация и финансовые страницы.

### 5.3. Data и migration

- [ ] `DR-E1-001` — выполнить contract audit `UserSession`, ADR-006/014/017/018
  и HTTP 8.4—8.5/10.1.
- [ ] `DR-E1-002` — добавить таблицу `user_session`:
  `id`, `user_id`, `refresh_token_hash`, `created_at`, `expires_at`,
  `revoked_at`.
- [ ] `DR-E1-003` — добавить PK, FK `user_id → app_user.id` с `RESTRICT`,
  unique index для `refresh_token_hash` и индексы очистки/поиска.
- [ ] `DR-E1-004` — добавить CHECK для времён и terminal state согласно
  ER-модели.
- [ ] `DR-E1-005` — реализовать Doctrine record/mapping и доказать
  schema/mapping parity.
- [ ] `DR-E1-006` — хранить только криптографический хэш refresh token;
  открытый token существует только в момент выдачи клиенту.

### 5.4. Backend Domain/Application

- [ ] `DR-E1-007` — определить session lifecycle: create, rotate, expire,
  revoke и reject replay.
- [ ] `DR-E1-008` — реализовать проверку нормализованного email и password
  через существующий PasswordHasher adapter.
- [ ] `DR-E1-009` — запретить login для `User.isActive = false` без
  различающей утечки лишних сведений.
- [ ] `DR-E1-010` — реализовать атомарное создание refresh-сессии при login.
- [ ] `DR-E1-011` — реализовать refresh с pessimistic lock строки сессии и
  атомарной заменой хэша.
- [ ] `DR-E1-012` — отклонять истёкшую, отозванную или уже ротированную
  refresh-сессию.
- [ ] `DR-E1-013` — решить нормативное поведение при replay старого refresh
  token строго по ADR/контракту; не добавлять silent fallback.
- [ ] `DR-E1-014` — реализовать current-user query, который возвращает только
  `id`, `name`, `email`, `isActive` и `createdAt`.
- [ ] `DR-E1-015` — проверять актуальный `isActive` для protected commands,
  не полагаясь только на claim JWT.

### 5.5. Security adapters

- [ ] `DR-E1-016` — реализовать RS256 JWT signer/verifier с
  `typ=at+jwt`, versioned `kid` и claims
  `iss/aud/sub/iat/exp/jti`.
- [ ] `DR-E1-017` — зафиксировать access TTL 15 минут и clock skew не более
  30 секунд.
- [ ] `DR-E1-018` — загрузить signing/verification key ring только из
  окружения; секреты и private key не коммитить.
- [ ] `DR-E1-019` — создать Symfony authenticator/current-user boundary без
  передачи Doctrine record в controller.
- [ ] `DR-E1-020` — выдавать refresh token только в `HttpOnly`,
  `Secure` production-cookie с нормативными `SameSite`, `Path` и сроком.
- [ ] `DR-E1-021` — реализовать session-bound signed double-submit CSRF token
  для refresh.
- [ ] `DR-E1-022` — реализовать точную same-origin проверку; не использовать
  wildcard CORS с credentials.
- [ ] `DR-E1-023` — применить login rate limits по email+IP и IP.
- [ ] `DR-E1-024` — исключить password, refresh token, cookie, CSRF secret,
  JWT и их хэши из logs и serialization.

### 5.6. HTTP

- [ ] `DR-E1-025` — реализовать `POST /api/v1/auth/login` с точными
  request/response/error contract.
- [ ] `DR-E1-026` — реализовать `POST /api/v1/auth/refresh` и rotation cookie.
- [ ] `DR-E1-027` — реализовать `GET /api/v1/me` как первый protected
  endpoint.
- [ ] `DR-E1-028` — возвращать `AUTHENTICATION_REQUIRED` для отсутствующего
  или недействительного access token.
- [ ] `DR-E1-029` — сохранить единый error envelope и `X-Request-Id` во всех
  auth ветках.
- [ ] `DR-E1-030` — проверить отсутствие account enumeration в
  `INVALID_CREDENTIALS` и rate-limit responses.

### 5.7. Frontend

- [ ] `DR-E1-031` — добавить страницу `/login` с pending, validation,
  invalid credentials, inactive account и technical retry states.
- [ ] `DR-E1-032` — реализовать in-memory access-token holder без
  `localStorage/sessionStorage`.
- [ ] `DR-E1-033` — реализовать client-side bootstrap
  `refresh → /me` после загрузки приложения.
- [ ] `DR-E1-034` — сериализовать одновременные refresh внутри вкладки.
- [ ] `DR-E1-035` — определить межвкладочную координацию строго по ADR-018.
- [ ] `DR-E1-036` — научить API client один раз обновлять access token после
  допустимого `401` без бесконечного retry loop.
- [ ] `DR-E1-037` — добавить protected route middleware, ожидающий завершения
  bootstrap.
- [ ] `DR-E1-038` — добавить минимальную защищённую страницу профиля/оболочку
  для доказательства сессии; не добавлять функции этапов 2—6.
- [ ] `DR-E1-039` — исключить token/cookie/CSRF значения из Nuxt state,
  rendered HTML, URL и browser logs.
- [ ] `DR-E1-040` — обеспечить доступные loading/error/empty/focus states.

### 5.8. Tests

- [ ] `DR-E1-041` — unit tests session lifecycle и JWT claims/validation.
- [ ] `DR-E1-042` — integration tests login, hash storage, refresh rotation,
  expiry, revoke и inactive user.
- [ ] `DR-E1-043` — concurrency test двух одновременных refresh: успешна ровно
  одна нормативная ветка, старый token не оживает.
- [ ] `DR-E1-044` — HTTP tests cookie attributes, CSRF, Origin, 401, rate
  limits и safe errors.
- [ ] `DR-E1-045` — component tests login и bootstrap states.
- [ ] `DR-E1-046` — browser E2E:
  `register → activate → login → reload → authenticated /me`.
- [ ] `DR-E1-047` — security regression: секреты отсутствуют в response body,
  logs, URL и browser storage.

### 5.9. Документация и exit criteria

- [ ] `DR-E1-048` — обновить реализованную схему, backend structure,
  access model, frontend foundation и HTTP implementation.
- [ ] `DR-E1-049` — добавить env-переменные и безопасные development defaults
  в `.env.example`/README без production secrets.
- [ ] `DR-E1-050` — `make check` и расширенный browser E2E проходят.
- [ ] `DR-E1-051` — активный пользователь сохраняет сессию после reload.
- [ ] `DR-E1-052` — неактивный пользователь и злоумышленник с неверным token
  не получают protected data.
- [ ] `DR-E1-053` — этап 1 закрыт и строка сводной карты отмечена `[x]`.

---

## 6. Этап 2 — Приглашение и создание Connect

### 6.1. Цель и пользовательский результат

Первый активный пользователь получает воспроизводимую ссылку, второй активный
пользователь открывает её и принимает приглашение. В результате существует ровно
один постоянный неориентированный `Connect`, видимый обеим сторонам.

### 6.2. Входит в этап

- `Connect` и `ConnectInvitation`;
- `POST /connect-invitations`;
- `GET /connect-invitations/{token}`;
- `POST /connect-invitations/{token}/accept`;
- `GET /connects` и `GET /connects/{connectId}` без финансовой истории;
- invitation token signing/HMAC, lifecycle, concurrency и privacy.

Не входят: общий поиск, revoke invitation, delete/hide Connect, создание Connect
через общий долг, долг, трансферы и ненулевой баланс.

### 6.3. Data и migration

- [ ] `DR-E2-001` — выполнить contract audit `BR-CON-*`, `BR-INV-*`,
  ADR-004/008/014 и HTTP 11—12.
- [ ] `DR-E2-002` — добавить таблицу `connect` с UUID сторон и `created_at`.
- [ ] `DR-E2-003` — сохранять стороны в каноническом порядке и запретить
  self-connect.
- [ ] `DR-E2-004` — добавить unique `(first_user_id, second_user_id)` и оба
  FK с `RESTRICT`.
- [ ] `DR-E2-005` — добавить `connect_invitation` со всеми lifecycle полями,
  FK автора/принявшего и token hash.
- [ ] `DR-E2-006` — обеспечить уникальный полный `token_hash` и не более одного
  незавершённого приглашения автора.
- [ ] `DR-E2-007` — добавить индексы `expires_at`, `used_at` и сторон Connect.
- [ ] `DR-E2-008` — реализовать Doctrine records/mapping и schema parity.

### 6.4. Domain/Application

- [ ] `DR-E2-009` — реализовать value object канонической неориентированной
  пары.
- [ ] `DR-E2-010` — реализовать get-or-create invitation: повтор возвращает
  ту же действующую ссылку, не продлевая TTL.
- [ ] `DR-E2-011` — создать новое приглашение только после истечения или
  использования предыдущего.
- [ ] `DR-E2-012` — реализовать token вида
  `<invitationId>.<HMAC-signature>` и хранение hash полного token.
- [ ] `DR-E2-013` — воспроизводить существующую ссылку через active/compatible
  signing keys по ADR-008.
- [ ] `DR-E2-014` — публично показывать только безопасную информацию об авторе
  приглашения.
- [ ] `DR-E2-015` — запретить собственное приглашение, inactive author и
  inactive acceptor.
- [ ] `DR-E2-016` — принять приглашение атомарно с блокировкой записи и
  созданием/получением Connect.
- [ ] `DR-E2-017` — повтор тому же `acceptedById` возвращает тот же Connect;
  другой пользователь получает нормативный отказ.
- [ ] `DR-E2-018` — сериализовать get-or-create при отсутствии строки
  invitation через lock User или advisory lock.
- [ ] `DR-E2-019` — считать Connect постоянным: не добавлять status,
  `isDeleted` или owner.
- [ ] `DR-E2-020` — реализовать список/карточку Connect относительно current
  user с нулевым балансом до этапа 3.

### 6.5. HTTP и ACL

- [ ] `DR-E2-021` — реализовать `POST /api/v1/connect-invitations`.
- [ ] `DR-E2-022` — реализовать публичный
  `GET /api/v1/connect-invitations/{token}`.
- [ ] `DR-E2-023` — реализовать protected
  `POST /api/v1/connect-invitations/{token}/accept`.
- [ ] `DR-E2-024` — реализовать `GET /api/v1/connects` с пагинацией.
- [ ] `DR-E2-025` — реализовать `GET /api/v1/connects/{connectId}`.
- [ ] `DR-E2-026` — скрывать чужой Connect через `404 RESOURCE_NOT_FOUND`.
- [ ] `DR-E2-027` — не возвращать email другой стороны там, где контракт
  разрешает только краткого пользователя.
- [ ] `DR-E2-028` — применить invitation accept rate limits по IP и
  invitation ID.

### 6.6. Frontend

- [ ] `DR-E2-029` — добавить страницу/действие создания приглашения с copy/share
  состояниями без сохранения token в storage.
- [ ] `DR-E2-030` — добавить публичную страницу invitation preview.
- [ ] `DR-E2-031` — после login позволить принять приглашение и перейти к
  карточке Connect.
- [ ] `DR-E2-032` — обработать own, expired, used-by-other, inactive и
  technical retry states.
- [ ] `DR-E2-033` — добавить список Connect и карточку с корректным empty
  balance state.
- [ ] `DR-E2-034` — не показывать search, revoke, hide или delete controls.

### 6.7. Tests

- [ ] `DR-E2-035` — unit tests canonical pair и invitation lifecycle.
- [ ] `DR-E2-036` — integration tests unique pair, single active invitation,
  expiry, token hash и key compatibility.
- [ ] `DR-E2-037` — concurrency tests двух get-or-create и двух accept.
- [ ] `DR-E2-038` — HTTP tests public preview, protected accept, ACL, safe
  errors, pagination и rate limits.
- [ ] `DR-E2-039` — component tests invitation/Connect UI states.
- [ ] `DR-E2-040` — browser E2E двух пользователей:
  `login A → invite → login B → accept → Connect у A и B`.

### 6.8. Документация и exit criteria

- [ ] `DR-E2-041` — обновить фактическую схему, backend structure, access
  model и frontend foundation.
- [ ] `DR-E2-042` — `make check` и двухпользовательский E2E проходят.
- [ ] `DR-E2-043` — одна пара не создаётся дважды при повторах/гонках.
- [ ] `DR-E2-044` — token и чужие персональные данные не раскрываются.
- [ ] `DR-E2-045` — этап 2 закрыт и строка сводной карты отмечена `[x]`.

---

## 7. Этап 3 — Первый финансовый путь: долг, доли, баланс и история

### 7.1. Цель и пользовательский результат

Автор выбирает плательщика и участников, вводит общий расход в EUR, а система
атомарно создаёт долг, состав, недостающие Connect и автоматические доли.
Участники видят карточку долга, историю операций и объяснимый двусторонний
баланс.

Это самый крупный этап и первая проверка основной продуктовой гипотезы. Его
следует реализовывать внутренними инкрементами 3A—3D, не публикуя частично
работающие writers.

### 7.2. Входит в этап

- `Debt`, `DebtParticipant`, `Transfer` типа `DEBT_SHARE`;
- `POST /debts`;
- `GET /debts` и `GET /debts/{debtId}`;
- `GET /connects`/`{id}` с реальным балансом;
- `GET /connects/{connectId}/transfers`;
- `GET /transfers/{transferId}`;
- равные доли по `ceil(totalAmount / participantCount)`;
- автоматическое создание Connect для payer/participant;
- ACL Debt/Transfer и скрытие недоступных ресурсов;
- idempotency создания долга.

Не входят: редактирование/удаление долга, `MANUAL`, `relatedTransferId`,
погашение, статус долга, неравные доли, несколько валют и пользовательская дата.

### 7.3. Инкремент 3A — схема и чистая модель

- [ ] `DR-E3-001` — выполнить contract audit `BR-DEBT-*`,
  `BR-TRF-001—003/009`, `BR-ACL-*`, ADR-002/003/005/009—012 и HTTP 7/11/13/14.
- [ ] `DR-E3-002` — добавить таблицу `debt` со всеми полями модели, EUR
  amount в евроцентах, `is_deleted=false` и `version=1`.
- [ ] `DR-E3-003` — добавить FK payer/author и CHECK
  `total_amount > 0`, `version >= 1`.
- [ ] `DR-E3-004` — добавить `debt_participant` с постоянной unique парой
  `(debt_id, user_id)`, включая исключённую запись.
- [ ] `DR-E3-005` — добавить `transfer` с полями обоих типов, soft-delete,
  version и nullable ссылками модели.
- [ ] `DR-E3-006` — добавить FK Connect/sender/receiver/creator/debt/
  participant/related transfer и выбранные `RESTRICT/SET NULL` строго по ER.
- [ ] `DR-E3-007` — реализовать локальные CHECK, различающие `DEBT_SHARE` и
  `MANUAL`; не пытаться выразить межтабличные инварианты CHECK-ами.
- [ ] `DR-E3-008` — обеспечить не более одного `DEBT_SHARE` на
  `DebtParticipant`.
- [ ] `DR-E3-009` — добавить индексы для debt list, access queries, Connect
  history, active balance и FK.
- [ ] `DR-E3-010` — реализовать Doctrine records/mappers без межмодульных ORM
  associations, нарушающих ADR-015.
- [ ] `DR-E3-011` — доказать миграцию на пустой БД и mapping/schema parity.

### 7.4. Инкремент 3B — агрегат Debt и расчёт долей

- [ ] `DR-E3-012` — реализовать money value object: положительное целое число
  евроцентов без float.
- [ ] `DR-E3-013` — реализовать Debt aggregate с неизменяемым payer,
  author, participants и version.
- [ ] `DR-E3-014` — валидировать минимум одного участника, отличного от
  плательщика.
- [ ] `DR-E3-015` — разрешить author=payer; если author не payer, требовать
  участие автора в разделении.
- [ ] `DR-E3-016` — поддержать флаг включения payer в participant count, не
  создавая self-transfer.
- [ ] `DR-E3-017` — рассчитывать одну долю как
  `ceil(totalAmount / participantCount)`.
- [ ] `DR-E3-018` — создавать ровно один `DEBT_SHARE` на каждого текущего
  участника, кроме payer.
- [ ] `DR-E3-019` — задавать направление `payer → participant`,
  `createdById=null` и обязательные debt links.
- [ ] `DR-E3-020` — документировать и тестировать допустимое превышение суммы
  математических долей максимум на `participantCount - 1` цент.
- [ ] `DR-E3-021` — проверять доступность нового пользователя: self,
  существующий Connect или текущий соучастник доступного долга.
- [ ] `DR-E3-022` — требовать active payer и active новые participants.
- [ ] `DR-E3-023` — автоматически get-or-create Connect между payer и каждым
  получателем доли в той же транзакции.
- [ ] `DR-E3-024` — создать Debt, participants, Connect и transfers одной
  атомарной транзакцией.
- [ ] `DR-E3-025` — применить `Idempotency-Key`: одинаковый retry возвращает
  исходный результат, другой body конфликтует.

### 7.5. Инкремент 3C — read models, ACL и баланс

- [ ] `DR-E3-026` — реализовать server-side ACL активного Debt для author,
  payer и current participants.
- [ ] `DR-E3-027` — исключённому participant не давать доступ к Debt, сохраняя
  доступ к его Transfer/Connect history.
- [ ] `DR-E3-028` — возвращать `404` для отсутствующего и недоступного Debt
  там, где этого требует privacy model.
- [ ] `DR-E3-029` — реализовать активный debt list без deleted debts.
- [ ] `DR-E3-030` — реализовать полную карточку Debt с author, payer,
  participants, participant count, shares и version.
- [ ] `DR-E3-031` — отдавать `ETag` карточки Debt уже сейчас, хотя writer
  появится на этапе 5.
- [ ] `DR-E3-032` — вычислять Connect balance только из
  `Transfer.isDeleted=false`.
- [ ] `DR-E3-033` — нормализовать знак/направление balance относительно
  current user согласно HTTP 7.2.
- [ ] `DR-E3-034` — не хранить отдельный mutable balance column/cache как
  источник истины.
- [ ] `DR-E3-035` — реализовать историю Connect с пагинацией, стабильной
  сортировкой и активными/удалёнными transfers.
- [ ] `DR-E3-036` — реализовать карточку Transfer и независимую проверку права
  на Transfer.
- [ ] `DR-E3-037` — при недоступном связанном Debt возвращать только
  контрактную безопасную ссылку `id + isAccessible=false`.
- [ ] `DR-E3-038` — не возвращать email других пользователей в финансовых
  read models.

### 7.6. Инкремент 3D — HTTP и frontend

- [ ] `DR-E3-039` — реализовать `POST /api/v1/debts`.
- [ ] `DR-E3-040` — реализовать `GET /api/v1/debts` с нормативной
  пагинацией.
- [ ] `DR-E3-041` — реализовать `GET /api/v1/debts/{debtId}` и `ETag`.
- [ ] `DR-E3-042` — расширить `GET /connects` и `GET /connects/{id}`
  реальным balance.
- [ ] `DR-E3-043` — реализовать
  `GET /connects/{connectId}/transfers`.
- [ ] `DR-E3-044` — реализовать `GET /transfers/{transferId}`.
- [ ] `DR-E3-045` — возвращать точные validation/state/access/idempotency
  errors без частичного эффекта.
- [ ] `DR-E3-046` — добавить debt create page с title, description, amount,
  payer, participants и payer-in-split.
- [ ] `DR-E3-047` — ввод amount преобразовывать в евроценты без float и
  неоднозначной locale-конверсии.
- [ ] `DR-E3-048` — показывать только доступных пользователей, полученных от
  сервера; не реализовывать общий поиск.
- [ ] `DR-E3-049` — добавить debt list/card с ролями, составом и долями.
- [ ] `DR-E3-050` — добавить Connect list/card с направлением и знаком
  balance.
- [ ] `DR-E3-051` — добавить history и transfer details с визуальным
  различием active/deleted и `DEBT_SHARE/MANUAL`.
- [ ] `DR-E3-052` — реализовать loading, empty, error, forbidden-as-not-found,
  pagination и idempotent retry states.
- [ ] `DR-E3-053` — не показывать edit/delete/pay controls до следующих
  этапов.

### 7.7. Tests

- [ ] `DR-E3-054` — unit tests Money, participant rules и формулы округления
  на граничных значениях.
- [ ] `DR-E3-055` — aggregate tests author/payer combinations,
  payer-in-split и no-self-transfer.
- [ ] `DR-E3-056` — integration tests DB constraints/FK/unique/check и
  persistence round trip трёх сущностей.
- [ ] `DR-E3-057` — transaction rollback test на каждом контролируемом месте
  создания Debt/participants/Connect/transfers/idempotency result.
- [ ] `DR-E3-058` — concurrency/idempotency tests двойного `POST /debts`.
- [ ] `DR-E3-059` — balance property tests: порядок строк не влияет,
  deleted исключаются, знак сторон симметричен.
- [ ] `DR-E3-060` — HTTP tests всех шести endpoint, pagination, ETag, ACL,
  safe Debt link и error contracts.
- [ ] `DR-E3-061` — component tests create/list/card/history.
- [ ] `DR-E3-062` — browser E2E:
  `A+B connected → A creates debt → A and B see share/history/balance`.
- [ ] `DR-E3-063` — privacy E2E третьего пользователя без доступа.

### 7.8. Документация и exit criteria

- [ ] `DR-E3-064` — обновить фактическую схему, backend structure, access
  model, frontend foundation и HTTP implementation.
- [ ] `DR-E3-065` — обновить ER DOT/SVG/PNG только если реализация потребовала
  принятого изменения нормативной модели.
- [ ] `DR-E3-066` — `make check` и финансовый E2E проходят.
- [ ] `DR-E3-067` — баланс полностью воспроизводится из active Transfer.
- [ ] `DR-E3-068` — повтор/гонка не создаёт второй Debt, participant, Connect
  или `DEBT_SHARE`.
- [ ] `DR-E3-069` — недоступный пользователь не получает название, состав,
  email или историю чужого Debt.
- [ ] `DR-E3-070` — этап 3 закрыт и строка сводной карты отмечена `[x]`.

---

## 8. Этап 4 — Ручной трансфер

### 8.1. Цель и пользовательский результат

Активная сторона Connect отражает возврат, передачу денег или корректировку
ручным `MANUAL`. Новая запись немедленно меняет двусторонний баланс и появляется
в общей истории.

### 8.2. Входит и не входит

Входит `POST /connects/{connectId}/transfers`, любое из двух направлений,
положительная сумма, переплата, идемпотентность и обновлённые read models.

Не входят edit/delete `MANUAL`, `relatedTransferId`, автоматическое погашение,
ограничение суммой долга или текущим балансом.

### 8.3. Backend/Data

- [ ] `DR-E4-001` — выполнить contract audit `BR-TRF-004—005`,
  `BR-IDEM-*`, ACL и HTTP 14.4.
- [ ] `DR-E4-002` — реализовать MANUAL factory с
  `debtId/debtParticipantId=null` и `createdById=currentUser`.
- [ ] `DR-E4-003` — разрешать sender/receiver только как две стороны
  указанного Connect.
- [ ] `DR-E4-004` — требовать, чтобы author был sender или receiver.
- [ ] `DR-E4-005` — требовать active author и active вторую сторону.
- [ ] `DR-E4-006` — принимать только положительные целые евроценты.
- [ ] `DR-E4-007` — не ограничивать amount текущим balance и разрешить смену
  знака/переплату.
- [ ] `DR-E4-008` — сохранять MANUAL и idempotency result атомарно.
- [ ] `DR-E4-009` — одинаковый retry возвращает прежний Transfer, другой body
  с тем же ключом конфликтует.
- [ ] `DR-E4-010` — не изменять Debt или `DEBT_SHARE` при создании MANUAL.

### 8.4. HTTP/Frontend

- [ ] `DR-E4-011` — реализовать
  `POST /api/v1/connects/{connectId}/transfers`.
- [ ] `DR-E4-012` — применять Connect ACL и скрывать чужой Connect.
- [ ] `DR-E4-013` — вернуть точные inactive side, validation,
  idempotency/access ошибки.
- [ ] `DR-E4-014` — добавить форму MANUAL в карточку Connect.
- [ ] `DR-E4-015` — явно показывать направление «кто передал кому», amount и
  прогнозируемый результат без трактовки как закрытия долга.
- [ ] `DR-E4-016` — после успеха обновлять balance/history без локального
  самостоятельного пересчёта как источника истины.
- [ ] `DR-E4-017` — использовать стабильный Idempotency-Key для неизменённой
  попытки и новый после редактирования формы.
- [ ] `DR-E4-018` — не показывать related debt, edit или delete controls.

### 8.5. Tests и exit criteria

- [ ] `DR-E4-019` — unit tests direction, author, amount и overpayment.
- [ ] `DR-E4-020` — integration/transaction tests persistence и rollback.
- [ ] `DR-E4-021` — idempotency/concurrency tests двойной отправки.
- [ ] `DR-E4-022` — HTTP tests ACL, inactive side, both directions,
  overpayment и safe errors.
- [ ] `DR-E4-023` — component tests формы и обновления read state.
- [ ] `DR-E4-024` — browser E2E:
  `existing debt balance → MANUAL → changed balance/history у обеих сторон`.
- [ ] `DR-E4-025` — обновить фактическую документацию.
- [ ] `DR-E4-026` — `make check` проходит.
- [ ] `DR-E4-027` — основной продуктовый путь раздела 6 `mvp-scope.md`
  проходит целиком.
- [ ] `DR-E4-028` — этап 4 закрыт и строка сводной карты отмечена `[x]`.

---

## 9. Этап 5 — Исправление долга и полный перерасчёт

### 9.1. Цель и пользовательский результат

Автор исправляет title, description, amount, состав или участие payer в
разделении. Система атомарно пересчитывает все доли, не создаёт дублей и
отклоняет stale update.

### 9.2. Входит и не входит

Входит `PUT /debts/{debtId}`, `If-Match`, soft exclude/restore participant,
полный перерасчёт `DEBT_SHARE` и новая version Debt.

Не входят смена payer, hard delete, удаление Debt, восстановление удалённого
Debt, прямое редактирование `DEBT_SHARE` и audit log версий.

### 9.3. Domain/Application

- [ ] `DR-E5-001` — выполнить contract audit `BR-DEBT-007—013`,
  `BR-CONC-*`, ADR-003/010 и HTTP 13.5.
- [ ] `DR-E5-002` — разрешить update только author активного Debt.
- [ ] `DR-E5-003` — заморозить update, если author или payer inactive.
- [ ] `DR-E5-004` — сделать payer неизменяемым на уровне command и Domain.
- [ ] `DR-E5-005` — требовать актуальный `If-Match`; отсутствие даёт `428`,
  stale version — нормативный `409`.
- [ ] `DR-E5-006` — блокировать Debt aggregate и проверить version до
  изменения.
- [ ] `DR-E5-007` — author, не являющийся payer, не может исключить себя.
- [ ] `DR-E5-008` — неактивного current participant разрешить оставить или
  исключить, но не добавить/восстановить.
- [ ] `DR-E5-009` — исключение переводит существующий
  `DebtParticipant.isDeleted=true` и удаляет его `DEBT_SHARE` мягко.
- [ ] `DR-E5-010` — повторное добавление active user восстанавливает прежние
  participant/share записи, сохраняя identity и исходный `createdAt`.
- [ ] `DR-E5-011` — пересчитать share для полного итогового current состава,
  а не применять локальную дельту.
- [ ] `DR-E5-012` — создать недостающие Connect для новых допустимых
  участников в той же транзакции.
- [ ] `DR-E5-013` — увеличивать Debt.version ровно один раз на фактическое
  изменение агрегата.
- [ ] `DR-E5-014` — определить контракт no-op update согласно HTTP/Domain и
  закрепить тестом.
- [ ] `DR-E5-015` — выполнить Debt, participants, shares, Connect и version
  одной транзакцией.

### 9.4. HTTP/Frontend

- [ ] `DR-E5-016` — реализовать `PUT /api/v1/debts/{debtId}`.
- [ ] `DR-E5-017` — принимать version только из `If-Match`, не доверять
  скрытому body fallback.
- [ ] `DR-E5-018` — возвращать обновлённую карточку/ETag строго по контракту.
- [ ] `DR-E5-019` — различать forbidden, not found, deleted, frozen,
  invalid composition, missing/stale precondition.
- [ ] `DR-E5-020` — показывать edit action только author; сервер остаётся
  источником авторизации.
- [ ] `DR-E5-021` — предзаполнять форму из карточки и сохранять полученный
  ETag.
- [ ] `DR-E5-022` — при conflict не перетирать серверное состояние:
  предложить reload/review, не делать silent retry.
- [ ] `DR-E5-023` — ясно показывать текущих, исключаемых и восстанавливаемых
  участников.
- [ ] `DR-E5-024` — после успеха обновлять Debt, balance и history данными
  сервера.
- [ ] `DR-E5-025` — не показывать payer selector как изменяемое поле.

### 9.5. Tests и exit criteria

- [ ] `DR-E5-026` — aggregate tests всех composition/freeze правил.
- [ ] `DR-E5-027` — recalculation tests изменения amount, payer-in-split,
  remove/restore и округления.
- [ ] `DR-E5-028` — integration tests identity восстановленных строк и
  soft-deleted shares.
- [ ] `DR-E5-029` — rollback tests каждого шага полного перерасчёта.
- [ ] `DR-E5-030` — concurrency test двух writers с одной version: применяется
  ровно один.
- [ ] `DR-E5-031` — HTTP tests `428/409`, ACL, frozen/deleted и ETag.
- [ ] `DR-E5-032` — component tests edit/conflict/reload states.
- [ ] `DR-E5-033` — browser E2E: изменение amount/composition меняет все
  затронутые shares и balances у участников.
- [ ] `DR-E5-034` — обновить фактическую документацию.
- [ ] `DR-E5-035` — `make check` проходит.
- [ ] `DR-E5-036` — ни один stale/failed update не оставляет частично
  пересчитанный агрегат.
- [ ] `DR-E5-037` — этап 5 закрыт и строка сводной карты отмечена `[x]`.

---

## 10. Этап 6 — Обязательный security lifecycle аккаунта

### 10.1. Цель и пользовательский результат

Пользователь может завершить текущую или все сессии, восстановить забытый пароль,
безопасно сменить известный пароль и повторно запросить активационную ссылку.
После security-изменения скомпрометированные refresh-сессии больше не выдают
новые access tokens.

### 10.2. Состав этапа

Этап завершает:

- оставшуюся часть `MVP-SC-001` — activation request;
- `MVP-SC-003` — logout и logout-all;
- `MVP-SC-004` — password recovery;
- `MVP-SC-005` — password change;
- соответствующие части `MVP-SC-019—021`.

Не входят изменение email/имени и деактивация/реактивация: это `Should have`.

### 10.3. Feature 6A — повторный запрос активации

- [ ] `DR-E6-001` — выполнить contract audit `BR-USR-002/006`,
  `BR-SEC-003—007` и HTTP 8.2.
- [ ] `DR-E6-002` — реализовать
  `POST /api/v1/auth/activation-requests` с одинаковым `202` для существующего и
  неизвестного email.
- [ ] `DR-E6-003` — нормализовать email до rate-limit/account lookup.
- [ ] `DR-E6-004` — не выдавать новый token активному аккаунту без
  нормативного основания реактивации.
- [ ] `DR-E6-005` — для допустимого пользователя заблокировать User,
  инвалидировать незавершённый `ACTIVATE_ACCOUNT` и создать replacement.
- [ ] `DR-E6-006` — сохранить token и outbox атомарно, используя существующую
  delivery chain.
- [ ] `DR-E6-007` — применить TTL 24 часа и rate limits email/user+IP.
- [ ] `DR-E6-008` — гарантировать отсутствие account enumeration по body,
  status, публичному error и доступной клиенту форме результата.
- [ ] `DR-E6-009` — добавить resend action на activation state без token в
  URL/storage.

### 10.4. Feature 6B — logout и logout-all

- [ ] `DR-E6-010` — выполнить contract audit `MVP-SC-003`, ADR-006/018 и
  HTTP 8.6—8.7.
- [ ] `DR-E6-011` — реализовать `POST /api/v1/auth/logout` с refresh cookie,
  CSRF и same-origin защитой.
- [ ] `DR-E6-012` — блокировать текущую session и идемпотентно устанавливать
  `revokedAt`.
- [ ] `DR-E6-013` — очистить refresh/CSRF cookies при успешном и повторном
  logout.
- [ ] `DR-E6-014` — реализовать protected
  `POST /api/v1/auth/logout-all`.
- [ ] `DR-E6-015` — блокировать User и атомарно отзывать все незавершённые
  sessions.
- [ ] `DR-E6-016` — очистить frontend in-memory access/current-user state
  после logout.
- [ ] `DR-E6-017` — явно учитывать, что уже выданный access token может жить
  до `exp`; не обещать мгновенный blacklist.
- [ ] `DR-E6-018` — добавить UI current logout и logout-all с понятными
  success/error states.

### 10.5. Feature 6C — восстановление пароля

- [ ] `DR-E6-019` — выполнить contract audit `BR-USR-007/009`,
  `BR-SEC-003—007` и HTTP 9.0—9.2.
- [ ] `DR-E6-020` — реализовать
  `POST /api/v1/auth/password-reset-requests` с неразличимым `202`.
- [ ] `DR-E6-021` — выпустить `RESET_PASSWORD` на 30 минут, инвалидируя
  предыдущий незавершённый token того же purpose.
- [ ] `DR-E6-022` — создать reset token и outbox одной транзакцией.
- [ ] `DR-E6-023` — применить email/IP rate limits без раскрытия аккаунта.
- [ ] `DR-E6-024` — расширить email handler purpose-specific template и URL,
  не превращая queue message в носитель секрета.
- [ ] `DR-E6-025` — реализовать
  `POST /api/v1/auth/password-reset`.
- [ ] `DR-E6-026` — применить общую policy 12—128 Unicode code points и хотя
  бы один non-space character.
- [ ] `DR-E6-027` — блокировать User и token, проверить purpose/lifecycle,
  обновить password hash, использовать token и отозвать все sessions одной
  транзакцией.
- [ ] `DR-E6-028` — reset не активирует inactive account.
- [ ] `DR-E6-029` — удалить открытый password/token из памяти frontend после
  terminal result.
- [ ] `DR-E6-030` — добавить request/reset pages с unknown-safe request
  результатом и invalid/expired/used token states.

### 10.6. Feature 6D — смена пароля

- [ ] `DR-E6-031` — выполнить contract audit `MVP-SC-005` и HTTP 9.3.
- [ ] `DR-E6-032` — реализовать protected
  `POST /api/v1/account/password-change`.
- [ ] `DR-E6-033` — проверить active User, текущий password и общую policy
  нового password.
- [ ] `DR-E6-034` — заблокировать User, заменить hash и отозвать все sessions
  одной транзакцией.
- [ ] `DR-E6-035` — не сохранять и не журналировать current/new password,
  canonical request или raw fingerprint.
- [ ] `DR-E6-036` — определить post-change frontend state: очистить local
  auth state и направить на повторный login согласно контракту.
- [ ] `DR-E6-037` — добавить account security form с autocomplete attributes,
  pending и safe errors.

### 10.7. Tests

- [ ] `DR-E6-038` — unit tests purpose policies и общей password policy.
- [ ] `DR-E6-039` — integration tests replacement token, reset/change
  password и массовый revoke sessions.
- [ ] `DR-E6-040` — transaction rollback tests для каждого security writer.
- [ ] `DR-E6-041` — concurrency tests двух activation requests, двух reset
  applications и logout-all против refresh.
- [ ] `DR-E6-042` — HTTP tests enumeration resistance, token lifecycle,
  rate limits, CSRF/Origin, cookie clearing и exact errors.
- [ ] `DR-E6-043` — email delivery tests для activation replacement и reset
  templates.
- [ ] `DR-E6-044` — component tests всех форм и terminal states.
- [ ] `DR-E6-045` — browser E2E reset:
  `request → email → set password → old login fails → new login succeeds`.
- [ ] `DR-E6-046` — browser E2E password change/logout-all с двумя sessions.
- [ ] `DR-E6-047` — sensitive-data regression для HTTP, logs, queue, HTML,
  URL и storage.

### 10.8. Документация и exit criteria

- [ ] `DR-E6-048` — обновить фактическую схему/структуру/access/frontend/HTTP
  документы.
- [ ] `DR-E6-049` — `make check` и security E2E проходят.
- [ ] `DR-E6-050` — потерянный password восстанавливается без ручного
  изменения БД.
- [ ] `DR-E6-051` — logout и security changes прекращают долгоживущий refresh
  access в предусмотренных границах.
- [ ] `DR-E6-052` — public requests не подтверждают существование email.
- [ ] `DR-E6-053` — этап 6 закрыт и строка сводной карты отмечена `[x]`.

---

## 11. Этап 7 — Эксплуатация, hardening и release gate

### 11.1. Цель и результат

Все обязательные сценарии объединены в один production-like путь. Технические
записи очищаются по нормативным срокам, email backlog наблюдаем и безопасно
обрабатывается, а ACL/consistency проверены для всей поверхности API.

Этап не добавляет новую финансовую модель. Он завершает сквозные
`MVP-SC-019—022` и доказывает готовность MVP по разделу 16 `mvp-scope.md`.

### 11.2. Retention и cleanup

- [ ] `DR-E7-001` — выполнить contract audit ADR-013/014,
  `BR-IDEM-005`, `BR-SEC-005—007` и lifecycle всех технических сущностей.
- [ ] `DR-E7-002` — составить явную retention matrix для `UserSession`,
  `UserActionToken`, `ConnectInvitation`, `IdempotencyRecord` и
  `EmailDeliveryOutbox`.
- [ ] `DR-E7-003` — реализовать batch cleanup command/job с bounded batch,
  стабильным порядком и безопасным повтором.
- [ ] `DR-E7-004` — не удалять active/non-terminal записи раньше срока.
- [ ] `DR-E7-005` — хранить idempotency result минимум нормативные 24 часа.
- [ ] `DR-E7-006` — физически очищать terminal technical records после
  нормативных 30 дней с учётом FK order.
- [ ] `DR-E7-007` — очищать encrypted email payload сразу при
  `SENT/FAILED` или недоставляемом/истёкшем token.
- [ ] `DR-E7-008` — не удалять business/financial history cleanup job-ом.
- [ ] `DR-E7-009` — добавить schedule/worker invocation без tight loop и
  без параллельного разрушительного запуска.
- [ ] `DR-E7-010` — добавить dry-run/summary только если он не выводит
  sensitive values.

### 11.3. Наблюдаемость email и безопасный replay

- [ ] `DR-E7-011` — определить наблюдаемые показатели:
  pending count/age, published count/age, failed count, failure transport size,
  relay/SMTP errors.
- [ ] `DR-E7-012` — добавить structured logs/metrics без recipient email,
  token, ciphertext, SMTP response body или stack trace в публичном контексте.
- [ ] `DR-E7-013` — определить warning/critical thresholds как deployment
  configuration; если числовые SLA не приняты, не объявлять их нормативными.
- [ ] `DR-E7-014` — документировать операторскую диагностику PostgreSQL
  outbox, RabbitMQ и Messenger failure transport.
- [ ] `DR-E7-015` — реализовать/описать safe replay только для всё ещё
  deliverable token и non-terminal delivery.
- [ ] `DR-E7-016` — запретить replay путём ручного изменения token/outbox
  status или копирования открытого token.
- [ ] `DR-E7-017` — доказать, что replay не создаёт второй business state и
  допускает только принятую `at-least-once` email семантику.
- [ ] `DR-E7-018` — описать terminal `FAILED` и порядок реакции оператора.

### 11.4. Сквозной security и consistency audit

- [ ] `DR-E7-019` — составить фактическую матрицу всех 31 endpoint и сравнить
  её с HTTP 16.
- [ ] `DR-E7-020` — проверить, что не реализованные Should/Later endpoints
  действительно отсутствуют, а не возвращают placeholder.
- [ ] `DR-E7-021` — проверить authentication/ACL для каждого protected read и
  writer.
- [ ] `DR-E7-022` — проверить `404` privacy для Connect, Debt и Transfer.
- [ ] `DR-E7-023` — проверить отсутствие чужого email во всех nested read
  models.
- [ ] `DR-E7-024` — проверить inactive-user restrictions во всех commands.
- [ ] `DR-E7-025` — проверить Idempotency-Key scope/operation/body conflict
  для всех предусмотренных create commands.
- [ ] `DR-E7-026` — проверить `ETag/If-Match` для всех реализованных
  versioned writers.
- [ ] `DR-E7-027` — проверить transaction rollback и отсутствие частичного
  состояния каждого многотабличного writer.
- [ ] `DR-E7-028` — проверить DB constraints против duplicate pair,
  participant, share, token и idempotency state.
- [ ] `DR-E7-029` — проверить password/token/JWT/cookie/ciphertext redaction
  в serializer, logs, E2E artifacts и failure paths.
- [ ] `DR-E7-030` — выполнить dependency/architecture audit ADR-015 для новых
  модулей `Connects`, `Invitations`, `Debts` и `Transfers`.

### 11.5. Full product E2E и quality

- [ ] `DR-E7-031` — создать isolated full-MVP browser E2E:
  два новых пользователя регистрируются и активируются.
- [ ] `DR-E7-032` — оба входят; A приглашает B; B принимает.
- [ ] `DR-E7-033` — A создаёт долг с нормативным составом.
- [ ] `DR-E7-034` — A и B видят Debt, `DEBT_SHARE`, history и исходный
  balance.
- [ ] `DR-E7-035` — одна сторона создаёт MANUAL; обе видят новый balance.
- [ ] `DR-E7-036` — author изменяет Debt; все затронутые доли и balances
  пересчитываются.
- [ ] `DR-E7-037` — третий пользователь не видит чужие ресурсы.
- [ ] `DR-E7-038` — повтор критических запросов не создаёт дублей.
- [ ] `DR-E7-039` — stale update не изменяет данные.
- [ ] `DR-E7-040` — E2E использует disposable PostgreSQL/Redis/RabbitMQ/
  Mailpit и не касается development volumes.
- [ ] `DR-E7-041` — расширить CI отдельным full-MVP job с безопасными
  artifacts.
- [ ] `DR-E7-042` — `make check-full` охватывает текущий основной путь.
- [ ] `DR-E7-043` — dependency audits и repository secret checks проходят.
- [ ] `DR-E7-044` — миграции проверены с нуля и на поддерживаемом upgrade path
  от текущей начальной схемы.

### 11.6. Deployment и операционная документация

- [ ] `DR-E7-045` — проверить обязательные services: Nginx, frontend,
  backend, PostgreSQL, Redis, RabbitMQ и worker.
- [ ] `DR-E7-046` — разделить development defaults и production-required
  secrets/key rings.
- [ ] `DR-E7-047` — проверить healthchecks, startup dependencies и graceful
  worker restart.
- [ ] `DR-E7-048` — документировать миграцию, запуск workers, cleanup,
  monitoring и failure replay.
- [ ] `DR-E7-049` — документировать key rotation action-token, invitation и
  JWT без раскрытия ключей.
- [ ] `DR-E7-050` — явно зафиксировать, что SLA, backup policy, RPO/RTO пока
  не определены и требуют отдельного решения до production, если владелец считает
  их release requirement.
- [ ] `DR-E7-051` — проверить актуальность README, индекса docs, фактической
  схемы, backend/frontend/access/quality документов.

### 11.7. Финальный release gate

- [ ] `DR-E7-052` — все `Must have` в traceability matrix имеют статус
  `[x]`.
- [ ] `DR-E7-053` — все 13 критериев готовности `mvp-scope.md` доказаны
  тестом или документированной операционной проверкой.
- [ ] `DR-E7-054` — ни один launch blocker раздела 16 не остаётся открытым.
- [ ] `DR-E7-055` — все допустимые ручные процессы соответствуют разделу 15;
  штатный путь не требует ручного SQL.
- [ ] `DR-E7-056` — Should/Later features не являются скрытыми зависимостями.
- [ ] `DR-E7-057` — все семь строк сводной карты отмечены `[x]`.
- [ ] `DR-E7-058` — MVP признан готовым отдельным решением владельца с датой
  и ссылкой на release/commit.

---

## 12. Backlog после обязательных семи этапов

Эти features описаны нормативно, но не должны незаметно расширять семь этапов.
Начинать их следует отдельным решением после прохождения соответствующего
Definition of Ready.

### 12.1. Should have

#### SH-006 — управление именем и email (`MVP-SC-006`)

Зависимости: этапы 1, 6 и email delivery.

- [ ] `DR-SH-006-01` — реализовать `PATCH /me` для имени.
- [ ] `DR-SH-006-02` — реализовать
  `POST /account/email-change-requests` с current password.
- [ ] `DR-SH-006-03` — сохранить новый email только в server-side payload
  `CHANGE_EMAIL` на 30 минут.
- [ ] `DR-SH-006-04` — реализовать
  `POST /auth/email-change-confirmations` с повторной проверкой уникальности.
- [ ] `DR-SH-006-05` — атомарно сменить email, использовать token и
  определить отзыв sessions по нормативному контракту.
- [ ] `DR-SH-006-06` — добавить profile UI, email templates, security/
  concurrency/browser tests и документацию.

#### SH-007 — деактивация и реактивация (`MVP-SC-007`)

Зависимости: этапы 1, 2, 6 и сохранённая финансовая история.

- [ ] `DR-SH-007-01` — реализовать `POST /account/deactivate` с current
  password.
- [ ] `DR-SH-007-02` — атомарно установить `isActive=false` и отозвать все
  sessions.
- [ ] `DR-SH-007-03` — блокировать новые financial commands немедленно.
- [ ] `DR-SH-007-04` — сохранить Connect, history и balance для других сторон.
- [ ] `DR-SH-007-05` — использовать activation-request flow для реактивации
  без восстановления старых sessions.
- [ ] `DR-SH-007-06` — покрыть freeze/visibility/reactivation E2E.

#### SH-014 — мягкое удаление долга (`MVP-SC-014`)

Зависимости: этапы 3 и 5.

- [ ] `DR-SH-014-01` — реализовать
  `DELETE /debts/{debtId}` с `If-Match`.
- [ ] `DR-SH-014-02` — применить те же author/freeze preconditions, что у
  update.
- [ ] `DR-SH-014-03` — атомарно soft-delete Debt и active `DEBT_SHARE`,
  сохранив MANUAL.
- [ ] `DR-SH-014-04` — зафиксировать состав на момент удаления и read-only
  прямую карточку.
- [ ] `DR-SH-014-05` — исключить Debt из list, но сохранить transfers в
  history и пересчитать balance.
- [ ] `DR-SH-014-06` — не добавлять restore/archive; покрыть ACL,
  concurrency и E2E.

#### SH-017 — изменение и удаление MANUAL (`MVP-SC-017`)

Зависимости: этап 4 и transfer version/ETag.

- [ ] `DR-SH-017-01` — реализовать
  `PUT /transfers/{transferId}` с `If-Match`.
- [ ] `DR-SH-017-02` — разрешить только author active MANUAL при active обеих
  сторонах.
- [ ] `DR-SH-017-03` — разрешить amount и перестановку только прежних двух
  сторон Connect.
- [ ] `DR-SH-017-04` — реализовать soft
  `DELETE /transfers/{transferId}` без восстановления.
- [ ] `DR-SH-017-05` — запретить прямое изменение `DEBT_SHARE`.
- [ ] `DR-SH-017-06` — обновлять version/balance/history и покрыть
  ACL/concurrency/E2E.

### 12.2. Later

#### LT-010 — Connect через общий долг (`MVP-SC-010`)

- [ ] `DR-LT-010-01` — подтвердить, что два пользователя active/current
  participants одного доступного неудалённого Debt.
- [ ] `DR-LT-010-02` — реализовать идемпотентный `POST /connects`.
- [ ] `DR-LT-010-03` — возвращать существующую canonical pair без дубля.
- [ ] `DR-LT-010-04` — добавить UI только после отдельного продуктового
  решения и покрыть ACL/concurrency.

#### LT-018 — информационная связь MANUAL с DEBT_SHARE (`MVP-SC-018`)

- [ ] `DR-LT-018-01` — добавить optional `relatedTransferId` в create/edit
  MANUAL contracts.
- [ ] `DR-LT-018-02` — проверять тот же Connect и active target при
  установке/замене.
- [ ] `DR-LT-018-03` — разрешить clear и сохранение прежней исторической
  ссылки после удаления target.
- [ ] `DR-LT-018-04` — явно не использовать связь для balance, debt status
  или ограничения amount.
- [ ] `DR-LT-018-05` — добавить объясняющий UI и privacy tests для
  недоступного Debt.

## 13. Явно исключённые features

Следующие сценарии нельзя добавлять задачей в этап 1—7 без изменения
`mvp-scope.md`, business rules/API/model и явного решения владельца:

- `MVP-SC-023` — импорт и экспорт;
- `MVP-SC-024` — admin panel и модерация;
- `MVP-SC-025` — мультивалютность, неравные доли, несколько плательщиков и
  пользовательская дата;
- `MVP-SC-026` — статусы `open/paid/closed`, автоматическое погашение и
  распределение возврата;
- `MVP-SC-027` — общий поиск, revoke invitation, hide/delete Connect;
- `MVP-SC-028` — архив и восстановление удалённых финансовых записей;
- `MVP-SC-029` — финансовые email/push/in-app уведомления.

Если одна из этих функций становится необходимой, сначала выполняются:

- `[ ]` новое продуктовое обоснование и категория;
- `[ ]` impact analysis на ACL, данные, API, миграции и основной путь;
- `[ ]` новый или заменяющий ADR, если меняется принятое решение;
- `[ ]` синхронизация glossary, BR, entities, ER, HTTP и MVP scope;
- `[ ]` отдельный этап roadmap с собственным Definition of Ready/Done.

## 14. Traceability всех сценариев

| Сценарий | Категория | Где реализуется/хранится | Статус |
|---|---|---|---|
| `MVP-SC-001` | Must | baseline + этап 6 | `[ ]` частично |
| `MVP-SC-002` | Must | этап 1 | `[ ]` |
| `MVP-SC-003` | Must | этап 6 | `[ ]` |
| `MVP-SC-004` | Must | этап 6 | `[ ]` |
| `MVP-SC-005` | Must | этап 6 | `[ ]` |
| `MVP-SC-006` | Should | `SH-006` | `[ ]` |
| `MVP-SC-007` | Should | `SH-007` | `[ ]` |
| `MVP-SC-008` | Must | этап 2 | `[ ]` |
| `MVP-SC-009` | Must | этапы 2—4 | `[ ]` |
| `MVP-SC-010` | Later | `LT-010` | `[ ]` |
| `MVP-SC-011` | Must | этап 3 | `[ ]` |
| `MVP-SC-012` | Must | этап 3 | `[ ]` |
| `MVP-SC-013` | Must | этап 5 | `[ ]` |
| `MVP-SC-014` | Should | `SH-014` | `[ ]` |
| `MVP-SC-015` | Must | этап 3 | `[ ]` |
| `MVP-SC-016` | Must | этап 4 | `[ ]` |
| `MVP-SC-017` | Should | `SH-017` | `[ ]` |
| `MVP-SC-018` | Later | `LT-018` | `[ ]` |
| `MVP-SC-019` | Must | baseline + этапы 6—7 | `[ ]` частично |
| `MVP-SC-020` | Must | все этапы, финальный audit в 7 | `[ ]` частично |
| `MVP-SC-021` | Must | все writers, финальный audit в 7 | `[ ]` частично |
| `MVP-SC-022` | Must | этап 7 | `[ ]` |
| `MVP-SC-023` | Out | раздел 13 | не планируется |
| `MVP-SC-024` | Out | раздел 13 | не планируется |
| `MVP-SC-025` | Out | раздел 13 | не планируется |
| `MVP-SC-026` | Out | раздел 13 | не планируется |
| `MVP-SC-027` | Out | раздел 13 | не планируется |
| `MVP-SC-028` | Out | раздел 13 | не планируется |
| `MVP-SC-029` | Out | раздел 13 | не планируется |

## 15. Контроль перед началом следующей feature

Исполнитель выбирает первый незавершённый пункт только внутри этапа, чьи
зависимости выполнены. Перед началом:

1. прочитать цель, границу и exit criteria этапа;
2. пройти feature Definition of Ready;
3. найти все связанные `BR/MVP-SC/ADR/HTTP`;
4. определить минимальный вертикальный PR, который не публикует частичную
   функцию;
5. добавить или обновить tests одновременно с production code;
6. после реализации отметить checkbox только вместе с подтверждающим diff/test;
7. не начинать следующий этап, оставив текущий endpoint частично доступным.

## 16. История обновлений

| Версия | Дата | Изменение |
|---:|---|---|
| 1 | 2026-08-08 | Создана подробная дорожная карта семи обязательных этапов, post-MVP backlog и traceability всех 29 сценариев |
