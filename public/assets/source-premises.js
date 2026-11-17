// Premises-specific navigation uses the current full server-filter form.
// Rendering, live search and visual roles belong to the existing shared runtime.
(() => {
  const form = () => document.getElementById('premises-search');
  function target(extra) {
    const url = new URL(location.pathname, location.origin);
    for (const [key,value] of new FormData(form())) if (value) url.searchParams.set(key,value);
    for (const [key,value] of Object.entries(extra)) url.searchParams.set(key,value);
    return url;
  }
  function syncExport() {
    const link=document.querySelector('[data-premises-export]');
    if (link && form()) link.href=target({export:'xlsx'});
  }
  document.addEventListener('input',syncExport);
  document.addEventListener('change',event=>{
    syncExport();
    if (event.target.matches('input[type=checkbox][form="premises-search"]') && form()
        && event.target.closest('[data-registry-view]')?.dataset.registryHydrating!=='true') form().requestSubmit();
  });
  syncExport();
  // The shared shell's text-only submit handler serializes q alone. Own this
  // form before that handler so external checkbox controls also reach Django.
  document.addEventListener('submit', event => {
    if (event.target !== form()) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    location.assign(target({}));
  }, true);
  document.addEventListener('click', event => {
    if (!form()) return;
    syncExport();
    // The shared reset has already restored its controls when click bubbles.
    if (event.target.closest('[data-registry-reset]')) {
      form().requestSubmit();
      return;
    }
    const tab = event.target.closest('[data-linked-tab]');
    const download = event.target.closest('[data-premises-export]');
    if (!tab && !download) return;
    event.preventDefault();
    location.assign(target(tab ? {tab:tab.value} : {export:'xlsx'}));
  });
})();
// A successful editor save can change shared question counters in the sidebar
// and footer, as well as rows. Refresh the full current filtered page on close.
(() => {
  let submitted=false;
  document.addEventListener('submit',event=>{
    if(event.target.id==='confirmed-premise-form') submitted=true;
  },true);
  document.getElementById('record-editor')?.addEventListener('close',()=>{
    if(!submitted)return;
    submitted=false;
    location.reload();
  });
})();
// Passive summary of the complete rendered premises selection. Shared runtime
// owns all filtering and rendering; raw canonical areas remain exact decimals.
(() => {
  const root=document.querySelector('[data-registry-id^="premises-confirmed"]');
  if (!root) return;
  const output=root.querySelector('[data-premises-area]');
  const body=root.querySelector('tbody');
  if (!output || !body) return;
  function update() {
    const records=new Map();
    for (const row of body.querySelectorAll('tr[data-record-key]')) {
      records.set(row.dataset.recordKey,JSON.parse(row.dataset.snapshotValues));
    }
    let total=0n,unknown=0;
    for (const item of records.values()) {
      const area=item.area_raw;
      if (area===null || area===undefined || area==='') { unknown++; continue; }
      // DecimalField has at most three fractional digits. Never parse variants.
      const match=/^(\d+)(?:\.(\d{1,3}))?$/.exec(area);
      if (!match) { output.querySelector('[data-area-known]').textContent='Площадь: требуется проверка данных'; return; }
      total+=BigInt(match[1])*1000n+BigInt((match[2]||'').padEnd(3,'0'));
    }
    const fraction=String(total%1000n).padStart(3,'0').replace(/0+$/,'');
    output.querySelector('[data-area-known]').textContent='Известная площадь: '+String(total/1000n)+(fraction?','+fraction:'')+' м²';
    const missing=output.querySelector('[data-area-unknown]');
    missing.textContent=unknown?' · не установлена у '+unknown:'';
    missing.hidden=!unknown;
  }
  new MutationObserver(update).observe(body,{childList:true});
  update();
})();

