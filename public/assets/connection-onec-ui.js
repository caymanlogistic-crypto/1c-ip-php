'use strict';

document.querySelector('#onec-settings[data-open-on-load]')?.showModal();
document.querySelector('#connection-record')?.showModal();

// The full row opens the existing document route, as in the showcase registry.
function openOnecDocument(event) {
  const row = event.target.closest('tr[data-connection-document]');
  if (!row || event.target.closest('a,button,input,select,textarea')) return;
  if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
  if (event.type === 'click' && window.getSelection()?.toString()) return;
  event.preventDefault();
  window.location.assign(row.dataset.connectionDocument);
}
document.addEventListener('click', openOnecDocument);
document.addEventListener('keydown', openOnecDocument);

// Fetch the next existing server page only when the last rendered row is near.
// Rows keep the shared table profile; no provider reads or imports are started.
const onecBody = document.querySelector('#records-body[data-onec-next]');
if (onecBody) {
  const feedback = document.querySelector('#onec-load-feedback');
  let loading = false;
  const observer = new IntersectionObserver(entries => {
    if (entries.some(entry => entry.isIntersecting)) loadNextOnecPage();
  }, { root: document.querySelector('main.content'), rootMargin: '0px 0px 180px 0px' });

  function watchLastRow() {
    observer.disconnect();
    if (onecBody.dataset.onecNext && onecBody.lastElementChild) {
      observer.observe(onecBody.lastElementChild);
    }
  }

  async function loadNextOnecPage() {
    if (loading || !onecBody.dataset.onecNext) return;
    loading = true;
    observer.disconnect();
    feedback.replaceChildren();
    onecBody.closest('table').setAttribute('aria-busy', 'true');
    try {
      const url = new URL(onecBody.dataset.onecNext, location.href);
      if (url.origin !== location.origin || url.pathname !== location.pathname) throw new Error('Invalid page');
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (!response.ok) throw new Error('Page unavailable');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const incoming = page.querySelector('#records-body');
      if (!incoming || !incoming.rows.length || incoming.dataset.onecNext === onecBody.dataset.onecNext) {
        throw new Error('Invalid page');
      }
      const rowKey = row => row.dataset.onecRowKey || row.dataset.connectionDocument;
      const existing = new Set([...onecBody.rows].map(rowKey));
      for (const row of incoming.rows) {
        if (!rowKey(row) || existing.has(rowKey(row))) continue;
        existing.add(rowKey(row));
        onecBody.append(document.importNode(row, true));
      }
      onecBody.dataset.onecNext = incoming.dataset.onecNext || '';
      document.querySelector('#visible-count').textContent = String(onecBody.rows.length);
      watchLastRow();
    } catch {
      const message = document.createElement('p');
      message.className = 'registry-ui-secondary';
      message.textContent = 'Не удалось загрузить следующие записи. Уже загруженные данные сохранены.';
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn btn-secondary';
      retry.textContent = 'Повторить загрузку';
      retry.addEventListener('click', loadNextOnecPage, { once: true });
      feedback.replaceChildren(message, retry);
    } finally {
      loading = false;
      onecBody.closest('table').removeAttribute('aria-busy');
    }
  }
  watchLastRow();
}
