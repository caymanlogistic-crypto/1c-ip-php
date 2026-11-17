# T001 — локальный кандидат для проверки

Рабочий каталог: `C:/Users/Vladimir/Documents/ChatGPT/1С-ИП 2`.
Источник `C:/Users/Vladimir/Documents/ChatGPT/1C-IP-PHP-CURRENT` и его БД сохранены без изменений. Старое приложение на 8765 не перезапускалось.

| Параметр | Фактическое значение |
|---|---|
| Вход | http://127.0.0.1:8766/login |
| Реестр | http://127.0.0.1:8766/rental/contracts |
| Основная форма | http://127.0.0.1:8766/rental/new-contract |
| Проверенная роль | M — менеджер, `core_d6dc6bc7_manager` |
| Пароль | Только `.local/core-test-accounts.json`, запись role=manager |
| Внутренний project_id | `f6e21e3c-d974-4604-82fb-333da66559d2`, сохранён |
| MySQL | Имеющийся 8.4.9 на `127.0.0.1:43061` |
| БД приёмки | `ip_ya_t001_20261117` |
| Пользователь приложения | `ipya_t001_20261117@127.0.0.1`, права только на новую схему |
| Конфигурация | `.local/database.php`, `.local/runtime-settings.json` |
| PHP | `C:/php/php.exe`, 8.5.6; GD включается локальным -d extension=gd |
| PowerShell | `C:/Users/Vladimir/.cache/codex-runtimes/codex-primary-runtime/dependencies/native/powershell/pwsh.exe`, 7.6.9 |
| Кандидат | Ветка `codex/t001-candidate`, tag `t001-candidate-20261117`; точный commit: `git rev-parse HEAD` |
| Принятая ветка | После принятия — `codex/accepted`; сейчас принятия нет |

Запуск из корня: `./Start.cmd`, либо `./Start-Local.ps1 -NoBrowser` в PowerShell 7 по указанному пути. Остановка: `./Stop.cmd` либо `./Stop-Local.ps1`. MySQL уже должен работать; эти команды не останавливают и не запускают его.

Запуск без -NoBrowser открывает установленный Chrome с отдельным профилем `.local/browser-profile`. Проверенная вкладка встроенного браузера этого чата также изолирована от обычных профилей Chrome/Edge и оставлена открытой. Не открывайте старый и новый экземпляры в одном профиле: разные порты не разделяют cookie IPPHPSESSID.

Launcher проверяет привязки app_root, project_id, DB, ограниченного пользователя, прав, порта и расширений через bin/local-preflight.php. Путь с пробелом передаётся PHP в кавычках. Собственные runtime/sessions, tmp, upload-tmp и PID receipt созданы заново; старые session/PID/receipts не переносились.

## Происхождение и копия данных

Фактический исходный HEAD: `bd59a7a2ebfbc5bf81a3ed9bb7ee17b3e41095d5`, ветка codex/current-runtime-20261117; исходный Git status чистый. История получена в независимую .git без alternates, ссылки на старый worktree и remote для публикации. SOURCE-ORIGIN.json сохранён: origin бизнес-кода `33947b5996ee225b4e78ed6d462b86546b39d48b`. Старое упоминание установленного e68ee946 не заменяет фактический HEAD.

Свежий согласованный снимок исходной действующей БД получен 17.11.2026 в 17:22:40 МСК штатным bin/backup.php через SELECT, REPEATABLE READ и CONSISTENT SNAPSHOT. Все 115 таблиц — InnoDB. SQL и receipt вне Git:
`E:/Codex-project-data/f6e21e3c-d974-4604-82fb-333da66559d2/t001-20261117/backup-20261117-172240-c63dc6ab15baf771.sql`.
SHA256 SQL: `addaa8fa98b54174c4440ae60e3a7a9f956553a9db0a0d8932ff40c5313af4cd`.

Новая схема и новый ограниченный пользователь созданы через уже доступные локальные административные реквизиты. Права существующих пользователей не менялись. Восстановление bin/restore.php выполнено в отдельную пустую схему. До входа 17:24:43 МСК сверены количества и хеши всех строк и DDL: **115 таблиц / 48 547 строк, полное совпадение**. Результат — `.local/copy-verification.json`. Вход затем штатно добавляет аудит.

Миграции не запускались. В schema_migrations 32 записи; 048/049 не применены, 050/051 остаются в pending. Вход и основная форма проверены на фактической восстановленной схеме; это не подтверждение всех остальных сценариев.

Перенесены код и composer.lock, установленный vendor без обновления версий, private, storage, uploads, приватная конфигурация, CA и 10 документных файлов / 378 333 байта. Копии vendor/private/документов/CA сверены SHA256 с источником; результат `.local/file-copy-verification.json`. Для word-renderer artifact_root изменён на собственный runtime/word-jobs. Исторический архив около 1 ГБ не восстанавливался и не подключался. Секреты, SQL и ключи исключены из Git.

Три документа из `C:/Users/Vladimir/Downloads/1C-IP-RESTART-KIT-20261117.zip`, папка project, установлены в корень. Аудит 02-AUDIT.md прочитан; его предложения не становятся дополнительными заданиями. Исходный AGENTS сохранён в Git-истории и приватной .local/AGENTS.source.md. CURRENT-SPEC.md сохранён без изменения бизнес-правил. Глобальная конфигурация Codex и другие проекты не менялись; проектного .codex и AGENTS.override не найдено. Старые чаты переноса idle/notLoaded, активных пишущих заданий для исходного CURRENT не обнаружено.

## Режимы и границы

В integration_connections из снимка сохранены enabled=1 у bank/diadoc/email/max/mosenergo/mosvodokanal/onec/pik. Это настройки, а не разрешение вызовов. Launcher очищает IPPHP_* и старые admission-переменные; Config::liveAllowed=false без IPPHP_ALLOW_EXTERNAL. AutoMAX: max_enabled=false, postcommit-переменная отсутствует. Старый billing admission SHA не активирован. Исторические guards сохранены. Workers, scheduler, outbox и внешние отправки не запускались; pending/outbox сохранены и не проигрывались. Константа live_sends из /health не использовалась как доказательство.

В браузере подтверждены /login, вход M, /rental/contracts, штатный диалог создания и прямой /rental/new-contract. Форма использует SourceCards::draft → ContractForm::render и существующие компоненты; CSS/JS/дизайн не менялись. Номер 169 — предложение формы, договор не сохранялся. Другие роли, создание/согласование договора, Word/PDF и интеграции не проверялись. Автоматический Word→PDF остаётся отложенным по CURRENT-SPEC. Актуальная принятая витрина не подтверждена; T002 не выполнялась.

После предъявления кандидата стенд не менять до ответа владельца. После «T001 принято» сохранить именно проверенный commit в codex/accepted, зафиксировать принятие в WORK.md и закрыть T001. Production и публикация не входят в задачу.
