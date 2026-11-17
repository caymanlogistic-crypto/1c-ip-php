# Карта текущего приложения для ИИ-агента

Снимок анализа: 1C-IP-PHP-CURRENT.zip от 17.11.2026, SHA256 e4224741a30c75c3d86272fe92b5106bd56b482c8372a090d660207bead73b84.

Документ описывает код этого снимка. Изменения, которые ещё нужно сделать, отмечены отдельно. Фактическую среду нового проекта бери из START-HERE.md после локального подтверждения. Не выдавай наличие класса за работоспособность функции.

Локально в T001 подтверждён источник CURRENT с HEAD bd59a7a2ebfbc5bf81a3ed9bb7ee17b3e41095d5. Приёмочная среда — START-HERE.md. Маршрут новой формы сверён с фактическим module.php; бизнес-код и дизайн при переносе не менялись.

## Среда и публикация

Текущий процесс — AGENTS.md и START-HERE.md: отдельный локальный worktree/ветка каждого агента, свои порты/сессии/изменяемые данные, один общий серверный экземпляр, последовательный GitHub merge и автодеплой main по «выкладывай». Существующие технические имена ui/ipphp-ui сохраняются; переход с прежних двух стендов выполнен 17.11.2026 и проверен, результат отражён в WORK.md. Receiver deploy/server/deploy.py допускает main OIDC; локальная сессия cli-server задаётся Start-Local.ps1 отдельно от серверных cookies. Бизнес-команды и внешние guards сменой процесса не меняются.

## 1. Форма системы

Это модульный PHP/MySQL-монолит. В архиве 271 PHP-файл, примерно 31,4 тысячи строк PHP. Основные модули — аренда, подключения и вспомогательные источники.

| Область | Реальные точки входа | Что здесь находится |
|---|---|---|
| Web | public/router.php, public/index.php | Маршрут запроса, аутентификация, CSRF, page policy, вызов handler |
| Общее ядро | app/bootstrap.php, DB.php, Auth.php, PagePolicy.php | Загрузка, PDO, транзакции, сессии, права |
| Аренда | modules/rental/module.php | Реестры, формы, договоры, ДС, счета, оплаты, документы, занятость |
| Подключения | modules/integrations/module.php | Провайдеры, авторизация, чтение/импорт, отправки, сопоставления |
| Источники/рынок | modules/auxiliary/module.php | Помещения, подтверждение данных, рынок |
| UI | app/View.php, modules/rental/RentalUI.php, public/design, public/assets | Оболочка, HTML-компоненты, палитра, CSS, JS |
| Схема/CLI | database/migrations, database/pending, bin | Явные операции миграции, диагностики, импорта и обслуживания |

Важно: app сейчас содержит и предметные классы BillingParts/ContractOccupancy/InvoiceScope. Это фактическое состояние, а не идеальная граница. Не перемещай весь каталог при обычной задаче.

## 2. Найти нужное место изменения

Маршруты регистрируются в порядке выполнения module.php. Позднее присваивание заменяет раннее. Ниже указаны действующие handlers снимка.

| URL / область | Действующий handler | Дальнейший путь |
|---|---|---|
| /rental/contract | SourceCards::show | Карточка договора, чтение данных, действия текущей роли |
| /rental/new-contract и /rental/edit-contract | SourceCards::draft | ContractForm::render |
| /rental/invoice | FinanceController::card | Финансовая карточка |
| /rental/finance-save | FinanceController::save | Сохранение через существующий командный путь |
| /rental/action | Controller::action | Service::command; затем событие/уведомление |
| /rental/parties и /rental/party | PartyDirectory::page / card | Контрагенты |
| /rental/objects и /rental/object | ObjectDirectory::page / card | Сдаваемые единицы |
| /rental/reservations и /rental/booking-action | BookingController::create / action | Бронь |
| /rental/documents и /rental/document | AddendumController::listing / card | Документы/ДС |
| /rental/addendum-form и /rental/addendum-action | AddendumController::editor / action | Создание/команды ДС |
| /rental/approvals | ApprovalRegistry::page | Реестр согласований |
| /rental/contracts-active и /rental/contracts-completed | LeaseRegistry::page | Действующие/завершённые договоры |
| /rental/operations и /rental/operation-action | OperationsRegistry::page / OperationActionController::action | Операции и их обработка |
| /rental/generate, /rental/download, /rental/upload | Documents::generate / download / upload | Генерация и файлы |
| /connections/{provider} | Integrations\Controller::page | PageView::render |
| /integrations/action | Integrations\Controller::action | Проверить конкретный action, затем ProviderService/другой целевой сервис |
| /integrations/onec/{preview,prepare,dispatch,reconcile,state} | OnecController | Специальный путь записи/сверки в 1С |
| /email и /max | Integrations\Controller::page | Настройки и сообщения |
| /auxiliary/market/{dashboard,runs,comparison,history,settings} | Auxiliary\Controller::market | Рынок |
| /api/menu-counts | public/index.php | InvoiceSignals::refreshForRequest и Menu::counts |

Последний endpoint не является чистым чтением: после 13:00 при допустимой роли он обновляет derived cache и события. Это не автономный планировщик.

Старые ветви Controller::page для contract/invoice/new-contract не являются основными handlers этих URL. Не удаляй их без проверки всех вызовов, но и не исправляй их наугад.

Новая страница может затрагивать module.php, PagePolicy, Menu и View. Сверь все фактические места регистрации. Новый провайдер также затрагивает Support::PROVIDERS.

## 3. Изменение бизнеса

Основной вход команд аренды: Service::command.

Он проверяет policy/права/роль, command_key, fingerprint повторной команды, версии изменяемых сущностей; выполняет бизнес-действие и запись результата/события в транзакции. Проверка версии находится в Service::version. Финансовые действия уходят через ExtraService в FinanceService; дополнительные сценарии имеют свои сервисы, но не должны обходить общий допуск.

Сеть и внешние эффекты не должны выполняться до подтверждённого сохранения бизнес-команды. В текущем коде часть уведомлений вынесена после commit; для отправок существуют отдельные intent/outbox/attempt механизмы. Улучшай этот путь по конкретной задаче.

| Факт | Основные данные | Где искать правило/запись | Особенность |
|---|---|---|---|
| Контрагент | parties, party_bank_accounts | PartyDirectory, Service | Блокировка влияет на ограниченный набор проектов |
| Договор | contracts, contract_objects, contract_terms | Service, ExtraService, ContractForm | Исторический и обслуживаемый договор различаются |
| ДС | rental_documents, document_terms | Addenda, AddendumController, ExtraService | Собственный цикл согласования и применения |
| Счёт | invoices, версии/базы выставления | FinanceService, InitialBilling, MonthlyBilling, InvoiceCalculation | Сумма и база выставления не меняются произвольно задним числом |
| Деньги | payments, payment_allocations | Service, FinanceService | Оплата и распределение — отдельные факты |
| Занятость | rental_objects, contract_objects, holds, reservations | Occupancy, ContractOccupancy, Availability | Исключённый объект может удерживаться до акта возврата |
| История/повторы | rental_commands, rental_events, audit | Service, WorkflowEvents, Audit | Не очищать ради повторной попытки |
| Внешний обмен | integration_connections, integration_runs, external_documents/files | ProviderService, Config, адаптеры | Логин, чтение, импорт и внешняя запись — разные результаты |

source_json и fact_json сейчас содержат смешанные исторические и изменяемые сведения. Их названия не определяют авторитетность. Для поля, которое меняет задача, явно установи: кто пишет, кто читает, что первично, что вычисляется. Не нормализуй все JSON без отдельной задачи.

Обнаружено расхождение: RegistryState::managed учитывает imported/workflowTouched/servicing_document_id; SourceCards::show учитывает только imported. Оно не считается исправленным этим документом.

## 4. Витрина и общая UI-база

### Витрина текущего проекта

- Действующая витрина: https://ui.89.208.213.14.sslip.io/design/demo/index.html, обычный вход приложения; ссылка «Витрина» в папке «Администрирование». Опубликована 17.11.2026 по поручению пользователя через main 308f927, workflow 44133157422; проверены каталог, поиск/Показать, форма/диалог, уведомления, графики, ссылки, права и короткое окно. До публикации объединённая версия проверена локально на 8873 с отдельной копией всех данных. Техническая интеграция принята в этой задаче; примеры сами по себе не утверждают бизнес-правила и согласование будущих UI-изменений. Результат — WORK.md, актуальная версия — /_ops.php.
- Источник: UI-SHOWCASE-CLEAN-20261117.zip; README.txt, INTEGRATE-PROMPT.txt, PACKAGE-NOTES.txt прочитаны. 21 общий файл совпал по содержимому (без учёта CRLF); более новые source-menu-counts.js и ui-shell.css сохранены. 15 отсутствовавших файлов перенесены адресно.
- При браузерной проверке исправлена композиция каталога: опциональный общий .registry-ui-compact-table--catalog даёт описанию/селектору/действию 43/39/18% ширины вместо фиксированных 92/105px. Пример использует этот же modifier; прочие компактные таблицы не меняются.
- Каталог содержит 33 компонента с поиском, описаниями, селекторами и «Показать»; guide.html, layout-rules.html, charts.html — связанные страницы. Возврат «В приложение» ведёт на `/`.
- HTML витрины проходит через существующую сессию: public/router.php локально; RewriteRule существующего Apache → public/index.php → App\Showcase на сервере. Без входа — /login; перечень HTML фиксирован. CSP демо запрещает сеть и отправку форм; демонстрационные данные остаются в памяти вкладки.
- Общие CSS/шрифты/иконки — public/design/system; JS компонентов — public/assets/source-{date-picker,menu-counts,notifications,registry-components,registry-viewport}.js. Дополнительные общие компоненты: public/design/system/{palette-runtime,registry-ux,registry-interactions,table-reference-select,time-picker}.js и charts/. Они подключены там, где нужны, по одному разу; демонстрационные demo_runtime.js, messages-demo.js, charts-demo.js — только public/design/demo.
- PACKAGE-NOTES: очищенная витрина использует общий каскад вместо старого встроенного CSS; опциональные .registry-ui-modal--probe и .registry-ui-data--invoices, а также порядок ui-controls не откатывались. Совпадение со старой встроенной версией по пикселям не заявляется.

### Источники оформления

- public/design/system/palette.css — смысловые цвета.
- public/design/system/css/erp-ui.css — базовые правила.
- registry-components.css, ui-controls.css, ui-shell.css, registry-viewport.css, notification-icons.css — общие компоненты/оболочка.
- time-picker.css — также подключается на странице входа; проверяй фактическую потребность конкретного экрана.
- public/design/demo/demo_geometry.css — несмотря на имя demo, используется рабочими страницами.
- app/View.php — порядок подключения CSS, предзагрузка существующих шрифтов и общий renderer папок навигации. Подключения (включая Email/MAX) и Администрирование фильтруются прежними правами. app/views/workspace.php и public/assets/workspace-menu-restore.js восстанавливают папки до запуска отложенных обработчиков; логика и изоляция настроек остаются в source-menu-counts.js.
- modules/rental/RentalUI.php — кнопки, сообщения, пары значений, модальные окна и другие общие HTML-компоненты.
- Подписи цветных счётчиков: app/Menu.php → attentionLabels; PHP меню/вкладки и workspace-ui.js используют один набор (JS получает его в data-count-labels оболочки). Для объектов подписи уточняют простой, недавнюю сдачу и бронь. Простой в Indicators и строках использует общие ObjectFacts::idleDays/idleSeverity и Availability; красный срок договора остаётся отдельным текстовым выделением.
- public/assets — текущая JS-логика; подбирай фактический script по экрану.

Файлы modules/auxiliary/source-ui — исходные фрагменты отдельных страниц, не доказанная общая витрина.

### Правило развития

Рабочий компонент и его пример используют общую реализацию. Изменение компонента сопровождается обновлением примера в той же задаче. Витрина не хранит отдельную копию CSS/JS, которая может устареть.

Перед сдачей сравни пример и рабочий экран: размеры/отступы, палитра/типографика, normal/disabled/error состояния, фокус, прокрутка и нижние действия модального окна. Для общего компонента проверь один соседний экран. Смысл цветов задаётся CURRENT-SPEC, а не личным вкусом агента.

Новый компонент или новый визуальный вариант показывается владельцу. Применение существующего принятого компонента не требует отдельного разрешения на каждую кнопку.

## 5. Подключения — отдельные сценарии

Перечень восьми основных провайдеров: Support::PROVIDERS. Рынок: Auxiliary\Market::SOURCES.

| Провайдер | Главный код | Минимальная самостоятельная приёмка |
|---|---|---|
| 1С | OnecReaderFactory, OnecMapping, OnecNativeService, native/php/onec | Организация + одна запись; запись в 1С отдельно |
| Банк через 1С | native/php/onec/bank_worker.php, ReadOnlyCheck | Операции за небольшой период; вход в 1С недостаточен |
| Диадок | native/php/diadoc, DiadocPendingConfirmation | Нужный ящик + один документ/оригинал |
| Мосэнергосбыт | MesClient, MesFreshFlow, MesMail, MesAutoOtp | Лицевой счёт + один документ; OTP без циклического перевыпуска |
| Мосводоканал | native/php/mosvodokanal, MvkMapping | Одна квитанция + правильный тип файла |
| ПИК-Комфорт | native/php/pik/Client.php | Один счёт/квитанция; генерация PDF отдельно |
| Email | Messaging, MessageTransport, RentalOutbox | SMTP-вход отдельно от явно порученной тестовой отправки |
| MAX | Messaging, WorkflowMaxEvent, MaxReadCheck | Проверка токена/группы; отправка отдельным поручением |
| ЦИАН, Яндекс, Morrow, Fortex | modules/auxiliary/Market* | Маленькая выборка одного источника и верные поля |

Важные ограничения:

1. В ZIP среди private/integrations есть только key и max.enc. Остальные профили и .local не переданы; их фактическое наличие проверяется локально.
2. WorkflowMaxEvent::dispatch привязан к старой R2-БД и порту. AutoMAX из CURRENT не является работающим. Общая проверка MAX и специальный MaxReadCheck — разные пути.
3. Отсутствие .local/onec-dev-transport.json выбирает direct. Не меняй транспорт молча при переносе.
4. GET_LOCK использует ipphp-provider:{provider} на уровне MySQL-сервера; разные схемы могут конкурировать за один провайдер.
5. Наличие другой pending-операции блокирует новые действия провайдера. Сначала выясняй прежний исход.
6. Банк может иметь результат общей авторизации с network_calls=0; это не доказательство чтения выписки.
7. RentalOutbox связывает политику с project/release/digest/получателями. Копия и новый commit не получают автоматически прежнее право отправки.
8. При неизвестном исходе внешнего действия выполняется сверка прежней операции. Новая попытка не должна создавать дубль.

## 6. Что является защитой, а что отдельной задачей улучшения

Сохранять: права, CSRF, versions, точные деньги, idempotency, транзакции, занятость, зафиксированные документы, аудит и сверку внешних результатов.

Отдельно пересматривать по задаче: жёсткие привязки к прежним каталогам/БД/снимкам, одноразовые миграционные допуски, неоднозначные маршруты, дублирующиеся определения, слабую диагностику.

FROZEN в FrozenPacketWire относится к содержимому утверждённого отправляемого пакета. Не путай его с исторической фразой о защите CSS. Если требуется защищённое изменение, найди конкретный механизм и границу, не объявляй всё дерево неизменяемым.

## 7. Горячие точки и постепенное улучшение

- Controller.php, Service.php, ExtraService.php, FinanceController.php, ProviderService.php совмещают много действий.
- Controller::query используется как слой данных из других экранов.
- rental и integrations знают внутренние классы друг друга.
- Один rental_number_guard сериализует основные команды.
- MAX(id)+1 в нескольких местах требует сохранения watermark и плохо подходит для дальнейшего расширения конкурентной записи.
- Runtime/config/миграции недостаточно переносимы.
- SafeErrors сохраняет класс и код ошибки, но почти не даёт контекста для расследования.
- native-source-manifest имеет расхождения с фактическими байтами части файлов; это историческая запись, а не доказательство текущей приёмки.

Желаемое направление для новых и затронутых участков:
- handler разбирает вход и вызывает сервис;
- сервис содержит бизнес-решение и транзакционную границу;
- чтение/запись данных имеют явного владельца;
- renderer использует общую UI-базу;
- адаптер провайдера получает явные параметры и возвращает понятный результат;
- одно бизнес-правило имеет одно авторитетное определение.

Не создавай все эти слои для маленькой надписи. Выделяй ответственность тогда, когда она снижает риск конкретной задачи. Все перечисленные долги не являются предварительным запретом на первую полезную правку.

## 8. Проверяемость

Для каждого изменения агент должен ответить:
1. Где фактическая точка входа?
2. Какое правило или представление меняется?
3. Какие данные и эффекты затрагиваются?
4. Какой компонент витрины используется, если меняется UI?
5. Какой короткий сценарий доказывает результат?

Если ответ требует долгой археологии, уточни этот раздел карты в рамках задачи. Не создавай новый слой отчётности.

