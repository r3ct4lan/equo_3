# Автоматическое покрытие первого вертикального среза (E1-15)

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-001` — регистрация и первичная активация по email |
| Статус | Implemented; `AC-001—061` трассированы и автоматизированы; AC-030 закрыт ADR-019 и HMAC regression tests |
| Дата | 2026-08-02 |
| Область | Domain, application, PostgreSQL, HTTP, frontend component/composable и browser E2E |

## 1. Обозначения тестов

- `HTTP` — `FirstVerticalSliceHttpTest` и общие `HttpInfrastructureTest`/`HealthControllerTest`.
- `APP` — `RegisterUserTest`, `ActivateAccountTest`, `ActivationAccessPolicyTest`.
- `DOM` — `EmailAddressTest`, `PasswordPolicyTest`, `UserTest`.
- `DB` — `InitialSchemaTest`, `FirstVerticalSliceTransactionTest`, `FirstVerticalSliceConcurrencyTest`.
- `MAIL` — `EmailDeliveryFlowTest`, `PayloadCipherTest`.
- `FE-U` — существующие Node unit-тесты `auth-flow.test.mjs` и `api-error.test.mjs`.
- `FE-C` — Nuxt component/composable tests в `happy-dom`.
- `E2E-01` — полный путь через Nginx, PostgreSQL, RabbitMQ и Mailpit.
- `E2E-02` — validation error с исправлением формы.
- `E2E-03` — отказ недействительного activation capability.
- `E2E-04` — безопасное восстановление после сетевой ошибки.

## 2. Матрица критериев: исходный пробел и закрытие E1-15

| Критерий/правило | Уровень теста | Покрытие до E1-15 | Исходный пробел | Реализованный автотест |
|---|---|---|---|---|
| AC-001 полный путь | HTTP, DB | `HTTP::testCompleteRegistrationAndActivationFlowMatchesContract` | Нет browser/email E2E | `E2E-01` |
| AC-002 публичная регистрация | HTTP, DB | complete flow + schema/DB assertions | Нет | Сохранить |
| AC-003 первое activation email | MAIL | relay/consumer success tests | Нет реальной связки RabbitMQ → Mailpit → UI | `E2E-01` |
| AC-004 применение token | APP, HTTP, DB | activate application, complete flow, concurrency | Нет | Сохранить |
| AC-005 без сессии | HTTP | пустой `204`, нет cookie/token | Нет явного DB assertion `UserSession=0` и UI/browser проверки | HTTP assertion + `FE-C` + `E2E-01` |
| AC-006 отсутствует name | HTTP, FE-U | invalid payload provider, client validator | Нет component behavior | `FE-C`/`E2E-02` |
| AC-007 blank name | DOM, HTTP, FE-U | `UserTest`, invalid provider, client validator | Нет component behavior | `FE-C`/`E2E-02` |
| AC-008 отсутствует email | HTTP, FE-U | invalid payload provider, client validator | Нет component behavior | `FE-C`/`E2E-02` |
| AC-009 invalid email | DOM, HTTP, FE-U | `EmailAddressTest`, invalid provider, client validator | Нет component behavior | `FE-C`/`E2E-02` |
| AC-010 нормализация email | DOM, HTTP, DB | `EmailAddressTest`, normalization HTTP test | Нет | Сохранить |
| AC-011 отсутствует password | HTTP, FE-U | invalid payload provider, client validator | Нет component behavior | `FE-C`/`E2E-02` |
| AC-012 password ровно 12 | DOM | `PasswordPolicyTest` | Нет проверки через HTTP | HTTP boundary provider |
| AC-013 password ровно 128 | DOM | `PasswordPolicyTest` | Нет проверки через HTTP | HTTP boundary provider |
| AC-014 password 11 | DOM, HTTP, FE-U | domain boundary + один HTTP business error | Нет | Сохранить/объединить provider |
| AC-015 password 129 | DOM, FE-U | domain/client boundary | Нет проверки через HTTP | HTTP boundary provider |
| AC-016 whitespace password | DOM, FE-U | domain/client boundary | Нет проверки через HTTP | HTTP boundary provider |
| AC-017 password не trim | DOM, security INT | significant whitespace + hasher verification | Нет сохранённого hash исходного password | DB/HTTP verification |
| AC-018 malformed JSON | HTTP, FE-U | malformed JSON both endpoints + safe client mapping | Нет | Сохранить |
| AC-019 отсутствует token | HTTP, FE-U | missing token + activation presentation | Нет | Сохранить |
| AC-020 duplicate email | APP, HTTP, DB, FE-U | duplicate no-write + normalized conflict | Нет component state | `FE-C` |
| AC-021 normalized uniqueness | DOM, HTTP, DB | normalization + unique constraint | Нет | Сохранить |
| AC-022 обязательный key | HTTP, FE-U | missing key no-write + key lifecycle | Нет | Сохранить |
| AC-023 replay same key/body | HTTP, DB | replay + concurrent replay | Нет | Сохранить |
| AC-024 key/different body | HTTP, DB | replay conflict | Нет | Сохранить |
| AC-025 concurrent same key | DB | two-process PostgreSQL race | Нет | Сохранить |
| AC-026 ровно 24 часа | HTTP | проверка только после 25 часов | Нет точной границы `expiresAt == now` | DB integration boundary |
| AC-027 concurrent email | DB, HTTP | two-process unique race + conflict | Нет | Сохранить |
| AC-028 rollback регистрации | DB | controlled outbox failure, zero rows | Нет | Сохранить |
| AC-029 минимальный ER-фрагмент | DB, HTTP | mapping/constraints + complete set counts | Нет | Сохранить |
| AC-030 секреты | DOM, security, HTTP, MAIL | password hashing, versioned request/token HMAC, ciphertext, safe serialization | Нет browser storage check | `E2E-01/E2E-03` |
| AC-031 UUID/UTC | HTTP, DB | exact response shape/format | Нет | Сохранить |
| AC-032 несвязанные данные | DB | registration set/rollback/concurrency assertions | Явная отрицательная область описана, отдельный synthetic test не нужен | Документировать |
| AC-033 unknown token | APP, HTTP, FE-U | access policy + no-change HTTP | Нет browser security state | `E2E-03` |
| AC-034 expired token | APP, HTTP, FE-U | exact-boundary policy + lifecycle HTTP + presentation | Нет | Сохранить |
| AC-035 used token | APP, HTTP, DB, FE-U | policy/lifecycle/concurrency | Нет browser повторного перехода | `E2E-01` |
| AC-036 invalidated token | APP, HTTP, FE-U | policy/lifecycle/presentation | Нет | Сохранить |
| AC-037 wrong purpose | APP, HTTP | policy + lifecycle HTTP | Нет | Сохранить |
| AC-038 concurrent activation | DB | two-process token race | Нет | Сохранить |
| AC-039 atomic activation error | DB | controlled post-flush rollback | Нет | Сохранить |
| AC-040 exact activation state | DOM, HTTP, DB | one-way transition + DB state | Нет browser persistence proof | `E2E-01` |
| AC-041 registration IP limit | HTTP | six requests from one IP | Нет изолированного имени теста | Разделить rate-limit cases |
| AC-042 registration email limit | HTTP | смешан с другими ключами | Ключ email не изолирован | Отдельный HTTP case |
| AC-043 activation IP limit | HTTP | смешан с token key | IP не изолирован | Отдельный HTTP case |
| AC-044 activation token limit | HTTP | repeated token/IP | Token с разными IP не проверен | Отдельный HTTP case |
| AC-045 все rate-limit keys | HTTP | общий combined case | Диагностика не доказывает каждый ключ отдельно | Четыре boundary cases + `Retry-After` |
| AC-046 atomic durable intent | APP, HTTP, DB | application coordination + four-row commit/rollback | Нет | Сохранить |
| AC-047 publish confirm | MAIL | published only after bus accepts delivery ID | Нет | Сохранить |
| AC-048 relay retry | MAIL | safe failure + 30-second defer | Полный exponential cap не проверен | Добавить boundary unit/integration assertions |
| AC-049 stale token | MAIL | expired token rejects SMTP and clears payload | Used/invalidated эквивалентны query policy; дублировать не требуется | Сохранить |
| AC-050 SMTP handoff | MAIL | synchronous transport + terminal cleanup | Нет реальной Mailpit доставки | `E2E-01` |
| AC-051 SMTP retry/final failure | MAIL | пять задержек + final subscriber | Нет | Сохранить |
| AC-052 duplicate completed delivery | MAIL | handler вызван дважды, SMTP один раз | FAILED terminal duplicate не проверен отдельно | Добавить terminal-state provider при малой стоимости |
| AC-053 допустимый at-least-once | MAIL | архитектура допускает retry, но неопределённый SMTP исход не смоделирован | Нет автоматического доказательства повторного handoff без дубля business state | MAIL fault simulation |
| AC-054 нет второго async email | MAIL | handler вызывает выделенный `TransportInterface` синхронно | Нет | Сохранить |
| AC-055 initial/loading UI | FE-U | только чистая validation/state логика | Нет реального компонента | `FE-C` |
| AC-056 double submit | FE-U, HTTP, DB | attempt key + replay/concurrency | Нет component/browser single-call assertion | `FE-C` + `E2E-02` |
| AC-057 registration success UI | FE-U | только error/flow utilities | Нет component/browser result | `FE-C` + `E2E-01/02` |
| AC-058 validation/business UI | FE-U | mappings | Нет DOM field/general error и input preservation | `FE-C` + `E2E-02` |
| AC-059 activation loading/success | FE-U | token presentation only | Нет component/browser lifecycle | `FE-C` + `E2E-01` |
| AC-060 token error UI | FE-U, HTTP | code mapping + lifecycle API | Нет browser representative path | `E2E-03` |
| AC-061 safe technical retry | HTTP, DB, FE-U | safe 500 infrastructure + rollback + retry presentation/key logic | Нет component/E2E recovery | `FE-C` + `E2E-04` |

## 3. Матрица бизнес-правил после E1-15

| Правило | Позитивный случай | Негативный случай | Граница | Сохранённое состояние |
|---|---|---|---|---|
| BR-USR-001—003 | HTTP complete flow | duplicate/blank inputs | normalized email | User inactive, затем active; session отсутствует |
| BR-USR-009 | `PasswordPolicyTest` и HTTP valid provider | domain/HTTP invalid provider | 11/12/128/129 Unicode code points | Symfony hash проверяется исходным untrimmed password |
| BR-IDEM-001—005 | replay same body | reused key/different body | exact 24h | один User/token/outbox/idempotency set; response очищается и окно начинается заново на границе |
| BR-TXN-001—002 | successful four-record commit | controlled registration/activation rollback | PostgreSQL constraints/races | ноль partial rows после ошибки |
| BR-SEC-003—005 | valid purpose-bound token | unknown/expired/used/invalidated/wrong purpose | exact expiry boundary | token hash only; atomic `usedAt` |
| BR-SEC-006 | relay/consumer success | broker/SMTP/stale token/terminal failures | 30/60/120/240/480/960/1800 second backoff и uncertain SMTP handoff | PENDING/PUBLISHED/SENT/FAILED и payload cleanup |
| BR-SEC-007 | requests до лимита | `429` после лимита | четыре независимо проверенных ключа | rejected request не создаёт business state; `Retry-After` numeric/bounded |

## 4. Неприменимые общие проверки

Оба endpoint первого среза публичны. Поэтому `401`, `403`, роль, blocked-user,
tenant/organization и protected-route middleware не являются достижимыми
состояниями `MVP-SC-001` и не будут искусственно добавляться. Доступ к объекту
защищён capability token; именно unknown/wrong-purpose/used token и привязка к
серверному User покрывают IDOR-риск. Initial data loading отсутствует, а пустая
registration form является нормативным empty state. Login/session bootstrap и
session expiry относятся к последующим сценариям.

## 5. Реализованные E2E-сценарии

| ID | Путь | Проверяемый результат |
|---|---|---|
| `E2E-01` | `/register` → PostgreSQL/outbox → RabbitMQ worker → Mailpit → email link → `/activate` | `201`, UI success, реальное письмо, `204`, URL очищен, нет session/cookie/storage token, состояние переживает reload, повтор ссылки даёт `410 TOKEN_USED` |
| `E2E-02` | пустая/невалидная форма → исправление → два синхронных click | field errors доступны и исправимы, отправлен ровно один `POST`, получен `201` |
| `E2E-03` | unknown capability → `/activate` | `400 INVALID_TOKEN`, URL очищен, resend отсутствует, capability отсутствует в DOM/cookie/storage |
| `E2E-04` | первый registration request принудительно оборван → retry | safe technical state, форма сохранена, второй запрос использует тот же `Idempotency-Key`, итог `201` |

Файл: `frontend/tests/e2e/first-vertical-slice.spec.ts`. Browser fixtures
собирают только warning/error/pageerror/request-failure, удаляют query string и
редактируют password/capability patterns. Screenshot создаётся только при
ошибке; trace и video отключены.

## 6. Изоляция и воспроизводимость

- `compose.e2e.yaml` не подключает основной `compose.yaml` и не объявляет named
  volumes;
- PostgreSQL 17, Redis и RabbitMQ используют `tmpfs`, Mailpit — отдельный
  ephemeral container;
- миграции применяются к пустой `equo_e2e` перед каждым запуском;
- уникальное имя Compose project отделяет E2E от dev stack;
- cleanup выполняет `down --remove-orphans`, но никогда не удаляет Docker
  volumes;
- до и после теста сравнивается полный `git status`; generated test output
  размещён в ignored `frontend/test-results/`;
- Playwright и Chromium устанавливаются одной pinned версией из npm lock;
- CI использует один worker, что исключает конкуренцию сценариев за rate-limit
  counters и email delivery state.

## 7. Найденные и исправленные дефекты

| Дефект | Влияние | Исправление | Regression |
|---|---|---|---|
| `crypto.randomUUID()` недоступен браузеру на non-secure HTTP origin | registration падала до HTTP-запроса и показывала safe network error | RFC 4122 UUID v4 генерируется через `crypto.getRandomValues`; формат и lifecycle ключа сохранены | `FE-U::generates an RFC 4122 UUID v4…`, `E2E-01/02/04` |
| Development PHP image выполнял Symfony cache warmup до передачи обязательных runtime env | чистая E2E/CI Docker build могла завершиться ошибкой | build создаёт writable `var/cache`/`var/log`; cache строится приложением с фактическим env | clean E2E image build + container health/migration |

Изменений HTTP-контрактов, бизнес-правил, ADR или схемы данных не потребовалось.
Новых открытых вопросов нет.

## 8. Команды

```bash
make test-backend
docker compose exec -T frontend npm run test:unit
docker compose exec -T frontend npm run test:component
make test-e2e
make check
make check-full
```

GitHub Actions job `First vertical slice browser E2E` запускает
`make test-e2e` на pull request, push в `main` и вручную. При падении redacted
screenshots/diagnostics публикуются как artifact на 7 дней.

## 9. Итог покрытия

Все 61 критерия имеют автоматическое отражение на минимально достаточном
уровне; критический пользовательский путь дополнительно проверяется сквозным
browser E2E. Искусственные тесты `401/403`, ролей, tenant и session expiry не
добавлены по причинам из раздела 4. Отдельный coverage percentage threshold не
вводился: готовность определяется трассировкой нормативных критериев и
прохождением обязательных наборов, а не процентом строк.

## 10. Подтверждённые результаты 2026-08-02

| Команда | Exit | Результат | Длительность |
|---|---:|---|---:|
| `php bin/phpunit tests/IdentityAccess/Domain` | 0 | 10/10 passed, 0 skipped, 0 failed; 12 assertions | 0.029 s |
| `php bin/phpunit tests/IdentityAccess/Application` | 0 | 9/9 passed, 0 skipped, 0 failed; 48 assertions | 0.023 s |
| `make test-backend` (финальный запуск внутри `check-full`) | 0 | empty DB migrations/mapping/schema; 106/106 passed, 0 skipped, 0 failed; 839 assertions | PHPUnit 13.134 s |
| `make test-frontend` | 0 | unit 16/16 + component 9/9; 0 skipped, 0 failed | unit 0.148 s; component 3.75 s |
| `make test-e2e` | 0 | 4/4 passed, 0 skipped, 0 failed; source drift отсутствует | tests 21.8 s; command 76.2 s |
| `make check-repository` | 0 | secret-pattern check и workflow YAML validation passed | 1.7 s |
| `make check` | 0 | backend quality/tests/audit, frontend quality/tests/build/audit, migrations, smoke, source-drift check passed | 62.0 s |
| `make check-full` | 0 | полный `make check` + 4/4 E2E; source drift отсутствует | 133.4 s; E2E 19.7 s |

После E1-15 был выполнен push и GitHub Actions run `30760126539`; он завершился
ошибкой на чистом checkout из-за отсутствующего `backend/.env` и различия npm
10/11 при проверке lock-файла. Исправления E1-16 описаны в
[quality gate](quality-gate.md), но новый CI run без отдельного commit/push не
выполнялся. Исторические локальные результаты таблицы выше остаются
действительными для ревизии E1-15; актуальные результаты E1-16 приведены в
[контрактной сверке](first-vertical-slice-contract-review.md).

После принятия ADR-019 добавлены четыре security tests на versioned HMAC,
несовпадение с обычным SHA-256, replay после ротации, legacy cutover и
fail-closed для неизвестной версии. HTTP test проверяет фактически сохранённый
HMAC. Финальный E1-16 `make check-full` прошёл: backend 111/111 (880
assertions), frontend 16 unit + 9 component и 4/4 browser E2E.
