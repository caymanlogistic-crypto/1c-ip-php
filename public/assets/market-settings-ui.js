(() => {
  const form = document.querySelector('form[data-market-settings]');
  if (!form) return;
  const dialog = document.getElementById('neighbor-dialog');
  const list = form.querySelector('[data-market-neighbors]');
  const address = document.getElementById('new-neighbor');
  let nextIndex = list?.children.length || 0;
  const status = message => {
    for (const node of form.querySelectorAll('[role="status"]')) node.textContent = message;
  };

  document.addEventListener('click', event => {
    const reload = event.target.closest('[data-market-reload]');
    if (reload && form.contains(reload)) location.assign('/auxiliary/market/settings');
    const remove = event.target.closest('[data-market-remove-neighbor]');
    if (remove && form.contains(remove)) remove.closest('[data-market-neighbor]')?.remove();
    if (event.target.closest('[data-market-add-neighbor]') && dialog) {
      address.value = '';
      address.setCustomValidity('');
      document.getElementById('new-neighbor-subunits').checked = false;
      dialog.showModal();
      address.focus();
    }
    if (['close-neighbor','cancel-neighbor'].includes(event.target.closest('button')?.id)) dialog?.close();
    if (event.target.closest('button')?.id === 'add-neighbor' && dialog) {
      if (!address.reportValidity()) return;
      const fragment = document.querySelector('template[data-market-neighbor-template]').content.cloneNode(true);
      for (const input of fragment.querySelectorAll('[name]')) input.name = input.name.replace('__INDEX__', String(nextIndex));
      fragment.querySelector('input[type="text"]').value = address.value;
      fragment.querySelector('input[type="checkbox"]').checked = document.getElementById('new-neighbor-subunits').checked;
      nextIndex++;
      list.append(fragment);
      dialog.close();
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (form.dataset.submitting) return;
    const data = new FormData(form, event.submitter);
    const buttons = [...form.querySelectorAll('button[type="submit"]')];
    form.dataset.submitting = 'true';
    for (const button of buttons) button.disabled = true;
    try {
      const response = await fetch('/auxiliary/market/settings', {
        method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'Market-Settings'}, body: data
      });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Не удалось сохранить настройки. Обновите страницу и войдите снова.');
      const result = await response.json();
      if (!response.ok || result.saved !== true) throw new Error(result.error || 'Не удалось сохранить настройки.');
      form.querySelector('[name="expected_etag"]').value = result.etag;
      status('Настройки сохранены');
    } catch (error) {
      status(error.message);
    } finally {
      delete form.dataset.submitting;
      for (const button of buttons) button.disabled = false;
    }
  });
})();
