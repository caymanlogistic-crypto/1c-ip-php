/* Business copy and a safe, dependency-free editor. This module never sends messages. */
(function(root){
 'use strict';
 const formats=Object.freeze(['plain','markdown','html']);
 const people={manager:'Раиса Шилова',lawyer:'Наиль Еналиев',accountant:'Наталия Демидова',director:'Андрей Яковлев',admin:'Владимир',actor:'Система'};
 const placeholders=Object.freeze(['text','number','party','lawyer','manager','accountant','director','admin','version','reason','invoiceNumbers','recipientNames','actor','documentNumber','effective','context','documentLabel','invoiceSummary','effectiveSummary','managerEmail','reservationStart','reservationCommentLine','reservationCommentPrefix','reservationComment','reservationTypeLabel','reservationAreaLabel','previousDeadline','contractSubmitTitle','contractStart','contractEnd','contractObjectLines','contractSubmitAction']);
 const tokenSet=new Set(placeholders),formatOf=v=>v===null||v===undefined||v===''?'plain':String(v);
 const max=(id,name,trigger,title,action,body='{text}')=>Object.freeze({id,name,trigger,format:'markdown',body:title+'\n{context}\n\n'+body+'\n\n'+action});
 const maxDefinitions=Object.freeze([
  max('movement','Движение документа','После зафиксированного изменения; создание и сохранение черновика не уведомляют.','ℹ️ **Изменение по аренде**','Инициатор: {actor}. Актуальные условия, ответственный и история доступны в карточке.'),
  max('submit','Передача юристу','Менеджер отправил договор на согласование.','📄 **Договор направлен юристу**','{lawyer}, проверьте контрагента и финансовые условия. Сформируйте PDF либо приложите актуальный Word/PDF, затем подтвердите согласование.','{manager} направил договор на юридическую проверку.\n{text}'),
  max('resubmit','Повторная передача юристу','Менеджер исправил замечания и повторно отправил документ.','🔄 **Документ повторно направлен юристу**','{lawyer}, проверьте исправления и актуальный файл. Дальнейшая передача бухгалтеру выполняется после вашего согласования.','{text}\nВерсия: {version}. Ответственный за повторную проверку: {lawyer}.'),
  max('legalApprove','Согласование юристом','Юрист выполнил две проверки и подтвердил точный файл.','✅ **Юридическая проверка завершена**','{accountant}, проверьте контрагента, финансовые условия и файл. Затем откройте состав пакета и подтвердите отправку.','{text}\nАктуальный файл направляется бухгалтеру отдельным письмом. Юрист: {lawyer}.'),
  max('accountantReturn','Возврат бухгалтером','Бухгалтер вернул документ юристу с причиной.','↩️ **Документ возвращён юристу**','{lawyer}, устраните замечания либо передайте их менеджеру. Следующий шаг выполняет назначенный получатель возврата.'),
  max('lawyerReturn','Возврат юристом','Юрист вернул документ или передал замечания менеджеру.','↩️ **Документ возвращён менеджеру**','{manager}, исправьте замечания и повторно направьте документ юристу. Возобновление проверки бухгалтером возможно после юридического согласования.'),
  max('package','Подтверждение пакета','Бухгалтер выполнил три проверки и подтвердил просмотренный пакет.','📨 **Пакет документов подтверждён**','{manager}, контролируйте получение и подписание. Результат каждой отправки проверяйте в разделе «Операции и отправки».','{text}\n{invoiceSummary}\nПодтверждённый файл и состав вложений зафиксированы в операции; принятие транспортом отражается отдельно.'),
  max('block','Блокировка контрагента','Подтверждена блокировка с обязательной причиной.','⛔ **Работа с контрагентом заблокирована**','Новые договоры с контрагентом недоступны. Незавершённые проекты аннулированы с сохранением истории. Действующие договоры продолжают обслуживаться.','{text}\nИнициатор: {actor}.'),
  max('unblock','Разблокировка контрагента','Руководитель подтвердил разблокировку с причиной.','✅ **Работа с контрагентом возобновлена**','{manager}, при необходимости создайте новый проект. Ранее аннулированные проекты автоматически не восстанавливаются.','{text}\nИнициатор: {actor}.'),
  max('booking','Изменение брони','Зафиксировано изменение бронирования.','📌 **Обновлено бронирование**','{manager}, проверьте помещение и срок брони в карточке. Дальнейшие действия выполняйте по актуальному состоянию.'),
  max('bookingCreated','Создание брони','Создана новая бронь помещения.','📌 **Помещение забронировано**','{manager}, получите реквизиты клиента и подготовьте проект договора до окончания срока брони.'),
  max('bookingUpdated','Продление брони','Изменён срок действующей брони.','🗓️ **Срок брони изменён**','{manager}, продолжайте оформление с учётом нового срока. Проверьте актуальную доступность помещения перед отправкой договора.'),
  max('bookingCancelled','Отмена брони','Автор отменил бронь либо срок брони истёк.','🔓 **Бронирование завершено**','{manager}, перед следующим бронированием проверьте доступность помещения. История предыдущей брони сохранена.'),
  max('addendum','Передача допсоглашения','Менеджер отправил допсоглашение на юридическую проверку.','📄 **Допсоглашение направлено юристу**','{lawyer}, проверьте полный новый состав условий и актуальный файл. Обеспечение и первый период начисляются только по добавленным помещениям.','{text}\n{effectiveSummary}'),
  max('paymentWarning','Контроль срока оплаты','Плановая проверка присвоила счёту жёлтый уровень.','🟡 **Требуется контроль оплаты**','{manager} и {accountant}, уточните поступление и сверьте остаток по счёту. Обновите результат проверки в карточке; не создавайте повторное начисление.'),
  max('paymentOverdue','Просроченная оплата','Плановая проверка присвоила счёту красный уровень.','🔴 **Нарушен срок оплаты**','{manager}, свяжитесь с клиентом. {accountant}, проверьте поступления и остаток. {director} — контроль урегулирования.'),
  max('payment','Поступление или исправление оплаты','Бухгалтер зафиксировал либо исправил платёж.','💳 **Обновлена оплата по счёту**','{accountant}, проверьте остаток и платёжный документ. {manager}, учитывайте подтверждённое поступление при работе с клиентом.'),
  max('desync','Расхождение с 1С','Сверка выявила различие номера, суммы или оплаты.','⚠️ **Данные 1С и ERP различаются**','{accountant}, сравните данные в карточке счёта и подтвердите ручную проверку. До устранения расхождения используйте проверенные суммы; автоматическая подмена данных не выполняется.'),
  max('expiry','Окончание договора','Проверка выявила месяц либо неделю до окончания аренды.','🗓️ **Приближается окончание договора**','{manager}, согласуйте продление и подготовьте допсоглашение либо начните процедуру завершения. При сроке неделя или меньше — контроль {director}.'),
  max('reconciliation','Финальная сверка','Зафиксирован шаг завершения аренды и финальной сверки.','🔎 **Финальная сверка по аренде**','{accountant}, проверьте взаиморасчёты; {lawyer}, отсутствие претензий; {manager}, получите подписанный акт возврата. Завершение возможно после всех подтверждений и наступления даты завершения.'),
  max('completed','Завершение договора','Подтверждены финансы, юридическая проверка и акт возврата.','✅ **Договор завершён**','Взаиморасчёты, отсутствие претензий и акт возврата подтверждены. Документы и история сохранены в карточке завершённого договора.'),
  max('signed','Получение подписанного документа','Менеджер загрузил файл с подписями обеих сторон.','✍️ **Получен подписанный документ**','{manager}, проверьте файл в карточке. Действие договора и новых условий определяется датами, подтверждёнными подписями и предусмотренными оплатами.'),
  max('activated','Начало действия условий','Договор активирован либо подписанное ДС вступило в силу.','✅ **Условия аренды вступили в силу**','{manager} и {accountant}, используйте актуальный состав помещений, сроки и начисления из карточки. История прежних условий сохранена.'),
  max('invoice','Создание и изменение счёта','Зафиксировано создание, изменение, сверка или изменение контроля счёта.','🧾 **Обновлён счёт**','{accountant}, проверьте актуальные реквизиты, сумму, период и файл в карточке. Отправка и её результат учитываются отдельной операцией.'),
  max('sendFailure','Ошибка формирования или отправки','Формирование файла или отправка завершились ошибкой.','⚠️ **Операция требует завершения**','Откройте «Операции и отправки», устраните указанную причину и повторите только незавершённый шаг. Если документы переданы вручную, зафиксируйте это отдельным действием.'),
  max('retry','Повтор операции','Пользователь повторил незавершённые шаги операции.','🔄 **Выполнен повтор операции**','Проверьте результат каждого шага в разделе «Операции и отправки». Успешные шаги повторно не выполняются; принятие транспортом не подтверждает прочтение адресатом.'),
  max('manual','Ручное завершение','Пользователь подтвердил ручное выполнение либо передачу клиенту.','🤝 **Зафиксировано ручное выполнение**','Инициатор: {actor}. Проверьте фактический результат и историю операции. Электронная доставка этим действием не подтверждается.'),
  max('cancelled','Аннулирование проекта','Уполномоченный пользователь аннулировал проект с причиной.','⛔ **Проект аннулирован**','Продолжение согласования и отправка по этому проекту недоступны. Причина и история сохранены; при необходимости создайте новый проект.')
 ]);
 const emailDefinitions=Object.freeze([
  Object.freeze({id:'legalApproval',name:'Проект бухгалтеру',trigger:'После двух подтверждений юриста и выбора актуального Word/PDF.',subject:'{documentLabel} — проверка бухгалтерией',body:'{accountant}, добрый день!\n\nЮридическая проверка завершена. Направляем на проверку: {documentLabel}.\nКонтрагент: {party}. Версия документа: {version}.\nОтветственный юрист: {lawyer}.\n\nВо вложении — точный файл, подтверждённый юристом. Проверьте контрагента, финансовые условия и содержание файла. Если есть замечания, верните документ юристу с причиной. После проверки откройте состав пакета и подтвердите отправку отдельным действием.\n\nС уважением,\n{lawyer}',recipientsPolicy:'accountant',attachmentPolicy:'document'}),
  Object.freeze({id:'clientPackage',name:'Пакет клиенту и менеджеру',trigger:'После трёх подтверждений бухгалтера, просмотра состава пакета и подтверждения отправки.',subject:'{documentLabel} — документы для подписания',body:'Здравствуйте!\n\nНаправляем документы для подписания: {documentLabel}.\n{effectiveSummary}\n{invoiceSummary}\n\nВо вложении — согласованный документ и счета из подтверждённого состава пакета, если они предусмотрены. Проверьте реквизиты и условия, подпишите документ и передайте экземпляр с подписями обеих сторон менеджеру. Оплату выполните в сроки, указанные в счетах и договоре.\n\nПо вопросам оформления и передачи подписанных документов:\n{manager}, {managerEmail}.\n\nС уважением,\nБухгалтерия',recipientsPolicy:'clientAndManager',attachmentPolicy:'documentAndInvoices'}),
  Object.freeze({id:'invoice',name:'Ежемесячный или отдельный счёт',trigger:'При явной отправке счёта, сохранении с отправкой либо отправке предусмотренного регулярного счёта.',subject:'Счёт {invoiceNumbers} по договору №{number}',body:'Здравствуйте!\n\nНаправляем счёт {invoiceNumbers} по договору №{number}.\nКонтрагент: {party}.\n\nФайл счёта приложен к письму. Назначение, расчётный период, сумма, банковские реквизиты и срок оплаты указаны в самом счёте. При оплате укажите номер счёта и договора в назначении платежа. Если оплата уже выполнена, сообщите менеджеру дату и номер платёжного документа для сверки.\n\nПо вопросам оформления: {manager}, {managerEmail}.\n\nС уважением,\nБухгалтерия',recipientsPolicy:'clientAndManager',attachmentPolicy:'invoices'})
 ]);
 function selectEvent(text,fallback='movement'){
  text=String(text||'');
  if(/(?:создан|сохран[её]н).*черновик/i.test(text))return null;
  // Preserve explicit workflow routing, including resubmission, before text heuristics.
  if(fallback&&fallback!=='movement'&&fallback!=='booking'&&fallback!=='block')return fallback;
  const rules=[
   [/разблокирован/i,'unblock'],[/заблокирован/i,'block'],[/^Создана бронь/,'bookingCreated'],[/^Бронь.*продлена/,'bookingUpdated'],[/^Бронь.*(?:снята|завершена)/,'bookingCancelled'],
   [/^Сделано вручную:|передан[ыоа]?.*(?:вручную|лично)/i,'manual'],[/^Повтор операций:/,'retry'],[/(?:ошибк[аи]|не удалось|недоступна|требуется завершить операции)/i,'sendFailure'],
   [/рассинхронизац|обнаружено расхождение/i,'desync'],[/Нарушен срок оплаты/,'paymentOverdue'],[/Приближается срок оплаты/,'paymentWarning'],[/^До окончания договора/,'expiry'],
   [/заверш[её]н: бухгалтер, юрист и акт возврата подтверждены/,'completed'],[/финальн.*сверк|взаиморасч[её]т|претензий по договору|акт возврата/i,'reconciliation'],
   [/^Получен[ыо].*(?:подпис|подписи)/,'signed'],[/вступило в силу|активирован|договор.*действует/i,'activated'],[/^(?:Внесена|Исправлена|Подтверждена) оплата/,'payment'],
   [/аннулирован/,'cancelled'],[/^Юрист вернул|замечания.*переданы менеджеру/i,'lawyerReturn'],[/^Бухгалтер вернул/,'accountantReturn'],[/^Юрист согласовал/,'legalApprove'],
   [/^Бухгалтер подтвердил пакет/,'package'],[/^Направлено на согласование: ДС/,'addendum'],[/отправлен юристу|направлен юристу/,'submit'],[/сч[её]т/i,'invoice']
  ];
  return rules.find(([pattern])=>pattern.test(text))?.[1]||fallback||'movement';
 }
 function valuesFor(input={}){
  const v={...people,...input};for(const key of placeholders)if(v[key]===null||v[key]===undefined)v[key]='';
  for(const key of Object.keys(v))v[key]=String(v[key]);
  for(const[key,name]of Object.entries(people))if(!v[key].trim())v[key]=name;
  v.text=v.text.trim()||'Изменение зафиксировано в журнале аренды.';
  v.version=v.version.trim()||'текущая';
  const number=v.number.trim(),doc=v.documentNumber.trim(),party=v.party.trim();
  v.context=v.context||[number?'Договор №'+number:'',doc&&doc!==number?'ДС №'+doc:'',party].filter(Boolean).join(' · ')||'Аренда · рабочие операции';
  v.documentLabel=v.documentLabel||(doc&&doc!==number?'Допсоглашение №'+doc+(number?' к договору №'+number:''):number?'Договор №'+number:'Документ по аренде');
  v.invoiceSummary=v.invoiceSummary||(v.invoiceNumbers.trim()?'Счета: '+v.invoiceNumbers+'.':'Состав вложений соответствует подтверждённому пакету.');
  v.effectiveSummary=v.effectiveSummary||(v.effective.trim()?'Новые условия действуют с '+v.effective+'.':'Условия и даты действия указаны в приложенном документе.');
  v.managerEmail=v.managerEmail||'office@vgbc.ru';
  return v;
 }
 function safeURL(value){if(!/^(?:https?:\/\/|mailto:)/i.test(value)||/[\s<>"'\\{}]/.test(value))return false;try{const url=new URL(value);return url.protocol==='mailto:'?!!url.pathname:!!url.hostname;}catch{return false;}}
 function validateHTML(template){
  let rest=template.replace(/<\/?(?:b|strong|i|em|s|del|u|ins|code|pre|mark|blockquote|h[1-6])\s*>/gi,'');
  rest=rest.replace(/<a\s+href=("([^"]*)"|'([^']*)')\s*>/gi,(_,all,a,b)=>{if(!safeURL(a||b))throw Error('Недопустимый адрес ссылки.');return '';}).replace(/<\/a\s*>/gi,'');
  if(/<[^>]*>/.test(rest)||/<\s*[a-z!/]/i.test(rest))throw Error('HTML поддерживает только безопасные теги форматирования и ссылки без дополнительных атрибутов.');
 }
 function validateTemplate(format,template,limit=4000){
  format=formatOf(format);if(!formats.includes(format))throw Error('Допустимые форматы: обычный текст, Markdown и HTML.');
  if(typeof template!=='string'||!template.trim())throw Error('Укажите текст шаблона.');
  if(template.length>limit)throw Error('Текст превышает '+limit+' символов.');
  for(const match of template.matchAll(/\{([^{}]*)\}/g))if(!tokenSet.has(match[1]))throw Error('Неизвестное поле: '+match[1]);
  if(/[{}]/.test(template.replace(/\{[^{}]+\}/g,'')))throw Error('Проверьте фигурные скобки полей шаблона.');
  if(format==='markdown')for(const m of template.matchAll(/\]\(([^)]*)\)/g))if(!safeURL(m[1]))throw Error('Для ссылки укажите полный безопасный адрес https://, http:// или mailto:.');
  if(format==='html')validateHTML(template);
  return template;
 }
 const escapeHTML=text=>String(text).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const escapeMarkdown=text=>String(text).replace(/[\\`*_{}\[\]()#+.!|>~^<+=-]/g,'\\$&');
 function formatMaxDates(value){
  const text=String(value),protectedParts=[...text.matchAll(/(?:\b[a-z][a-z\d+.-]*:\/\/|\bmailto:|\bwww\.|[a-z]:[\\/]|\b[\p{L}\p{N}_.-]+[\\/]|\/(?=[\p{L}_.-]))[^\s<>"']+|[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+|[^\s<>"'\\/]+\.(?:pdf|docx?|xlsx?|csv|json|png|jpe?g|zip|html?|txt)\b/giu)];
  return text.replace(/(\d{4})-(\d{2})-(\d{2})/g,(date,year,month,day,at)=>{
   const before=text.slice(0,at),after=text.slice(at+date.length);
   // Identifiers, timestamps, addresses, links and filenames keep their exact spelling.
   if(/[\p{L}\p{N}_/\\@#?=&%.-]$/u.test(before)||/^[\p{L}\p{N}_/\\@#?=&%-]/u.test(after)||/^\.[\p{L}\p{N}_]/u.test(after)||/(?:№\s*|(?:^|\s)(?:id|uuid|номер)\s*[:=]?\s*)$/iu.test(before)||protectedParts.some(m=>at>=m.index&&at<m.index+m[0].length))return date;
   const y=Number(year),m=Number(month),d=Number(day),days=[31,y%4===0&&(y%100!==0||y%400===0)?29:28,31,30,31,30,31,31,30,31,30,31];
   return y>0&&m>=1&&m<=12&&d>=1&&d<=days[m-1]?day+'.'+month+'.'+year:date;
  });
 }
 function render(format,template,values={}){
  format=formatOf(format);validateTemplate(format,template);const v=valuesFor(values),escape=format==='html'?escapeHTML:format==='markdown'?escapeMarkdown:String;
  const identifiers=new Set(['number','documentNumber','invoiceNumbers','invoiceSummary']);
  const result=formatMaxDates(template).replace(/\{([^{}]+)\}/g,(_,key)=>{
   if(key==='contractObjectLines'){
    const bold=format==='html'?['<b>','</b>']:format==='markdown'?['**','**']:['',''];
    return JSON.parse(v[key]||'[]').map(item=>'Объект: '+bold[0]+escape(item.name)+bold[1]+' ('+escape(item.type)+')'
     +(item.area!==''?' — '+escape(item.area)+' м²':'')).join('\n');
   }
   return escape(identifiers.has(key)?v[key]:formatMaxDates(v[key]));
  });
  if(result.length>4000)throw Error('После подстановки сообщение превышает 4000 символов. Сократите шаблон или данные события.');
  return result;
 }
 function renderEmail(template,values={}){
  validateTemplate('plain',template.subject,200);validateTemplate('plain',template.body,4000);
  if(/[\r\n]/.test(template.subject))throw Error('Тема письма должна быть одной строкой.');
  const v=valuesFor(values),sub=text=>text.replace(/\{([^{}]+)\}/g,(_,key)=>v[key]),subject=sub(template.subject),body=sub(template.body);
  if(/[\r\n]/.test(subject))throw Error('Данные темы письма содержат перевод строки.');
  if(subject.length>200||body.length>4000)throw Error('После подстановки тема должна быть до 200 символов, текст письма — до 4000.');
  return{subject,body};
 }
 const toolbarActions=Object.freeze(['bold','italic','strike','code','link','emoji']);
 const emoji=Object.freeze(['✅','📄','📨','🧾','📌','🗓️','⚠️','🔴','🟡','🔄','✍️','🤝']);
 function editText(text,start,end,action,format='markdown',argument){
  format=formatOf(format);if(!formats.includes(format))throw Error('Неизвестный формат.');
  if(!toolbarActions.includes(action))throw Error('Неизвестная команда редактора.');
  text=String(text);start=Math.max(0,Math.min(text.length,start));end=Math.max(start,Math.min(text.length,end));let selected=text.slice(start,end),insert;
  if(action==='emoji')insert=emoji.includes(argument)?argument:emoji[0];
  else if(action==='link'){if(!safeURL(argument||''))throw Error('Укажите безопасный адрес ссылки.');selected=selected||'Открыть документ';insert=format==='html'?'<a href="'+escapeHTML(argument)+'">'+selected+'</a>':format==='markdown'?'['+selected+']('+argument+')':selected+' ('+argument+')';}
  else{selected=selected||({bold:'Важный текст',italic:'Уточнение',strike:'Отменённое условие',code:'Номер документа'})[action];const wraps=format==='html'?{bold:['<b>','</b>'],italic:['<i>','</i>'],strike:['<s>','</s>'],code:['<code>','</code>']}:format==='markdown'?{bold:['**','**'],italic:['*','*'],strike:['~~','~~'],code:['`','`']}:{bold:['',''],italic:['',''],strike:['',''],code:['','']};insert=wraps[action][0]+selected+wraps[action][1];}
  return{text:text.slice(0,start)+insert+text.slice(end),start,end:start+insert.length};
 }
 // Build preview nodes; never assign template markup to innerHTML.
 function previewInto(container,format,text){
  const doc=container.ownerDocument;container.replaceChildren();format=formatOf(format);
  function inline(parent,value){const re=/\\([\\`*_{}\[\]()#+.!|>~^<+=-])|\[([^\]\n]+)\]\(([^)\s]+)\)|(\*\*|~~|`|\*)(.+?)\4/g;let at=0,m;while((m=re.exec(value))){parent.append(doc.createTextNode(value.slice(at,m.index)));if(m[1])parent.append(doc.createTextNode(m[1]));else if(m[2]&&safeURL(m[3])){const a=doc.createElement('a');a.textContent=m[2];a.href=m[3];a.rel='noopener noreferrer';a.target='_blank';parent.append(a);}else if(m[4]){const el=doc.createElement(({'**':'strong','~~':'s','`':'code','*':'em'})[m[4]]);el.textContent=m[5];parent.append(el);}else parent.append(doc.createTextNode(m[0]));at=re.lastIndex;}parent.append(doc.createTextNode(value.slice(at)));}
  if(format==='html'){
   validateHTML(text);const parsed=new doc.defaultView.DOMParser().parseFromString(text,'text/html'),allowed=new Set(['B','STRONG','I','EM','S','DEL','U','INS','CODE','PRE','MARK','BLOCKQUOTE','H1','H2','H3','H4','H5','H6','A']);
   const append=(parent,node)=>{if(node.nodeType===3){const lines=node.textContent.split('\n');lines.forEach((line,i)=>{if(i)parent.append(doc.createElement('br'));parent.append(doc.createTextNode(line));});return;}if(node.nodeType!==1||!allowed.has(node.tagName)){parent.append(doc.createTextNode(node.textContent||''));return;}const el=doc.createElement(/^H[1-6]$/.test(node.tagName)?'strong':node.tagName.toLowerCase());if(node.tagName==='A'){if(!safeURL(node.getAttribute('href')||'')){parent.append(doc.createTextNode(node.textContent));return;}el.href=node.getAttribute('href');el.target='_blank';el.rel='noopener noreferrer';}for(const child of node.childNodes)append(el,child);parent.append(el);};for(const node of parsed.body.childNodes)append(container,node);
  }else for(const line of text.split('\n')){const row=doc.createElement('div');if(format==='markdown')inline(row,line);else row.textContent=line;if(!line)row.append(doc.createElement('br'));container.append(row);}
 }
 function mountEditor(textarea,options={}){
  if(!textarea?.ownerDocument||!textarea.parentNode)throw Error('Поле редактора не найдено.');
  const doc=textarea.ownerDocument,toolbar=doc.createElement('div'),preview=doc.createElement('div'),counter=doc.createElement('span'),listeners=[];let format=formatOf(options.format||'markdown');
  toolbar.className='demo-row';toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Форматирование сообщения');preview.className='registry-ui-modal-text';preview.setAttribute('aria-label','Предпросмотр сообщения');counter.className='registry-ui-modal-help';counter.setAttribute('aria-live','polite');
  textarea.before(toolbar);textarea.after(counter,preview);
  const listen=(el,type,fn)=>{el.addEventListener(type,fn);listeners.push(()=>el.removeEventListener(type,fn));};
  function refresh(){try{const value=options.values?render(format,textarea.value,typeof options.values==='function'?options.values():options.values):validateTemplate(format,textarea.value);previewInto(preview,format,value);counter.textContent=value.length+' / 4000';counter.removeAttribute('role');return true;}catch(e){preview.replaceChildren();counter.textContent=e.message;counter.setAttribute('role','alert');return false;}}
  function insert(action,argument){const result=editText(textarea.value,textarea.selectionStart||0,textarea.selectionEnd||0,action,format,argument);textarea.value=result.text;textarea.focus();textarea.setSelectionRange(result.start,result.end);textarea.dispatchEvent(new doc.defaultView.Event('input',{bubbles:true}));return result;}
  function button(label,action){const b=doc.createElement('button');b.type='button';b.className='btn';b.textContent=label;b.setAttribute('aria-label',label);b.dataset.templateAction=action;toolbar.append(b);listen(b,'click',()=>{if(action==='link'){url.hidden=!url.hidden;apply.hidden=url.hidden;if(!url.hidden)url.focus();}else insert(action);});return b;}
  const formatButtons=[button('Жирный','bold'),button('Курсив','italic'),button('Зачеркнуть','strike'),button('Код','code')];button('Ссылка','link');
  const url=doc.createElement('input');url.type='url';url.placeholder='https://';url.setAttribute('aria-label','Адрес ссылки');url.hidden=true;toolbar.append(url);const apply=doc.createElement('button');apply.type='button';apply.className='btn';apply.textContent='Вставить ссылку';apply.hidden=true;toolbar.append(apply);listen(apply,'click',()=>{try{insert('link',url.value);url.hidden=true;apply.hidden=true;}catch(e){counter.textContent=e.message;counter.setAttribute('role','alert');url.hidden=false;apply.hidden=false;url.focus();}});
  const picker=doc.createElement('select');picker.className='field-select toolbar-select';picker.setAttribute('aria-label','Выбрать смайл');for(const value of emoji){const option=doc.createElement('option');option.value=value;option.textContent=value;picker.append(option);}toolbar.append(picker);const add=doc.createElement('button');add.type='button';add.className='btn';add.textContent='Вставить смайл';toolbar.append(add);listen(add,'click',()=>insert('emoji',picker.value));
  listen(textarea,'input',()=>{refresh();options.onChange?.(textarea.value,format);});
  function setFormat(next){next=formatOf(next);if(!formats.includes(next))throw Error('Неизвестный формат.');format=next;for(const b of formatButtons)b.disabled=format==='plain';return refresh();}
  setFormat(format);return{toolbar,preview,counter,refresh,insert,setFormat,destroy(){for(const remove of listeners)remove();toolbar.remove();preview.remove();counter.remove();}};
 }
 const api=Object.freeze({formats,maxDefinitions,emailDefinitions,placeholders,selectEvent,valuesFor,validateTemplate,formatMaxDates,render,renderEmail,editText,previewInto,mountEditor,toolbarActions,emoji});root.RentalNotificationTemplates=api;if(typeof module==='object')module.exports=api;
})(typeof globalThis!=='undefined'?globalThis:this);
