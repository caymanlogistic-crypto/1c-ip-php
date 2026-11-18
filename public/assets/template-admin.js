'use strict';
(() => {
  const configNode=document.getElementById('template-admin-config');
  if(!configNode)return;
  const config=JSON.parse(configNode.dataset.config),dialog=document.getElementById('template-editor');
  const form=document.getElementById('template-editor-form'),body=document.getElementById('template-body');
  const secondary=form.querySelector('[name="subject"], [name="title"]'),format=document.getElementById('template-format');
  let preview=document.getElementById('template-preview');
  const error=document.getElementById('template-preview-error');
  const counter=document.getElementById('template-counter'),api=window.RentalNotificationTemplates;
  const save=form.querySelector('[type="submit"]'),popup=document.getElementById('template-show-popup');
  const fields=[body,secondary,format].filter(Boolean),original=fields.map(field=>field.value);
  let target=body,rendered=null;
  const dirty=()=>fields.some((field,i)=>field.value!==original[i]);
  function close(){if(dirty()&&!window.confirm('Закрыть редактор без сохранения изменений?'))return;location.assign(config.closeUrl);}
  form.querySelectorAll('[data-template-close]').forEach(button=>button.addEventListener('click',close));
  dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  const length=text=>Array.from(text).length;
  function validate(text,limit,singleLine=false){
    if(!text.trim())throw Error('Заполните обязательные поля.');
    if(length(text)>limit)throw Error('Превышен предел: '+limit+' символов.');
    if(/[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(text)||singleLine&&/[\r\n]/.test(text))throw Error('Заголовок или тема должны занимать одну строку; служебные символы недопустимы.');
    for(const match of text.matchAll(/\{([^{}]*)\}/g))if(!config.variables.includes(match[1]))throw Error('Неизвестная подстановка: {'+match[1]+'}. Выберите поле из списка подстановок.');
    if(/[{}]/.test(text.replace(/\{[^{}]+\}/g,'')))throw Error('Проверьте фигурные скобки подстановок.');
  }
  const replace=text=>text.replace(/\{([^{}]+)\}/g,(_,key)=>config.values[key]??'');
  function textBlock(text){const node=document.createElement('div');for(const [index,line] of text.split('\n').entries()){if(index)node.append(document.createElement('br'));node.append(document.createTextNode(line));}return node;}
  function refresh(){
    counter.textContent=length(body.value)+' / '+(config.channel==='top'?600:4000)+' символов в шаблоне';
    try{
      validate(body.value,config.channel==='top'?600:4000);
      if(secondary)validate(secondary.value,config.channel==='top'?80:200,true);
      preview.replaceChildren();
      if(config.channel==='top'){
        rendered={title:replace(secondary.value),body:replace(body.value)};
        validate(rendered.title,120,true);validate(rendered.body,1000);
        const title=document.createElement('strong');title.textContent=rendered.title;
        preview.append(title,textBlock(rendered.body));
      }else if(config.channel==='email'){
        rendered=api.renderEmail({subject:secondary.value,body:body.value},config.values);
        const title=document.createElement('strong');title.textContent=rendered.subject;
        preview.append(title,textBlock(rendered.body));
      }else{
        rendered={body:api.render(format.value,body.value,config.values)};
        api.previewInto(preview,format.value,rendered.body);
      }
      error.hidden=true;error.textContent='';save.disabled=false;if(popup)popup.disabled=false;return true;
    }catch(failure){rendered=null;preview.replaceChildren();error.hidden=false;error.textContent=failure.message;save.disabled=true;if(popup)popup.disabled=true;return false;}
  }
  for(const field of [body,secondary].filter(Boolean))field.addEventListener('focus',()=>{target=field;});
  form.querySelectorAll('[data-template-token]').forEach(button=>button.addEventListener('click',()=>{
    const token='{'+button.dataset.templateToken+'}',start=target.selectionStart??target.value.length,end=target.selectionEnd??start;
    target.setRangeText(token,start,end,'end');target.focus();target.dispatchEvent(new Event('input',{bubbles:true}));
  }));
  if(config.channel==='max'){
    const editor=api.mountEditor(body,{format:format.value,values:config.values});
    preview.replaceWith(editor.preview);preview=editor.preview;preview.id='template-preview';editor.counter.remove();
    body.setAttribute('aria-label','Текст сообщения');
    format.addEventListener('change',()=>editor.setFormat(format.value));
  }
  fields.forEach(field=>field.addEventListener('input',refresh));
  fields.forEach(field=>field.addEventListener('change',refresh));
  if(popup)popup.addEventListener('click',()=>{if(refresh())window.PlanexNotifications.show({tone:config.tone,title:rendered.title,message:rendered.body});});
  form.addEventListener('submit',event=>{if(!refresh()){event.preventDefault();error.focus();}else save.disabled=true;});
  dialog.showModal();refresh();
})();
