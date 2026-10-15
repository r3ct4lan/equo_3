# Техническая проверка SC-016
Дата: 2026-10-11. Ветка: codex/sc-016-manual-transfer от main 8c36eec.
Application snapshot b2a6307 fast-forward merged в main и pushed 2026-10-11.
Итог: functional PASS; release BLOCKED. Product Owner acceptance не подтверждена.

## Реализованный результат
Активная сторона Connect записывает MANUAL в любом направлении между его
двумя активными сторонами. Положительные целые евроценты 1..2147483647;
переплата разрешена. Автор — current user; debt/participant/related links null,
version=1. Баланс и история обеих сторон читаются из ledger, Debt и DEBT_SHARE
не изменяются. Связи с долями и edit/delete controls не экспонируются.

## Матрица доказательств
| Требование | Доказательство | Результат |
|---|---|---|
| Direction/author/amount | ManualTransferTest, manual-transfer.test.mjs; оба направления, обе роли автора, overflow и self-transfer | PASS |
| Atomic persistence | ManualTransferCreationTest; rollback на transfer INSERT, idempotency INSERT и restart UPDATE | PASS |
| No duplicate / conflict / TTL | Integration и HTTP; original 201 body, normalized UUID, изменённый body/target, logical 24h expiry | PASS |
| Real races | ManualTransferConcurrencyTest: same key, changed body, different key, first rollback, deactivation; pg_blocking_pids barrier | PASS |
| ACL / activity / wire types | ManualTransferHttpTest: hidden 404, Bearer, inactive 409, неверная пара, numeric string/float/bool/overflow, unknown fields | PASS |
| UI / late responses | 9 component tests: preview, double submit, frozen network/5xx retry, новый key после definitive correction, session/Connect/unmount guards, server refresh, inactive form | PASS |
| Полный основной путь | Browser: две регистрации/activation/login → invitation/Connect → expense → MANUAL → обе карточки/истории | PASS |
| Lost committed response | Реальный POST 201 прерван после commit; explicit retry сохраняет body/key/ID/Location/ETag; один MANUAL | PASS |
| Переплата / immutable Debt | Расход €10.01: доля €5.01; перевод €7.00 даёт -€1.99/+€1.99; Debt representation сравнивается по stable participant IDs | PASS |
| Safe diagnostics | PHP-FPM access log не содержит URI; E2E проверяет отсутствие capability paths в service logs | PASS |

## Проверки
- Полный backend suite: **588 tests / 7537 assertions**, PHP 8.4.26.
- Frontend: **59 unit**, **76 component**, lock/lint/typecheck и production build PASS.
- Browser: **25/25 PASS**, без source drift; после успешного прогона остаётся
  только безопасный .last-run.json, trace/video отключены.
- Repository checks, backend style/PHPStan/container/YAML/routes/mapping,
  пять миграций на пустой isolated test DB/schema parity, Composer audit PASS.
- Dev smoke и Nginx syntax PASS; после пересоздания backend адрес upstream
  обновлён reload Nginx, dev volumes и данные сохранены.
- После смены PHP patch runtime свежий style cache обнаружил четыре старых
  замечания форматирования. Исправлены пробелы и optional trailing commas.
  Генераторы применённой Version20261011120000 до/после выдали идентичные
  **18 SQL statements и параметры**; SQL/schema не изменены, миграция повторно
  в dev не применялась. Дополнительная focused регрессия: 76 tests / 1350 assertions; migration/schema parity и fresh backend quality PASS.
- make check-full выполнен дважды; функциональные проверки проходят,
  команда останавливается на **npm audit: 11 high**, braces
  GHSA-vfj7-8cjw-p6xm и node-forge GHSA-86w9-cpqp-85rv.
  Browser и smoke выполнены отдельно, поскольку audit прерывает полный gate.

## Ограничения и дальнейшая работа
DR-E4-026/028 остаются открытыми: release gate не PASS, этап 4 формально
не закрыт. Merge функционально проверенной фичи не означает audit exemption,
продуктовую приёмку или готовность MVP. Политика audit не менялась.
SC-013 (этап 5), эксплуатационный этап 7 и Product Owner acceptance остаются
отдельными работами. Нормативная модель/ER/BR не менялись.

## Повторная проверка quality gate — 2026-10-15

На `main` snapshot `c390841` GitHub Actions run `38766369660` завершил полный
workflow успешно: backend/frontend quality и build, миграции и тесты,
dependency audit по принятой политике, full MVP browser E2E и application
smoke. Browser job проходит MANUAL transfer journey и проверяет изменение
баланса и истории. CI запускает проектные проверки из `docs/quality-gate.md`;
локальный `make check` отдельно не запускался. Это закрывает технические
DR-E4-026/028; Product Owner acceptance и готовность MVP остаются отдельным
решением.
