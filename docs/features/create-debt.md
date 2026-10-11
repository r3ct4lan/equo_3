# SC-011 — общий расход, доли, баланс и история

Дата: 2026-10-11. Ветка: codex/sc-011-create-debt, base 4f62907.
Статус: реализация. Scope — финансовый этап 3 roadmap (DR-E3-001—070):
SC-011 с необходимыми read models SC-009/012/015. SC-002 и SC-008
функционально проверены и merged; dependency audit остаётся release blocker.

Нормативные источники: BR-DEBT-001—014, BR-TRF-001—003/008—009,
BR-ACL-001—007, BR-IDEM-001—005, BR-CONC-001—003; HTTP 4—7/11/13/14;
Debt/DebtParticipant/Transfer и ER constraints; ADR-002/003/005/009—012/015.

## Проверяемые этапы

| ID | Объём | Проверка | Статус |
|---|---|---|---|
| SC11-01 | Contract audit, план и границы | Документы и Git | PASS |
| SC11-02 | Чистые Money/Debt/Transfer, новая миграция, records | Unit, DB constraints, mapping parity | Pending |
| SC11-03 | Атомарное создание, допустимые пользователи, idempotency | Integration, rollback, real-process races | Pending |
| SC11-04 | ACL, ledger balance, history, шесть endpoints | HTTP, property, privacy, pagination, ETag | Pending |
| SC11-05 | Create/list/card/history, server-derived выбор пользователей | Frontend unit/component, lint/typecheck | Pending |
| SC11-06 | Полный gate, финансовый browser путь и третья сторона | make check-full, отдельный E2E при audit blocker | Pending |

Стабильные ID DR-E3-001—070 сохраняются. Checkbox отмечаются только после
проверки и включения в main. SC-013/014, MANUAL writer, общий поиск, pay/edit/delete
controls, пользовательская дата и другие валюты не экспонируются этим этапом.

## Технический контракт

- Debt — корень агрегата; payer неизменяем, author — subject Bearer.
  participantIds исключает payer и дубли; минимум один иной участник.
  Если author не payer, author обязательно в participantIds.
- includePayerInSplit преобразуется в запись DebtParticipant, отдельного
  поля Debt нет. Доля рассчитывается intdiv + остаток без float/overflow.
  Payer входит в count только при включении; self-transfer не создаётся.
- Суммы в евроцентах используют PostgreSQL INTEGER (ER integer), 1—2147483647.
  Выход за storage range отклоняется до SQL; сумма баланса агрегируется в BIGINT.
  Бизнес-правило округления сохраняется; отдельного mutable balance нет.
- Domain не зависит от framework. Debts и Transfers — отдельные модули.
  Межмодульные calls идут через Application/Api; UUID links без ORM associations.
- Create: central idempotency reservation → User locks в sorted UUID порядке →
  fresh activity/eligibility → Debt/participants → Connect get-or-create →
  DEBT_SHARE → полный первоначальный результат → commit одной транзакции.
  Replay использует scope user:<subject> и operation debt.create; нормализация
  включает отсортированный participantIds. Сохранённый ответ возвращается
  со статусом/Location/ETag первоначальной команды. Неверная команда не резервирует ключ.
- Используется существующий central DoctrineIdempotency через новый port Debts;
  Infrastructure реализует adapter, дублирующей idempotency таблицы/engine нет.
- Eligible: self, Connect автора или текущий active соучастник доступного автору
  активного долга. Backend повторно проверяет eligibility и active state.
  UI собирает только server-returned brief users из полного paginated Connect
  list и доступных active Debt cards; новый search/options endpoint не вводится.
- Debt ACL: author/payer/current participants; excluded participant теряет Debt,
  сохраняя Transfer history. Deleted Debt доступен frozen составу, из списка
  исключается. Чужой Debt — 404 DEBT_NOT_AVAILABLE; Transfer ACL независим.
- Balance(current,other) = sum(current→other) - sum(other→current) только active.
  History включает deleted; недоступная debtReference только id/isAccessible=false.
  Brief users без email. Cursor createdAt DESC/id DESC; limit 20/100; ETag version.
- UI reuse $api/$auth; pending guard; create retry с неизменным key/body после
  потери ответа, новый key только для новой команды. No edit/delete/pay controls.
- Новая migration не меняет применённые. Тесты только в tmpfs test/E2E окружениях.
  Полный gate запускается; dependency audit не обходится и release PASS не заявляется.
