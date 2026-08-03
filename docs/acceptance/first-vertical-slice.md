# Техническая демонстрация и приёмка первого вертикального среза (E1-17)

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-001` — первичная регистрация с активацией по первому email |
| Дата проверки | 2026-08-02 |
| Ветка | `main` |
| Commit SHA продукта | `3e2bd2179a2bb73b16a39a7ac2d84a4120d7094a` |
| Совпадение с remote | `HEAD == origin/main` |
| Product-код | Проверен ровно из указанного commit; в E1-17 не изменялся |
| Незакоммиченные файлы | Только `compose.demo.yaml`, этот протокол и безопасные screenshots |
| Технический verdict | **TECHNICAL PASS** |
| Решение владельца продукта | **ACCEPTED** — работа принята владельцем продукта 2026-08-03 |

## 1. Объём и источники истины

Проверка проведена по [критериям AC-001—061](../first-vertical-slice-acceptance.md),
[сверке E1-16](../first-vertical-slice-contract-review.md),
[HTTP-контракту](../api/http-contracts.md),
[бизнес-правилам](../business-rules/business-rules.md),
[модели доступа](../security/access-model.md) и
[матрице автоматического покрытия](../first-vertical-slice-test-coverage.md).
Применимого `AGENTS.md` в репозитории нет; найденный файл относится к соседнему проекту и не применялся.

До демонстрации подтверждено:

- ветка `main`, product HEAD и `origin/main` указывают на один SHA;
- открытых вопросов: 0 из 20; BLOCKER/HIGH из E1-16 не осталось;
- оба endpoint публичны по принятому сценарию: registration выполняет анонимный посетитель, activation защищена purpose-bound одноразовой capability;
- login, роль и tenant не являются предусловием `MVP-SC-001`; успешная activation намеренно не создаёт сессию;
- CI exact SHA завершён успешно.

## 2. Окружение и воспроизводимость

Ручная демонстрация использовала отдельный Compose project `equo-3-acceptance-3e2bd21`,
`compose.e2e.yaml` и demo-only override `compose.demo.yaml`. PostgreSQL, Redis и RabbitMQ
работали на `tmpfs`; именованные volumes и dev-база не использовались. Nginx был опубликован
только на `127.0.0.1:18080`, Mailpit — на `127.0.0.1:18025`. Все credentials и keys были
только тестовыми значениями из E2E-профиля; production secrets и реальные персональные данные
не использовались.

Demo-only override нужен потому, что штатный container E2E использует внутренний URL
`http://nginx`, а host browser должен получить loopback activation URL. Override не доступен
production и не меняет product-код.

### Версии сервисов

| Компонент | Версия |
|---|---|
| PHP | 8.4.24 |
| Symfony FrameworkBundle | 7.4.14 |
| Node.js / npm | 22.23.2 / 11.6.2 |
| Nuxt / Vue | 4.5.0 / 3.5.40 |
| Nginx | 1.28.3 |
| PostgreSQL | 17.10 |
| Redis | 8.2.8 |
| RabbitMQ | 4.2.9 |
| Mailpit | 1.28.4 |

### Воспроизводимый запуск

```bash
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml build backend frontend
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml up -d --wait rabbitmq
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml up -d --wait postgres redis mailpit
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml run --rm -T backend \
  php bin/console doctrine:migrations:migrate --no-interaction
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml up -d --wait backend frontend nginx
docker compose -p equo-3-acceptance-3e2bd21 \
  -f compose.e2e.yaml -f compose.demo.yaml up -d worker
```

Остановка выполняется той же парой Compose-файлов через `down --remove-orphans` без `-v`.
Пустая БД получила 2/2 migrations и достигла `Version20260731153000`; unavailable/new migrations — 0/0.

## 3. Обозначения доказательств

| Код | Доказательство |
|---|---|
| `MAN-01` | Nginx UI и `/api/health` открыты через host browser; 200/200 |
| `MAN-02` | Empty/invalid form показывает field-level validation; исправление без reload приводит к success |
| `MAN-03` | UI registration: один POST, `201`, экран `Check your email` |
| `MAN-04` | Реальное письмо найдено и открыто в Mailpit |
| `MAN-05` | Email link открыл UI activation, POST вернул `204`, экран `Your account is active` |
| `MAN-06` | Reload очищенного `/activate` показывает missing link; повтор исходной ссылки даёт `410 TOKEN_USED` |
| `MAN-07` | Неизвестная capability даёт `400 INVALID_TOKEN`, удаляется из URL и не видна в DOM |
| `MAN-08` | Повтор normalized email даёт `409 EMAIL_ALREADY_EXISTS`; значения формы сохранены |
| `MAN-09` | Back/Forward: `/` → `/register` → `/`; browser console warn/error пуст; 429px activation viewport без horizontal overflow |
| `DB-01` | Read-only SQL: 1 user/1 active, 1 used token, 1 idempotency record, 1 SENT outbox, payload cleared, orphan tokens 0 |
| `BE-111` | PHPUnit: 111 tests, 880 assertions |
| `FE-16/9` | Frontend: 16 unit и 9 component tests |
| `E2E-4` | 4 browser tests: happy path, validation/double-submit, invalid capability, safe retry |
| `CI-30764973795` | Exact SHA CI: все 7 jobs success |

## 4. Demo script и фактический результат

| Шаг | Действие | Ожидаемый результат | Критерий | Доказательство | Статус |
|---:|---|---|---|---|---|
| 1 | Зафиксировать branch/SHA/status | Однозначная версия; product-код чист относительно commit | E1-17 | Git branch/SHA/status | PASS |
| 2 | Собрать отдельный demo project и применить migrations | Все сервисы healthy; БД создана с нуля | AC-029, AC-046—050 | Compose/migration status | PASS |
| 3 | Открыть UI и health | Frontend и API доступны через Nginx | AC-001 | `MAN-01` | PASS |
| 4 | Отправить пустую форму | Ошибки у name/email/password; состояние исправимо | AC-006—009, AC-011, AC-055, AC-058 | `MAN-02` | PASS |
| 5 | Исправить данные и зарегистрироваться | Один реальный POST; `201`; inactive account и durable email intent | AC-001—003, AC-056—057 | `MAN-03`, `DB-01` | PASS |
| 6 | Открыть Mailpit | Получено первое activation email | AC-003, AC-050 | `MAN-04` | PASS |
| 7 | Открыть email link | Token удалён из URL; `204`; success UI; сессия не заявлена | AC-004—005, AC-059 | `MAN-05` | PASS |
| 8 | Reload и повторно открыть ссылку | Reload без capability безопасен; исходная ссылка уже использована; DB остаётся active | AC-030, AC-035, AC-040, AC-060 | `MAN-06`, `DB-01` | PASS |
| 9 | Повторить регистрацию в другом регистре email | `409`; второй набор данных не создан | AC-020—021, AC-027, AC-058 | `MAN-08`, `DB-01` | PASS |
| 10 | Открыть неизвестную capability | `400`; чужой объект не раскрыт; DB не меняется | AC-033, AC-039, AC-060 | `MAN-07`, `DB-01` | PASS |
| 11 | Проверить восстановление | Validation исправлена без reload; бизнес-ошибка сохраняет поля и навигацию | AC-055, AC-058, AC-061 | `MAN-02`, `MAN-08` | PASS |
| 12 | Проверить navigation/console/narrow screen | Back/Forward штатны; ошибок console нет; узкий activation UI без overflow | UI quality | `MAN-09` | PASS |

Keyboard focus order построен из native inputs/button и корректной DOM-последовательности.
Инъекция клавиши Tab через выбранный browser-control runtime не дала надёжного сигнала смены focus;
это зафиксировано как verification limitation ниже, а не как подтверждённый product defect.

## 5. Приёмочная матрица AC-001—061

| Критерий | Результат | Способ проверки | Доказательство |
|---|---|---|---|
| AC-001 | PASS | UI + API + email + DB | `MAN-03—05`, `DB-01`, `E2E-4` |
| AC-002 | PASS | UI/API public registration | `MAN-03`, `BE-111` |
| AC-003 | PASS | Mailpit delivery | `MAN-04`, `E2E-4` |
| AC-004 | PASS | One-time activation | `MAN-05`, `DB-01` |
| AC-005 | PASS | No sign-in/session claim | `MAN-05`, `E2E-4`, `FE-16/9` |
| AC-006 | PASS | Missing name | `MAN-02`, `BE-111`, `FE-16/9` |
| AC-007 | PASS | Blank-trimmed name | `BE-111` |
| AC-008 | PASS | Missing email | `MAN-02`, `BE-111`, `FE-16/9` |
| AC-009 | PASS | Invalid email | `MAN-02`, `BE-111`, `E2E-4` |
| AC-010 | PASS | Email normalization | `MAN-08`, `BE-111` |
| AC-011 | PASS | Missing password | `MAN-02`, `BE-111`, `FE-16/9` |
| AC-012 | PASS | Password min boundary | `BE-111`, `FE-16/9` |
| AC-013 | PASS | Password max boundary | `BE-111`, `FE-16/9` |
| AC-014 | PASS | Password below min | `BE-111`, `FE-16/9` |
| AC-015 | PASS | Password above max | `BE-111`, `FE-16/9` |
| AC-016 | PASS | Whitespace-only password | `BE-111`, `FE-16/9` |
| AC-017 | PASS | No password normalization | `BE-111` |
| AC-018 | PASS | Malformed JSON | `BE-111` |
| AC-019 | PASS | Missing activation token | `BE-111`, `FE-16/9` |
| AC-020 | PASS | Existing email conflict | `MAN-08`, `DB-01`, `BE-111` |
| AC-021 | PASS | Conflict after normalization | `MAN-08`, `BE-111` |
| AC-022 | PASS | Required Idempotency-Key | `BE-111` |
| AC-023 | PASS | Same key/same request replay | `BE-111` |
| AC-024 | PASS | Same key/different request conflict | `BE-111` |
| AC-025 | PASS | Concurrent key replay | `BE-111` |
| AC-026 | PASS | 24-hour idempotency window | `BE-111` |
| AC-027 | PASS | Concurrent normalized email | `MAN-08`, `BE-111`, `DB-01` |
| AC-028 | PASS | Registration rollback | `BE-111` |
| AC-029 | PASS | Minimal ER fragment | Empty-DB migration/schema validation, `DB-01` |
| AC-030 | PASS | Secret storage/exposure | `DB-01`, `BE-111`, `E2E-4` |
| AC-031 | PASS | UUID/server UTC | `DB-01`, `BE-111` |
| AC-032 | PASS | No unrelated data changes | `BE-111`, `DB-01` |
| AC-033 | PASS | Unknown token denied | `MAN-07`, `BE-111`, `E2E-4` |
| AC-034 | PASS | Expired token | `BE-111`, `FE-16/9` |
| AC-035 | PASS | Used token | `MAN-06`, `BE-111`, `FE-16/9` |
| AC-036 | PASS | Invalidated token | `BE-111`, `FE-16/9` |
| AC-037 | PASS | Wrong-purpose token | `BE-111` |
| AC-038 | PASS | Concurrent token application | `BE-111` |
| AC-039 | PASS | Activation atomicity | `MAN-07`, `BE-111`, `DB-01` |
| AC-040 | PASS | Exact activation state | `MAN-05`, `DB-01`, `BE-111` |
| AC-041 | PASS | Registration IP limit | `BE-111` |
| AC-042 | PASS | Registration email limit | `BE-111` |
| AC-043 | PASS | Activation IP limit | `BE-111` |
| AC-044 | PASS | Activation token-hash limit | `BE-111` |
| AC-045 | PASS | Required rate-limit keys | `BE-111` |
| AC-046 | PASS | Atomic durable intent | `DB-01`, `BE-111` |
| AC-047 | PASS | Publisher confirm boundary | `BE-111` |
| AC-048 | PASS | Relay retry profile | `BE-111` |
| AC-049 | PASS | Stale token not sent | `BE-111` |
| AC-050 | PASS | SMTP handoff | `MAN-04`, `DB-01`, `E2E-4` |
| AC-051 | PASS | SMTP retry/failure | `BE-111` |
| AC-052 | PASS | Terminal delivery deduplication | `BE-111` |
| AC-053 | PASS | Documented at-least-once boundary | `BE-111`, normative acceptance |
| AC-054 | PASS | No second async email loop | `BE-111`, E1-16 review |
| AC-055 | PASS | Initial/loading/validation UI | `MAN-02`, `FE-16/9`, `E2E-4` |
| AC-056 | PASS | Double submit does not duplicate | `E2E-4`, `BE-111` |
| AC-057 | PASS | Registration success UI | `MAN-03`, `FE-16/9` |
| AC-058 | PASS | Validation/business/rate UI | `MAN-02`, `MAN-08`, `FE-16/9` |
| AC-059 | PASS | Activation loading/success UI | `MAN-05`, `FE-16/9`, `E2E-4` |
| AC-060 | PASS | Token error UI | `MAN-06—07`, `FE-16/9`, `E2E-4` |
| AC-061 | PASS | Safe technical retry | `E2E-4`, `BE-111`, `FE-16/9` |

## 6. HTTP и данные ручной демонстрации

Safe request chronology через Nginx/backend: registration `201`, activation `204`,
reused token `410`, unknown token `400`, duplicate normalized email `409`.
Request bodies, password и raw capability в доказательства не включены.

Read-only SQL после успешного и негативных шагов подтвердил:

- `app_user`: 1 запись, `is_active=true`, server timestamp заполнен, password представлен one-way value;
- `user_action_token`: 1 связанная запись `ACTIVATE_ACCOUNT`, `used_at` заполнен, hash versioned;
- `idempotency_record`: 1 запись, response status `201`, versioned request HMAC;
- `email_delivery_outbox`: 1 запись `SENT`, `sent_at` заполнен, encrypted payload очищен;
- orphan token rows: 0;
- после `400` и `409` counts остались `1/1/1/1`; частичных наборов нет;
- owner/tenant неприменимы: сценарий public, activation object access задаётся серверно связанной capability.

## 7. Финальные автоматические проверки

| Проверка | Команда | Exit | Результат |
|---|---|---:|---|
| Backend style | `docker compose exec -T -e APP_ENV=test backend composer check:style` | 0 | 0/100 files требуют исправления |
| PHP static analysis | `docker compose exec -T -e APP_ENV=test backend composer analyse` | 0 | PHPStan no errors; test cache warmup OK |
| Symfony/config/routes/mapping | `docker compose exec -T -e APP_ENV=test backend composer check:symfony` | 0 | container, 17 YAML, routes, mapping OK |
| Empty DB migrations + full PHPUnit | `./scripts/test-backend.sh` | 0 | 2/2 migrations; schema sync; 111 tests, 880 assertions |
| Frontend lint | `docker compose exec -T frontend npm run lint` | 0 | 0 warnings/errors |
| TypeScript | `docker compose exec -T frontend npm run typecheck` | 0 | passed |
| Frontend unit | `docker compose exec -T frontend npm run test:unit` | 0 | 16/16 |
| Frontend component | `docker compose exec -T frontend npm run test:component` | 0 | 9/9 in 3 files |
| Nuxt production build | `docker compose exec -T frontend npm run build` | 0 | Nuxt 4.5 production build complete |
| Composer audit | `composer audit --locked` in backend container | 0 | no advisories |
| npm audit | `npm audit --audit-level=moderate` in frontend container | 0 | 0 vulnerabilities |
| Browser E2E | `make test-e2e` | 0 | 4/4; migrations from zero; no source changes |
| Full quality gate | `make check` | 0 | full gate + smoke; no generated source changes |
| Full gate with E2E | `make check-full` | 0 | 111/880; 16+9; build/audits/smoke; 4/4 E2E |
| API smoke | `curl http://127.0.0.1:8080/api/health` | 0 | HTTP 200, exact safe health JSON |
| Frontend smoke | `curl http://127.0.0.1:8080/register` | 0 | HTTP 200, HTML |
| CI exact SHA | `gh run view 30764973795` | 0 | 7/7 jobs success for exact SHA |

Verification history сохранена прозрачно: первый standalone `make test-e2e` завершился
до тестов на старте disposable RabbitMQ при одновременной работе dev+demo+E2E стеков (exit 1).
После остановки только demo project штатный запуск прошёл 4/4; дополнительный wrapper-run
однажды получил внешний timeout 124 после 64 секунд и не считался результатом проекта.
Заключительные `make test-e2e` и `make check-full` оба завершились exit 0.

## 8. CI

GitHub Actions run [30764973795](https://github.com/r3ct4lan/equo_3/actions/runs/30764973795)
имеет `headSha=3e2bd2179a2bb73b16a39a7ac2d84a4120d7094a`, status `completed`,
conclusion `success`. Успешны jobs:

- Repository quality;
- Backend tests and migrations;
- Backend quality;
- First vertical slice browser E2E;
- Dependency audit;
- Frontend quality and build;
- Application smoke.

Неблокирующие annotations сообщают о deprecated Node.js 20 runtime внутри pinned GitHub Actions;
runner принудительно использовал Node.js 24, все jobs успешны.

## 9. Дефекты, вопросы и ограничения

### BLOCKER / HIGH / MEDIUM

Подтверждённых дефектов нет.

### LOW / эксплуатационные наблюдения

| ID | Наблюдение | Воспроизведение / влияние | Follow-up | Блокирует |
|---|---|---|---|---|
| E1-17-001 | Локальный RabbitMQ чувствителен к одновременному запуску трёх стеков | Первый E2E остановился до тестов при работающих dev+demo; intended topology после остановки demo прошёл | Добавить в runbook последовательность demo → stop → E2E и минимальные Docker resources | Нет |
| E1-17-002 | Tab-focus не получил надёжного runtime-сигнала | Native controls/DOM order корректны, но injected Tab не сменил наблюдаемый focus | Добавить отдельный Playwright keyboard-focus сценарий | Нет; отдельного AC нет |
| E1-17-003 | GitHub Actions сообщает Node 20 deprecation | Только annotations, jobs green | Планово обновить pinned action revisions после проверки совместимости | Нет |

Продуктовых, архитектурных и security open questions нет; журнал содержит 20 resolved / 0 open.

Ограничения демонстрации:

- demo использует development frontend image из принятого E2E-контура; production build проверен отдельно;
- success activation после reload не может быть восстановлен без capability: URL намеренно scrubbed;
  persistence подтверждается повторным открытием исходной ссылки (`410 TOKEN_USED`) и SQL state;
- login и role UI относятся к следующему сценарию и не добавлялись.

## 10. Screenshots

- [Registration initial state](evidence/01-registration-initial.jpg)
- [Registration validation](evidence/02-registration-validation.jpg)
- [Registration success](evidence/03-registration-success.jpg)
- [Activation email in Mailpit](evidence/04-mailpit-activation-email.jpg)
- [Activation success, narrow viewport](evidence/05-activation-success.jpg)
- [Used activation link](evidence/06-activation-reused.jpg)
- [Unknown activation capability](evidence/07-activation-invalid.jpg)
- [Duplicate normalized email](evidence/08-registration-duplicate.jpg)

Снимки содержат только synthetic `example.test` данные. Password, raw capability, cookies,
production credentials и реальные персональные данные отсутствуют.

## 11. Verdict и решение владельца продукта

**TECHNICAL PASS**: основной и негативные пути работают; migrations воспроизводимы с нуля;
данные и atomicity подтверждены; capability access и safe errors соблюдены; все AC-001—061
имеют PASS evidence; локальные gates, E2E и CI exact SHA зелёные; BLOCKER/HIGH отсутствуют.

Владелец продукта подтвердил, что принимает работу как выполненную.

- [x] Принять сценарий;
- [ ] Принять условно;
- [ ] Отклонить и вернуть на исправление.

**Решение владельца продукта:** `ACCEPTED` (2026-08-03).

## 12. Контроль изменений и безопасности

- Product backend/frontend и business requirements в E1-17 не изменялись.
- Добавлены только acceptance docs, screenshots и production-inaccessible demo fixture.
- Production credentials не использовались.
- Dev-база не очищалась и не удалялась; Docker volumes не удалялись.
- Demo-данные synthetic и после остановки disposable tmpfs-контура недоступны.
- На момент технической демонстрации commit, push, merge, rebase и pull request не выполнялись;
  результат приёмки публикуется отдельным разрешённым commit.
