/* Shared opt-in form validation: system field states, without native validation balloons. */
(() => {
  'use strict';
  function markedForm(target) { return target?.closest?.('form[data-source-validation]'); }
  function alertTemplate(form) { return form.querySelector('template[data-source-validation-alert]'); }
  function showInlineError(form, template) {
    const body=form.closest('.modal-body');
    const current=body?.parentElement.querySelector('[data-rental-form-error]');
    if(!body || !template?.content.firstElementChild)return false;
    if(current?.hasAttribute('data-source-client-error'))return true;
    const alert=template.content.firstElementChild.cloneNode(true);
    alert.setAttribute('data-rental-form-error','');
    alert.setAttribute('data-source-client-error','');
    current?.remove();
    body.before(alert);
    return true;
  }
  function validate(form, focus=false) {
    const template=alertTemplate(form);
    let first=null;
    const fields=new Map();
    for (const control of form.querySelectorAll('input,select,textarea')) {
      if(control.disabled || control.type==='hidden')continue;
      const selection=control.dataset.requiredItems;
      const missing=selection && ![...form.querySelectorAll(selection)].some(item=>!item.disabled && item.value.trim()!=='');
      const blank=template && control.required && control.type==='text' && control.value.trim()==='';
      const unavailable=template && control.required && control.selectedOptions?.[0]?.disabled;
      const bad=!!missing || !!blank || !!unavailable || !control.validity.valid;
      const field=control.closest('.field');
      control.classList.toggle('is-error',bad);
      if(bad)control.setAttribute('aria-invalid','true');else control.removeAttribute('aria-invalid');
      if(field)fields.set(field,(fields.get(field)||false)||bad);
      const visible=control.hidden?field?.querySelector('[role="combobox"]'):control;
      if(visible && visible!==control){if(bad)visible.setAttribute('aria-invalid','true');else visible.removeAttribute('aria-invalid');}
      if(bad && !first)first=visible || control;
    }
    for(const [field,bad] of fields)field.classList.toggle('is-error',bad);
    if(template && !first)form.closest('dialog')?.querySelector('[data-source-client-error]')?.remove();
    if(focus && first){first.scrollIntoView({block:'nearest'});first.focus({preventScroll:true});}
    return !first;
  }
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!form.matches('form[data-source-validation]') || event.submitter?.formNoValidate)return;
    form.dataset.validationAttempted='true';
    const template=alertTemplate(form);
    if(validate(form,true)){
      if(template)form.closest('dialog')?.querySelector('[data-rental-form-error]')?.remove();
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    if(!showInlineError(form,template))window.PlanexNotifications?.show({tone:'danger',title:'Заполните обязательные поля',message:'Они выделены красным в форме.'});
  },true);
  function changed(event){const form=markedForm(event.target);if(form?.dataset.validationAttempted)validate(form);}
  document.addEventListener('input',changed);
  document.addEventListener('change',changed);
  // Added/removed selections can resolve a required-items field without changing its select value.
  new MutationObserver(records=>{const forms=new Set(records.map(record=>markedForm(record.target)).filter(Boolean));for(const form of forms)if(form.dataset.validationAttempted)validate(form);}).observe(document.documentElement,{childList:true,subtree:true});
})();
