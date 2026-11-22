'use strict';
// Presentation adapter for C directory-ui.js:19–23,90. PHP owns fields and validation.
(() => {
  const chosenCadastreRentability = new WeakSet();
  const referenceValueEvents = new WeakSet();
  const observedInn = new WeakMap();
  const innReferenceValues = new WeakMap();
  const externalValueClass = 'is-external-value';
  function syncAddress(form) {
    if (!form) return;
    const same = form.querySelector('input[type="checkbox"][name="postal_same_as_legal"]');
    const legal = form.querySelector('[name="legal_address"]') || form.querySelector('[name="address"]');
    const postal = form.querySelector('[name="postal_address"]');
    if (!same || !legal || !postal) return;
    postal.readOnly = same.checked;
    postal.setAttribute('aria-readonly', String(same.checked));
    if (same.checked) {
      postal.value = legal.value;
      const origin = innReferenceValues.get(legal);
      if (origin?.value === legal.value) innReferenceValues.set(postal, origin);
      else innReferenceValues.delete(postal);
    }
    postal.classList.toggle(externalValueClass, same.checked && legal.classList.contains(externalValueClass));
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
    const bank = form.querySelector('[data-party-bank]');
    if (!bank) return;
    const required = type.value !== 'person' && bank.dataset.bankRequired === '1';
    for (const input of bank.querySelectorAll('input')) input.required = required;
    for (const mark of bank.querySelectorAll('.req')) mark.hidden = !required;
  }

  const referenceLabels = {
    name: 'Название в документах', search_name: 'Название для поиска', kpp: 'КПП', ogrn: 'ОГРН',
    ogrnip: 'ОГРНИП', address: 'Адрес', signer: 'Подписант', signer_position: 'Должность подписанта',
    bank: 'Банк', correspondent_account: 'Корреспондентский счёт'
  };

  function setReferenceField(field, proposed, external, inn = null) {
    field.value = proposed;
    if (inn === null) innReferenceValues.delete(field);
    else innReferenceValues.set(field, {inn, value: field.value});
    field.classList.toggle(externalValueClass, external);
    const event = new Event(field.matches('select') ? 'change' : 'input', {bubbles: true});
    referenceValueEvents.add(event);
    field.dispatchEvent(event);
  }

  function syncEdoOperator(form, action) {
    const operator = form.elements.namedItem('edo');
    const identifier = form.elements.namedItem('edo_id');
    const conflict = form.querySelector('[data-edo-conflict]');
    if (!operator?.matches('select[data-rental-picker="edo"]') || !identifier || !conflict) return;
    const prefix = identifier.value.trim().slice(0, 3).toUpperCase();
    const matches = prefix.length === 3 ? [...operator.options].filter(option =>
      option.dataset.prefixes?.split(' ').includes(prefix)) : [];
    const automatic = operator.classList.contains(externalValueClass);
    conflict.hidden = true;
    if (matches.length !== 1) {
      if (automatic) setReferenceField(operator, '', false);
      return;
    }
    const name = matches[0].value;
    if (operator.value === name || (action === 'manual' && !operator.value)) return;
    if (!operator.value || automatic || action === 'confirm') {
      setReferenceField(operator, name, true);
      return;
    }
    conflict.querySelector('[role="status"]').textContent = 'По идентификатору найден оператор «' + name
      + '». Заменить выбранного оператора?';
    conflict.hidden = false;
  }

  function setKppLookupError(field, active) {
    field.classList.toggle('is-error', active);
    if (active) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', 'party-reference-inn-error');
    } else {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    }
  }

  function syncInnChange(input) {
    const form = input.form;
    const current = input.value.trim();
    if (current === (observedInn.get(input) ?? input.defaultValue.trim())) return;
    observedInn.set(input, current);
    if (form.elements.namedItem('id').value === '0') {
      for (const field of form.elements) {
        const origin = innReferenceValues.get(field);
        if (!origin || origin.inn === current) continue;
        if (origin.value === field.value) setReferenceField(field, '', false);
        else {
          innReferenceValues.delete(field);
          field.classList.remove(externalValueClass);
        }
      }
    }
    setKppLookupError(form.elements.namedItem('kpp'), false);
    for (const status of form.querySelectorAll('#party-reference-inn-error,[data-party-reference-result="inn"]')) {
      status.hidden = true;
      status.textContent = '';
    }
  }

  async function previewReference(button) {
    const form = button.form;
    const kind = button.dataset.partyReference;
    const input = form.elements.namedItem(kind);
    if (kind === 'inn') syncInnChange(input);
    const panel = form.querySelector('[data-party-reference-result="' + kind + '"]');
    const error = form.querySelector('#party-reference-' + kind + '-error');
    const kppField = form.elements.namedItem('kpp');
    const showError = (message, field) => {
      if (!form.isConnected) return;
      if (kind === 'inn') setKppLookupError(kppField, field === 'kpp');
      panel.textContent = '';
      panel.hidden = true;
      error.hidden = false;
      error.textContent = kind === 'bik' ? 'Поиск банка. ' + message : message;
      if (field === 'kpp' && kppField.disabled) error.textContent += ' Для ввода КПП выберите тип «Организация».';
      if (kind === 'bik') error.scrollIntoView({block: 'nearest', inline: 'nearest'});
    };
    const value = input.value.trim();
    const typeField = form.elements.namedItem('party_type');
    const type = typeField.value;
    const lookupKpp = () => kind === 'inn' && !kppField.disabled ? kppField.value.trim() : '';
    const kpp = lookupKpp();
    const id = form.elements.namedItem('id').value;
    const newParty = id === '0';
    const writtenForInn = newParty && kind === 'inn' ? value : null;
    const initialValues = newParty ? new Map(Object.keys(referenceLabels).map(key => [key, form.elements.namedItem(key)?.value])) : null;
    const sameLookup = (expectedType, expectedKpp) => input.value.trim() === value
      && (kind !== 'inn' || (typeField.value === expectedType && lookupKpp() === expectedKpp));
    error.hidden = true;
    error.textContent = '';
    input.setAttribute('aria-describedby', error.id);
    panel.hidden = false;
    panel.textContent = 'Поиск реквизитов…';
    button.disabled = true;
    try {
      const params = new URLSearchParams({kind, value, kpp, type, id,
        _csrf: form.elements.namedItem('_csrf').value});
      const response = await fetch('/rental/party-reference', {
        method: 'POST', body: params, headers: {Accept: 'application/json'}
      });
      if (!form.isConnected) return;
      if (!response.ok && response.status !== 422) {
        showError('Не удалось получить реквизиты. Повторите поиск или введите их вручную.');
        return;
      }
      const result = await response.json();
      if (!sameLookup(type, kpp)) {
        showError('Данные поиска изменились. Повторите поиск.');
        return;
      }
      const external = result.external === true;
      if (kind === 'inn' && newParty && ['company', 'entrepreneur'].includes(result.partyType) && result.partyType !== type) {
        setReferenceField(typeField, result.partyType, external, writtenForInn);
      }
      if (result.error) {
        showError(result.error, result.field);
        return;
      }
      if (kind === 'inn') {
        setKppLookupError(kppField, false);
      }
      let hasProposals = false;
      const changes = [];
      const list = document.createElement('ul');
      for (const [key, proposed] of Object.entries(result.values)) {
        const field = form.elements.namedItem(key);
        if (!referenceLabels[key] || !field || field.disabled) continue;
        hasProposals = true;
        if (field.value.trim() === proposed || (key === 'kpp' && kpp.toUpperCase() === proposed)) continue;
        if (newParty && !field.value.trim() && field.value === initialValues.get(key)
            && key !== 'signer' && key !== 'signer_position') {
          setReferenceField(field, proposed, external, writtenForInn);
          continue;
        }
        const row = document.createElement('li');
        row.textContent = referenceLabels[key] + ' · ' + (field.value.trim() || '—') + ' → ' + proposed;
        list.append(row);
        changes.push([field, field.value, proposed]);
      }
      if (newParty && hasProposals && !changes.length) {
        panel.textContent = '';
        panel.hidden = true;
        return;
      }
      const source = result.source ? result.source + (changes.length || result.message ? '' : ' · изменений нет.') : '';
      const status = [
        source,
        newParty && changes.length ? 'Подтвердите замену введённых реквизитов.' : '', result.message
      ];
      panel.textContent = status.filter(Boolean).join(' · ') || 'Справочный источник недоступен.';
      if (!changes.length) return;
      const previewType = typeField.value;
      const previewKpp = lookupKpp();
      const apply = document.createElement('button');
      apply.type = 'button';
      apply.className = 'btn btn-secondary';
      apply.textContent = 'Подставить в форму';
      apply.addEventListener('click', () => {
        if (!sameLookup(previewType, previewKpp) || changes.some(([field, current]) => field.value !== current)) {
          showError('Данные формы изменились. Повторите поиск.');
          return;
        }
        for (const [field, , proposed] of changes) setReferenceField(field, proposed, external, writtenForInn);
        panel.textContent = newParty ? '' : 'Реквизиты подставлены. Проверьте их и сохраните карточку.';
        panel.hidden = newParty;
      }, {once: true});
      panel.append(list, apply);
    } catch {
      showError('Не удалось получить реквизиты. Повторите поиск или введите их вручную.');
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
    const message = event.target.name === 'party_type' && event.target.validity.valueMissing
      ? 'Выберите тип клиента.' : event.target.validationMessage;
    window.PlanexNotifications?.show({tone: 'danger', message});
  }, true);

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-party-reference]');
    if (button) previewReference(button);
    const applyEdo = event.target.closest('[data-edo-apply]');
    if (applyEdo) syncEdoOperator(applyEdo.form, 'confirm');
  });

  document.addEventListener('change', event => {
    if (event.target.matches('#party-reference-inn')) syncInnChange(event.target);
    if (event.target.matches('[data-party-editor] input[name="edo_id"]')) syncEdoOperator(event.target.form, 'identifier');
    if (event.target.matches('select[data-rental-picker="edo"]') && !referenceValueEvents.has(event)) {
      event.target.classList.remove(externalValueClass);
      syncEdoOperator(event.target.form, 'manual');
    }
    if (event.target.matches('select[name="party_type"]')) {
      if (!referenceValueEvents.has(event)) {
        event.target.classList.remove(externalValueClass);
        innReferenceValues.delete(event.target);
      }
      syncPartyType(event.target.closest('form'));
    }
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
    if (!referenceValueEvents.has(event)) {
      event.target.classList.remove(externalValueClass);
      innReferenceValues.delete(event.target);
    }
    if (event.target.matches('[data-party-editor] input[name="edo_id"]')) syncEdoOperator(event.target.form, 'identifier');
    if (event.target.matches('#party-reference-inn')) syncInnChange(event.target);
    if (event.target.matches('[name="legal_address"],[name="address"]')) syncAddress(event.target.closest('form'));
    if (event.target.matches('[name="email"],[name="account_email"]')) syncRecipients(event.target.closest('form'));
  });

  window.RentalPartyUI = Object.freeze({enhance});
})();
