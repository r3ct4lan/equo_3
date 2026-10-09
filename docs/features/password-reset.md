# Восстановление забытого пароля

| Поле | Значение |
|---|---|
| Сценарий | MVP-SC-004 |
| Статус | In progress |
| Дата contract audit | 2026-10-09 |
| Ветка | codex/sc-004-password-reset |
| Base | 6d2617e; принятый функциональный результат SC-003, ещё не merged |
| Roadmap | DR-E6-019—030; применимые tests DR-E6-038—047 |

## Требования и фактический старт

Нормативные источники: [MVP-SC-004](../mvp-scope.md#mvp-sc-004),
[BR-USR-007/009 и BR-SEC-003—007](../business-rules/business-rules.md),
[HTTP 9.0—9.2, 17—18](../api/http-contracts.md),
[UserActionToken и transaction boundaries](../data-model/entities.md),
[ADR-006/007/013/014/016/018](../adr/architecture-decisions.md).
Handoff содержит только сокращённый scope; нормативный комплект репозитория
содержит необходимые детали, существенных противоречий не обнаружено.

SC-004 отсутствует в коде: нет reset endpoints/pages, token issuance/apply и
reset email template. Общие HMAC token codec, PasswordPolicy/hasher, encrypted
outbox, relay/consumer, atomic session revoke и cookie factory уже реализованы.
Схема поддерживает RESET_PASSWORD и нужные lifecycle fields; migration не нужна.

## Контракт

- POST `/api/v1/auth/password-reset-requests`: `{email}`, JSON; нормализованный
  email, sliding-window 3/hour email и 20/hour trusted IP. Unknown/active/inactive
  account в пределах лимитов получает одинаковый `202` и
  `{status: "password_reset_email_scheduled"}`. Unknown не создаёт записи.
- Для существующего User: User lock → invalidate всех unfinished RESET_PASSWORD,
  включая expired → новый random purpose-bound HMAC token на 30 минут → encrypted
  outbox в одной transaction. ACTIVATE_ACCOUNT/CHANGE_EMAIL не меняются.
- POST `/api/v1/auth/password-reset`: `{token,newPassword}`, JSON, без Bearer/CSRF.
  Capability authority — token. Общая policy 12—128 Unicode code points, минимум
  один non-space, без trim/normalization; backend проверяет независимо от UI.
- Apply использует общий UserActionToken IP/hash limiter 10/15min и 5/15min.
  Strict malformed/unknown/wrong-purpose → 400 INVALID_TOKEN; invalidated/used/
  expired → 410 TOKEN_INVALIDATED/TOKEN_USED/TOKEN_EXPIRED, как существующая token
  error model. Validation → 422 VALIDATION_ERROR с field violations; 429 с
  Retry-After; safe request ID/error envelope.
- Lookup token owner без lock → User FOR UPDATE → token FOR UPDATE и fresh
  lifecycle/purpose recheck → password hash update + usedAt + revoke unfinished
  sessions в одной transaction. Lock order User → token → sessions; rollback
  не оставляет частичного состояния. isActive не меняется, auto-login нет.
- Success — пустой 204, обе auth cookies очищаются с прежними names/paths/flags.
  Failure cookies не меняет. JWT не blacklist-ится и действует до exp.
- Email consumer проверяет deliverability, purpose/template pair, отправляет
  `/password-reset?token=...` через существующую chain; queue содержит только
  deliveryId, encrypted payload очищается после terminal delivery.
- UI `/forgot-password` и `/password-reset`, переход из login. Token очищается из
  URL при client mount и остаётся только private in-memory; отсутствует в SSR
  payload/state/storage/logs. Новые password/token удаляются при terminal result
  и unmount; technical failure допускает явный retry без сохранения секретов.
  Success очищает local auth и публикует token-free session-ended только после
  подтверждённого 204, направляет к explicit signed-out login. Inactive account
  остаётся inactive и использует обычную активацию отдельно.

## Этапы и проверка

| Этап | Статус | Результат / проверка |
|---|---|---|
| SC4-01 | Verified | Contract audit, выбранная feature, accepted SC-003 и декомпозиция |
| SC4-02 | Verified | Request/apply application и persistence; User→token→sessions, login fresh credential recheck; backend 344 tests / 3525 assertions, migrations/schema и quality PASS |
| SC4-03 | Verified | Два public endpoints, field password policy, shared token quotas, matching email template/purpose; HTTP + delivery 33 tests / 280 assertions, backend quality PASS |
| SC4-04 | Verified | Request/reset UI, shared validation, private capability, coordinated cookie/auth cleanup; frontend quality, 45 unit + 35 component tests PASS |
| SC4-05 | Planned | Real process/DB races reset/reissue/login/refresh; privacy и auth regressions |
| SC4-06 | Planned | Browser recovery journey, replaced link, old credentials/sessions rejected; docs/full gate |

Каждый проверенный этап получает отдельный commit и обновление этого файла.
Окончательная матрица и фактические числа tests фиксируются в acceptance/.
Известный audit blocker предыдущей фичи не скрывается и не означает функциональную
невозможность SC-004; выпуск остаётся blocked до его устранения/отдельного решения.
