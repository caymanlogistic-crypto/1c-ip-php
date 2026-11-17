'use strict';
// Presentation adapter for C directory-ui.js:19–23,90. PHP owns fields and validation.
(() => {
  const chosenCadastreRentability = new WeakSet();
  function showPanel(editor, key, focusTab = false) {
    if (!editor) return;
    for (const panel of editor.querySelectorAll('[data-party-panel]')) {
      panel.hidden = panel.dataset.partyPanel !== key;
    }
    for (const tab of editor.querySelectorAll('[data-party-tab]')) {
      const selected = tab.dataset.partyTab === key;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focusTab) tab.focus({preventScroll: true});
    }
  }

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

  function enhance(container) {
    for (const editor of container.querySelectorAll('[data-party-editor]')) {
      const selected = editor.querySelector('[data-party-tab][aria-selected="true"]');
      showPanel(editor, selected?.dataset.partyTab || 'details');
    }
    for (const form of container.querySelectorAll('form')) {
      syncAddress(form);
      syncRecipients(form);
      syncObjectFloor(form);
    }
  }

  document.addEventListener('click', event => {
    const tab = event.target.closest('[data-party-tab]');
    if (!tab) return;
    const editor = tab.closest('[data-party-editor]');
    if (!editor) return;
    event.preventDefault();
    showPanel(editor, tab.dataset.partyTab);
  });

  document.addEventListener('keydown', event => {
    const tab = event.target.closest('[data-party-tab]');
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
    if (!tab || !keys.includes(event.key)) return;
    const editor = tab.closest('[data-party-editor]');
    if (!editor) return;
    const tabs = [...editor.querySelectorAll('[data-party-tab]')];
    const index = tabs.indexOf(tab);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
      : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    event.preventDefault();
    showPanel(editor, tabs[next].dataset.partyTab, true);
  });

  document.addEventListener('invalid', event => {
    const panel = event.target.closest('[data-party-panel]');
    if (!panel) return;
    event.preventDefault();
    showPanel(panel.closest('[data-party-editor]'), panel.dataset.partyPanel);
    event.target.focus({preventScroll: true});
    event.target.scrollIntoView({block: 'nearest'});
    window.PlanexNotifications?.show({tone: 'danger', message: event.target.validationMessage});
  }, true);

  document.addEventListener('change', event => {
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
