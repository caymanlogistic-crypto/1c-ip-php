/* Shared date picker: canonical value is ISO, display is DD.MM.YYYY. */
(function(root){
 'use strict';
 function makeDate(y,m,d){const date=new Date(2000,0,1,12);date.setFullYear(y,m,d);return date;}
 function iso(date){return String(date.getFullYear()).padStart(4,'0')+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0');}
 function parseISO(value){const m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(value||'');if(!m||Number(m[1])<1)return null;const date=makeDate(+m[1],+m[2]-1,+m[3]);return iso(date)===value?date:null;}
 function display(date){return String(date.getDate()).padStart(2,'0')+'.'+String(date.getMonth()+1).padStart(2,'0')+'.'+String(date.getFullYear()).padStart(4,'0');}
 function parseDisplay(value){const m=/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(value.trim());return m?parseISO(m[3]+'-'+m[2].padStart(2,'0')+'-'+m[1].padStart(2,'0')):null;}
 function monthDays(y,m){const first=makeDate(y,m,1),start=makeDate(y,m,1-((first.getDay()+6)%7));return Array.from({length:42},(_,i)=>makeDate(start.getFullYear(),start.getMonth(),start.getDate()+i));}
 const core=Object.freeze({parseISO,parseDisplay,iso,display,monthDays});
 if(typeof module==='object'&&module.exports)module.exports=core;
 if(typeof document!=='object')return;
 if(root.UIDatePicker?.enhance)return;
 const months=['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
 let active=null,serial=0;
 const instances=new Map(),suppressed=new WeakSet();
 function attach(original){
  if(instances.has(original))return;original.dataset.uiDateMounted='true';
  const saved={id:original.getAttribute('id'),className:original.className,type:original.type},initialDefault=original.defaultValue;
  const initial=parseISO(original.value),wrapper=document.createElement('span'),input=document.createElement('input'),toggle=document.createElement('button');
  wrapper.className='ui-date-field';input.type='text';input.className=original.className||'field-input';input.value=initial?display(initial):'';input.placeholder='ДД.ММ.ГГГГ';input.inputMode='numeric';input.autocomplete='off';input.defaultValue=input.value;
  for(const a of ['aria-label','aria-describedby'])if(original.hasAttribute(a))input.setAttribute(a,original.getAttribute(a));
  const oldId=original.id||'ui-date-'+(++serial);input.id=oldId;original.id=oldId+'-value';
  original.type='hidden';original.className='';original.after(wrapper);
  toggle.type='button';toggle.className='ui-date-toggle';toggle.disabled=input.disabled||input.readOnly;toggle.setAttribute('aria-label','Открыть календарь');toggle.setAttribute('aria-haspopup','dialog');toggle.setAttribute('aria-expanded','false');toggle.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/></svg>';
  wrapper.append(input,toggle);let popup=null,view=initial||new Date(),notifying=false;
  function allowed(date){const v=iso(date);return (!original.min||v>=original.min)&&(!original.max||v<=original.max);}
  function notify(){notifying=true;try{original.dispatchEvent(new Event('input',{bubbles:true}));original.dispatchEvent(new Event('change',{bubbles:true}));}finally{notifying=false;}}
  function invalid(message){input.setCustomValidity(message);input.setAttribute('aria-invalid',String(!!message));wrapper.classList.toggle('is-invalid',!!message);}
  function syncFlags(){input.disabled=original.disabled;input.readOnly=original.readOnly;input.required=original.required;toggle.disabled=input.disabled||input.readOnly;if(toggle.disabled)close();}
  function syncValue(){if(notifying)return;const date=parseISO(original.value);input.value=date?display(date):'';invalid(date&&!allowed(date)?'Выберите дату в допустимом диапазоне':'');}
  function commit(date){syncFlags();if(input.disabled||input.readOnly||(date&&!allowed(date)))return;original.value=date?iso(date):'';input.value=date?display(date):'';invalid('');notify();close(true);}
  function close(refocus=false){if(!popup)return;popup.remove();popup=null;toggle.setAttribute('aria-expanded','false');if(active?.wrapper===wrapper)active=null;if(refocus&&input.isConnected)input.focus();}
  function position(){if(!popup)return;const rect=wrapper.getBoundingClientRect(),width=popup.offsetWidth,height=popup.offsetHeight;popup.style.left=Math.max(8,Math.min(rect.left,innerWidth-width-8))+'px';popup.style.top=Math.max(8,rect.bottom+height+5<innerHeight?rect.bottom+5:rect.top-height-5)+'px';}
  function drawGrid(){if(!popup)return;const y=view.getFullYear(),m=view.getMonth(),selected=original.value,today=iso(new Date());popup.querySelector('.ui-calendar-grid').innerHTML=['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map(day=>'<span class="ui-calendar-weekday">'+day+'</span>').join('')+monthDays(y,m).map(date=>{const v=iso(date);return '<button class="ui-calendar-day'+(date.getMonth()!==m?' is-outside':'')+(v===selected?' is-selected':'')+(v===today?' is-today':'')+'" type="button" data-day="'+v+'" aria-label="'+display(date)+'" aria-pressed="'+(v===selected)+'"'+(!allowed(date)?' disabled':'')+'>'+date.getDate()+'</button>';}).join('');}
  function draw(focusISO){
   if(!popup)return;const y=view.getFullYear(),m=view.getMonth(),selected=original.value,today=iso(new Date());
   popup.innerHTML='<div class="ui-calendar-head"><button class="ui-calendar-nav" type="button" data-month-step="-1" aria-label="Предыдущий месяц">‹</button><select class="field-select" aria-label="Месяц">'+months.map((name,i)=>'<option value="'+i+'"'+(i===m?' selected':'')+'>'+name+'</option>').join('')+'</select><input class="field-input" type="number" min="1" max="9999" value="'+y+'" aria-label="Год"><button class="ui-calendar-nav" type="button" data-month-step="1" aria-label="Следующий месяц">›</button></div><div class="ui-calendar-grid" role="group" aria-label="Дни месяца">'+''+'</div><div class="ui-calendar-foot"><button type="button" data-date-clear>Очистить</button><button type="button" data-date-today>Сегодня</button><button type="button" data-date-close>Закрыть</button></div>';
   drawGrid();position();if(focusISO)popup.querySelector('[data-day="'+focusISO+'"]')?.focus();
  }
  function open(){
   syncFlags();if(input.disabled||input.readOnly)return;if(popup){close();return;}active?.close();view=parseISO(original.value)||new Date();popup=document.createElement('div');popup.className='ui-calendar';popup.id='ui-calendar-'+(++serial);popup.setAttribute('role','dialog');popup.setAttribute('aria-label','Выбор даты');popup.setAttribute('popover','manual');(original.closest('dialog')||document.body).append(popup);toggle.setAttribute('aria-controls',popup.id);toggle.setAttribute('aria-expanded','true');
   active={original,wrapper,close,contains:node=>wrapper.contains(node)||popup?.contains(node)};draw();if(popup.showPopover)popup.showPopover();position();
   popup.addEventListener('click',e=>{const b=e.target.closest('button');if(!b||b.disabled)return;e.preventDefault();if(b.dataset.day){commit(parseISO(b.dataset.day));return;}if(b.hasAttribute('data-month-step')){view=makeDate(view.getFullYear(),view.getMonth()+Number(b.dataset.monthStep),1);draw();}else if(b.hasAttribute('data-date-clear'))commit(null);else if(b.hasAttribute('data-date-today'))commit(new Date());else if(b.hasAttribute('data-date-close'))close(true);});
   popup.addEventListener('input',e=>{if(e.target.getAttribute('aria-label')==='Год'&&/^\d{4}$/.test(e.target.value)){const year=Number(e.target.value);if(year>0){view=makeDate(year,view.getMonth(),1);drawGrid();}}});
   popup.addEventListener('change',e=>{if(e.target.getAttribute('aria-label')==='Месяц'){view=makeDate(view.getFullYear(),Number(e.target.value),1);drawGrid();}if(e.target.getAttribute('aria-label')==='Год'){const year=Number(e.target.value);if(Number.isInteger(year)&&year>0&&year<10000){view=makeDate(year,view.getMonth(),1);drawGrid();}}});
   popup.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close(true);return;}const day=e.target.closest('[data-day]');if(!day)return;let date=parseISO(day.dataset.day),delta={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7}[e.key];if(e.key==='Home')delta=-((date.getDay()+6)%7);if(e.key==='End')delta=6-((date.getDay()+6)%7);if(delta!==undefined)date=makeDate(date.getFullYear(),date.getMonth(),date.getDate()+delta);else if(e.key==='PageUp'||e.key==='PageDown')date=makeDate(date.getFullYear(),date.getMonth()+(e.key==='PageUp'?-1:1),1);else return;e.preventDefault();view=date;draw(iso(date));});
   popup.querySelector('[data-day="'+original.value+'"]')?.focus();
  }
  toggle.addEventListener('click',e=>{e.preventDefault();open();});
  input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'&&e.altKey){e.preventDefault();open();}if(e.key==='Escape')close();});
  function manualInput(normalize=false){
   syncFlags();if(input.disabled||input.readOnly){syncValue();return;}
   const old=original.value,date=parseDisplay(input.value);
   if(!input.value.trim()){original.value='';invalid('');if(old)notify();return;}
   if(!date||!allowed(date)){original.value='';invalid('Введите допустимую дату в формате ДД.ММ.ГГГГ');if(old)notify();return;}
   original.value=iso(date);if(normalize)input.value=display(date);invalid('');if(old!==original.value)notify();
  }
  input.addEventListener('input',()=>manualInput());
  input.addEventListener('change',()=>manualInput(true));
  original.addEventListener('input',syncValue);original.addEventListener('change',syncValue);
  const observer=new MutationObserver(records=>{syncFlags();if(records.some(record=>['min','max'].includes(record.attributeName)))manualInput(false);if(popup)draw();});
  observer.observe(original,{attributes:true,attributeFilter:['disabled','readonly','required','min','max']});
  const form=original.form;
  function reset(){original.value=initialDefault||'';syncValue();close();}
  const onReset=event=>queueMicrotask(()=>{if(!event.defaultPrevented&&instances.has(original))reset();});
  form?.addEventListener('reset',onReset);
  function destroy(suppress=true){
   if(suppress)suppressed.add(original);
   close();observer.disconnect();form?.removeEventListener('reset',onReset);
   original.removeEventListener('input',syncValue);original.removeEventListener('change',syncValue);
   const currentValue=original.value;wrapper.remove();original.type=saved.type;original.className=saved.className;original.defaultValue=initialDefault;original.value=currentValue;
   if(saved.id===null)original.removeAttribute('id');else original.id=saved.id;
   delete original.dataset.uiDateMounted;instances.delete(original);
  }
  instances.set(original,{sync(){syncFlags();syncValue();},reset,destroy});syncFlags();syncValue();

 }
 function candidates(scope,type){return [...(scope.matches?.('input[type="'+type+'"]')?[scope]:[]),...scope.querySelectorAll('input[type="'+type+'"]')];}
 function enhance(scope=document){candidates(scope,'date').forEach(original=>{suppressed.delete(original);attach(original);});}
 function autoEnhance(){candidates(document,'date').filter(original=>!suppressed.has(original)).forEach(attach);}
 function each(scope,method){for(const [original,instance] of instances)if(scope===original||scope.contains(original))instance[method]();}
 function sync(scope=document){each(scope,'sync');}
 function reset(scope=document){each(scope,'reset');}
 function destroy(scope=document){each(scope,'destroy');}
 function init(){autoEnhance();new MutationObserver(()=>{for(const [original,instance] of instances)if(!original.isConnected)instance.destroy(false);autoEnhance();}).observe(document.body,{childList:true,subtree:true});document.addEventListener('pointerdown',e=>{if(active&&!active.contains(e.target))active.close();});document.addEventListener('keydown',e=>{if(e.key==='Escape')active?.close(true);if(e.key==='Tab')requestAnimationFrame(()=>{if(active&&!active.contains(document.activeElement))active.close();});});window.addEventListener('resize',()=>active?.close());}
 root.UIDatePicker=Object.freeze({...core,enhance,sync,reset,destroy});if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})(typeof window==='object'?window:globalThis);
