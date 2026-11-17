# Один общий сайт, автодеплой из main

Репозиторий: https://github.com/caymanlogistic-crypto/1c-ip-php. Сервер: 89.208.213.14, существующий корень /srv/1c-ip-php.

Общий сайт использует https://ui.89.208.213.14.sslip.io, каталог ui, Compose ipphp-ui, БД ipphp_ui, прежние данные, TLS, обычную авторизацию и SSH-канал ipphp-ci-ui. Имена сохраняются, новый экземпляр не создаётся. Прежний integrations останавливается с сохранением данных; серверных экземпляров для агентов нет.

## Публикация

Свой worktree от main → локальная проверка → кандидат пользователю → «выкладывай» → свежий main в свою ветку → адресная проверка → PR и merge через GitHub → автодеплой main → проверка опубликованной версии и сценария → завершение.

Новая .github/workflows/staging.yml сохраняет имя файла, но публикует только push main через существующий environment ui. Task/codex-ветки и PR собираются в build без деплоя. Повторно используются UI_DEPLOY_SSH_KEY и DEPLOY_HOST/DEPLOY_KNOWN_HOSTS. Интеграционный ключ workflow не использует.

Receiver допускает только ui release с GitHub OIDC: repository/owner, refs/heads/main, точный commit/run_id, event=push и .github/workflows/staging.yml@refs/heads/main. Staging/task/PR и второй target отвергаются. Сохранены проверка digest (SHA256 архива для старого протокола), OCI commit, точный image_id, server flock, highest-run и возврат предыдущего образа. Main concurrency group не отменяет выполняющийся деплой; перед отправкой проверяется актуальный main. Следующая задача не сливается до проверки предыдущей публикации; локальная работа остальных продолжается.

## Доставка слоёв через приватный GHCR

Workflow публикует образ только для push main в `ghcr.io/caymanlogistic-crypto/1c-ip-php`. Task/PR проверяют сборку, но не публикуют образ, build cache или сайт. Main сохраняет registry build cache; метка SOURCE_COMMIT находится после runtime/vendor, поэтому они повторно используются при обычной правке кода.

Вместо полного tar.gz по SSH идёт только короткий JSON-заголовок: OIDC, commit/run, точный config image_id и `registry_ref` с manifest digest. Receiver разрешает только фиксированный GHCR repository и digest, получает недостающие Docker-слои и сверяет config image_id/OCI revision до запуска. Пакет остаётся приватным (настройку видимости не менять). GITHUB_TOKEN ограничен сроком job; на сервере docker login использует отдельный временный DOCKER_CONFIG с удалением при успехе/ошибке. В state/artifact попадают только публичные реквизиты и метрики delivery, без токенов.

Однократное внедрение: дождаться отсутствия активной публикации, сохранить установленный receiver и атомарно установить проверенный `deploy/server/deploy.py` root:root 0755 под существующим deploy.lock. Затем merge через GitHub и проверка main workflow, `/_ops.php`, входа и delivery в state/current.json. Старый archive-протокол совместим с уже начатыми workflow; новые сборки никогда не переключаются на него при ошибке registry. Откат приложения по-прежнему использует локальный предыдущий image_id без registry и не затрагивает БД/документы.

Первый запуск заполняет registry/cache; на последующих сервер получает только новые слои. `delivery.pull_seconds`, `layers_downloaded` и `layers_already_present` позволяют проверить это фактически. Ошибка загрузки/проверки digest не переключает работающий app. Пересоздаётся только app (`--no-deps`), DB и data mounts сохраняются; SQL/миграции не выполняются.

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

### Ручная SMTP-почта

Публикуется тем же main workflow. Серверный opt-in — приватный `ui/data/local/email-admission.json`:

```json
{"enabled":true,"project_id":"f6e21e3c-d974-4604-82fb-333da66559d2","provider":"email","scope":"manual-smtp","stand":"ui","smtp_host":"smtp.spaceweb.ru","smtp_port":465}
```

EmailAdmission проверяет scope/проект/сайт/host/port/TLS; `approved_recipients` и пароль остаются в encrypted email.enc. Маркер не включает RentalOutbox, MAX или общий external gate. Настройки SMTP в БД и секреты сохраняются вне образа; миграций и импорта при деплое нет. `/_ops.php` сообщает `manual_email` отдельно от `external_access` и `automated_sends`.

Инфраструктурные файлы из принятого main устанавливаются root-owned: `deploy/server/email-network.py` → `/usr/local/sbin/ipphp-email-network` (0755), service/timer из той же папки → `/etc/systemd/system/` (0644). После `systemctl daemon-reload` включают `ipphp-email-network.timer`. Timer восстанавливает SMTP-маршруты после автодеплоя и перезапуска контейнера/сервера; общий receiver не меняется. Действующий внутренний backend сохраняется; helper проверяет контейнер, интерфейс и отсутствие чужого маршрута, добавляет только закреплённый `/32` SMTP-адрес на TCP/465, без default route. Compose закрепляет smtp.spaceweb.ru на тот же 77.222.41.51 через extra_hosts: в internal-сети нет внешнего DNS. TLS продолжает проверять имя smtp.spaceweb.ru. При первой настройке новый compose из принятого main устанавливается до активации образа workflow; существующий app вручную не пересоздаётся. Изменение DNS-пула требует сверки и обновления pins отдельной правкой; не открывать общий egress ради SMTP.

https://ui.89.208.213.14.sslip.io/_ops.php требует сессию и сообщает commit, SHA256 image config digest, deployed_at, БД и gates. Сверять с artifact workflow, /srv/1c-ip-php/ui/state/current.json и контейнером. HTTP 200 и /health не заменяют версию и сценарий.

Авторизованный администратор возвращает предыдущий совместимый образ:

```sh
sudo /usr/local/sbin/ipphp-deploy ui rollback
```

Это откат приложения, не БД/документов/внешних эффектов/main. Исправление идёт через ту же задачу и GitHub. Не удалять текущий/предыдущий образ. Импорт SQL, миграции и восстановление старой БД поверх новых данных не входят в деплой. Исходники в работающем контейнере не правятся.

## Сохраняемые ограничения

Остаются PHP 8.5.6 Apache, MySQL 8.4.9, lock-зависимости, internal network общего сайта, данные и TLS. Не включать внешнюю сеть, admission, профили отправок и workers/outbox при смене процесса. Pending/attempts/outbox сохраняются и не проигрываются. Чтение провайдера и внешняя запись требуют своих поручений; guards сохраняются.

### Явно разрешённая проверка 1С

Для обычной кнопки «Проверить» предусмотрен отдельный `OnecReadAdmission`, а общий `IPPHP_ALLOW_EXTERNAL` остаётся выключенным. Операторский файл `/srv/1c-ip-php/ui/data/local/onec-read-admission.json` содержит только `enabled: true`, `project_id: f6e21e3c-d974-4604-82fb-333da66559d2`, `stand: ui`, `provider: onec`, `scope: bounded-check-only`, `profile_reference: working-mounted-main-20261116-v1`. Он разрешает только команду check и её authenticate/records; sync/import через native broker, банк, остальные провайдеры и запись в 1С допуск не получают. Смена выбранного профиля закрывает допуск. Права, CSRF, версия, блокировка провайдера и receipt сохраняются.

Из принятого образа устанавливаются root-owned `/usr/local/sbin/ipphp-onec-network` (исходник `deploy/server/onec-network.py`) и `ipphp-onec-network.service`. Скрипт добавляет только `/32`-маршрут к `89.208.213.32` через существующий внутренний bridge, узкие FORWARD/обратный ESTABLISHED и MASQUERADE только TCP/443 от app `172.30.101.10`; default route, DNS, другие адреса и БД не открываются. Он отвергает чужой маршрут/другой контейнер/профиль. Повторный запуск не дублирует правила. Сервис восстанавливает маршрут после перезапуска Docker/хоста; установленный receiver из того же main вызывает его после пересоздания app при release/rollback. Образ не содержит секретов и не меняет бизнес-данные.

Первое включение: опубликовать main; из проверенного образа установить сетевой скрипт и unit, обновить установленный receiver из `deploy/server/deploy.py` со сверкой его прежней версии и резервом; создать приватный marker; запустить/включить unit. Проверить обычную web-кнопку, receipt, `/_ops.php`, запреты sync/других провайдеров и отсутствие default route. Чтобы немедленно закрыть прикладной допуск, убрать marker; ранее загруженные записи и история сохраняются. Удаление собственных сетевых правил/маршрута выполняется оператором отдельно; без marker приложение уже не делает внешних запросов.

Первичный snapshot/receipt и резервные документы находятся в /srv/1c-ip-php/initial-backup вне Git; повторный начальный импорт не нужен. Прежние хеши/runs доступны в Git-истории. Витрина публикуется с приложением из того же main без отдельного сервиса.
