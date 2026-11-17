/* Shared time picker: visible HH:mm, canonical named field HH:mm. */
(function(root){
 'use strict';
 function parseTime(value){const m=/^(\d{1,2}):([0-5]\d)$/.exec(String(value??'').trim());if(!m||+m[1]>23)return null;return +m[1]*60+(+m[2]);}
 function formatTime(value){return Number.isInteger(value)&&value>=0&&value<1440?String(Math.floor(value/60)).padStart(2,'0')+':'+String(value%60).padStart(2,'0'):'';}
 function parseSeconds(value){const m=/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/.exec(String(value??'').trim());return m&&+m[1]<24?(+m[1])*3600+(+m[2])*60+(+(m[3]||0)):null;}
 function timeSettings(values={}){
  const min=parseSeconds(values.min),max=parseSeconds(values.max),initial=parseSeconds(values.value);
  const step=values.step==='any'?null:Number(values.step)>0?Number(values.step):60;
  return {min,max,step,base:min??initial??0};
 }
 function isAllowed(value,settings={}){
  if(!Number.isInteger(value)||value<0||value>=1440)return false;
  const second=value*60,min=settings.min??null,max=settings.max??null;
  if(min!==null&&max!==null&&min>max){if(second<min&&second>max)return false;}
  else if((min!==null&&second<min)||(max!==null&&second>max))return false;
  if(settings.step!==null&&settings.step!==undefined){const remainder=((second-(settings.base||0))%settings.step+settings.step)%settings.step;if(Math.min(remainder,settings.step-remainder)>.00001)return false;}
  return true;
 }
 function nearestAllowed(value,settings={}){
  const target=Number.isFinite(value)?Math.max(0,Math.min(1439,Math.round(value))):0;
  for(let distance=0;distance<1440;distance++){if(isAllowed(target-distance,settings))return target-distance;if(distance&&isAllowed(target+distance,settings))return target+distance;}
  return null;
 }
 const core=Object.freeze({parseTime,formatTime,parseSeconds,timeSettings,isAllowed,nearestAllowed});
 if(typeof module==='object'&&module.exports)module.exports=core;
 if(typeof document!=='object')return;
 if(root.UITimePicker?.enhance)return;
 let active=null,serial=0;
 const instances=new Map(),suppressed=new WeakSet();
 const pad=n=>String(n).padStart(2,'0');
 function attach(original){
  if(instances.has(original))return;
  original.dataset.uiTimeMounted='true';
  const saved={id:original.getAttribute('id'),className:original.className,type:original.type},initialValue=original.value,initialAttribute=original.getAttribute('value')||'';
  const wrapper=document.createElement('span'),input=document.createElement('input'),toggle=document.createElement('button');
  wrapper.className='ui-time-field';input.type='text';input.className=original.className||'field-input';input.value=formatTime(parseTime(initialValue));input.defaultValue=input.value;input.placeholder='ЧЧ:ММ';input.inputMode='numeric';input.autocomplete='off';input.spellcheck=false;
  for(const name of ['aria-label','aria-describedby','title'])if(original.hasAttribute(name))input.setAttribute(name,original.getAttribute(name));
  const id=original.id||'ui-time-'+(++serial);input.id=id;original.id=id+'-value';
  original.type='hidden';original.className='';original.after(wrapper);
  toggle.type='button';toggle.className='ui-time-toggle';toggle.setAttribute('aria-label','Открыть выбор времени');toggle.setAttribute('aria-haspopup','dialog');toggle.setAttribute('aria-expanded','false');
  toggle.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  wrapper.append(input,toggle);
  let popup=null,draft=null,settings=null,notifying=false;
  const constraints=()=>timeSettings({min:original.min,max:original.max,step:original.getAttribute('step'),value:initialAttribute});
  function syncFlags(){input.disabled=original.disabled;input.readOnly=original.readOnly;input.required=original.required;toggle.disabled=input.disabled||input.readOnly;if(toggle.disabled)close();}
  function notify(){notifying=true;try{original.dispatchEvent(new Event('input',{bubbles:true}));original.dispatchEvent(new Event('change',{bubbles:true}));}finally{notifying=false;}}
  function invalid(message){input.setCustomValidity(message);input.setAttribute('aria-invalid',String(!!message));wrapper.classList.toggle('is-invalid',!!message);}
  function manual(normalize=false){
   syncFlags();if(input.disabled||input.readOnly){syncValue();return;}
   const old=original.value,value=parseTime(input.value);
   if(!input.value.trim()){original.value='';invalid('');if(old)notify();return;}
   if(value===null){original.value='';invalid('Введите время от 00:00 до 23:59 в формате ЧЧ:ММ');if(old)notify();return;}
   if(!isAllowed(value,constraints())){original.value='';invalid('Выберите время в допустимом диапазоне и с заданным шагом');if(old)notify();return;}
   original.value=formatTime(value);if(normalize)input.value=original.value;invalid('');if(old!==original.value)notify();
  }
  function syncValue(){if(notifying)return;const value=parseTime(original.value);input.value=formatTime(value);invalid(value!==null&&!isAllowed(value,constraints())?'Выберите время в допустимом диапазоне и с заданным шагом':'');}
  function close(refocus=false){if(!popup)return;popup.remove();popup=null;toggle.setAttribute('aria-expanded','false');if(active?.wrapper===wrapper)active=null;if(refocus&&input.isConnected)input.focus();}
  function commit(value){syncFlags();if(input.disabled||input.readOnly||(value!==null&&!isAllowed(value,constraints())))return;original.value=value===null?'':formatTime(value);input.value=original.value;invalid('');notify();close(true);}
  function position(){
   if(!popup)return;const r=wrapper.getBoundingClientRect(),w=popup.offsetWidth,h=popup.offsetHeight;
   popup.style.left=Math.max(8,Math.min(r.left,innerWidth-w-8))+'px';
   popup.style.top=Math.max(8,r.bottom+h+5<innerHeight?r.bottom+5:r.top-h-5)+'px';
  }
  function hourAllowed(hour){for(let m=0;m<60;m++)if(isAllowed(hour*60+m,settings))return true;return false;}
  function updateSelection(scroll=false){
   if(!popup)return;
   const hour=draft===null?null:Math.floor(draft/60),minute=draft===null?null:draft%60;
   popup.querySelector('[data-time-preview]').textContent=draft===null?'—:—':formatTime(draft);
   popup.querySelector('[data-time-apply]').disabled=draft===null;
   popup.querySelector('[data-time-empty]').hidden=draft!==null;
   popup.querySelectorAll('[data-time-hour]').forEach(b=>{const n=Number(b.dataset.timeHour),selected=n===hour;b.disabled=!hourAllowed(n);b.classList.toggle('is-selected',selected);b.setAttribute('aria-selected',String(selected));b.tabIndex=selected?0:-1;});
   popup.querySelectorAll('[data-time-minute]').forEach(b=>{const n=Number(b.dataset.timeMinute),selected=n===minute;b.disabled=hour===null||!isAllowed(hour*60+n,settings);b.classList.toggle('is-selected',selected);b.setAttribute('aria-selected',String(selected));b.tabIndex=selected?0:-1;});
   const now=new Date(),nowMinute=now.getHours()*60+now.getMinutes();popup.querySelector('[data-time-now]').disabled=!isAllowed(nowMinute,settings);
   if(scroll)for(const part of ['hour','minute'])popup.querySelector('[data-time-'+part+'].is-selected')?.scrollIntoView({block:'nearest'});
  }
  function choose(part,n){
   if(draft===null)return;
   if(part==='minute'){const candidate=Math.floor(draft/60)*60+n;if(isAllowed(candidate,settings))draft=candidate;}
   else{
    const oldMinute=draft%60;let candidate=null;
    for(let distance=0;distance<60;distance++){for(const m of [oldMinute-distance,oldMinute+distance])if(m>=0&&m<60&&isAllowed(n*60+m,settings)){candidate=n*60+m;break;}if(candidate!==null)break;}
    if(candidate!==null)draft=candidate;
   }
   updateSelection();
  }
  function open(){
   syncFlags();if(input.disabled||input.readOnly)return;if(popup){close();return;}active?.close();
   settings=constraints();const now=new Date();draft=nearestAllowed(parseTime(original.value)??(now.getHours()*60+now.getMinutes()),settings);
   popup=document.createElement('div');popup.className='ui-time-picker';popup.id='ui-time-popup-'+(++serial);popup.setAttribute('role','dialog');popup.setAttribute('aria-label','Выбор времени');popup.setAttribute('popover','manual');
   const hoursId=popup.id+'-hours',minutesId=popup.id+'-minutes';
   popup.innerHTML='<div class="ui-time-head"><span class="ui-time-heading">Выбор времени</span><output class="ui-time-preview" data-time-preview aria-live="polite"></output><button class="ui-time-close" type="button" data-time-close aria-label="Закрыть выбор времени">×</button></div><div class="ui-time-columns"><div><div class="ui-time-label" id="'+hoursId+'">Часы</div><div class="ui-time-list" role="listbox" aria-labelledby="'+hoursId+'">'+Array.from({length:24},(_,n)=>'<button type="button" class="ui-time-option" role="option" data-time-hour="'+n+'" aria-label="Час '+pad(n)+'">'+pad(n)+'</button>').join('')+'</div></div><div><div class="ui-time-label" id="'+minutesId+'">Минуты</div><div class="ui-time-list" role="listbox" aria-labelledby="'+minutesId+'">'+Array.from({length:60},(_,n)=>'<button type="button" class="ui-time-option" role="option" data-time-minute="'+n+'" aria-label="Минута '+pad(n)+'">'+pad(n)+'</button>').join('')+'</div></div></div><p class="field-msg is-error ui-time-empty" data-time-empty hidden>Нет доступного времени в заданных ограничениях.</p><div class="ui-time-footer"><button class="btn btn-ghost" type="button" data-time-clear>Очистить</button><button class="btn btn-ghost" type="button" data-time-now>Сейчас</button><button class="btn btn-primary ui-time-apply" type="button" data-time-apply>Применить</button></div>';
   (original.closest('dialog')||document.body).append(popup);toggle.setAttribute('aria-controls',popup.id);toggle.setAttribute('aria-expanded','true');
   active={original,wrapper,close,contains:node=>wrapper.contains(node)||popup?.contains(node)};updateSelection();if(popup.showPopover)popup.showPopover();position();updateSelection(true);
   popup.addEventListener('click',e=>{const b=e.target.closest('button');if(!b||b.disabled)return;e.preventDefault();if(b.hasAttribute('data-time-hour'))choose('hour',Number(b.dataset.timeHour));else if(b.hasAttribute('data-time-minute'))choose('minute',Number(b.dataset.timeMinute));else if(b.hasAttribute('data-time-apply'))commit(draft);else if(b.hasAttribute('data-time-clear'))commit(null);else if(b.hasAttribute('data-time-now')){const d=new Date();commit(d.getHours()*60+d.getMinutes());}else if(b.hasAttribute('data-time-close'))close(true);});
   popup.addEventListener('keydown',e=>{
    if(e.key==='Escape'){e.preventDefault();close(true);return;}
    const b=e.target.closest('[data-time-hour],[data-time-minute]');if(!b)return;
    if(e.key==='Enter'){e.preventDefault();commit(draft);return;}
    const part=b.hasAttribute('data-time-hour')?'hour':'minute',other=part==='hour'?'minute':'hour';
    if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();popup.querySelector('[data-time-'+other+'].is-selected')?.focus();return;}
    const enabled=Array.from(popup.querySelectorAll('[data-time-'+part+']:not(:disabled)'));let index=enabled.indexOf(b),next=index;
    if(e.key==='ArrowUp')next=index-1;else if(e.key==='ArrowDown')next=index+1;else if(e.key==='PageUp')next=index-5;else if(e.key==='PageDown')next=index+5;else if(e.key==='Home')next=0;else if(e.key==='End')next=enabled.length-1;else return;
    e.preventDefault();next=Math.max(0,Math.min(enabled.length-1,next));const target=enabled[next];if(target){choose(part,Number(target.dataset[part==='hour'?'timeHour':'timeMinute']));target.focus();target.scrollIntoView({block:'nearest'});}
   });
   (popup.querySelector('[data-time-hour].is-selected')||popup.querySelector('[data-time-close]')).focus();
  }
  toggle.addEventListener('click',e=>{e.preventDefault();open();});
  input.addEventListener('input',()=>manual());input.addEventListener('change',()=>manual(true));
  input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'&&e.altKey){e.preventDefault();open();}else if(e.key==='Escape')close();});
  original.addEventListener('input',syncValue);original.addEventListener('change',syncValue);
  const observer=new MutationObserver(records=>{syncFlags();if(records.some(record=>['min','max','step'].includes(record.attributeName)))manual(false);if(popup){settings=constraints();draft=nearestAllowed(draft,settings);updateSelection();position();}});observer.observe(original,{attributes:true,attributeFilter:['disabled','readonly','required','min','max','step']});
  const form=original.form;
  function reset(){original.value=initialAttribute;syncValue();close();}
  const onReset=event=>queueMicrotask(()=>{if(!event.defaultPrevented&&instances.has(original))reset();});
  form?.addEventListener('reset',onReset);
  function destroy(suppress=true){
   if(suppress)suppressed.add(original);
   close();observer.disconnect();form?.removeEventListener('reset',onReset);
   original.removeEventListener('input',syncValue);original.removeEventListener('change',syncValue);
   const currentValue=original.value;wrapper.remove();original.type=saved.type;original.className=saved.className;original.defaultValue=initialAttribute;original.value=currentValue;
   if(saved.id===null)original.removeAttribute('id');else original.id=saved.id;
   delete original.dataset.uiTimeMounted;instances.delete(original);
  }
  instances.set(original,{sync(){syncFlags();syncValue();},reset,destroy});syncFlags();syncValue();

 }
 function candidates(scope,type){return [...(scope.matches?.('input[type="'+type+'"]')?[scope]:[]),...scope.querySelectorAll('input[type="'+type+'"]')];}
 function enhance(scope=document){candidates(scope,'time').forEach(original=>{suppressed.delete(original);attach(original);});}
 function autoEnhance(){candidates(document,'time').filter(original=>!suppressed.has(original)).forEach(attach);}
 function each(scope,method){for(const [original,instance] of instances)if(scope===original||scope.contains(original))instance[method]();}
 function sync(scope=document){each(scope,'sync');}
 function reset(scope=document){each(scope,'reset');}
 function destroy(scope=document){each(scope,'destroy');}
 function init(){
  autoEnhance();new MutationObserver(()=>{for(const [original,instance] of instances)if(!original.isConnected)instance.destroy(false);autoEnhance();}).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('pointerdown',e=>{if(active&&!active.contains(e.target))active.close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')active?.close(true);if(e.key==='Tab')requestAnimationFrame(()=>{if(active&&!active.contains(document.activeElement))active.close();});});
  window.addEventListener('resize',()=>active?.close());
 }
 root.UITimePicker=Object.freeze({...core,enhance,sync,reset,destroy});if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})(typeof window==='object'?window:globalThis);
