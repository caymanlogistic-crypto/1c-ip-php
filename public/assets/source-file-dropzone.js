(() => {
  'use strict';

  function dropzone(event) {
    return event.target.closest?.('[data-file-dropzone]');
  }

  function draggedFiles(event) {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
  }

  function showDropTarget(event) {
    const zone = dropzone(event);
    if (!zone || !draggedFiles(event)) return;
    event.preventDefault();
    const available = !zone.closest('[data-submitting]');
    event.dataTransfer.dropEffect = available ? 'copy' : 'none';
    zone.classList.toggle('is-dragover', available);
  }

  document.addEventListener('dragenter', showDropTarget);
  document.addEventListener('dragover', showDropTarget);

  document.addEventListener('dragleave', event => {
    const zone = dropzone(event);
    if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove('is-dragover');
  });
  document.addEventListener('change', event => {
    const input = event.target;
    if (!input.matches?.('input[type="file"]')) return;
    const selection = dropzone(event)?.querySelector('[data-file-dropzone-selection]');
    if (selection) selection.textContent = input.files?.[0]?.name ?? '';
  });

  document.addEventListener('drop', event => {
    const zone = dropzone(event);
    const files = event.dataTransfer?.files;
    if (!zone || !files?.length) return;
    event.preventDefault();
    zone.classList.remove('is-dragover');
    const input = zone.querySelector('input[type="file"]');
    if (!input || input.disabled || zone.closest('[data-submitting]')) return;
    input.files = files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
