/* Derived project canonical runtime, owner-approved 2026-11-13. Preserved upstream stays unchanged. */
/* Shared data/state/scroll API. Business filtering and authorization stay on the page. */
(() => {
  if(window.PlanexRegistry)return;
  const mounted = new WeakMap(),mediaBindings=new WeakMap();
  function textRoles(root) {
    root.querySelectorAll('.registry-ui-name,.registry-ui-secondary').forEach(element=>{element.dataset.registryFullText??=element.textContent;});
    root.querySelectorAll('[data-registry-cards-at]').forEach(table=>{
      const width=Number(table.dataset.registryCardsAt);
      if(![640,1050].includes(width))return;
      if(table.dataset.registryCardsReady)return;
      table.dataset.registryCardsReady='true';
      const media=matchMedia(`(max-width:${width}px)`);
      const sync=()=>table.classList.toggle('registry-ui-data--cards',media.matches);
      media.addEventListener('change',sync);mediaBindings.set(table,{media,sync});sync();
    });
    const segmenter = new Intl.Segmenter('ru', {granularity:'grapheme'});
    root.querySelectorAll('[data-registry-trip]').forEach(element => {
      const changed=element.dataset.registryRenderedText!==undefined&&element.textContent!==element.dataset.registryRenderedText;
      const full = changed ? element.textContent : element.dataset.registryFullText ?? element.textContent;
      element.dataset.registryFullText = full;
      element.setAttribute('aria-label',full);
      const letters = [...segmenter.segment(full)].map(item=>item.segment);
      element.textContent = letters.length>30 ? letters.slice(0,30).join('')+'…' : full;
      element.dataset.registryRenderedText=element.textContent;
    });
    document.fonts.ready.then(()=>{
      if(!root.isConnected)return;
      const canvas=document.createElement('canvas'), context=canvas.getContext('2d');
      context.font=`700 12px ${getComputedStyle(root).fontFamily}`;
      let width=64;
      root.querySelectorAll('tbody .registry-ui-id').forEach(element=>{
        width=Math.max(width,Math.ceil(context.measureText(element.textContent.trim()).width+24));
      });
      // One exception for longer real identifiers; never clip or shrink the font.
      root.querySelectorAll('.registry-ui-data').forEach(table=>table.style.setProperty('--registry-id-width',`${width}px`));
    });
  }
  function state(root) {
    const fields=[...root.querySelectorAll('[data-registry-field]')];
    const reset=root.querySelector('.registry-ui-reset');
    if(!fields.length||!reset)return()=>{};
    let disposed=false;const bindings=[],listen=(node,type,fn)=>{node.addEventListener(type,fn);bindings.push([node,type,fn]);},queuedSync=()=>queueMicrotask(()=>{if(!disposed)sync();});
    const tabs=[...root.querySelectorAll('[data-registry-tab]')];
    const defaults=Object.fromEntries(fields.map(field=>[field.dataset.registryField,field.type==='checkbox'?field.dataset.registryDefault==='true':field.dataset.registryDefault??'']));
    if(tabs.length)defaults.tab=root.dataset.registryDefaultTab||'all';
    const filters=fields.filter(field=>field.dataset.registryFilter!=='false'&&field.dataset.registryField!=='sort');
    // Supplied by the server: account + membership/role + company + permission revision.
    // Never use a query parameter as an authorization or persistence scope.
    const scope=root.dataset.registryStateScope;
    const key=scope?`planex:registry:v1:${location.pathname}:${root.dataset.registryId}:${scope}`:null;
    const read=()=>{
      const value=Object.fromEntries(fields.map(field=>[field.dataset.registryField,field.type==='checkbox'?field.checked:field.value]));
      if(tabs.length)value.tab=tabs.find(tab=>tab.getAttribute('aria-pressed')==='true')?.dataset.registryTab||defaults.tab;
      return value;
    };
    const sync=()=>{
      const value=read();reset.hidden=!filters.some(field=>value[field.dataset.registryField]!==defaults[field.dataset.registryField]);
      try{if(key)localStorage.setItem(key,JSON.stringify(value));}catch{/* Reset/filtering also work when storage is unavailable. */}
      root.dispatchEvent(new CustomEvent('registry:state',{detail:value}));
    };
    const apply=value=>{
      const serverFiltered=root.hasAttribute('data-registry-server-filter');
      if(serverFiltered)root.dataset.registryHydrating='true';
      for(const field of fields){
        const name=field.dataset.registryField;
        let next=Object.hasOwn(value,name)?value[name]:defaults[name];
        if(field.type==='checkbox')field.checked=typeof next==='boolean'?next:defaults[name];
        else {
          if(typeof next!=='string'||(field.options&&![...field.options].some(option=>!option.disabled&&option.value===next)))next=defaults[name];
          field.value=next;
        }
        field.dispatchEvent(new Event(field.matches('[type="search"],[type="text"]')?'input':'change',{bubbles:true}));
      }
      if(tabs.length)(tabs.find(tab=>tab.dataset.registryTab===value.tab)||tabs.find(tab=>tab.dataset.registryTab===defaults.tab))?.click();
      if(serverFiltered){delete root.dataset.registryHydrating;root.dispatchEvent(new Event('registry:hydrated'));}
    };
    let saved=null;try{if(key)saved=JSON.parse(localStorage.getItem(key)||'null');}catch{/* Ignore malformed/unavailable storage. */}
    // Server-filtered registries may be opened with an explicit filtered URL.
    // Keep that URL's rendered controls on the first visit; later visits restore
    // the same account/role/company-scoped state as client-filtered registries.
    const initial=root.hasAttribute('data-registry-server-filter')?read():defaults;
    apply(saved&&typeof saved==='object'&&!Array.isArray(saved)?saved:initial);
    fields.forEach(field=>['input','change'].forEach(type=>listen(field,type,queuedSync)));
    tabs.forEach(tab=>listen(tab,'click',queuedSync));
    listen(reset,'click',()=>{const next=read();for(const field of filters)next[field.dataset.registryField]=defaults[field.dataset.registryField];apply(next);sync();});
    sync();return()=>{disposed=true;for(const [node,type,fn]of bindings)node.removeEventListener(type,fn);};
  }
  function scroll(root) {
    const cards=[...(root.matches?.('.registry-ui-scroll')?[root]:[]),...root.querySelectorAll('.registry-ui-scroll')],disposers=[];
    cards.forEach(card=>{
      const wrap=card.querySelector(':scope > .table-scroll,:scope > .rp-v010-table-wrap'),table=wrap?.querySelector('table'),head=table?.tHead,toolbar=card.querySelector('.registry-ui-toolbar');if(!head)return;
      const owner=card.closest('main.content')||window,initial={transform:head.style.transform,height:card.style.getPropertyValue('--registry-toolbar-height'),tab:wrap.getAttribute('tabindex'),label:wrap.getAttribute('aria-label')};let frame=0,shift=0,disposed=false;
      const update=()=>{frame=0;if(disposed||!card.isConnected)return;if(toolbar)card.style.setProperty('--registry-toolbar-height',`${toolbar.getBoundingClientRect().height}px`);const rect=table.getBoundingClientRect(),headRect=head.getBoundingClientRect(),visible=headRect.width>2&&headRect.height>2&&getComputedStyle(head).display!=='none',wide=wrap.scrollWidth>wrap.clientWidth+1;wrap.dataset.registryScrollX=String(wide);if(wide){wrap.tabIndex=0;if(!wrap.hasAttribute('aria-label'))wrap.setAttribute('aria-label','Таблица: горизонтальная прокрутка');}else wrap.removeAttribute('tabindex');head.classList.toggle('registry-ui-scroll-native',visible&&!wide);head.classList.toggle('registry-ui-scroll-translated',visible&&wide);if(!visible||!wide){head.style.transform='';shift=0;return;}const top=headRect.top-shift;shift=Math.max(0,Math.min((toolbar?.getBoundingClientRect().bottom||0)-top,rect.bottom-headRect.height-top));head.style.transform=`translateY(${shift}px)`;};
      const schedule=()=>{if(!disposed&&!frame)frame=requestAnimationFrame(update);};owner.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule,{passive:true});wrap.addEventListener('scroll',schedule,{passive:true});const observer=new ResizeObserver(schedule);[wrap,table,toolbar].filter(Boolean).forEach(node=>observer.observe(node));const mutations=new MutationObserver(schedule);mutations.observe(table,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden','class']});
      disposers.push(()=>{disposed=true;cancelAnimationFrame(frame);owner.removeEventListener('scroll',schedule);window.removeEventListener('resize',schedule);wrap.removeEventListener('scroll',schedule);observer.disconnect();mutations.disconnect();head.style.transform=initial.transform;head.classList.remove('registry-ui-scroll-native','registry-ui-scroll-translated');delete wrap.dataset.registryScrollX;if(initial.height)card.style.setProperty('--registry-toolbar-height',initial.height);else card.style.removeProperty('--registry-toolbar-height');for(const [name,value]of [['tabindex',initial.tab],['aria-label',initial.label]])if(value===null)wrap.removeAttribute(name);else wrap.setAttribute(name,value);});update();
    });return()=>disposers.forEach(dispose=>dispose());
  }
  function unmount(root){const entry=mounted.get(root);if(!entry)return;for(const dispose of entry.disposers)dispose?.();for(const table of root.querySelectorAll('[data-registry-cards-at]')){const binding=mediaBindings.get(table);if(binding){binding.media.removeEventListener('change',binding.sync);mediaBindings.delete(table);delete table.dataset.registryCardsReady;}}mounted.delete(root);delete root.dataset.registryReady;}
  function mount(root,{managedMarkup=false,scroll:withScroll=true}={}){if(mounted.has(root))return mounted.get(root);const entry={disposers:[]};mounted.set(root,entry);if(!managedMarkup){textRoles(root);entry.disposers.push(state(root));}if(withScroll)entry.disposers.push(scroll(root));root.dataset.registryReady='true';return entry;}
  window.PlanexRegistry={mount,unmount,textRoles};
  const init=()=>setTimeout(()=>document.querySelectorAll('[data-registry-id]').forEach(root=>mount(root,{managedMarkup:root.hasAttribute('data-registry-managed-markup'),scroll:!root.hasAttribute('data-registry-managed-scroll')})),0);
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
