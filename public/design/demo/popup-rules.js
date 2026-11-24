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
  const reviewStatus=reviewTerms.querySelector('[data-demo-review-files-status]');
  reviewTerms.querySelector('[data-demo-review-files]').addEventListener('change',event=>{
    reviewStatus.textContent=[...event.target.files].map(file=>file.name).join(', ');
  });
  reviewTerms.querySelectorAll('[data-demo-review-zip]').forEach(link=>link.addEventListener('click',event=>{
    event.preventDefault();
    reviewStatus.textContent='В рабочем окне комплект скачивается одним ZIP.';
  }));
  const accountantReview=document.getElementById('accountant-review-example');
  document.querySelector('[data-open-accountant-review]').addEventListener('click',()=>accountantReview.showModal());
  accountantReview.querySelectorAll('[data-close-accountant-review]').forEach(button=>button.addEventListener('click',()=>accountantReview.close()));
  accountantReview.querySelectorAll('[data-demo-accountant-file]').forEach(link=>link.addEventListener('click',event=>event.preventDefault()));
  accountantReview.querySelectorAll('form').forEach(form=>form.addEventListener('submit',event=>{
    event.preventDefault();
    form.reset();
    accountantReview.close();
  }));
  const reviewParty=document.getElementById('review-party-example');
  document.querySelector('[data-open-review-party]').addEventListener('click',()=>reviewParty.showModal());
  reviewParty.querySelectorAll('[data-close-review-party]').forEach(button=>button.addEventListener('click',()=>reviewParty.close()));
  const dialog=document.getElementById('compact-popup-example');
  document.querySelector('[data-open-compact-example]').addEventListener('click',()=>dialog.showModal());
  dialog.querySelectorAll('[data-close-compact-example]').forEach(button=>button.addEventListener('click',()=>dialog.close()));
  dialog.querySelector('[data-delete-compact-draft]').addEventListener('click',()=>{document.querySelector('[data-compact-example-result]').textContent='Демонстрационный черновик удалён. Рабочие данные не изменялись.';dialog.close();});
  dialog.querySelector('form').addEventListener('submit',event=>{event.preventDefault();document.querySelector('[data-compact-example-result]').textContent='Сохранено: '+dialog.querySelector('[name=example-name]').value;dialog.close();});
})();
