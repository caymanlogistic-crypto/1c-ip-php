/* Neutral demonstration of system messages and canonical dialogs. No CSS overrides. */
(function(root){
 'use strict';
 const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let activeDialog=null,serial=0,openIssue=null;
 const scenarios=[
  ['error','Красная ошибка','Сохранение не выполнено. В сообщении есть повторная попытка.','Открыть ошибку','danger'],
  ['warning','Жёлтое предупреждение','Проверка необычного значения: отмена или подтверждение.','Открыть предупреждение','warning'],
  ['form','Ошибки в форме попапа','Общий итог проверки, ошибка поля и предупреждение рядом со значением.','Открыть форму','danger'],
  ['long','Длинное сообщение','Несколько причин, длинное наименование и прокрутка содержимого.','Открыть подробности','neutral'],
  ['confirm','Подтверждение действия','Короткий вопрос с отменой и явным подтверждением.','Открыть подтверждение','neutral'],
  ['toast-success','Зелёное уведомление','Успешное действие: зелёное сообщение сверху страницы.','Зелёное уведомление','ok'],
  ['toast-error','Красное уведомление','Краткое сообщение об ошибке поверх рабочей страницы.','Красное уведомление','danger'],
  ['toast-warning','Жёлтое уведомление','Задача по договору поверх рабочей страницы.','Жёлтое уведомление','warning'],
  ['toast-stack','Несколько уведомлений','Три цвета одновременно. Новое сообщение сдвигает предыдущие ниже.','Три уведомления подряд','neutral']
 ];
 function renderPage({page,header,primary,secondary}){
  return '<section class="demo-page" data-page="messages" hidden>'+header(page,secondary('Пример формы','msg:form')+primary('Красный попап','msg:error'))+'<section class="table-card registry-ui-card"><div class="table-toolbar registry-ui-toolbar"><div class="found-label">Сценариев: <b>'+scenarios.length+'</b></div><div class="toolbar-right"><span class="registry-ui-secondary">Выберите ситуацию и откройте рабочий попап</span></div></div><div class="table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Сценарий</th><th>Что показывается</th></tr></thead><tbody>'+scenarios.map(([key,title,description,label,tone])=>'<tr tabindex="0" data-message-scenario="'+key+'"><td><span class="badge badge-'+tone+'">'+title+'</span></td><td><span class="registry-ui-primary">'+description+'</span></td></tr>').join('')+'</tbody></table></div></section><section class="panel-section" id="messages-open-issue" hidden><div class="form-alert alert-error" role="alert"><span class="alert-mark" aria-hidden="true">×</span><div class="alert-body"><div class="alert-body-title">Действие не завершено</div><div class="alert-body-sub" data-message-issue-text></div></div>'+secondary('Повторить сохранение','msg:resolve-toast-error')+'</div></section><section class="panel-section"><div class="form-alert alert-info" role="status" aria-live="polite"><span class="alert-mark" aria-hidden="true">i</span><div class="alert-body"><div class="alert-body-title">Результат последнего действия</div><div class="alert-body-sub" id="messages-last-result">Выберите сценарий в таблице.</div></div></div></section></section>';
 }
 function setStatus(message){if(typeof document==='object'){const el=document.getElementById('messages-last-result');if(el)el.textContent=message;}}
 function getIssues(){return {error:openIssue?1:0,warning:0};}
 function setIssue(message){
  openIssue=message?String(message):null;
  if(typeof document==='object'){
   const panel=document.getElementById('messages-open-issue');
   if(panel){panel.hidden=!openIssue;const text=panel.querySelector('[data-message-issue-text]');if(text)text.textContent=openIssue||'';}
  }
  if(typeof root.dispatchEvent==='function'&&typeof root.Event==='function')root.dispatchEvent(new root.Event('demo:issues-change'));
 }
 function alertMarkup(tone,title,text,attributes=''){
  return '<div class="form-alert alert-'+tone+'" '+attributes+'><span class="alert-mark" aria-hidden="true">'+(tone==='error'?'×':tone==='warning'?'!':'i')+'</span><div class="alert-body"><div class="alert-body-title">'+escape(title)+'</div><div class="alert-body-sub">'+escape(text)+'</div></div></div>';
 }
 function launch(api,scenario,title,body,foot,variant='registry-ui-modal--confirmation',onSubmit=null,cancelMessage='Сообщение закрыто.'){
  if(activeDialog){activeDialog.dataset.messageOutcome='replaced';activeDialog.close();}
  const d=api.openDialog(title,body,foot,variant,onSubmit);d.dataset.messageScenario=scenario;activeDialog=d;
  d.addEventListener('close',()=>{if(activeDialog===d)activeDialog=null;if(!d.dataset.messageOutcome)setStatus(cancelMessage);},{once:true});
  return d;
 }
 function complete(api,tone,message){if(activeDialog){activeDialog.dataset.messageOutcome='complete';activeDialog.close();activeDialog=null;}setStatus(message);api.toast(tone,message);}
 function validateValues(values){
  const name=String(values.name??'').trim(),raw=String(values.quantity??'').trim(),quantity=Number(raw),errors={};
  if(!name)errors.name='Введите наименование объекта.';
  if(!raw||!Number.isFinite(quantity)||!Number.isInteger(quantity)||quantity<1)errors.quantity='Укажите целое количество не меньше 1.';
  return {valid:Object.keys(errors).length===0,name,quantity,errors,warning:!errors.quantity&&quantity>100?'Количество выше обычного. Проверьте значение перед сохранением.':''};
 }
 function openForm(api){
  const prefix='message-form-'+(++serial),nameId=prefix+'-name',quantityId=prefix+'-quantity';let acknowledged=false;
  const body='<div class="registry-ui-modal-grid"><div class="form-alert alert-error" role="alert" data-message-summary><span class="alert-mark" aria-hidden="true">×</span><div class="alert-body"><div class="alert-body-title">Проверьте данные перед сохранением</div><ul class="alert-body-sub" data-message-error-list><li>Введите наименование объекта.</li></ul></div></div><div class="field is-error" data-message-field="name"><label class="field-label" for="'+nameId+'">Наименование объекта<span class="req">*</span></label><input class="field-input" id="'+nameId+'" name="messageName" aria-required="true" aria-invalid="true" aria-describedby="'+nameId+'-help" placeholder="Например, объект «Альфа»"><span class="field-msg" id="'+nameId+'-help">Введите наименование объекта.</span></div><div class="field is-warning" data-message-field="quantity"><label class="field-label" for="'+quantityId+'">Количество<span class="req">*</span></label><input class="field-input" id="'+quantityId+'" name="messageQuantity" type="text" inputmode="numeric" value="120" aria-required="true" aria-invalid="false" aria-describedby="'+quantityId+'-help"><span class="field-msg" id="'+quantityId+'-help">Количество выше обычного. Проверьте значение перед сохранением.</span></div>'+alertMarkup('warning','Количество требует проверки','Если значение верно, подтвердите его при сохранении.','data-message-warning role="status"')+'</div>';
  const foot=api.closeButton+'<button class="btn btn-secondary" type="button" data-message-fill-valid>Заполнить корректно</button><button class="btn btn-primary" type="submit" data-message-form-save>Сохранить</button>';
  const d=launch(api,'form','Редактирование объекта',body,foot,'registry-ui-modal--weight-single',()=>{
   const check=update();
   if(!check.valid){setStatus('В попапе показаны ошибки заполнения.');d.querySelector('[aria-invalid="true"]')?.focus();return;}
   if(check.warning&&!acknowledged){acknowledged=true;d.querySelector('[data-message-warning] .alert-body-title').textContent='Подтвердите проверенное количество';d.querySelector('[data-message-form-save]').textContent='Подтвердить и сохранить';setStatus('Ожидается подтверждение предупреждения.');return;}
   complete(api,'success','Объект «'+check.name+'» сохранён. Количество: '+check.quantity+'.');
  },'Редактирование отменено.');
  d.querySelector('form').noValidate=true;
  function values(){return {name:d.querySelector('[name="messageName"]').value,quantity:d.querySelector('[name="messageQuantity"]').value};}
  function update(){
   const check=validateValues(values()),errors=Object.values(check.errors),summary=d.querySelector('[data-message-summary]');summary.hidden=!errors.length;
   d.querySelector('[data-message-error-list]').innerHTML=errors.map(text=>'<li>'+escape(text)+'</li>').join('');
   for(const field of ['name','quantity']){
    const wrapper=d.querySelector('[data-message-field="'+field+'"]'),input=wrapper.querySelector('input'),error=check.errors[field],warning=field==='quantity'&&!error?check.warning:'';
    wrapper.classList.toggle('is-error',!!error);wrapper.classList.toggle('is-warning',!!warning);wrapper.classList.toggle('is-success',!error&&!warning);input.setAttribute('aria-invalid',String(!!error));wrapper.querySelector('.field-msg').textContent=error||warning||(field==='name'?'Наименование заполнено.':'Количество проверено.');
   }
   d.querySelector('[data-message-warning]').hidden=!check.warning;
   return check;
  }
  d.addEventListener('input',e=>{if(!e.target.matches('[name="messageName"],[name="messageQuantity"]'))return;acknowledged=false;d.querySelector('[data-message-form-save]').textContent='Сохранить';d.querySelector('[data-message-warning] .alert-body-title').textContent='Количество требует проверки';update();});
  d.querySelector('[data-message-fill-valid]').addEventListener('click',()=>{d.querySelector('[name="messageName"]').value='Альфа';d.querySelector('[name="messageQuantity"]').value='5';acknowledged=false;d.querySelector('[data-message-form-save]').textContent='Сохранить';update();setStatus('В форму подставлены корректные значения.');});
  update();setStatus('Открыт попап с ошибкой поля и предупреждением.');
 }
 function handleAction(action,api){
  if(typeof action!=='string'||!action.startsWith('msg:'))return false;
  switch(action.slice(4)){
   case 'error':
    launch(api,'error','Не удалось сохранить объект','<div class="registry-ui-modal-grid">'+alertMarkup('error','Изменения не сохранены','Сервис временно не ответил. Повторите попытку.','role="alert"')+'<p class="registry-ui-modal-text">Объект <strong class="registry-ui-entity-id">#1002</strong> · «Альфа»</p><p class="registry-ui-modal-secondary">В этом примере повторная попытка завершается успешно.</p></div>',api.closeButton+api.primary('Повторить сохранение','msg:retry'));
    setIssue('Объект #1002 не сохранён: сервис временно не ответил. Повторите сохранение.');setStatus('Открыта красная ошибка сохранения.');return true;
   case 'retry':if(activeDialog?.dataset.messageScenario==='error'){setIssue(null);complete(api,'success','Повторное сохранение объекта #1002 выполнено.');}return true;
   case 'resolve-toast-error':if(openIssue){setIssue(null);complete(api,'success','Повторная попытка выполнена. Изменения сохранены.');}return true;
   case 'warning':
    launch(api,'warning','Проверьте количество','<div class="registry-ui-modal-grid">'+alertMarkup('warning','Значение выше обычного','Количество: 120. Проверьте значение и подтвердите продолжение.','role="alert"')+'<p class="registry-ui-modal-text">Объект <strong class="registry-ui-entity-id">#1004</strong> · «Бета»</p></div>',api.closeButton+api.primary('Подтвердить и продолжить','msg:confirm-warning'),'registry-ui-modal--confirmation',null,'Проверка количества отложена.');
    setStatus('Открыто жёлтое предупреждение с подтверждением.');return true;
   case 'confirm-warning':if(activeDialog?.dataset.messageScenario==='warning')complete(api,'success','Количество 120 подтверждено. Действие продолжено.');return true;
   case 'form':openForm(api);return true;
   case 'long':{
    const entries=Array.from({length:24},(_,i)=>'<section class="registry-ui-modal-grid"><p class="registry-ui-modal-summary">Запись #'+(1001+i)+'</p><p class="registry-ui-modal-text">'+escape(['Не заполнено обязательное значение. Откройте запись и укажите наименование.','Значение требует проверки. Сравните количество с исходными данными и подтвердите его.','Не удалось завершить действие. Повторите попытку после проверки сведений.'][i%3])+'</p></section>').join('');
    launch(api,'long','Подробности проверки','<div class="registry-ui-modal-grid">'+alertMarkup('error','Часть записей требует внимания','Ниже перечислены причины и действия для каждой записи.','role="alert"')+'<p class="registry-ui-modal-text">Наименование: '+escape('ДемонстрационныйОбъектСОченьДлиннымНаименованиемДляПроверкиПереносаТекста'.repeat(3))+'</p>'+entries+'</div>','<button class="btn btn-primary" type="button" data-close>Понятно</button>','registry-ui-modal--reading');
    setStatus('Открыто длинное сообщение с прокруткой и переносом текста.');return true;
   }
   case 'confirm':
    launch(api,'confirm','Подтвердить действие','<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">Применить новые параметры к объекту <strong class="registry-ui-entity-id">#1005</strong> «Гамма»?</p><p class="registry-ui-modal-secondary">Количество: 5. Категория: A.</p></div>',api.closeButton+api.primary('Применить параметры','msg:confirm-action'),'registry-ui-modal--confirmation',null,'Применение параметров отменено.');
    setStatus('Открыт попап подтверждения действия.');return true;
   case 'confirm-action':if(activeDialog?.dataset.messageScenario==='confirm')complete(api,'success','Новые параметры объекта #1005 применены.');return true;
   case 'toast-success':api.toast('success','Изменения сохранены. Все данные обновлены.');setStatus('Вызвано зелёное системное уведомление.');return true;
   case 'toast-stack':
    api.toast('success','Объект «Альфа» сохранён.');api.toast('warning','Объект «Бета» требует проверки.');api.toast('danger','Объект «Гамма» не сохранён. Исправьте данные.');setIssue('Объект «Гамма» не сохранён. Повторите сохранение после проверки данных.');setStatus('Показаны три уведомления: новое сверху, предыдущие ниже.');return true;
   case 'toast-error':api.toast('danger','Не удалось завершить действие. Проверьте данные и повторите попытку.');setIssue('Не удалось завершить действие. Проверьте данные и повторите попытку.');setStatus('Вызвано красное системное уведомление.');return true;
   case 'toast-warning':api.toast('warning','Договор №173. Получить подписи обеих сторон.','Новая задача или уведомление');setStatus('Вызвано жёлтое системное уведомление.');return true;
   default:return false;
  }
 }
 const exports=Object.freeze({renderPage,handleAction,validateValues,getIssues});
 if(typeof module==='object'&&module.exports)module.exports=exports;
 else root.UIDemoMessages=exports;
})(typeof window==='object'?window:globalThis);
