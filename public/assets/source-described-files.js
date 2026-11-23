'use strict';
(() => {
  const nextSlot = new WeakMap();

  function addFile(group, list, selected) {
    const index = nextSlot.get(group) ?? 0;
    nextSlot.set(group, index + 1);
    const row = document.createElement('div');
    row.className = 'registry-ui-modal-grid';
    row.dataset.describedFileRow = '';

    const file = document.createElement('input');
    file.type = 'file';
    file.hidden = true;
    file.name = group.dataset.fileName + '[' + index + ']';
    const transfer = new DataTransfer();
    transfer.items.add(selected);
    file.files = transfer.files;

    const actions = document.createElement('div');
    actions.className = 'registry-ui-review-actions';
    const filename = document.createElement('span');
    filename.className = 'registry-ui-modal-text';
    filename.textContent = selected.name;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-secondary';
    remove.dataset.removeDescribedFile = '';
    remove.textContent = 'Убрать';
    remove.setAttribute('aria-label', 'Убрать файл ' + selected.name);
    actions.append(filename, remove);

    const field = document.createElement('label');
    field.className = 'field';
    const title = document.createElement('span');
    title.className = 'field-label';
    title.textContent = 'Короткое описание';
    const description = document.createElement('input');
    description.className = 'field-input';
    description.type = 'text';
    description.name = group.dataset.descriptionName + '[' + index + ']';
    description.maxLength = Number(group.dataset.descriptionLimit);
    description.required = true;
    field.append(title, description);
    row.append(file, actions, field);
    list.append(row);
    return description;
  }

  function error(group, message = '') {
    const output = group.querySelector('[data-described-file-error]');
    output.textContent = message;
    output.hidden = !message;
  }

  document.addEventListener('change', event => {
    const picker = event.target.closest('[data-pick-described-files]');
    if (!picker) return;
    const group = picker.closest('[data-described-files]');
    if (!group) return;
    const list = group.querySelector('[data-described-file-list]');
    const files = Array.from(picker.files);
    picker.value = '';
    const limit = Number(group.dataset.fileLimit);
    if (files.length + list.children.length > limit) {
      error(group, 'За один раз можно добавить до ' + limit + ' файлов.');
      return;
    }
    if (files.some(file => !/\.(docx?|pdf)$/i.test(file.name) || !file.size || file.size > 20 * 1024 * 1024)) {
      error(group, 'Выберите файлы Word или PDF размером до 20 МБ каждый.');
      return;
    }
    error(group);
    const descriptions = files.map(file => addFile(group, list, file));
    descriptions[0]?.focus();
  });

  document.addEventListener('click', event => {
    const button = event.target.closest('[data-remove-described-file]');
    const group = button?.closest('[data-described-files]');
    if (!group) return;
    button.closest('[data-described-file-row]').remove();
    error(group);
    group.querySelector('[data-pick-described-files]').focus();
  });
})();
