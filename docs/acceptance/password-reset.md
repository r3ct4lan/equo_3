# Техническая проверка восстановления пароля

| Поле | Значение |
|---|---|
| Сценарий | MVP-SC-004 |
| Дата | 2026-10-10 |
| Ветка | codex/sc-004-password-reset |
| Base | 6d2617e, SC-003; ещё не merged в main |
| Функциональный результат | PASS |
| Release gate | BLOCKED: dependency audit; исключений нет |
| Решение Product Owner | SC-004 пока не принята; принятие SC-003 не распространяется на release exemption |

Нормативные требования и этапы: [SC4-01—06](../features/password-reset.md),
BR-USR-007/009, BR-SEC-003—007, HTTP 9.0—9.2/17—18,
ADR-006/007/013/014/016/018. Критерии проверены реализацией всех слоёв,
а не готовностью отдельного API или страницы.

## Матрица доказательств

| Требование | Доказательство |
|---|---|
| Unknown/active/inactive получают одинаковый 202; unknown без DB writes | PasswordResetTest, PasswordResetHttpTest; browser request journeys |
| Нормализация email, 3/hour email и 20/hour trusted IP; 429 Retry-After | HTTP tests exact quotas, normalized identity, untrusted forwarding headers |
| RESET_PASSWORD 30 минут, HMAC hash-only/null payload; replacement всех unfinished same purpose, включая expired | PasswordResetPersistenceTest; другие ACTIVATE_ACCOUNT/CHANGE_EMAIL сохранены |
| Token + encrypted outbox одной транзакцией; queue только deliveryId | Persistence rollback и EmailDeliveryFlowTest; общие relay/message regressions |
| Общая policy 12—128 Unicode code points, non-space, без trim | Application/HTTP/unit/component tests; exact password persistence, 422 field violation |
| Capability apply public, strict purpose/lifecycle, 400/410/422/429 и request ID | PasswordResetHttpTest и Application tests; failure не очищает cookies |
| User → token → sessions; fresh lifecycle и password+usedAt+revoke атомарны | Persistence rollback и реальные process/DB concurrency tests |
| Login до reset создаёт отзываемую session; старый пароль после reset отвергается | Оба порядка в PasswordResetConcurrencyTest; fresh credential recheck |
| Refresh до reset отзывается, refresh после reset отвергается | Оба порядка в PasswordResetConcurrencyTest; browser отдельная session |
| Два apply, apply/reissue в обоих порядках, два reissue | Реальные DB locks с наблюдаемым pg_blocking_pids; один используемый/reset-current token |
| Activation не удерживает token, ожидая User; wrong-purpose безопасен | Regression сначала воспроизвёл инверсию, после исправления третий connection получает token FOR UPDATE NOWAIT; serial activation/reset regression |
| Inactive остаётся inactive, auto-login нет; другая purpose не изменяется | Persistence active/inactive и browser reset → 403 login → исходная activation → новый login |
| Empty 204 очищает обе cookies точными paths/flags; JWT действует до exp | HTTP cookie assertions; browser old refresh 401 и ранее выданный JWT 200 |
| Email purpose/template совпадают, token deliverability проверяется, ciphertext очищается terminal | EmailDeliveryFlowTest; реальное письмо через relay/RabbitMQ/worker/Mailpit в E2E |
| Token query удалён, private memory only, SSR HTML/storage без секретов | Unit/component и browser; server plugin очищает Nuxt payload.path для reset и activation |
| Password очищен после результата/unmount; terminal capability удалена; controlled retry | Component lifecycle tests; отсутствует persistence секретов |
| Local auth завершается только после exact 204; другая вкладка получает token-free event | Auth session unit tests и browser две вкладки; signed-out login с подтверждением сохранения пароля |
| Reuse/replaced/malformed/missing link безопасны | HTTP lifecycle matrix и три browser recovery journeys |
| Public errors/logs не раскрывают token/password/hash/SQL | PasswordResetHttpTest privacy regression, существующий safe error pipeline и browser diagnostics |

## Проверки

Итоговые команды: `make check-full`, отдельно `make test-e2e` и `make smoke`,
поскольку audit останавливает общий gate до browser/smoke. Перед финальным gate
точечно проверены reset persistence/concurrency и регрессии activation.

Финальный запуск 2026-10-10:

| Проверка | Результат |
|---|---|
| Repository checks | PASS |
| Backend quality: style, PHPStan, DI/YAML/routes/mapping | PASS |
| Пустая изолированная БД: все migrations, schema sync, PHPUnit | PASS: 378 tests / 3912 assertions |
| Composer audit --locked | PASS |
| Frontend lock, ESLint, typecheck | PASS |
| Frontend unit / component | PASS: 45 / 35 tests, 9 component files |
| Production Nuxt build | PASS |
| npm audit --audit-level=moderate | FAIL: 11 high, два корневых advisory |
| make check-full | BLOCKED на npm audit; browser/smoke в этой команде не запускались |
| Отдельный make test-e2e | PASS: 19/19, 2.3 минуты; source drift отсутствует |
| Отдельный make smoke | PASS: frontend и API через Nginx |
| Профильные reset/activation regressions перед полным gate | PASS: 44 tests / 528 assertions |

Последние registry versions braces 3.0.3 и node-forge 1.4.0 проверены 2026-10-10.
Предлагаемый audit Nuxt 4.6.1 обновляет CLI, но @nuxt/nitro-server 4.6.1 по-прежнему
требует Nitro ^2.13.4 с обеими уязвимыми цепочками; эта рекомендация не доказывает
устранение корневых дефектов. Обновление без результата и audit fix --force не
применялись. Финальная ревизия использует Nuxt 4.5.2 и существующий lock.

E2E использует отдельный HTTPS Compose project с tmpfs DB/Redis/RabbitMQ,
Mailpit и Chromium. Worker пересобирается вместе с backend, чтобы не оставался
устаревший consumer image. Разрушающие проверки выполнены только в test/E2E;
dev rows, применённые миграции и volumes не изменены. Новая migration не нужна.

## Оставшиеся ограничения

- Полный release gate блокируют два upstream advisory: braces
  GHSA-vfj7-8cjw-p6xm и node-forge GHSA-86w9-cpqp-85rv, 11 high affected packages.
  Результат audit подтверждён 2026-10-10. Threshold не понижен, ignore не добавлен.
  Варианты: исправленные upstream releases либо отдельный проверенный patch/
  замена зависимостей; временное исключение требует явного решения владельца.
- Внешний staging SMTP, эксплуатационный выпуск и продуктовая приёмка SC-004
  не подтверждены локальными тестами. Сначала нужно проверить staging доставку
  и согласовать выпуск после устранения audit blocker.
- Ветка включает SC-003; merge должен учитывать эту зависимость. Roadmap
  checkbox остаются открытыми до включения в main, повторная реализация не нужна.
- Следующая незавершённая фича — SC-005, смена известного пароля. Этап 6 и MVP
  целиком не закрыты.
