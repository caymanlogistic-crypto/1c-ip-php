/* Shared native disclosure: retain inputs and reveal invalid fields before focusing. */
(() => {
  'use strict';
  function reveal(target){for(let panel=target.closest?.('[data-form-disclosure]');panel;panel=panel.parentElement?.closest('[data-form-disclosure]'))panel.open=true;}
  document.addEventListener('invalid',event=>reveal(event.target),true);
  function inspect(scope){for(const error of scope.querySelectorAll('[data-form-disclosure] [aria-invalid="true"], [data-form-disclosure] .is-error'))reveal(error);}
  new MutationObserver(records=>{for(const record of records){if(record.type==='attributes'){if(record.target.getAttribute('aria-invalid')==='true')reveal(record.target);}else for(const node of record.addedNodes)if(node.nodeType===1)inspect(node);}}).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['aria-invalid']});
  inspect(document);
})();
