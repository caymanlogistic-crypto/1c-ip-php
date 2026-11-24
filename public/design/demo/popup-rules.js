// Compact dialog reference; existing components only.
(() => {
  const party=document.getElementById('party-composition-example');
  window.RentalPartyUI?.enhance(document);
  document.querySelector('[data-open-party-example]').addEventListener('click',()=>party.showModal());
  party.querySelectorAll('[data-close-party-example]').forEach(button=>button.addEventListener('click',()=>party.close()));
  party.querySelector('[data-demo-bank-reference]').addEventListener('click',()=>{
    const error=party.querySelector('#demo-party-bik-error');
    const valid=/^[0-9]{9}$/.test(party.querySelector('[name=bik]').value.trim());
    error.hidden=valid;
    error.textContent=valid?'':'Поиск банка. Укажите БИК из 9 цифр.';
    if (!valid) return;
    party.querySelector('[name=bank]').value='Учебный банк';
    party.querySelector('[name=correspondent_account]').value='00000000000000000000';
  });
  party.querySelector('form').addEventListener('submit',event=>{event.preventDefault();document.querySelector('[data-compact-example-result]').textContent='Сохранён демонстрационный контрагент: '+party.querySelector('[name=name]').value;party.close();});
  const reviewTerms=document.getElementById('review-terms-example');
  document.querySelector('[data-open-review-terms]').addEventListener('click',()=>reviewTerms.showModal());
  reviewTerms.querySelectorAll('[data-close-review-terms]').forEach(button=>button.addEventListener('click',()=>reviewTerms.close()));
  document.querySelectorAll('[data-demo-review-reason]').forEach(form=>form.addEventListener('submit',event=>{
    event.preventDefault();
    const panel=form.closest('[data-form-disclosure]');
    panel.open=false;
    document.querySelector('[aria-controls="'+panel.id+'"]')?.focus();
  }));
  const securityForm=reviewTerms.querySelector('[data-demo-security-multiple]');
  const securityState=reviewTerms.querySelector('[data-demo-security-state]');
  const transfer=reviewTerms.querySelector('[data-demo-legal-transfer]');
  const saveSecurity=securityForm.querySelector('button[type="submit"]');
  let confirmedMultiple=null;
  securityForm.addEventListener('input',()=>{
    const field=securityForm.elements.multiple;
    const matches=field.value===confirmedMultiple;
    saveSecurity.disabled=matches||!field.validity.valid||!/^(?:0|[1-9][0-9]*)$/.test(field.value);
    transfer.disabled=!matches;
  });
  securityForm.addEventListener('submit',event=>{
    event.preventDefault();
    confirmedMultiple=securityForm.elements.multiple.value;
    securityState.textContent='Согласована';
    securityState.setAttribute('data-complete','');
    saveSecurity.disabled=true;
    transfer.disabled=false;
  });
  const reviewStatus=reviewTerms.querySelector('[data-demo-review-files-status]');
  reviewTerms.querySelector('[data-demo-review-files]').addEventListener('change',event=>{
    reviewStatus.textContent=[...event.target.files].map(file=>file.name).join(', ');
  });
  reviewTerms.querySelectorAll('[data-demo-review-zip]').forEach(link=>link.addEventListener('click',event=>{
    event.preventDefault();
    reviewStatus.textContent='В рабочем окне комплект скачивается одним ZIP.';
  }));
  const approvalPreview=document.getElementById('approval-preview-example');
  document.querySelector('[data-open-approval-preview]').addEventListener('click',()=>approvalPreview.showModal());
  approvalPreview.querySelectorAll('[data-close-approval-preview]').forEach(button=>button.addEventListener('click',()=>approvalPreview.close()));
  approvalPreview.querySelector('[data-demo-approval-file]').addEventListener('click',event=>event.preventDefault());
  const accountantReview=document.getElementById('accountant-review-example');
  document.querySelector('[data-open-accountant-review]').addEventListener('click',()=>accountantReview.showModal());
  accountantReview.querySelectorAll('[data-close-accountant-review]').forEach(button=>button.addEventListener('click',()=>accountantReview.close()));
  accountantReview.querySelectorAll('[data-demo-accountant-file]').forEach(link=>link.addEventListener('click',event=>event.preventDefault()));
  accountantReview.querySelectorAll('[data-show-accountant-decision]').forEach(button=>button.addEventListener('click',()=>{
    document.getElementById(button.dataset.showAccountantDecision).showModal();
  }));
  document.querySelectorAll('[data-close-accountant-decision]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog').close()));
  document.querySelector('[data-finish-accountant-decision]').addEventListener('click',event=>{
    event.target.closest('dialog').close();
    accountantReview.close();
  });
  const reviewDecision=document.getElementById('review-decision-example');
  const reasonForm=reviewDecision.querySelector('form');
  const redActions={
    block:['Новые договоры с этим контрагентом будут заблокированы. Текущий договор вернётся менеджеру.','Причина блокировки','Заблокировать и вернуть'],
    'party-block':['Учебная компания','Причина','Заблокировать'],
    documents:['Договор вернётся менеджеру с указанным списком.','Какие документы нужны','Отправить запрос'],
    terms:['Договор вернётся менеджеру с указанными замечаниями.','Какие условия нужно исправить','Вернуть на доработку'],
    accounting:['Договор вернётся юристу с указанной причиной.','Причина возврата','Вернуть']
  };
  let reviewDecisionParent=null;
  document.querySelectorAll('[data-demo-review-decision]').forEach(button=>button.addEventListener('click',()=>{
    const [effect,label,submit]=redActions[button.dataset.demoReviewDecision];
    reviewDecisionParent=button.closest('dialog');
    reviewDecision.querySelector('.modal-title').textContent=button.textContent;
    reviewDecision.querySelector('[data-review-decision-effect]').textContent=effect;
    reviewDecision.querySelector('[data-review-decision-reason]').textContent=label;
    reviewDecision.querySelector('[type=submit]').textContent=submit;
    reasonForm.reset();
    reviewDecision.showModal();
  }));
  reviewDecision.querySelectorAll('[data-close-review-decision]').forEach(button=>button.addEventListener('click',()=>reviewDecision.close()));
  reasonForm.addEventListener('submit',event=>{
    event.preventDefault();
    reviewDecision.close();
    reviewDecisionParent?.close();
  });
  const reviewParty=document.getElementById('review-party-example');
  document.querySelectorAll('[data-open-review-party]').forEach(button=>button.addEventListener('click',()=>reviewParty.showModal()));
  reviewParty.querySelectorAll('[data-close-review-party]').forEach(button=>button.addEventListener('click',()=>reviewParty.close()));
  const structuredParty=document.getElementById('structured-party-example');
  document.querySelector('[data-open-structured-party]').addEventListener('click',()=>structuredParty.showModal());
  structuredParty.querySelectorAll('[data-close-structured-party]').forEach(button=>button.addEventListener('click',()=>structuredParty.close()));
  structuredParty.querySelectorAll('[data-demo-party-contract]').forEach(button=>button.addEventListener('click',()=>{
    document.getElementById(button.dataset.demoPartyContract).showModal();
  }));
  const dialog=document.getElementById('compact-popup-example');
  document.querySelector('[data-open-compact-example]').addEventListener('click',()=>dialog.showModal());
  dialog.querySelectorAll('[data-close-compact-example]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.querySelector('[data-delete-compact-draft]').addEventListener('click',()=>{document.querySelector('[data-compact-example-result]').textContent='Демонстрационный черновик удалён. Рабочие данные не изменялись.';dialog.close();});
  dialog.querySelector('form').addEventListener('submit',event=>{event.preventDefault();document.querySelector('[data-compact-example-result]').textContent='Сохранено: '+dialog.querySelector('[name=example-name]').value;dialog.close();});
})();
