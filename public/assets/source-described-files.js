'use strict';
(() => {
  const nextSlot = new WeakMap();
  const label = (text, input) => {
    const field = document.createElement('label');
    field.className = 'field';
    const title = document.createElement('span');
    title.className = 'field-label';
    title.textContent = text;
    field.append(title, input);
    return field;
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-add-described-file], [data-remove-described-file]');
    const group = button?.closest('[data-described-files]');
    if (!group) return;
    const list = group.querySelector('[data-described-file-list]');
    const add = group.querySelector('[data-add-described-file]');
    if (button.hasAttribute('data-remove-described-file')) {
      button.closest('[data-described-file-row]').remove();
      add.disabled = false;
      add.focus();
      return;
    }
    const limit = Number(group.dataset.fileLimit);
    if (list.children.length >= limit) return;
    const index = nextSlot.get(group) ?? 0;
    nextSlot.set(group, index + 1);
    const row = document.createElement('div');
    row.className = 'registry-ui-modal-grid';
    row.dataset.describedFileRow = '';
    const inputs = document.createElement('div');
    inputs.className = 'form-grid-2';
    const file = document.createElement('input');
    file.className = 'field-input';
    file.type = 'file';
    file.name = group.dataset.fileName + '[' + index + ']';
    file.accept = group.dataset.fileAccept;
    const description = document.createElement('input');
    description.className = 'field-input';
    description.type = 'text';
    description.name = group.dataset.descriptionName + '[' + index + ']';
    description.maxLength = Number(group.dataset.descriptionLimit);
    const syncRequired = () => {
      file.required = description.value.trim() !== '';
      description.required = file.files.length > 0;
    };
    file.addEventListener('change', syncRequired);
    description.addEventListener('input', syncRequired);
    inputs.append(label('Файл', file), label('Короткое описание', description));
    const controls = document.createElement('div');
    controls.className = 'demo-row';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-secondary';
    remove.dataset.removeDescribedFile = '';
    remove.textContent = 'Убрать файл';
    controls.append(remove);
    row.append(inputs, controls);
    list.append(row);
    add.disabled = list.children.length >= limit;
    file.focus();
  });
})();
