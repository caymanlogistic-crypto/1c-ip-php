'use strict';

// Shared browser client: other pages pass a business identity and their current CSRF.
// No callers supply credentials, URLs, native bodies or approval flags.
window.OnecOperations = Object.freeze({
  async call(connection, action, input = {}, csrf = '') {
    if (!['onec-counterparty', 'onec-invoice', 'onec-print'].includes(connection) ||
        !['check', 'preview', 'prepare', 'dispatch', 'reconcile', 'state', 'generate'].includes(action)) {
      throw new Error('Неизвестное действие 1С.');
    }
    const read = ['preview', 'state'].includes(action);
    const params = new URLSearchParams(input);
    const url = '/integrations/onec-actions/' + connection + '/' + action;
    if (!read) params.set('_csrf', csrf);
    const response = await fetch(url + (read ? '?' + params : ''), {
      method: read ? 'GET' : 'POST', credentials: 'same-origin',
      headers: {Accept: 'application/json'}, ...(read ? {} : {body: params}),
    });
    const result = await response.json();
    if (!result || typeof result.state !== 'string') throw new Error('Ответ 1С не подтверждён.');
    return {...result, httpStatus: response.status};
  },
});

(() => {
  const root = document.querySelector('[data-onec-operation]');
  if (!root) return;
  const connection = root.dataset.onecOperation;
  const csrf = root.dataset.onecCsrf;
  const dialog = root.querySelector('#onec-operation-dialog');
  const output = dialog.querySelector('[data-onec-operation-result]');
  const commands = [...dialog.querySelectorAll('[data-onec-command]')];
  let identity = null, intent = null, busy = false, selection = 0;

  function show(target, result) {
    target.replaceChildren();
    for (const text of [result.title, ...(result.messages || [])].filter(Boolean)) {
      const line = document.createElement('p');
      line.className = 'registry-ui-modal-text';
      line.textContent = text;
      target.append(line);
    }
  }
  function buttons(state) {
    for (const button of commands) {
      button.hidden = !({prepare: state === 'ready_for_prepare',
        dispatch: state === 'prepared' && root.dataset.onecWriteEnabled === 'true',
        reconcile: ['unknown', 'unconfirmed', 'posting', 'ack_received'].includes(state)})[button.dataset.onecCommand];
    }
  }
  root.querySelector('[data-onec-check]')?.addEventListener('click', async event => {
    const button = event.currentTarget, target = root.querySelector('#onec-operation-feedback');
    button.disabled = true;
    show(target, {title: 'Проверка подключения…'});
    try {
      const result = await window.OnecOperations.call(connection, 'check', {request_key: crypto.randomUUID()}, csrf);
      show(target, result);
      if (result.scope === 'connection_read_only') {
        show(target, {...result, messages: ['Проверен доступ для чтения. Создание записей этой проверкой не выполняется.']});
      }
    } catch { show(target, {title: 'Ответ не получен. Проверьте историю 1С перед повтором.'}); }
    finally { button.disabled = false; }
  });
  async function open(event) {
    const row = event.target.closest('[data-onec-payload]');
    if (!row || event.target.closest('button,a,input,select,textarea')) return;
    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    if (busy || (event.type === 'click' && window.getSelection()?.toString())) return;
    event.preventDefault();
    identity = JSON.parse(row.dataset.onecPayload); intent = identity.intent_id || null;
    const current = ++selection;
    dialog.querySelector('[data-onec-selection]').textContent = row.innerText.replaceAll('\n', ' · ');
    buttons('loading'); show(output, {title: 'Проверка данных…'}); dialog.showModal();
    try {
      const result = await window.OnecOperations.call(connection, intent ? 'state' : 'preview', identity, csrf);
      if (current !== selection) return;
      show(output, result); buttons(result.state);
      if (result.state === 'prepared' && root.dataset.onecWriteEnabled !== 'true') {
        show(output, {...result, messages: ['Передача в 1С пока не настроена. Данные не отправлялись.']});
      }
    } catch { if (current === selection) show(output, {title: 'Не удалось проверить данные. Запись в 1С не выполнялась.'}); }
  }
  root.addEventListener('click', open); root.addEventListener('keydown', open);
  dialog.addEventListener('close', () => { ++selection; });
  for (const button of commands) button.addEventListener('click', async () => {
    if (busy) return;
    busy = true; for (const item of commands) item.disabled = true;
    const action = button.dataset.onecCommand;
    try {
      const result = await window.OnecOperations.call(connection, action, action === 'prepare' ? identity : {intent_id: intent}, csrf);
      intent = result.intent_id || intent; show(output, result); buttons(result.state);
    } catch {
      show(output, {title: action === 'dispatch' ? 'Результат записи неизвестен. Сначала сверьте результат; повторная отправка не выполняется.' : 'Ответ не подтверждён. Проверьте историю операций.'});
      buttons(action === 'dispatch' ? 'unknown' : 'blocked');
    } finally { busy = false; for (const item of commands) item.disabled = false; }
  });
})();
