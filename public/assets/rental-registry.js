'use strict';
// Presentation-only registry refresh. Filtering and business completion targets come from PHP.
(() => {
  const completionPaths = new Set(['contracts','contracts-active','contracts-completed','approvals','templates','invoices','invoice-list','reconciliation','parties','organization','cadastre','objects','operations','errors','journal','summary'].map(page=>'/rental/'+page));
  let pending = null;
  let generation = 0;
  let searchTimer = null;
  let savedFeedback = null;

  function localURL(value) {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin) throw new Error('Недоступный адрес.');
    return url;
  }

  function setGroup(button, expanded) {
    if (button.hasAttribute('data-registry-disclosure')) {
      window.PlanexRegistry.setDisclosure(button, expanded);
    }
    button.setAttribute('aria-expanded', String(expanded));
    if (!button.hasAttribute('data-registry-disclosure') && (button.dataset.groupName || ['+','−','–'].includes(button.textContent.trim()))) button.textContent = expanded ? '−' : '+';
    if (!button.hasAttribute('data-registry-disclosure') && button.dataset.groupName) button.setAttribute('aria-label', (expanded ? 'Свернуть ' : 'Развернуть ')+button.dataset.groupName);
    for (const row of document.querySelectorAll('[data-rental-invoice-group]')) {
      if (row.dataset.rentalInvoiceGroup === button.dataset.rentalToggleGroup) row.hidden = !expanded;
    }
  }

  function savedInvoice(value) {
    if (!/^\d+$/.test(String(value))) return;
    if (savedFeedback) {
      clearTimeout(savedFeedback.timer);
      savedFeedback.row.classList.remove('registry-ui-save-flash');
      savedFeedback = null;
    }
    const workspace = document.getElementById('workspace');
    const row = [...workspace.querySelectorAll('tr[data-row-key]')].find(item => item.dataset.rowKey === 'invoice:' + value);
    if (!row) return; // The current server filter may legitimately hide the saved record.
    if (row.dataset.rentalInvoiceGroup) {
      const button = [...workspace.querySelectorAll('[data-rental-toggle-group]')].find(item => item.dataset.rentalToggleGroup === row.dataset.rentalInvoiceGroup);
      if (button) setGroup(button, true);
    }
    const feedback = {row, timer: null};
    savedFeedback = feedback;
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (savedFeedback !== feedback || !row.isConnected) return;
      row.classList.add('registry-ui-save-flash');
      const rect = row.getBoundingClientRect();
      const bounds = workspace.getBoundingClientRect();
      const heading = row.closest('table').tHead.getBoundingClientRect();
      if (rect.top < Math.max(bounds.top, heading.bottom) || rect.bottom > Math.min(innerHeight, bounds.bottom)) {
        row.scrollIntoView({block: 'nearest', inline: 'nearest', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
      }
      feedback.timer = setTimeout(() => {
        row.classList.remove('registry-ui-save-flash');
        if (savedFeedback === feedback) savedFeedback = null;
      }, 1600);
    }));
  }

  async function load(value, options = {}) {
    const url = localURL(value);
    if (options.completion && !completionPaths.has(url.pathname)) throw new Error('Неизвестный реестр завершения операции.');
    pending?.abort();
    const abort = new AbortController();
    pending = abort;
    const revision = ++generation;
    const workspace = document.getElementById('workspace');
    const groups = new Map([...workspace.querySelectorAll('[data-rental-toggle-group]')].map(button=>[button.dataset.rentalToggleGroup,button.getAttribute('aria-expanded')==='true']));
    const focused = workspace.contains(document.activeElement) ? document.activeElement : null;
    const focus = focused ? {id:focused.id,name:focused.name,tag:focused.tagName,type:focused.type,start:focused.selectionStart,end:focused.selectionEnd} : null;
    const scroll = {top:workspace.scrollTop,left:workspace.scrollLeft};
    const response = await fetch(url, {signal:abort.signal,headers:{'X-Requested-With':'ERP-Fetch',Accept:'text/html'}});
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (!response.ok) throw new Error(doc.querySelector('[role=alert]')?.textContent.trim() || 'Не удалось обновить реестр.');
    const replacement = doc.querySelector('#workspace');
    if (!replacement) throw new Error('Сеанс завершён. Войдите снова.');
    if (revision !== generation) return false;
    for (const root of workspace.querySelectorAll('[data-registry-id]')) window.PlanexRegistry?.unmount(root);
    workspace.replaceChildren(...replacement.childNodes);
    const noticeProjection=doc.querySelector('#rental-notices-bootstrap');
    if(noticeProjection)window.dispatchEvent(new CustomEvent('rental:notices',{detail:JSON.parse(noticeProjection.textContent)}));
    for (const root of workspace.querySelectorAll('[data-registry-id]')) window.PlanexRegistry?.mount(root, {managedMarkup:root.hasAttribute('data-registry-managed-markup')});
    for (const button of workspace.querySelectorAll('[data-rental-toggle-group]')) {
      if (groups.has(button.dataset.rentalToggleGroup)) setGroup(button, groups.get(button.dataset.rentalToggleGroup));
    }
    history.replaceState(null, '', url.pathname+url.search);
    if (options.preserve) {
      const target = focus && [...workspace.querySelectorAll('input,select,button')].find(node=>focus.id ? node.id===focus.id : node.name===focus.name && node.tagName===focus.tag && node.type===focus.type);
      if (target) {
        target.focus({preventScroll:true});
        if (typeof target.setSelectionRange==='function' && focus.start!==null) {
          try { target.setSelectionRange(focus.start,focus.end); } catch { /* Non-text controls have no caret. */ }
        }
      }
      workspace.scrollTop = scroll.top;
      workspace.scrollLeft = scroll.left;
    }
    if (options.savedInvoice) savedInvoice(options.savedInvoice);
    return true;
  }

  function fromForm(form, submitter = null, latestFilters = null) {
    if (!form.isConnected) return;
    const url = localURL(form.getAttribute('action') || location.href);
    url.search = '';
    const data = new FormData(form, submitter);
    if (latestFilters) {
      // Invoice tabs preserve the values currently typed, before debounce refresh.
      for (const [name,value] of Object.entries(latestFilters)) data.set(name, value);
    }
    for (const [key,value] of data) if (key!=='_csrf' && typeof value==='string') url.searchParams.append(key,value);
    load(url, {preserve:true}).catch(error=>{
      if (error.name!=='AbortError') window.PlanexNotifications?.show({tone:'danger',message:error.message});
    });
  }

  function filterEmployee(row) {
    const role = row.dataset.rentalEmployeeFilter;
    if (!['manager','lawyer','accountant','director'].includes(role)) return;
    const form = document.querySelector('form[data-rental-error-filter]');
    const actor = form?.querySelector('select[name="actor"]');
    const flag = form?.querySelector('input[type="hidden"][name="flag"]');
    if (!form || form.closest('dialog') || !actor || !flag
        || ![...actor.options].some(option=>option.value===role)) return;
    actor.value = role;
    flag.value = 'all';
    clearTimeout(searchTimer);
    // Submit the current form, including text entered before debounce fires.
    fromForm(form);
  }

  document.addEventListener('dblclick', event=>{
    const row = event.target.closest('[data-rental-employee-filter]');
    if (row && !event.target.closest('a,button,input,select,textarea')) {
      event.preventDefault();
      filterEmployee(row);
    }
  });
  document.addEventListener('keydown', event=>{
    const form = event.target.closest('form[data-rental-registry-filter]');
    if (event.key==='Enter' && event.target.matches('.registry-ui-search') && form && !form.closest('dialog')) {
      // Enter searches; it must not implicitly activate the first submit button (reset).
      event.preventDefault();
      clearTimeout(searchTimer);
      fromForm(form);
      return;
    }
    const row = event.target.closest('[data-rental-employee-filter]');
    if (row && event.key==='Enter' && event.target===row) {
      event.preventDefault();
      filterEmployee(row);
    }
  });

  document.addEventListener('input', event=>{
    const form = event.target.closest('form[data-rental-registry-filter]');
    if (!form || form.closest('dialog') || !event.target.matches('.registry-ui-search')) return;
    generation++;
    pending?.abort();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(()=>fromForm(form), 220);
  });
  document.addEventListener('change', event=>{
    const form = event.target.closest('form[data-rental-registry-filter]');
    if (form && !form.closest('dialog') && event.target.matches('select,input[type=checkbox]')) fromForm(form);
  });
  document.addEventListener('submit', event=>{
    const form = event.target;
    if (form.matches('form.registry-ui-tabs') && !form.closest('dialog')
        && ['/rental/invoices','/rental/invoice-list','/rental/payments'].includes(location.pathname)) {
      const filters = document.querySelector('[data-rental-invoice-registry] form[data-rental-registry-filter]');
      if (filters) {
        event.preventDefault();
        clearTimeout(searchTimer);
        fromForm(form, event.submitter, {
          q: filters.querySelector('input.registry-ui-search[name="q"]')?.value || '',
          party: filters.querySelector('select[name="party"]')?.value || 'all'
        });
        return;
      }
    }
    if (form.matches('form[data-rental-registry-filter]') && !form.closest('dialog')) {
      event.preventDefault();
      clearTimeout(searchTimer);
      fromForm(form, event.submitter);
    }
  });

  window.RentalRegistry = Object.freeze({
    load,
    currentWorkspace() {
      return completionPaths.has(location.pathname) ? location.pathname + location.search : '';
    },
    toggleGroup(button) {setGroup(button,button.getAttribute('aria-expanded')!=='true');}
  });
})();
