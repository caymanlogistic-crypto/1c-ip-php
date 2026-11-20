'use strict';
// Presentation adapter for C directory-ui.js:19–23,90. PHP owns fields and validation.
(() => {
  const chosenCadastreRentability = new WeakSet();
  function syncAddress(form) {
    if (!form) return;
    const same = form.querySelector('input[type="checkbox"][name="postal_same_as_legal"]');
    const legal = form.querySelector('[name="legal_address"]') || form.querySelector('[name="address"]');
    const postal = form.querySelector('[name="postal_address"]');
    if (!same || !legal || !postal) return;
    postal.readOnly = same.checked;
    postal.setAttribute('aria-readonly', String(same.checked));
    if (same.checked) postal.value = legal.value;
  }

  function syncRecipients(form) {
    if (!form) return;
    for (const [flag,name] of [['document_recipient','email'],['additional_document_recipient','account_email']]) {
      const selected = form.querySelector('input[type="checkbox"][name="' + flag + '"]');
      const email = form.querySelector('[name="' + name + '"]');
      if (!selected || !email) continue;
      email.required = selected.checked;
      email.setCustomValidity(selected.checked && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())
        ? 'Укажите корректный Email выбранного получателя документации.' : '');
    }
  }

  function syncObjectFloor(form) {
    if (!form?.matches('[data-rental-object-editor][data-rental-new-object]')) return;
    const floor = form.querySelector('[name="floor"]');
    const parent = form.querySelector('select[name="cadastre_id"]');
    if (floor && parent && floor.value === '') floor.value = parent.selectedOptions[0]?.dataset.floor || '';
  }

  function syncPartyType(form) {
    const type = form.querySelector('select[name="party_type"]');
    if (!type) return;
    for (const fields of form.querySelectorAll('[data-party-type-fields]')) {
      fields.hidden = fields.dataset.partyTypeFields !== type.value;
      for (const input of fields.querySelectorAll('input')) input.disabled = fields.hidden;
    }
    for (const input of form.querySelectorAll('[data-party-bank] input')) {
      input.required = type.value !== 'person';
    }
  }

  const referenceLabels = {
    name: 'Название в документах', search_name: 'Название для поиска', kpp: 'КПП', ogrn: 'ОГРН',
    ogrnip: 'ОГРНИП', address: 'Адрес', signer: 'Подписант', signer_position: 'Должность подписанта',
    bank: 'Банк', correspondent_account: 'Корреспондентский счёт'
  };

  async function previewReference(button) {
    const form = button.form;
    const kind = button.dataset.partyReference;
    const input = form.elements.namedItem(kind);
    const panel = form.querySelector('[data-party-reference-result="' + kind + '"]');
    const value = input.value.trim();
    const type = form.elements.namedItem('party_type').value;
    panel.hidden = false;
    panel.textContent = 'Поиск реквизитов…';
    button.disabled = true;
    try {
      const params = new URLSearchParams({kind, value, id: form.elements.namedItem('id').value});
      const response = await fetch('/rental/party-reference?' + params, {headers: {Accept: 'application/json'}});
      if (!response.ok) throw new Error(response.status === 422 ? 'Проверьте введённый ИНН или БИК.' : 'Не удалось получить реквизиты.');
      const result = await response.json();
      if (input.value.trim() !== value) {
        panel.textContent = 'Номер изменился. Повторите поиск.';
        return;
      }
      const changes = [];
      const list = document.createElement('ul');
      for (const [key, proposed] of Object.entries(result.values)) {
        const field = form.elements.namedItem(key);
        if (!referenceLabels[key] || !field || field.disabled || field.value.trim() === proposed) continue;
        const row = document.createElement('li');
        row.textContent = referenceLabels[key] + ' · ' + (field.value.trim() || '—') + ' → ' + proposed;
        list.append(row);
        changes.push([field, field.value, proposed]);
      }
      panel.textContent = result.source ? result.source + (changes.length ? '' : ' · изменений нет.') : 'Справочный источник недоступен.';
      if (!changes.length) return;
      const apply = document.createElement('button');
      apply.type = 'button';
      apply.className = 'btn btn-secondary';
      apply.textContent = 'Подставить в форму';
      apply.addEventListener('click', () => {
        if (input.value.trim() !== value || form.elements.namedItem('party_type').value !== type
            || changes.some(([field, current]) => field.value !== current)) {
          panel.textContent = 'Данные формы изменились. Повторите поиск.';
          return;
        }
        for (const [field, current, proposed] of changes) {
          field.value = proposed;
          field.dispatchEvent(new Event('input', {bubbles: true}));
        }
        panel.textContent = 'Реквизиты подставлены. Проверьте их и сохраните карточку.';
      }, {once: true});
      panel.append(list, apply);
    } catch (error) {
      panel.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  }

  function syncObjectActivity(form) {
    if (!form?.matches('[data-rental-object-editor]')) return;
    const state = form.querySelector('[name="active"]');
    const date = form.querySelector('input[name="active_on"]');
    if (!state || !date) return;
    const changed = state.value !== form.dataset.originalActive;
    date.disabled = !changed;
    date.required = changed;
  }

  function enhance(container) {
    for (const form of container.querySelectorAll('form')) {
      syncAddress(form);
      syncRecipients(form);
      syncPartyType(form);
      syncObjectFloor(form);
      syncObjectActivity(form);
    }
  }

  document.addEventListener('invalid', event => {
    if (!event.target.closest('[data-party-editor]')) return;
    event.preventDefault();
    event.target.focus({preventScroll: true});
    event.target.scrollIntoView({block: 'nearest'});
    window.PlanexNotifications?.show({tone: 'danger', message: event.target.validationMessage});
  }, true);

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-party-reference]');
    if (button) previewReference(button);
  });

  document.addEventListener('change', event => {
    if (event.target.matches('select[name="party_type"]')) syncPartyType(event.target.closest('form'));
    if (event.target.matches('select[name="active"]')) syncObjectActivity(event.target.closest('form'));
    if (event.target.matches('select[name="cadastre_id"]')) syncObjectFloor(event.target.closest('form'));
    // Source default applies only to a new cadastre until the user chooses it.
    const cadastre = event.target.closest('[data-rental-cadastre-editor][data-rental-new-cadastre]');
    if (cadastre && event.target.matches('select[name="rentable"]')) {
      chosenCadastreRentability.add(cadastre);
    }
    if (cadastre && event.target.matches('select[name="type"]') && !chosenCadastreRentability.has(cadastre)) {
      const rentable = cadastre.querySelector('select[name="rentable"]');
      if (rentable) rentable.value = event.target.value === 'Коридор' ? '0' : '1';
    }
    if (event.target.matches('input[type="checkbox"][name="postal_same_as_legal"]')) {
      syncAddress(event.target.closest('form'));
    }
    if (event.target.matches('input[type="checkbox"][name="document_recipient"],input[type="checkbox"][name="additional_document_recipient"]')) syncRecipients(event.target.closest('form'));
  });
  document.addEventListener('input', event => {
    if (event.target.matches('[name="legal_address"],[name="address"]')) syncAddress(event.target.closest('form'));
    if (event.target.matches('[name="email"],[name="account_email"]')) syncRecipients(event.target.closest('form'));
  });

  window.RentalPartyUI = Object.freeze({enhance});
})();
