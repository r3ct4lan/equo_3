# Безопасная смена известного пароля

| Поле | Значение |
|---|---|
| Сценарий | MVP-SC-005 |
| Статус | Contract audited; implementation in progress |
| Дата | 2026-10-10 |
| Ветка | codex/sc-005-password-change |
| Base | 4cae6c8; SC-004 и SC-003 ещё не merged |
| Roadmap | DR-E6-031—037; применимые DR-E6-038—047 |

## Contract audit и дизайн

Источники: [MVP-SC-005](../mvp-scope.md#mvp-sc-005), BR-USR-007/009,
HTTP 9.0/9.3/16—18, transaction boundaries модели, ADR-006/013/014/015,
принятые OQ-005/009/010/014/018. Код reset/session lifecycle подтверждён на base 4cae6c8.
SC-005 отсутствует; схема уже поддерживает смену hash и bulk revoke, migration
не нужна. Нормативных противоречий нет.

- POST `/api/v1/account/password-change`, JSON `{currentPassword,newPassword}`,
  Bearer active User; authority берётся только из authenticated subject.
- Общая policy нового пароля: 12—128 Unicode code points, non-space, без trim
  и normalization. Текущий пароль проверяется буквально; новая policy к нему
  не применяется. Равный текущему новый пароль допускается: запрета в BR нет.
- User FOR UPDATE с fresh hydration → active recheck → current credential
  verify → replace hash → shared revoke всех unfinished sessions → commit.
  Порядок User → sessions согласован с login/refresh/reset/logout-all.
- Success: exact empty 204, обе cookies очищены общей factory; JWT до exp.
  Failure не меняет cookies/hash/sessions. Missing/invalid/expired Bearer,
  unknown/inactive User: 401 AUTHENTICATION_REQUIRED с Bearer challenge.
  Неверный текущий пароль: существующий 401 INVALID_CREDENTIALS; ошибки полей:
  422 VALIDATION_ERROR; JSON/media/unknown fields по общей HTTP модели.
- У endpoint нет cookie authority или отдельной квоты в HTTP 17/ADR-014;
  новые security requirements и idempotency storage не добавляются.
- UI `/account/security` доступен из профиля; current-password/new-password,
  shared policy, pending/duplicate guard, accessible safe errors. Пароли живут
  только в памяти формы, очищаются после server result и unmount.
- Изменение и clearing cookies сериализуются с refresh общим Web Lock.
  Неверный current password не запускает refresh/retry и не завершает сессию.
  Для отказа Bearer максимум один refresh/retry без nested lock. Только
  подтверждённый 204 очищает auth, публикует token-free session-ended и
  направляет на explicit signed-out login с сообщением о новом пароле.

## Проверяемые этапы

| ID | Статус | Результат / проверки |
|---|---|---|
| SC5-01 | Verified | Contract audit, фактическая base, декомпозиция и lock/error design |
| SC5-02 | Verified | Application/persistence; backend quality, migrations/schema, 387 tests / 3979 assertions PASS; 9 новых unit/integration, rollback после flush и fresh hydration |
| SC5-03 | Verified | Protected HTTP/Bearer, JSON validation, cookies, errors/log privacy; backend quality PASS; PasswordChange suite 25 tests / 249 assertions, без notices |
| SC5-04 | Verified | Account security UI, coordinated lifecycle; quality/build, 53 unit + 41 component tests PASS |
| SC5-05 | Verified | 11 real process/DB serial orders, pg_blocking_pids barrier; 165 assertions, backend quality PASS |
| SC5-06 | Pending | Browser two sessions, полный gate, документация и acceptance |

Каждый проверенный этап получает отдельный commit. Checkbox roadmap остаются
открытыми до merge. Известный npm audit blocker не скрывается; release,
продуктовая приёмка и MVP completion отделяются от functional PASS.

SC5-03: endpoint включён и в firewall access_control, и в supports общего Bearer
authenticator. HTTP suite проверяет ошибки без изменения cookies, чужой subject
field отвергается serializer, API logger не получает credentials или hashes.

SC5-05: contender запускается отдельным PHP-процессом и действительно ожидает
DB lock (наблюдаемый pg_blocking_pids), затем проверяется serial result. Покрыты
оба порядка change с login, refresh, reset, logout-all и current logout; два
change со старым currentPassword дают один успех и INVALID_CREDENTIALS второго.
