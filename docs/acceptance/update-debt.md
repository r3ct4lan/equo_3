# Техническая проверка SC-013

Дата: 2026-10-12. Ветка: codex/sc-013-update-debt от main 4282715.
Итог: functional PASS; интеграция pending; release BLOCKED.
Product Owner acceptance и готовность MVP не подтверждены.

## Реализованный результат

Author исправляет title/description/amount/полный состав и участие payer
в разделе долей. Payer/author неизменяемы; non-payer author остаётся текущим.
PUT требует настоящий If-Match, полностью пересчитывает доли одной транзакцией.
Исключение/возвращение сохраняет participant/share IDs и createdAt. Текущий
inactive ordinary participant допускается; новый/возвращаемый — только active
и доступный автору. Author/payer inactive или deleted Debt запрещают update.
MANUAL не изменяется. Актуальный normalized no-op сохраняет версии без записи.

## Матрица доказательств

| Требование | Доказательство | Результат |
|---|---|---|
| Composition/freeze/author/no-op | DebtRevisionTest: полный состав, неизменный payer, author != payer, inactive current, normalization, stale no-op | PASS |
| Stable shares / rounding | ShareRevisionTest и integration: ceil division, same IDs/createdAt, restore, version только при фактическом изменении; запрет MANUAL revision | PASS |
| Atomic persistence | DebtUpdateIntegrationTest: root UPDATE, participant UPDATE/INSERT, Connect INSERT, Transfer UPDATE/INSERT; snapshot financial rows/idempotency после failure | PASS |
| Real races | DebtUpdateConcurrencyTest: same version, first rollback, payer deactivation, retained ordinary deactivation; отдельные PHP processes, pg_blocking_pids barrier | PASS |
| HTTP / ETag / ACL | DebtUpdateHttpTest: 428/400/409, quoted positive integer, unknown payer/version body, strict JSON types, 200 ETag/no-store, 404/403/frozen/deleted | PASS |
| Excluded participant privacy | Integration/HTTP/browser: Debt скрыт, Connect/Transfer history остаётся; минимальный inaccessible Debt reference, противоположные balances | PASS |
| UI / actual header | 11 новых component cases: header вместо body.version, missing ETag fail closed, immutable payer, обязательный author, preview, pending guard, explicit reload | PASS |
| Late responses / no silent retry | Component: session/path/version/unmount guards, conflict и uncertain outcome блокируют resubmit до нового GET/review | PASS |
| Browser end-to-end | Три accounts: author != payer, existing MANUAL, amount/composition edits, exclude/restore и stale editor с explicit reload | PASS |
| Identity / manual preservation | Browser сравнивает исходные participant/share IDs/createdAt, восстановленную version и неизменный полный MANUAL representation | PASS |

## Проверки

- Полный backend: **652 tests / 8020 assertions**, PHP 8.4.26.
- Frontend: **62 unit**, **87 component**; lock/lint/typecheck, production build PASS.
- Browser: **26/26 PASS**, без source drift; isolated E2E project, trace/video
  отключены. E2E выполнен отдельно, так как audit останавливает check-full.
- Repository checks, backend style/PHPStan/container/YAML/routes/mapping,
  пять миграций на пустой isolated test DB/schema parity, Composer audit PASS.
- Dev smoke и Nginx syntax PASS. Новых/переписанных миграций нет; dev data
  и named volumes сохранены. Разрушающие фикстуры только в test/E2E средах.
- Два полных прогона прерваны остановкой Docker после успешных отдельных
  проверок; окружение восстановлено и make check-full повторён до результата.
  Повторный прогон останавливается на **npm audit: 11 high**, прежние braces
  GHSA-vfj7-8cjw-p6xm и node-forge GHSA-86w9-cpqp-85rv.
  Прерванные прогоны не считаются полным PASS. Audit threshold и исключения
  не менялись; зависимости этой фичей не обновлялись.

## Ограничения и дальнейшая работа

DR-E5-035/037 остаются открытыми: release gate не PASS, этап 5 формально
не закрыт. 35/37 задач функционально проверены; merge не означает audit
exemption, Product Owner acceptance или готовность MVP. Нормативные
BR/ER/Must-Should-Later границы не менялись. Следующая обязательная область —
этап 7: SC-019—022, эксплуатация, staging/SMTP и финальная приёмка.
