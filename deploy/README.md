# Один общий сайт, автодеплой из main

Репозиторий: https://github.com/caymanlogistic-crypto/1c-ip-php. Сервер: 89.208.213.14, существующий корень /srv/1c-ip-php.

Общий сайт использует https://ui.89.208.213.14.sslip.io, каталог ui, Compose ipphp-ui, БД ipphp_ui, прежние данные, TLS, обычную авторизацию и SSH-канал ipphp-ci-ui. Имена сохраняются, новый экземпляр не создаётся. Прежний integrations останавливается с сохранением данных; серверных экземпляров для агентов нет.

## Публикация

Свой worktree → локальная проверка → кандидат пользователю → «выкладывай» → PR → `./Queue-PR.ps1 -PullRequest <номер>` → автоматические обновление/проверка/слияние/выкладка → проверка своего сценария.

Новая .github/workflows/staging.yml сохраняет имя файла, но публикует только main (push либо точный workflow_dispatch очереди) через существующий environment ui. Task/codex-ветки и PR собираются в build без деплоя. Повторно используются UI_DEPLOY_SSH_KEY и DEPLOY_HOST/DEPLOY_KNOWN_HOSTS. Интеграционный ключ workflow не использует.

Receiver допускает только ui release с GitHub OIDC: repository/owner, refs/heads/main, точный commit/run_id, event=push/workflow_dispatch и .github/workflows/staging.yml@refs/heads/main. Staging/task/PR и второй target отвергаются. Сохранены проверка digest (SHA256 архива для старого протокола), OCI commit, точный image_id, server flock, highest-run и возврат предыдущего образа. Main concurrency group не отменяет выполняющийся деплой; перед отправкой проверяется актуальный main. Следующая задача не сливается до проверки предыдущей публикации; локальная работа остальных продолжается.

## Автоматическая очередь

Постановка одобренного PR из своего чистого worktree:

```powershell
./Queue-PR.ps1 -PullRequest 15
```

Быстрый просмотр заявки: `gh pr view <номер> --repo caymanlogistic-crypto/1c-ip-php --json labels,url`. Подробный stage, проверенный head, deploy_run и image_id находятся в комментарии Actions этого PR. Статус всего intake workflow может быть cancelled, если GitHub заменил ожидающий worker: durable ticket и очередь при этом сохраняются; ориентируйся на ticket.

Эквивалент: `gh workflow run merge-queue.yml --repo caymanlogistic-crypto/1c-ip-php --ref main -f pr=15 -f head=<полный-одобренный-SHA>`. Существующие открытые PR автоматически не принимаются. Постановка проверяет право отправителя, открытый ready PR из этого репозитория в main и точное совпадение head. Изменённый после постановки код без нового запроса не сливается.

Intake сохраняет ticket в комментарии Actions и ставит queue:ready до ожидания worker. Один worker concurrency `ipphp-merge-deploy-queue` выполняет весь цикл для одного PR: подтверждает доставку текущего main, обновляет ветку через GitHub (только merge существующего main), запускает сборку точного нового head, перепроверяет head/base, сливает и явно dispatch-ит публикацию main. Это важно: merge через GITHUB_TOKEN сам по себе не запускает push workflow. Первый GET нового run может кратковременно вернуть 404: выполняются до 8 коротких повторов только чтения статуса, без повторного dispatch/merge. Публикация проверяет expected_sha; receiver принимает только OIDC точного main workflow. Для перехода к следующему PR нужны успешный run и artifact с deployment.json, совпадающими commit/image_id, ui, database_ready и registry transport. Одного зелёного run с пропущенным деплоем недостаточно.

Обычный pull_request workflow запускается при opened/reopened; новые commits task/codex проверяются по push. Обновление ветки очередью проверяется явным exact-SHA workflow_dispatch, поэтому synchronize от GITHUB_TOKEN не создаёт лишний workflow с ручным approval. Worker будится по завершению публикации main и по собственным заявкам; обычные сборки чужих веток его не будят.

Конфликт/провал теста до merge помечает PR queue:blocked, сохраняя main. Агент исправляет кандидата и ставит новый head. Провал/неверная квитанция после merge блокирует последующие слияния; после исправления причины повторяют именно неуспешный run (`gh run rerun <deploy_run>`), а очередь возобновляется по workflow_run. Повторное начало worker читает сохранённый ticket и фактическое merged-состояние, не выполняет неизвестное слияние повторно. queued-заявку можно снять удалением queue:ready до её начала.

Следующий worker запускается по завершению build/deploy или через явный dispatch оставшихся заявок. Пустая очередь не запускает cron и не расходует Actions-минуты. После отмены самого worker возобновление — `gh workflow run merge-queue.yml --repo caymanlogistic-crypto/1c-ip-php --ref main`; ticket сохраняется. Отдельная БД, сервер, PAT, scheduler-сервис и постоянный агент не создаются. Состояние заявок сохраняется в PR, поэтому замена pending job механизмом concurrency не теряет очередь. Источник статуса — комментарий ticket и метки PR; агент не обязан ждать или слать одинаковые сообщения о чужой выкладке.

На текущем бесплатном тарифе приватного личного репозитория GitHub API защиты main возвращает 403 (требуется Pro). Владелец отказался от оплаты. Поэтому это автоматическая последовательная очередь, а не серверный запрет обхода: общий admin-доступ всё ещё способен выполнить прямой merge. AGENTS.md запрещает агентам прямой merge/push; обнаруженное изменение main вне очереди останавливает её. Не заявлять, что платная защита включена.

## Доставка слоёв через приватный GHCR

Workflow публикует образ только для main (push или workflow_dispatch очереди) в `ghcr.io/caymanlogistic-crypto/1c-ip-php`. Task/PR проверяют сборку, но не публикуют образ, build cache или сайт. Main сохраняет registry build cache; метка SOURCE_COMMIT находится после runtime/vendor, поэтому они повторно используются при обычной правке кода.

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

### MAX — события активности объектов только в ТЕСТОВАЯ

Отдельно разрешённый владельцем Linux opt-in: приватный `ui/data/local/object-max-admission.json` содержит schema=1, enabled=true, project_id проекта, scope=object-activity, stand=ui, database=ipphp_ui, revision профиля, group_id, group_name=ТЕСТОВАЯ, chat_id, vault_sha256 и event_floor. Настройки и токен MAX остаются вне образа; encrypted max.enc шифруется существующим серверным ключом, ключ/Email/1С не заменяются. `ServerObjectMax` разрешает только свежий committed переход активности, точный текст события и один POST сообщения; чужие группы, изменение профиля/версии/квитанции, pending и uncertain другого object-activity уведомления закрывают отправку. Исторические uncertain иных операций сохраняются и не воспроизводятся; новый тип события не повторяет их payload/key. Общий gate, ручные MAX-рассылки, WorkflowMaxEvent и RentalOutbox не включаются. /_ops.php отражает object_activity_max отдельно. Для TLS только этого транспорта применяется существующий одобренный CA bundle из .local/certs/windows-trusted-roots.pem с фиксированным SHA256; системное доверие и остальные провайдеры не меняются.

Сеть: текущий подтверждённый pin platform-api2.max.ru → 155.212.204.149 в compose; TLS проверяет исходное имя. Root-owned `object-max-network.py` устанавливается как `/usr/local/sbin/ipphp-object-max-network`, соответствующие service/timer — в `/etc/systemd/system/`. Timer поддерживает только /32 TCP/443 в существующей internal-сети приложения, без default route; чужой маршрут отвергается. Перед включением проверяются актуальный DNS и pin. При изменении адреса требуется новая сверка, не общий egress. Compose дополняется до штатного обновления app через очередь; receiver и доставка образа не меняются. Старые события ниже event_floor не воспроизводятся.

### Ручная SMTP-почта — настройки

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

### MAX — согласования договоров в ТЕСТОВАЯ

Только по отдельному разрешению владельца: после принятой выкладки выполнить из опубликованного образа `php bin/update-contract-max-templates.php --database=ipphp_ui` (проверка), затем ту же команду с `--apply`. Обновляются только восемь текстов из defaults/contract-max-templates.json, с штатной историей каталога; SQL-импорт не используется. Сохранить приватный backup прежних восьми записей. Существующие токен/ключ, группа, object-max-admission и сеть не меняются.

`php bin/server-workflow-max-setup.php --enable` в Linux/ui сверяет прежний разрешённый TEST-профиль, vault/revision и точные согласованные тексты, выдаёт JSON для `/srv/1c-ip-php/ui/data/local/workflow-max-admission.json`. Записать через временный файл/rename, root:33, 0640. Marker закрепляет event_floor на момент включения, catalog_versions и единственную группу. Он не содержит токена. Сеть использует уже установленный узкий MAX /32:443 и прежний CA pin; общая сеть/ручная рассылка/очереди остаются закрыты. Отключение — убрать только workflow-max-admission.json. /_ops.php должен показать contract_workflow_max=true. Старые события не пересылаются. Обновление каталога в будущем требует пересверки marker; несовпадение версии закрывает отправку.

Остаются PHP 8.5.6 Apache, MySQL 8.4.9, lock-зависимости, internal network общего сайта, данные и TLS. Не включать внешнюю сеть, admission, профили отправок и workers/outbox при смене процесса. Pending/attempts/outbox сохраняются и не проигрываются. Чтение провайдера и внешняя запись требуют своих поручений; guards сохраняются.

### Явно разрешённая проверка 1С

Для обычной кнопки «Проверить» предусмотрен отдельный `OnecReadAdmission`, а общий `IPPHP_ALLOW_EXTERNAL` остаётся выключенным. Операторский файл `/srv/1c-ip-php/ui/data/local/onec-read-admission.json` содержит только `enabled: true`, `project_id: f6e21e3c-d974-4604-82fb-333da66559d2`, `stand: ui`, `provider: onec`, `scope: bounded-check-only`, `profile_reference: working-mounted-main-20261116-v1`. Он разрешает только команду check и её authenticate/records; sync/import через native broker, банк, остальные провайдеры и запись в 1С допуск не получают. Смена выбранного профиля закрывает допуск. Права, CSRF, версия, блокировка провайдера и receipt сохраняются.

Из принятого образа устанавливаются root-owned `/usr/local/sbin/ipphp-onec-network` (исходник `deploy/server/onec-network.py`) и `ipphp-onec-network.service`. Скрипт добавляет только `/32`-маршрут к `89.208.213.32` через существующий внутренний bridge, узкие FORWARD/обратный ESTABLISHED и MASQUERADE только TCP/443 от app `172.30.101.10`; default route, DNS, другие адреса и БД не открываются. Он отвергает чужой маршрут/другой контейнер/профиль. Повторный запуск не дублирует правила. Сервис восстанавливает маршрут после перезапуска Docker/хоста; установленный receiver из того же main вызывает его после пересоздания app при release/rollback. Образ не содержит секретов и не меняет бизнес-данные.

Первое включение: опубликовать main; из проверенного образа установить сетевой скрипт и unit, обновить установленный receiver из `deploy/server/deploy.py` со сверкой его прежней версии и резервом; создать приватный marker; запустить/включить unit. Проверить обычную web-кнопку, receipt, `/_ops.php`, запреты sync/других провайдеров и отсутствие default route. Чтобы немедленно закрыть прикладной допуск, убрать marker; ранее загруженные записи и история сохраняются. Удаление собственных сетевых правил/маршрута выполняется оператором отдельно; без marker приложение уже не делает внешних запросов.

Первичный snapshot/receipt и резервные документы находятся в /srv/1c-ip-php/initial-backup вне Git; повторный начальный импорт не нужен. Прежние хеши/runs доступны в Git-истории. Витрина публикуется с приложением из того же main без отдельного сервиса.

## Часовая синхронизация 1С ИП Яковлев

Для «1С ИП Яковлев» используется отдельный часовой таймер `deploy/server/ipphp-onec-yakovlev-sync.timer` и соответствующий `.service`. После публикации оператор устанавливает оба файла в `/etc/systemd/system/`, выполняет `systemctl daemon-reload` и включает `systemctl enable --now ipphp-onec-yakovlev-sync.timer`. Служба запускает CLI из текущего контейнера общего сайта от `www-data`. Проверочный запуск — `systemctl start ipphp-onec-yakovlev-sync.service`. Журнал приложения — `/connections/onec-yakovlev?tab=history`. Для паузы расписания — `systemctl disable --now ipphp-onec-yakovlev-sync.timer`. Действующий допуск `.local/onec-yakovlev-read.json` необходим, запросы к 1С выполняются только по GET.

## Справочный поиск DaData на общем сайте

Карточка контрагента использует два фиксированных HTTPS-запроса `findById/party` и `findById/bank`. На `ui` для них нужны два файла из приватного монтирования `/srv/1c-ip-php/ui/data/local`: `dadata-party.json` с полем `api_key` и `dadata-network.json` с полями `host` (`suggestions.dadata.ru`) и `ipv4` (сверенный публичный A-адрес указанного имени). Оператор устанавливает оба файла с владельцем `root:www-data` и правами 0640. Ключ передаётся по защищённому каналу через stdin. Изменения Compose для подключения не требуются.

После публикации установить root-owned `deploy/server/dadata-network.py` в `/usr/local/sbin/ipphp-dadata-network`, unit `ipphp-dadata-network.service` в `/etc/systemd/system` и обновить `ipphp-deploy` из того же принятого main. Сверить хеш исходного receiver и сохранить его резерв под `deploy.lock` при пустой очереди. Сначала установить только сетевой файл и запустить `ipphp-dadata-network check`. Команда сверяет DNS и TLS для указанного имени и адреса. Затем включить oneshot unit, проверить из app TLS и ответ HTTPS без ключа, установить ключ и проверить форму под разрешённой ролью. Receiver восстанавливает маршрут после очередной выкладки.

Сетевой скрипт ограничен внутренним UI-контейнером и одним проверенным IPv4 /32 на TCP/443. Обратный трафик разрешён только для установленных соединений. HTTPS проверяет сертификат `suggestions.dadata.ru`. При смене A-записи оператор отдельно сверяет новый адрес перед заменой. Для отключения сначала убрать ключ в резерв, отключить unit и выполнить `ipphp-dadata-network remove` с прежним сетевым файлом, затем убрать этот файл. Общий сетевой допуск, внешний DNS и планировщики этим порядком не включаются.

## Применение расписаний кабинетов из приложения

После публикации версии со страницей `/connections/schedule` установить `deploy/server/cabinet-dispatch.sh` как `/usr/local/sbin/ipphp-cabinet-dispatch` с правами 0755. Скопировать `ipphp-cabinet-service-dispatch.conf` в `/etc/systemd/system/ipphp-cabinet-sync.service.d/zz-application-schedule.conf`, а `ipphp-cabinet-timer-dispatch.conf` в `/etc/systemd/system/ipphp-cabinet-sync.timer.d/90-application-schedule.conf`. Имена обеспечивают применение после ранее установленных настроек таймера и проверки сессий. Системный путь к почтовому профилю и файлу допуска остаётся прежним.

Проверить `systemd-analyze verify ipphp-cabinet-sync.service ipphp-cabinet-sync.timer`. Затем выполнить `systemctl daemon-reload` и перезапустить только таймер. В свободную минуту `docker exec --user www-data --workdir /opt/app ipphp-ui-app-1 php bin/cabinet-schedule.php due` выдаёт пустую строку. В свой слот кабинет использует существующие сессию и ограничения чтения. После задержки до 60 минут диспетчер может обработать пропущенный слот один раз.
