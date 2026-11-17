# Один общий сайт, автодеплой из main

Репозиторий: https://github.com/caymanlogistic-crypto/1c-ip-php. Сервер: 89.208.213.14, существующий корень /srv/1c-ip-php.

Общий сайт использует https://ui.89.208.213.14.sslip.io, каталог ui, Compose ipphp-ui, БД ipphp_ui, прежние данные, TLS, обычную авторизацию и SSH-канал ipphp-ci-ui. Имена сохраняются, новый экземпляр не создаётся. Прежний integrations останавливается с сохранением данных; серверных экземпляров для агентов нет.

## Публикация

Свой worktree от main → локальная проверка → кандидат пользователю → «выкладывай» → свежий main в свою ветку → адресная проверка → PR и merge через GitHub → автодеплой main → проверка опубликованной версии и сценария → завершение.

Новая .github/workflows/staging.yml сохраняет имя файла, но публикует только push main через существующий environment ui. Task/codex-ветки и PR собираются в build без деплоя. Повторно используются UI_DEPLOY_SSH_KEY и DEPLOY_HOST/DEPLOY_KNOWN_HOSTS. Интеграционный ключ workflow не использует.

Receiver допускает только ui release с GitHub OIDC: repository/owner, refs/heads/main, точный commit/run_id, event=push и .github/workflows/staging.yml@refs/heads/main. Staging/task/PR и второй target отвергаются. Сохранены SHA256 архива, OCI commit, точный image_id, server flock, highest-run и возврат предыдущего образа. Main concurrency group не отменяет выполняющийся деплой; перед отправкой проверяется актуальный main. Следующая задача не сливается до проверки предыдущей публикации; локальная работа остальных продолжается.

## Выполненный одноразовый переход

Выполнено 17.11.2026 по «выкладывай»: GitHub PR #1, main 3a77e2dae48069279a3d1b4df885501fc060b567, успешный workflow 44126518499. /_ops.php и docker inspect подтвердили image_id sha256:d04e94389c87cea2d902e36b8a3afbafb6da7d02fce86242fe36c873983b5e8f; manager/admin вход, форма и реестр проверены. Резерв настроек и проверка сохранности второго экземпляра — /srv/1c-ip-php/transition-main-20261117. Ниже сохранён выполненный порядок, повторять его для обычной задачи не нужно:

1. Проверить отсутствие выполняющихся публикаций и чужого владельца. Сохранить текущие commit/image_id, root-owned receiver/ci-entry, Apache proxy config и GitHub environment policies. БД/документы не менять.
2. В существующем GitHub environment ui заменить deployment branch policy staging-ui на только main, type=branch. Секреты/vars сохранить, значения не выводить. Build оставить для сборок. Убрать прежний допуск staging-integrations в его environment. Проверить policies через GitHub API.
3. Установить проверенные deploy/server/deploy.py в /usr/local/sbin/ipphp-deploy и deploy/server/ci-entry.sh в /usr/local/bin/ipphp-ci-entry: root-owned, executable. Сохранить ограниченные forced-command/sudo для ipphp-ci-ui; отключить SSH-допуск прежнего ipphp-ci-integrations. Это не меняет работающий app image; staging refs после этого отвергаются.
4. Штатно остановить только app и db проекта ipphp-integrations, сохранив контейнеры, volumes, БД, каталоги, документы, образы и очереди. Старый integrations URL направить редиректом на общий UI URL в существующем Apache proxy. Проверить apache2ctl configtest и reload; адрес не должен проксировать в выключенный app или попадать на чужой default vhost.
5. Получить свежий main, объединить/проверить эту ветку, создать PR и слить через GitHub. Дождаться workflow и сверить artifact/summary с /_ops.php, проверить вход и адресный сценарий. Проверить, что integrations URL перенаправляет, а второго app-процесса нет.
6. Записать фактический результат в START-HERE.md/WORK.md. После перехода общий сайт обновляется только из main. Удалять исторические staging-ветки и данные не требуется.

Сейчас receiver/entry установлены, environment ui содержит только main, старый environment не допускает staging-integrations; прежний SSH-ключ отключён. Активны только ipphp-ui-app-1 и ipphp-ui-db-1; integrations-контейнеры остановлены и сохранены, их data-каталоги на месте. /login и /health прежнего URL отвечают 301 на соответствующий путь общего сайта. При сбое будущей публикации сохрани работающий образ и сообщи блокер; возврат настроек — явный возврат процесса, не обход main push в staging.

## Диагностика и возврат

https://ui.89.208.213.14.sslip.io/_ops.php требует сессию и сообщает commit, SHA256 image config digest, deployed_at, БД и gates. Сверять с artifact workflow, /srv/1c-ip-php/ui/state/current.json и контейнером. HTTP 200 и /health не заменяют версию и сценарий.

Авторизованный администратор возвращает предыдущий совместимый образ:

```sh
sudo /usr/local/sbin/ipphp-deploy ui rollback
```

Это откат приложения, не БД/документов/внешних эффектов/main. Исправление идёт через ту же задачу и GitHub. Не удалять текущий/предыдущий образ. Импорт SQL, миграции и восстановление старой БД поверх новых данных не входят в деплой. Исходники в работающем контейнере не правятся.

## Сохраняемые ограничения

Остаются PHP 8.5.6 Apache, MySQL 8.4.9, lock-зависимости, internal network общего сайта, данные и TLS. Не включать внешнюю сеть, admission, профили отправок и workers/outbox при смене процесса. Pending/attempts/outbox сохраняются и не проигрываются. Чтение провайдера и внешняя запись требуют своих поручений; guards сохраняются.

Первичный snapshot/receipt и резервные документы находятся в /srv/1c-ip-php/initial-backup вне Git; повторный начальный импорт не нужен. Прежние хеши/runs доступны в Git-истории. Витрина публикуется с приложением из того же main без отдельного сервиса.
