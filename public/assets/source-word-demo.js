/* Presentation helper: fills the requested Word fields without submitting the form. */
(() => {
  'use strict';
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-word-demo-fill]');
    if(!button)return;
    const form=button.form;
    if(!form?.hasAttribute('data-word-demo-values'))return;
    let values;
    try{values=JSON.parse(form.dataset.wordDemoValues);}catch{return;}
    for(const input of form.elements){
      const match=/^data\[([a-z][a-z0-9_]{0,63})\]$/.exec(input.name||'');
      if(!match||input.disabled||input.readOnly||!Object.hasOwn(values,match[1]))continue;
      input.value=String(values[match[1]]);
      input.dispatchEvent(new Event('input',{bubbles:true}));
      input.dispatchEvent(new Event('change',{bubbles:true}));
    }
    window.UIDatePicker?.sync(form);
  });
})();
