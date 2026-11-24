'use strict';

(() => {
  const dialog = document.querySelector('#message-settings[data-open-on-load]');
  if (dialog && !dialog.open) dialog.showModal();

  const groups = document.querySelector('[data-integration-groups]');
  const template = document.getElementById('integration-group-template');
  document.querySelector('[data-integration-group-add]')?.addEventListener('click', () => {
    const markup = template.innerHTML.replaceAll('__INDEX__', String(groups.children.length));
    groups.insertAdjacentHTML('beforeend', markup);
  });
  groups?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-integration-group-remove]');
    if (!button) return;
    const group = button.closest('[data-integration-group]');
    group.querySelector('[data-integration-group-delete]').value = '1';
    group.hidden = true;
  });

  document.querySelector('[data-integration-files]')?.addEventListener('change', (event) => {
    const list = document.querySelector('[data-integration-file-list]');
    list.replaceChildren();
    for (const file of event.target.files) {
      const row = document.createElement('div');
      row.className = 'file-row';
      row.textContent = file.name + ' · ' + file.size + ' Б';
      list.append(row);
    }
  });
})();
