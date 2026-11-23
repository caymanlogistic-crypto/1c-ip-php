/* Shared native disclosure: retain inputs and reveal invalid fields before focusing. */
(() => {
  'use strict';
  function reveal(target){for(let panel=target.closest?.('[data-form-disclosure]');panel;panel=panel.parentElement?.closest('[data-form-disclosure]'))panel.open=true;}
  document.addEventListener('invalid',event=>reveal(event.target),true);
  function connect(control) {
    const panel=control.closest('[data-form-disclosure-scope],form')?.querySelector('#'+CSS.escape(control.getAttribute('aria-controls')));
    const summary=panel?.querySelector(':scope > summary');
    const collapsed=summary?.querySelector('[data-disclosure-collapsed]');
    const expanded=summary?.querySelector('[data-disclosure-expanded]');
    if (!collapsed || !expanded || !control.hidden) return;
    const sync=()=>{
      control.setAttribute('aria-expanded',String(panel.open));
      control.textContent=(panel.open?expanded:collapsed).textContent;
    };
    control.addEventListener('click',()=>{
      panel.open=!panel.open;
      sync();
      if(panel.open && panel.closest('[data-form-disclosure-scope]')) panel.querySelector('textarea')?.focus();
    });
    panel.addEventListener('toggle',sync);
    panel.dataset.externalSummary='';
    summary.hidden=true;
    control.hidden=false;
    sync();
  }
  function inspect(scope){
    if(scope.matches?.('[data-form-disclosure-control]'))connect(scope);
    for(const control of scope.querySelectorAll('[data-form-disclosure-control]'))connect(control);
    for(const error of scope.querySelectorAll('[data-form-disclosure] [aria-invalid="true"], [data-form-disclosure] .is-error'))reveal(error);
  }
  new MutationObserver(records=>{for(const record of records){if(record.type==='attributes'){if(record.target.getAttribute('aria-invalid')==='true')reveal(record.target);}else for(const node of record.addedNodes)if(node.nodeType===1)inspect(node);}}).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['aria-invalid']});
  inspect(document);
})();
