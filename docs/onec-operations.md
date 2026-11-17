# Операции 1С из страниц приложения

Три страницы подключений используют один `Modules\Integrations\OnecOperations`:

| Ключ | Страница | Назначение |
|---|---|---|
| `onec-counterparty` | `/connections/onec-counterparty` | Контрагент из подтверждённого документа |
| `onec-invoice` | `/connections/onec-invoice` | Счёт на оплату покупателю |
| `onec-print` | `/connections/onec-print` | Печатный файл; до получения образца — `awaiting_template` |

Это операции общего подключения `onec`, а не три копии профиля или транспорта. Попап настроек использует прежний renderer и сохраняет общие настройки 1С; технический профиль скрыт. Права новых страниц присутствуют в общем каталоге PagePolicy. Прав на странице недостаточно для передачи: бухгалтер/admin должен иметь прежнее подтверждение нормального пакета.

Данные берутся из текущих доступных договоров и действующих счетов, включая active/ended, с общей постраничной подгрузкой. Сброс бизнес-данных не возвращает удалённые демо-строки. `state` в истории отдельно возвращает `historical`, `proof_verified` и `source_current`: подтверждённый прежний CREATE сохраняет доказательство, но не разрешает запись по удалённому/изменённому пакету. При historical=true, source_current=false written=false; prepare/dispatch/reconcile всё равно требуют актуальную бизнес-авторизацию. Проверка старой квитанции и проверка живого источника — разные действия.

Доступ к технической странице/проверке подключения проверяется отдельно. Бизнес-вызовы из страниц аренды используют прежние права OnecOwnerFacade (бухгалтер/admin, finance.write, уровень Согласований, текущий подтверждённый пакет); для них не требуется разрешение менять настройки подключения. HTTP actor всегда берётся из сессии. Передача actorId серверному PHP entry point — ответственность авторизованного handler, как у прежнего сервиса.

## Вызов из PHP

```php
$operations = new \Modules\Integrations\OnecOperations(\App\DB::pdo(), \Modules\Integrations\Config::store());
$preview = $operations->execute('onec-invoice', 'preview', $actorId, [
    'record_kind' => 'contract', // либо document для дополнительного документа
    'record_id' => (string)$contractId,
    'subject_id' => 'invoice:'.$invoiceId,
]);
```

Контрагент использует `subject_id=party:<id>`. Привязку к текущему согласованному документу проверяют существующие NativePacket/OnecOwnerFacade/OnecPayloadBuilder. `prepare` принимает ту же идентичность; `dispatch`, `state` и `reconcile` — только `intent_id` из результата подготовки. Вид и владелец intent повторно проверяются. Caller не передаёт OData body, реквизиты доступа, transport URL или флаги согласования.

## Вызов из браузера

Подключить `/assets/onec-operations.js` один раз на странице. Клиент работает и без корневого элемента страницы подключения:

```js
const result = await window.OnecOperations.call('onec-invoice', 'preview', {
  record_kind: 'contract', record_id: String(contractId), subject_id: 'invoice:' + invoiceId,
});
// После готовности preview: prepare с той же идентичностью и текущим CSRF страницы.
```

API: `/integrations/onec-actions/<ключ>/<действие>`. `preview`/`state` используют GET, остальные действия — POST с `_csrf`. Текущего actor берёт серверная сессия. Публичный preview не возвращает исходный пакет и его приватные доказательства. При ошибке сети клиент не повторяет POST автоматически; неизвестный исход записи переводит UI к сверке.

`check` повторно использует штатную ограниченную проверку чтения 1С и её request_key/журнал. Он не подтверждает возможность CREATE. `dispatch` сохраняет прежние write gate, source policy, журнал, предзапрос и сверку результата OnecNativeService. Неизвестный исход не разрешает повторную запись. Наличие этих страниц не включает внешнюю запись, outbox или workers.

После подтверждённых 201 и readback общий journal сохраняет типизированную связь `parties`/`invoices` через OnecBindings::recordSavedReference. Следующая страница получает проверенный Ref_Key из общего реестра, а не из произвольного браузерного поля. Повтор saved/reconcile не выполняет новый POST и не заменяет более новое наблюдение справочника старой квитанцией. Чужой ключ, изменённая HMAC-квитанция или несовпадающее тело блокируют сохранение связи.

Серверный `OnecWriteAdmission` открывает этот существующий journal без общего внешнего gate. Приватный `.local/onec-write-admission.json` задаётся оператором: enabled=true, project_id проекта, stand=ui, scope=prepared-counterparty-customer-invoice-create, source_instance, profile_reference, целочисленные connection_revision и source_revision. Проверяются текущие выбранные настройки и private write_policy, а также source binding. Изменение профиля/revision закрывает допуск. Сетевой доступ использует прежний pinned HTTPS /32; отдельный доступ к другим провайдерам не появляется.

В pinned metadata организации и валюты не имеют IsFolder; отсутствие этого поля допустимо только для этих двух типов. У контрагентов, договоров и номенклатуры требуется явное false. Договор покупателя должен соответствовать организации, владельцу, RUB и виду СПокупателем. Отсутствующее в этой схеме поле Закрыт не изобретается; явное true/null отклоняется. DeletionMark всегда должен быть явным false. Эти проверки общие для сохранённого наблюдения и live preflight.

`onec-print` не создаёт приблизительный файл: check/generate возвращают HTTP 409 `ONEC_PRINT_TEMPLATE_REQUIRED`. Формат и renderer будут добавлены по отдельному согласованному образцу.

## Проверка

`APP_DB_CONFIG_FILE=<своя ipphp_task_* БД> php bin/test-onec-operations.php` проверяет реальный preview, блокировку неподтверждённого пакета при повторе, вид subject, лишний native body, права/page grants, чужой/отсутствующий intent и обязательность реального печатного образца. Тест не делает сетевых запросов; транзакционная проверка прав откатывается.

`bin/test-onec-write-admission.php` на пустой конфигурации собственной task-БД проверяет scoped CREATE/GET, отсутствие общего доступа, revision/profile/source/private-policy guards и фактическую семантику флагов каталогов. Dummy marker/secret удаляются, SQL откатывается; сетевых вызовов нет.
