/* Shared opt-in form validation: system field states, without native validation balloons. */
(() => {
  'use strict';
  function markedForm(target) { return target?.closest?.('form[data-source-validation]'); }
  function validate(form, focus=false) {
    let first=null;
    const fields=new Map();
    for (const control of form.querySelectorAll('input,select,textarea')) {
      if(control.disabled || control.type==='hidden')continue;
      const selection=control.dataset.requiredItems;
      const missing=selection && ![...form.querySelectorAll(selection)].some(item=>!item.disabled && item.value.trim()!=='');
      const bad=!!missing || !control.validity.valid;
      const field=control.closest('.field');
      control.classList.toggle('is-error',bad);
      if(bad)control.setAttribute('aria-invalid','true');else control.removeAttribute('aria-invalid');
      if(field)fields.set(field,(fields.get(field)||false)||bad);
      const visible=control.hidden?field?.querySelector('[role="combobox"]'):control;
      if(visible && visible!==control){if(bad)visible.setAttribute('aria-invalid','true');else visible.removeAttribute('aria-invalid');}
      if(bad && !first)first=visible || control;
    }
    for(const [field,bad] of fields)field.classList.toggle('is-error',bad);
    if(focus && first){first.scrollIntoView({block:'nearest'});first.focus({preventScroll:true});}
    return !first;
  }
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!form.matches('form[data-source-validation]') || event.submitter?.formNoValidate)return;
    form.dataset.validationAttempted='true';
    if(!validate(form,true)){event.preventDefault();event.stopImmediatePropagation();window.PlanexNotifications?.show({tone:'danger',title:'Заполните обязательные поля',message:'Они выделены красным в форме.'});}
  },true);
  function changed(event){const form=markedForm(event.target);if(form?.dataset.validationAttempted)validate(form);}
  document.addEventListener('input',changed);
  document.addEventListener('change',changed);
  // Added/removed selections can resolve a required-items field without changing its select value.
  new MutationObserver(records=>{const forms=new Set(records.map(record=>markedForm(record.target)).filter(Boolean));for(const form of forms)if(form.dataset.validationAttempted)validate(form);}).observe(document.documentElement,{childList:true,subtree:true});
})();
