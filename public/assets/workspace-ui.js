'use strict';
(() => {
  const navigation=document.getElementById('navigation');
  let menu=null;
  const notices=window.RentalNotices?.create({storage:window.localStorage,component:window.PlanexNotifications});
  const noticeBootstrap=document.getElementById('rental-notices-bootstrap');
  if(noticeBootstrap)notices?.update(JSON.parse(noticeBootstrap.textContent));
  window.addEventListener('rental:notices',event=>notices?.update(event.detail));
  const tones=['is-alert','is-warning','is-success','is-info'],colors=['red','yellow','green','blue'],labelSets=JSON.parse(navigation?.dataset.countLabels||'{}');
  function badges(link,counts,sub){
    link.querySelector(':scope > .nav-counts')?.remove();
    const box=document.createElement('span');
    box.className='nav-counts';
    const labels=labelSets[link.dataset.page]||labelSets.default||[];
    for(let i=0;i<4;i++){
      const n=Number(counts?.[i]??0);
      if(!Number.isSafeInteger(n)||n<=0)continue;
      const badge=document.createElement('span');
      badge.className=(sub?'sub-count':'nav-count')+' '+tones[i];
      badge.dataset.attention=colors[i];
      badge.setAttribute('aria-label',labels[i]+': '+n);
      badge.title=labels[i]+': '+n;
      badge.textContent=String(n);
      box.append(badge);
    }
    if(box.children.length){
      const chevron=link.querySelector('.nav-chevron');
      if(chevron)link.insertBefore(box,chevron);
      else link.append(box);
    }
  }
  if(navigation?.dataset.userId&&window.UIMenuCounts){
    menu=window.UIMenuCounts.createController({
      projectId:'f6e21e3c-d974-4604-82fb-333da66559d2',surface:'php-rental',identityProvider:()=>navigation.dataset.userId,folderIds:[...navigation.querySelectorAll('[data-folder]')].map(el=>el.dataset.folder),readCounts:async()=>{
        const r=await fetch('/api/menu-counts',{
          headers:{
            Accept:'application/json'
          }
        });
        if(!r.ok||!r.headers.get('content-type')?.includes('application/json'))return null;
        const value=await r.json();
        notices?.update(value.notices);
        return value;
      },applyCounts:value=>{
        for(const link of navigation.querySelectorAll('[data-page]'))badges(link,value.pages?.[link.dataset.page],true);
        for(const link of navigation.querySelectorAll('[data-folder]'))badges(link,value.folders?.[link.dataset.folder],false);
      }
    });
    menu.mount(navigation).start();
  }
  const localURL=value=>{
    const url=new URL(value,location.href);
    if(url.origin!==location.origin)throw new Error('Недоступный адрес.');
    return url;
  };
  const parse=text=>new DOMParser().parseFromString(text,'text/html');
  const notify=message=>window.PlanexNotifications?.show({
    tone:'danger',message
  });
  let lastFocus=null,activeRental=null;
  const rentalStack=[],availabilityRequests=new WeakMap();
  function disposeRental(dialog){
    if(!dialog)return;
    window.RentalPickers?.destroy(dialog);
    window.UIDatePicker?.destroy(dialog);
    if(dialog.open)dialog.close();
    dialog.remove();
    if(activeRental===dialog)activeRental=null;
  }
  function closeRental(dialog){
    if(!dialog)return;
    const fallback=dialog.dataset.rentalReturn,autopen=dialog.hasAttribute('data-rental-autopen');
    disposeRental(dialog);
    if(rentalStack.length){
      const saved=rentalStack.pop();
      activeRental=saved.dialog;
      lastFocus=saved.lastFocus;
      activeRental.showModal();
      const body=activeRental.querySelector('.modal-body');
      if(body)body.scrollTop=saved.scroll;
      saved.opener?.isConnected&&saved.opener.focus({
        preventScroll:true
      });
      return;
    }
    if(lastFocus?.isConnected)lastFocus.focus({
      preventScroll:true
    });
    if(autopen&&fallback)location.assign(localURL(fallback));
  }
  function mountRental(dialog,opener,preserveParent=false){
    if(activeRental){
      if(preserveParent){
        const parent=activeRental;
        rentalStack.push({
          dialog:parent,opener,lastFocus,scroll:parent.querySelector('.modal-body')?.scrollTop||0
        });
        parent.close();
      }
      else disposeRental(activeRental);
    }
    lastFocus=opener?.isConnected?opener:document.activeElement;
    document.getElementById('dialog-root').append(dialog);
    activeRental=dialog;
    if(rentalStack.length){
      const dismiss=dialog.querySelector('[data-rental-dismiss]');
      if(dismiss)dismiss.textContent='Вернуться';
      dialog.querySelector('.modal-close[data-rental-close]')?.setAttribute('aria-label','Вернуться');
      for(const button of dialog.querySelectorAll('.modal-foot [data-rental-open]')){
        if(button.textContent.trim().startsWith('Вернуться'))button.hidden=true;
      }
    }
    dialog.addEventListener('cancel',event=>{
      event.preventDefault();
      closeRental(dialog);
    });
    dialog.showModal();
    window.RentalPickers?.enhance(dialog);
    window.RentalPartyUI?.enhance(dialog);
  }
  async function openRental(value,opener,preserveParent=!!opener?.closest('dialog[data-rental-dialog]')){
    try{
      const response=await fetch(localURL(value),{
        headers:{
          'X-Requested-With':'ERP-Fetch'
        }
      });
      const doc=parse(await response.text());
      const dialog=doc.querySelector('dialog[data-rental-dialog]');
      if(!response.ok||!dialog)throw new Error(doc.querySelector('[role="alert"]')?.textContent.trim()||'Не удалось открыть форму. Обновите страницу и войдите снова.');
      mountRental(dialog,opener,preserveParent);
    }
    catch(error){
      notify(error.message);
    }
  }
  function createdParty(value,dialog){
    const parent=rentalStack.at(-1)?.dialog,select=parent?.querySelector('select[data-rental-picker="party"],select[name="party_id"]');
    if(!select||!/^\d+$/.test(String(value?.id))||typeof value.name!=='string')throw new Error('Не удалось вернуть контрагента в исходную форму.');
    let option=[...select.options].find(item=>item.value===String(value.id));
    if(!option){
      option=document.createElement('option');
      option.value=String(value.id);
      select.append(option);
    }
    option.textContent=value.name;
    option.dataset.name=value.name;
    option.dataset.description=typeof value.description==='string'?value.description:'';
    option.dataset.search=[value.name,option.dataset.description].join(' ');
    select.value=String(value.id);
    select.dispatchEvent(new Event('change',{
      bubbles:true
    }));
    window.RentalPickers?.refresh(parent);
    closeRental(dialog);
    select.nextElementSibling?.querySelector('.cs-trigger')?.focus({
      preventScroll:true
    });
  }
  async function refreshAvailability(form){
    const value=form.dataset.rentalAvailabilityUrl;
    if(!value)return;
    availabilityRequests.get(form)?.abort();
    const abort=new AbortController();
    availabilityRequests.set(form,abort);
    try{
      const url=localURL(value);
      for(const [field,param]of [['start_date','start'],['end_date','end']])url.searchParams.set(param,form.elements.namedItem(field)?.value||'');
      const response=await fetch(url,{
        signal:abort.signal,headers:{
          Accept:'application/json'
        }
      });
      if(!response.ok)throw new Error('Не удалось обновить доступность помещений.');
      const data=await response.json();
      if(!Array.isArray(data.items)||data.items.length>5000)throw new Error('Некорректный ответ справочника помещений.');
      if(!form.isConnected)return;
      for(const select of form.querySelectorAll('select[data-rental-picker="room"],select[data-rental-object-picker]')){
        const selected=select.value;
        select.replaceChildren(new Option('Выберите помещение',''));
        for(const item of data.items){
          if(!/^\d+$/.test(String(item.id))||typeof item.name!=='string')throw new Error('Некорректная запись помещения.');
          const option=new Option(item.label||item.name,String(item.id));
          option.dataset.name=item.name;
          option.dataset.description=item.description||'';
          option.dataset.search=item.label||[item.name,item.description||''].join(' ');
          option.dataset.available=String(item.available===true);
          option.dataset.type=item.type||'';
          option.dataset.area=item.area??'';
          option.disabled=item.disabled===true;
          select.append(option);
        }
        select.value=selected;
        window.RentalPickers?.refresh(form);
      }
    }
    catch(error){
      if(error.name!=='AbortError')formError(form.closest('dialog'),error.message);
    }
  }
  function formError(dialog,message){
    let host=dialog.querySelector('[data-rental-form-error]');
    if(!host){
      host=document.createElement('div');
      host.dataset.rentalFormError='';
      host.className='form-alert alert-error';
      host.setAttribute('role','alert');
      const mark=document.createElement('span');
      mark.className='alert-mark';
      mark.setAttribute('aria-hidden','true');
      mark.textContent='×';
      const body=document.createElement('div');
      body.className='alert-body';
      const text=document.createElement('div');
      text.className='alert-body-title';
      body.append(text);
      host.append(mark,body);
      dialog.querySelector('.modal-body')?.prepend(host);
    }
    host.querySelector('.alert-body-title').textContent=message;
  }
  async function finishRental(value,savedInvoice){
    const applied=await window.RentalRegistry.load(value,{
      completion:true,savedInvoice
    });
    if(!applied)return;
    if(activeRental){
      activeRental.removeAttribute('data-rental-autopen');
      disposeRental(activeRental);
    }
    while(rentalStack.length)disposeRental(rentalStack.pop().dialog);
    lastFocus=null;
    document.getElementById('workspace').focus({
      preventScroll:true
    });
    menu?.requestRefresh();
  }
  async function submitRental(form,dialog,submitter){
    if(form.dataset.submitting)return;
    const data=new FormData(form,submitter);
    form.dataset.submitting='true';
    const buttons=[...dialog.querySelectorAll('button[type="submit"]')];
    buttons.forEach(b=>b.disabled=true);
    try{
      const method=(submitter?.getAttribute('formmethod')||form.getAttribute('method')||'post').toUpperCase(),requestURL=localURL(submitter?.getAttribute('formaction')||form.getAttribute('action')||location.href);
      if(method==='GET'){
        for(const [key,value]of data)if(key!=='_csrf'&&typeof value==='string')requestURL.searchParams.append(key,value);
      }
      const response=await fetch(requestURL,{
        method,body:method==='GET'?undefined:data,headers:{
          'X-Requested-With':'ERP-Fetch',
          'X-Rental-Workspace-URL':window.RentalRegistry.currentWorkspace()
        }
      });
      const type=response.headers.get('content-type')||'',completion=response.headers.get('X-Rental-Completion-URL');
      const refresh=response.headers.get('X-Rental-Refresh-URL'),savedInvoice=response.headers.get('X-Rental-Saved-Invoice');
      if(response.ok&&(response.headers.get('content-disposition')?.includes('attachment')||type.includes('application/pdf')||type.includes('application/vnd.openxmlformats'))){
        const blob=await response.blob();
        const url=URL.createObjectURL(blob);
        const link=document.createElement('a');
        link.href=url;
        link.download=type.includes('pdf')?'document.pdf':'document.docx';
        link.click();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
        return;
      }
      if(response.ok&&completion&&!type.includes('application/json')){
        await finishRental(completion,savedInvoice);
        return;
      }
      if(type.includes('application/json')){
        const result=await response.json();
        if(response.ok&&result.createdParty){
          createdParty(result.createdParty,dialog);
          menu?.requestRefresh();
          return;
        }
        if(response.ok&&completion){
          await finishRental(completion,savedInvoice);
          return;
        }
        throw new Error(result.message||'Не удалось сохранить форму.');
      }
      const doc=parse(await response.text());
      if(!response.ok)throw new Error(doc.querySelector('[role="alert"]')?.textContent.trim()||'Не удалось сохранить. Проверьте доступ и повторите попытку.');
      const replacement=doc.querySelector('dialog[data-rental-dialog]');
      if(replacement){
        if(refresh) await window.RentalRegistry.load(refresh,{completion:true,preserve:true,savedInvoice});
        // A committed command replaces its obsolete navigation context.
        // Pure reads keep the original DOM, including unsaved fields, in rentalStack.
        while(rentalStack.length)disposeRental(rentalStack.pop().dialog);
        mountRental(replacement,lastFocus);
        menu?.requestRefresh();
        return;
      }
      const workspace=doc.querySelector('#workspace');
      if(!workspace)throw new Error('Сеанс завершён. Войдите снова.');
      dialog.removeAttribute('data-rental-autopen');
      closeRental(dialog);
      for(const root of document.querySelectorAll('#workspace [data-registry-id]'))window.PlanexRegistry?.unmount(root);
      document.getElementById('workspace').replaceChildren(...workspace.childNodes);
      for(const root of document.querySelectorAll('#workspace [data-registry-id]'))window.PlanexRegistry?.mount(root,{
        managedMarkup:root.hasAttribute('data-registry-managed-markup')
      });
      const url=localURL(response.url);
      history.replaceState(null,'',url.pathname+url.search);
      menu?.requestRefresh();
    }
    catch(error){
      formError(dialog,error.message);
    }
    finally{
      delete form.dataset.submitting;
      buttons.forEach(b=>b.disabled=false);
    }
  }
  function total(form){
    let cents=0n;
    for(const input of form.querySelectorAll('input[name^="term_amount["]')){
      const value=input.value.trim().replace(',','.');
      if(/^term_amount\[part_[a-f0-9]{64}\]$/.test(input.name)){
        if(!/^\d{1,16}(?:\.\d{1,12})?$/.test(value))continue;
        const [rub,fraction='']=value.split('.');
        cents+=BigInt(rub)*100n+BigInt(fraction.padEnd(2,'0').slice(0,2))+(fraction.length>2&&fraction[2]>='5'?1n:0n);
        continue;
      }
      if(!/^\d+(?:\.\d{0,2})?$/.test(value))continue;
      const [rub,fraction='']=value.split('.');
      cents+=BigInt(rub)*100n+BigInt(fraction.padEnd(2,'0'));
    }
    const rub=(cents/100n).toString(),fraction=(cents%100n).toString().padStart(2,'0');
    const field=form.querySelector('[name="amount"]');
    if(field)field.value=rub+'.'+fraction;
    const display=form.querySelector('[data-rental-contract-total]');
    if(display)display.textContent=(display.dataset.rentalTotalPrefix||'')+rub.replace(/\B(?=(\d{3})+(?!\d))/g,'\u00a0')+','+fraction+(display.hasAttribute('data-rental-total-prefix')?' ₽':'');
    const body=form.querySelector('[data-rental-terms]'),count=body?.querySelectorAll('input[name=\"objects[]\"]').length||0;
    for(const label of form.querySelectorAll('[data-rental-object-count]'))label.textContent=String(count);
    if(body&&!count&&!body.querySelector('[data-rental-empty-terms]')){
      const tr=document.createElement('tr'),td=document.createElement('td');
      tr.dataset.rentalEmptyTerms='';
      td.colSpan=body.closest('table').querySelectorAll('thead th').length||3;
      td.textContent='Помещения пока не добавлены.';
      tr.append(td);
      body.append(tr);
    }
  }
  function addObject(form){
    const picker=form.querySelector('[data-rental-object-picker]'),option=picker?.selectedOptions[0],template=form.querySelector('template[data-rental-term-template]'),body=form.querySelector('[data-rental-terms]');
    if(!option||!template||!body||!/^\d+$/.test(option.value))return;
    if(option.disabled){
      notify('Помещение недоступно для выбранного периода.');
      return;
    }
    const id=option.value;
    if([...body.querySelectorAll('[name="objects[]"]')].some(input=>input.value===id)){
      notify('Объект уже добавлен.');
      return;
    }
    const fragment=template.content.cloneNode(true);
    const walker=document.createTreeWalker(fragment,NodeFilter.SHOW_TEXT);
    while(walker.nextNode())walker.currentNode.textContent=walker.currentNode.textContent.replaceAll('__ID__',id).replaceAll('__NAME__',option.dataset.name||option.textContent).replaceAll('__TYPE__',option.dataset.type||'');
    for(const el of fragment.querySelectorAll('*'))for(const attr of [...el.attributes])if(attr.value.includes('__ID__'))el.setAttribute(attr.name,attr.value.replaceAll('__ID__',id));
    for(const el of fragment.querySelectorAll('[data-rental-term-name]'))el.textContent=option.dataset.name||option.textContent;
    for(const el of fragment.querySelectorAll('[data-rental-term-type]'))el.textContent=option.dataset.type||'';
    for(const el of fragment.querySelectorAll('[data-rental-term-area]'))el.textContent=option.dataset.area||'—';
    for(const empty of body.querySelectorAll('[data-rental-empty-terms]'))empty.remove();
    if(form.hasAttribute('data-rental-addendum-form')){
      for(const [prefix,name] of [['term_start[','start_date'],['term_end[','end_date']]){
        const boundary=form.querySelector('[name="'+name+'"]')?.value||'';
        for(const input of fragment.querySelectorAll('input'))if(input.name.startsWith(prefix))input.value=boundary;
      }
    }
    body.append(fragment);
    total(form);
  }
  document.addEventListener('click',event=>{
    const folder=event.target.closest('[data-folder]');
    if(folder&&menu){
      event.preventDefault();
      menu.toggle(folder.dataset.folder);
      return;
    }
    const group=event.target.closest('[data-rental-toggle-group]');
    if(group){
      event.preventDefault();
      window.RentalRegistry.toggleGroup(group);
      return;
    }
    const nested=event.target.closest('[data-rental-nested-open]');
    if(nested){
      event.preventDefault();
      openRental(nested.dataset.rentalNestedOpen,nested,true);
      return;
    }
    const create=event.target.closest('[data-rental-create-party]');
    if(create){
      event.preventDefault();
      openRental(create.dataset.rentalCreateParty||create.dataset.rentalOpen||create.getAttribute('href'),create,true);
      return;
    }
    const open=event.target.closest('[data-rental-open]');
    if(open){
      event.preventDefault();
      openRental(open.dataset.rentalOpen||open.getAttribute('href'),open);
      return;
    }
    const close=event.target.closest('[data-rental-close]');
    if(close){
      event.preventDefault();
      closeRental(close.closest('dialog'));
      return;
    }
    const add=event.target.closest('[data-rental-add-object]');
    if(add){
      event.preventDefault();
      const form=add.closest('form');
      if(form)addObject(form);
      return;
    }
    const remove=event.target.closest('[data-rental-remove-object]');
    if(remove){
      event.preventDefault();
      const form=remove.closest('form');
      remove.closest('tr')?.remove();
      if(form)total(form);
    }
  });
  document.addEventListener('dblclick',event=>{
    const row=event.target.closest('[data-rental-url]');
    if(row&&!event.target.closest('a,button,input,select'))openRental(row.dataset.rentalUrl,row);
  });
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'){
      const close=activeRental?.querySelector('.ui-calendar [data-date-close]');
      if(close){
        event.preventDefault();
        event.stopImmediatePropagation();
        close.click();
      }
    }
  },true);
  document.addEventListener('keydown',event=>{
    const folder=event.target.closest('[data-folder]');
    if(folder&&event.key===' '&&menu){
      event.preventDefault();
      menu.toggle(folder.dataset.folder);
    }
    const row=event.target.closest('[data-rental-url]');
    if(row&&event.key==='Enter'&&event.target===row){
      event.preventDefault();
      openRental(row.dataset.rentalUrl,row);
    }
  });
  document.addEventListener('change',event=>{
    if(event.target.matches('[name=\"start_date\"],[name=\"end_date\"]')){
      const form=event.target.closest('form[data-rental-availability-url]');
      if(form){
        if(form.hasAttribute('data-rental-addendum-form')){
          const start=event.target.name==='start_date',key=start?'rentalPeriodStart':'rentalPeriodEnd',prefix=start?'term_start[':'term_end[',previous=form.dataset[key]||'';
          for(const input of form.querySelectorAll('input'))if(input.name.startsWith(prefix)&&(input.value===previous||input.value===''))input.value=event.target.value;
          form.dataset[key]=event.target.value;
          window.UIDatePicker?.sync(form);
        }
        refreshAvailability(form);
      }
    }
  });
  document.addEventListener('input',event=>{
    if(event.target.matches('input[name^="term_amount["]')){
      const form=event.target.closest('form');
      if(form)total(form);
    }
  });
  document.addEventListener('submit',event=>{
    const dialog=event.target.closest('dialog[data-rental-dialog]');
    if(dialog){
      event.preventDefault();
      submitRental(event.target,dialog,event.submitter);
    }
  });
  const autopen=document.querySelector('dialog[data-rental-autopen]');
  if(autopen)mountRental(autopen,document.getElementById('workspace'));
})();
