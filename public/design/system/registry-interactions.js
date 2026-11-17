/* Shared keyboard focus modality and accessible horizontal navigation. No vertical scroll owner is changed. */
(() => {
  'use strict';
  if (window.PlanexRegistryInteractions) return;
  const states = new Map();
  let frame = 0, observing = null, started = false;
  const keyMode = event => {
    if (!['Shift', 'Control', 'Alt', 'Meta'].includes(event.key)) document.documentElement.dataset.uiInput = 'keyboard';
  };
  const pointerMode = () => { document.documentElement.dataset.uiInput = 'pointer'; };
  function start() {
    if (started) return;
    started = true;
    document.documentElement.dataset.uiInput = 'keyboard';
    document.addEventListener('keydown', keyMode, true);
    document.addEventListener('pointerdown', pointerMode, true);
    window.addEventListener('resize', schedule, {passive: true});
  }

  function cards(root) {
    return [...(root.matches?.('.registry-ui-scroll') ? [root] : []), ...root.querySelectorAll('.registry-ui-scroll')];
  }
  function syncOne(state) {
    const {card, wrap, range, control} = state;
    if (!card.isConnected) { remove(state); return; }
    const width = wrap.clientWidth;
    const max = Math.max(0, wrap.scrollWidth - width);
    const available = width > 0 && max > 1;
    if (control.hidden === available) control.hidden = !available;
    range.max = String(max);
    range.disabled = !available;
    const value = Math.max(0, Math.min(max, wrap.scrollLeft));
    range.value = String(value);
    const percent = max > 0 ? Math.round(value / max * 100) : 0;
    range.setAttribute('aria-valuetext', percent === 0 ? 'Левый край таблицы' : percent === 100 ? 'Правый край таблицы' : `Положение колонок: ${percent}%`);
  }
  function schedule() {
    if (frame) return;
    frame = window.requestAnimationFrame(() => { frame = 0; for (const state of [...states.values()]) syncOne(state); });
  }
  function mount(root = document) {
    start();
    for (const card of cards(root)) {
      if (states.has(card)) continue;
      const toolbar = card.querySelector(':scope > .registry-ui-toolbar');
      const wrap = card.querySelector(':scope > .table-scroll,:scope > .rp-v010-table-wrap');
      const table = wrap?.querySelector('table');
      if (!toolbar || !wrap || !table) continue;
      const control = document.createElement('label');
      control.className = 'registry-ui-horizontal-control';
      control.hidden = true;
      const label = document.createElement('span');
      label.className = 'registry-ui-horizontal-label';
      label.textContent = 'Колонки';
      const range = document.createElement('input');
      range.type = 'range'; range.min = '0'; range.max = '0'; range.step = '1'; range.value = '0';
      range.className = 'registry-ui-horizontal-range';
      range.setAttribute('aria-label', 'Горизонтальная прокрутка таблицы');
      range.disabled = true;
      control.append(label, range); toolbar.append(control);
      const state = {card, wrap, range, control, observer: null};
      state.input = () => { wrap.scrollLeft = Number(range.value); syncOne(state); };
      state.scroll = () => syncOne(state);
      state.key = event => {
        const max = Number(range.max), old = wrap.scrollLeft;
        const step = Math.max(32, Math.round(wrap.clientWidth / 10));
        const values = {ArrowLeft: old - step, ArrowRight: old + step, ArrowDown: old - step, ArrowUp: old + step, Home: 0, End: max, PageUp: old - wrap.clientWidth, PageDown: old + wrap.clientWidth};
        if (!Object.hasOwn(values, event.key) || event.altKey || event.metaKey || event.ctrlKey) return;
        event.preventDefault();
        wrap.scrollLeft = Math.max(0, Math.min(max, values[event.key]));
        syncOne(state);
      };
      range.addEventListener('input', state.input);
      range.addEventListener('keydown', state.key);
      wrap.addEventListener('scroll', state.scroll, {passive: true});
      if (window.ResizeObserver) {
        state.observer = new ResizeObserver(schedule);
        state.observer.observe(wrap); state.observer.observe(table);
      }
      states.set(card, state); syncOne(state);
    }
    if (!observing && document.body && window.MutationObserver) {
      observing = new MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes ?? []) if (node.nodeType === 1) mount(node);
        schedule();
      });
      observing.observe(document.body, {subtree: true, childList: true, attributes: true, attributeFilter: ['hidden']});
    }
    schedule();
  }
  function remove(state) {
    state.observer?.disconnect();
    state.range.removeEventListener('input', state.input);
    state.range.removeEventListener('keydown', state.key);
    state.wrap.removeEventListener('scroll', state.scroll);
    state.control.remove(); states.delete(state.card);
  }
  function destroy(root = document) {
    for (const state of [...states.values()]) if (root === document || root === state.card || root.contains?.(state.card)) remove(state);
    if (root === document) {
      observing?.disconnect(); observing = null;
      if (frame) window.cancelAnimationFrame(frame);
      frame = 0;
      document.removeEventListener('keydown', keyMode, true);
      document.removeEventListener('pointerdown', pointerMode, true);
      window.removeEventListener('resize', schedule);
      started = false;
      delete document.documentElement.dataset.uiInput;
    }
  }
  function sync(root = document) {
    for (const state of states.values()) if (root === document || root === state.card || root.contains?.(state.card)) syncOne(state);
  }
  window.PlanexRegistryInteractions = Object.freeze({mount, sync, destroy});
  mount();
})();

/* Owner standard: persisted semantic column profiles. No inference from labels,
   numeric strings, current batch, viewport, or live record measurements. */
(() => {
 'use strict';
 if(window.ERPTableProfiles)return;
 const c=(key,type='text',size='text')=>Object.freeze({key,type,size});
 const flex=key=>c(key,'text','flex');
 const profiles={
  'universal-objects':[c('id','identifier','id'),flex('name'),c('category'),c('owner','text','code'),c('date','date'),c('availability'),c('status')],
  'universal-documents':[c('id','identifier','id'),flex('document'),c('participant'),c('due','date'),c('plan','money'),c('actual','money'),c('remainder','money'),c('status')],
  'universal-operations':[c('id','identifier','code'),flex('object'),c('operation'),c('count','text','short'),c('value','text','code'),c('related'),c('date','date'),c('owner','text','code')],
 };
 Object.values(profiles).forEach(Object.freeze);Object.freeze(profiles);
 function money(value){
  const raw=String(value??'').trim().replace(/\s/g,'').replace(/₽$/,'').replace(/^(−?-?\d+),(\d+)$/,'$1.$2');
  const match=raw.match(/^([−-]?)(\d+)(?:\.(\d*))?$/);
  if(!match)return String(value??'');
  const fraction=(match[3]||'').padEnd(3,'0');
  let cents=BigInt(match[2])*100n+BigInt(fraction.slice(0,2));
  if(fraction[2]>='5')cents+=1n;
  const digits=(cents/100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
  return (match[1]&&cents!==0n?'-':'')+digits+'.'+(cents%100n).toString().padStart(2,'0');
 }
 const crop=value=>{const chars=Array.from(String(value));return chars.length>50?chars.slice(0,50).join('')+'…':String(value);};
 const processed=new WeakMap();
 function applyCell(cell,spec){
  cell.classList.add('registry-column-'+spec.type,'registry-column-'+(spec.type==='money'||spec.type==='date'?spec.type:spec.size));
  cell.dataset.columnKey=spec.key;
  if(cell.colSpan>1)return;
  const previous=processed.get(cell), incoming=cell.textContent;
  if(previous?.rendered===incoming)return;
  const full=previous?incoming.trim():(cell.dataset.fulltext||incoming.trim());
  cell.removeAttribute('title');
  cell.dataset.fulltext=full;
  const nodes=[];
  const walk=node=>{
   for(const child of [...node.childNodes]){
    if(child.nodeType===3)nodes.push(child);
    else if(child.nodeType===1&&!['SVG','BUTTON','INPUT','SELECT','TEXTAREA'].includes(child.tagName))walk(child);
   }
  };walk(cell);
  if(spec.type==='money'){
   for(const node of nodes)if(node.textContent.trim())node.textContent=money(node.textContent);
  }else if(spec.type==='date'){
   for(const node of nodes){
    const value=node.textContent.trim(),iso=value.match(/^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/),local=value.match(/^(\d{2}\.\d{2}\.\d{4})(?:\s+\d{2}:\d{2}(?::\d{2})?.*)?$/);
    if(iso)node.textContent=iso[3]+'.'+iso[2]+'.'+iso[1];else if(local)node.textContent=local[1];
   }
  }else{
   // One display budget for the entire cell, including split primary/secondary values.
   let remaining=50, shortened=false;
   const total=nodes.reduce((sum,node)=>sum+Array.from(node.textContent).length,0);
   if(total>50)for(const node of nodes){
    const chars=Array.from(node.textContent),shown=chars.slice(0,remaining);remaining-=shown.length;
    node.textContent=shown.join('')+(!shortened&&remaining===0?'…':'');
    if(remaining===0)shortened=true;
   }
  }
  processed.set(cell,{rendered:cell.textContent,raw:full});
 }
 function applyRow(row,spec){
  const width=[...row.cells].reduce((sum,cell)=>sum+(cell.colSpan||1),0);if(width!==spec.length)return;
  let offset=0;for(const cell of row.cells){const span=cell.colSpan||1;if(span===1)applyCell(cell,spec[offset]);offset+=span;}
 }
 function declaredProfile(table){
  const raw=table.getAttribute('data-column-spec');if(raw===null)return null;
  try{
   const spec=JSON.parse(raw),keys=new Set(),types=new Set(['text','identifier','money','date']),sizes=new Set(['id','short','code','text','account','long','flex']);
   if(!Array.isArray(spec)||!spec.length||spec.length>100)throw new Error('Invalid columns');
   for(const column of spec){
    if(!column||Object.keys(column).sort().join()!=='key,size,type'||!/^[a-z][a-z0-9_]{0,63}$/.test(column.key)||keys.has(column.key)||!types.has(column.type)||!sizes.has(column.size)||(['money','date'].includes(column.type)&&column.size==='flex'))throw new Error('Invalid column metadata');
    keys.add(column.key);
   }
   if(!spec.some(column=>column.size==='flex'))throw new Error('Missing flexible column');
   return spec;
  }catch(error){table.dataset.columnProfileError='invalid-spec';return false;}
 }
 function mount(root=document){
  const tables=new Set([...root.querySelectorAll('[data-registry-id]')].map(registry=>registry.querySelector('table.registry-ui-data')).filter(Boolean));
  root.querySelectorAll('table[data-column-profile],table[data-column-spec]').forEach(table=>tables.add(table));
  for(const table of tables){
   const id=table.closest('[data-registry-id]')?.dataset.registryId||'';
   const declared=table.dataset.columnProfile||id;
   const explicit=declaredProfile(table),spec=explicit===null?profiles[declared]:explicit;
   if(!spec||table.tHead?.rows[0]?.cells.length!==spec.length){table.dataset.columnProfileError=explicit===false?'invalid-spec':!spec?'unknown-profile':'header-count';continue;}
   delete table.dataset.columnProfileError;
   table.dataset.columnProfile=declared;
   let group=table.querySelector(':scope > colgroup');if(!group){group=document.createElement('colgroup');table.prepend(group);}
   if(!group.dataset.columnProfile){group.replaceChildren(...spec.map(item=>{const col=document.createElement('col');col.className='registry-column-'+(item.type==='money'||item.type==='date'?item.type:item.size);col.dataset.columnKey=item.key;return col;}));group.dataset.columnProfile=declared;}
   [...table.tHead.rows[0].cells].forEach((cell,i)=>{cell.classList.add('registry-column-'+spec[i].type);cell.dataset.columnKey=spec[i].key;});
   for(const section of [table.tBodies[0],table.tFoot])if(section)for(const row of section.rows)applyRow(row,spec);
  }
 }
 window.ERPTableProfiles=Object.freeze({profiles,money,crop,mount,applyCell,applyRow,declaredProfile});
 if(typeof MutationObserver!=='undefined'){
  let scheduled=false;
  const observer=new MutationObserver(()=>{if(!scheduled){scheduled=true;queueMicrotask(()=>{scheduled=false;mount();});}});
  observer.observe(document.documentElement,{childList:true,characterData:true,subtree:true});
 }
 mount();
})();
