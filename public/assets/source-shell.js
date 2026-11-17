// Local UI behavior. Visual roles come from the shared system.
(() => {
  'use strict';
  const editor = document.getElementById('record-editor');
  const confirmation = document.getElementById('confirm-action');
  let pendingForm = null, editorUrl = null, submitting = false, searchTimer = null, searchRequest = null, searchRevision = 0;
  const appending = new WeakSet();
  const headers = {'X-Requested-With': 'ERP-Fetch'};
  const parse = text => new DOMParser().parseFromString(text, 'text/html');
  const localUrl = value => { const url = new URL(value, location.href); if (url.origin !== location.origin) throw new Error('Недоступный адрес.'); return url; };
  function notify(message, tone='success') { window.PlanexNotifications?.show({tone, message}); }
  function messages(doc) { doc.querySelectorAll('[data-notification]').forEach(el => notify(el.textContent.trim(), el.dataset.notification)); }
  document.querySelectorAll('[data-notification]').forEach(el => { notify(el.textContent.trim(),el.dataset.notification); el.remove(); });
  function errorBox(message) {
    const box=document.createElement('div');box.className='form-alert alert-error';box.setAttribute('role','alert');
    const mark=document.createElement('span');mark.className='alert-mark';mark.setAttribute('aria-hidden','true');mark.textContent='×';
    const body=document.createElement('div');body.className='alert-body';const text=document.createElement('div');text.className='alert-body-title';text.textContent=message;body.append(text);box.append(mark,body);return box;
  }
  function showForm(doc, url) {
    const source=doc.querySelector('[data-editor-fragment]'), actions=source?.querySelector('[data-editor-actions]');
    if (!source || !actions) throw new Error('Не удалось открыть форму. Обновите страницу и войдите снова.');
    if(source.querySelector('.table-card,.registry-ui-card,.panel-section,.page-head,.table-toolbar'))throw new Error('Форма содержит контейнер полной страницы.');
    const body=editor.querySelector('[data-editor-body]'), foot=editor.querySelector('[data-editor-foot]');body.replaceChildren();foot.replaceChildren();
    editor.querySelector('.modal-title').textContent=source.dataset.editorTitle || 'Данные записи';
    for(const node of [...source.children])if(node!==actions)body.append(node.cloneNode(true));
    body.querySelector('form[id]')?.setAttribute('action',url);foot.append(actions.cloneNode(true));
    editorUrl=url;window.PlanexRegistry?.textRoles(editor);
  }
  async function openForm(value) {
    try {const url=localUrl(value).href; const response=await fetch(url,{headers});if(!response.ok) throw new Error('Не удалось открыть запись.');showForm(parse(await response.text()),url);if(!editor.open) editor.showModal();}
    catch(error){notify(error.message,'danger');}
  }
  async function refreshRegistry(value, changeUrl=false, append=false, registry=document.querySelector('[data-registry-view]')) {
    if(!registry || (append && appending.has(registry)))return;
    if(append){appending.add(registry);registry.setAttribute('aria-busy','true');}
    const revision=++searchRevision;searchRequest?.abort();searchRequest=new AbortController();
    const url=localUrl(value);
    try {const response=await fetch(url,{headers,signal:searchRequest.signal});if(!response.ok)throw new Error('Не удалось обновить список.');const doc=parse(await response.text()),next=doc.querySelector('[data-registry-view]');if(!next)throw new Error('Сеанс завершён. Войдите снова.');if(revision!==searchRevision)return;
      if(!registry.isConnected || registry.dataset.registryId!==next.dataset.registryId)return;
      const body=registry.querySelector('tbody'),incoming=next.querySelector('tbody');
      if(append){
        const keys=new Set([...body.querySelectorAll('[data-record-key]')].map(row=>row.dataset.recordKey));
        const additions=[...incoming.querySelectorAll('tr[data-record-key]')].filter(row=>{
          const key=row.dataset.recordKey;if(keys.has(key))return false;keys.add(key);return true;
        });
        body.append(...additions);
      }else body.replaceChildren(...incoming.childNodes);
      const count=registry.querySelector('[data-result-count]'),nextCount=next.querySelector('[data-result-count]');
      if(count && nextCount)count.textContent=nextCount.textContent;
      const pagination=registry.querySelector('[data-pagination]');if(pagination)pagination.replaceChildren(...next.querySelector('[data-pagination]').childNodes);
      if(changeUrl && !append)history.replaceState(null,'',url.pathname+url.search);window.PlanexRegistry?.textRoles(registry);messages(doc);
    } catch(error) {if(error.name!=='AbortError')notify(error.message,'danger');}
    finally {if(append){appending.delete(registry);registry.removeAttribute('aria-busy');}}
  }
  function search() {
    searchTimer=null;
    const url=new URL(location.href),input=document.querySelector('[data-live-search]');if(!input)return;
    const form=input.closest('[data-search-form]');
    if(form)for(const [key,value] of new FormData(form))url.searchParams.set(key,value);
    url.searchParams.set('q',input.value);url.searchParams.delete('page');refreshRegistry(url,true);
  }
  async function save(form) {
    if(submitting)return;submitting=true;const buttons=[...editor.querySelectorAll('button[type=submit]')];buttons.forEach(b=>b.disabled=true);
    try {const response=await fetch(localUrl(form.getAttribute('action')||editorUrl),{method:'POST',body:new FormData(form),headers});if(!response.ok)throw new Error('Не удалось сохранить. Проверьте доступ и повторите попытку.');const doc=parse(await response.text());
      if(response.redirected && doc.querySelector('[data-registry-view]')) {editor.close();messages(doc);await refreshRegistry(location.href);}
      else showForm(doc,editorUrl);
    }catch(error){editor.querySelector('[data-editor-body]').prepend(errorBox(error.message));}
    finally{submitting=false;buttons.forEach(b=>b.disabled=false);}
  }
  function toggleFolder(control) {
    const folder=document.getElementById(control.getAttribute('aria-controls'));
    if(!folder)return;
    const expanded=control.getAttribute('aria-expanded')!=='true';
    control.setAttribute('aria-expanded',String(expanded));
    control.classList.toggle('is-open',expanded);
    folder.classList.toggle('is-open',expanded);
    folder.hidden=!expanded;
  }
  document.addEventListener('keydown',event=>{
    const folder=event.target.closest('[data-nav-folder]');
    if(folder&&event.key===' '){event.preventDefault();toggleFolder(folder);}
  });
  document.addEventListener('click',event=>{
    const folder=event.target.closest('[data-nav-folder]');if(folder){event.preventDefault();toggleFolder(folder);return;}
    const open=event.target.closest('[data-open-form]');if(open){event.preventDefault();const target=open.dataset.openForm||open.getAttribute('href');if(target)openForm(target);return;}
    if(event.target.closest('[data-editor-close]')){editor.close();return;}
    if(event.target.closest('[data-confirm-cancel]')){pendingForm=null;confirmation.close();return;}
    if(event.target.closest('#confirm-submit')){const form=pendingForm;pendingForm=null;confirmation.close();if(form){form.dataset.confirmed='true';form.requestSubmit();}return;}
    const more=event.target.closest('a[data-append-rows]');if(more){
      event.preventDefault();
      if(searchTimer!==null){clearTimeout(searchTimer);search();return;}
      refreshRegistry(more.href,false,true,more.closest('[data-registry-view]'));return;
    }
    const page=event.target.closest('[data-pagination] a');if(page){event.preventDefault();refreshRegistry(page.href,true);}
  });
  document.addEventListener('dblclick',event=>{const row=event.target.closest('tr[data-record-url]');if(row&&!event.target.closest('a,button,input,select'))openForm(row.dataset.recordUrl);});
  document.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.matches('tr[data-record-url]')){event.preventDefault();openForm(event.target.dataset.recordUrl);}});
  document.addEventListener('input',event=>{if(event.target.matches('[data-live-search]') && event.target.closest('[data-registry-view]')?.dataset.registryHydrating!=='true'){clearTimeout(searchTimer);searchRequest?.abort();searchTimer=setTimeout(search,150);}});
  document.addEventListener('change',event=>{if(event.target.matches('[data-registry-sort]') && event.target.closest('[data-registry-view]')?.dataset.registryHydrating!=='true'){clearTimeout(searchTimer);search();}});
  document.addEventListener('registry:hydrated',event=>{
    const form=event.target.querySelector('[data-search-form]');if(!form)return;
    const url=new URL(location.href),defaults={q:'',sort:'date',view:'documents'};
    if([...new FormData(form)].some(([key,value])=>String(value)!==(url.searchParams.get(key)??defaults[key]??''))){clearTimeout(searchTimer);search();}
  });
  document.addEventListener('submit',event=>{
    const form=event.target;if(form.matches('[data-search-form]')){event.preventDefault();clearTimeout(searchTimer);search();return;}
    if(form.matches('[data-confirm]')&&form.dataset.confirmed!=='true'){event.preventDefault();pendingForm=form;confirmation.querySelector('#confirm-message').textContent=form.dataset.confirm;confirmation.showModal();return;}
    delete form.dataset.confirmed;
    if(editor?.contains(form)){event.preventDefault();save(form);}
  });
  confirmation?.addEventListener('cancel',()=>pendingForm=null);
})();
