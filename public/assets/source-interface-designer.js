'use strict';
(() => {
  const mounted = new WeakSet();
  const editing = new Map();
  const clamp = (value, min, max) => Math.min(max, Math.max(min, Math.round(value)));
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  const button = (text, action, primary = false) => {
    const node = element('button', text, 'btn ' + (primary ? 'btn-primary' : 'btn-secondary'));
    node.type = 'button';
    node.addEventListener('click', action);
    return node;
  };
  function reorder(parent, items) {
    if (!parent) return;
    const nodes = new Map([...parent.children].filter(node => node.dataset.designKey)
      .map(node => [node.dataset.designKey, node]));
    let before = [...parent.children].find(node => !node.dataset.designKey && node.previousElementSibling?.dataset.designKey) || null;
    // Keep unkeyed siblings in their existing positions before or after the configurable block.
    for (const item of [...items].reverse()) {
      const node = nodes.get(item.key);
      if (!node) continue;
      if (node.nextElementSibling !== before) parent.insertBefore(node, before);
      before = node;
    }
  }
  function summary(row) {
    const cells = [...row.cells];
    const content = row.querySelector('[data-design-summary-content]');
    const party = cells.find(cell => cell.dataset.designKey === 'party');
    let start = null;
    for (const cell of cells) {
      cell.hidden = false;
      cell.colSpan = 1;
      if (!cell.hasAttribute('data-design-merge')) {
        start = null;
        continue;
      }
      if (!start) start = cell;
      else {
        start.colSpan++;
        cell.hidden = true;
      }
      if (cell === party && content.parentNode !== start) start.append(content);
    }
  }
  function tableLayout(root, layout) {
    const byKey = new Map(layout.columns.map(item => [item.key, item]));
    const totals = root.querySelector('[data-design-totals]');
    if (totals) {
      reorder(totals, layout.columns);
      for (const node of totals.children) node.querySelector('[data-design-label]').textContent = byKey.get(node.dataset.designKey).label;
    }
    for (const table of root.querySelectorAll('[data-design-table]')) {
      const columns = table.querySelector(':scope > colgroup');
      const headings = table.tHead.rows[0];
      reorder(columns, layout.columns);
      reorder(headings, layout.columns);
      for (const col of columns.children) {
        const width = byKey.get(col.dataset.designKey)?.width;
        col.style.width = width ? width + 'px' : '';
      }
      for (const th of headings.cells) {
        const item = byKey.get(th.dataset.designKey);
        if (item) th.querySelector('[data-design-label]').textContent = item.label;
      }
      for (const row of table.tBodies[0].rows) {
        reorder(row, layout.columns);
        if (row.hasAttribute('data-design-summary')) summary(row);
      }
      table.toggleAttribute('data-design-widths', layout.columns.some(item => item.width));
    }
  }
  function dialogLayout(root, layout) {
    const form = root.querySelector('[data-design-fields]');
    const groups = {
      sections: form,
      fields: form.querySelector('[data-design-key="dates"]'),
      actions: root.querySelector('.modal-foot-actions')
    };
    for (const [key, group] of Object.entries(groups)) {
      const sizes = new Map(layout[key].map(item => [item.key, item.width]));
      reorder(group, layout[key]);
      for (const node of group.children) {
        if (!sizes.has(node.dataset.designKey)) continue;
        const width = sizes.get(node.dataset.designKey);
        node.style.setProperty('--design-item-width', key === 'actions' ? (width ? width + 'px' : '') : width);
      }
    }
    geometry(root, layout.window);
  }
  function geometry(root, box) {
    root.style.setProperty('--design-width', box.width ? box.width + 'px' : '');
    root.style.setProperty('--design-height', box.height ? box.height + 'px' : '');
    // A closed native dialog has no measurable box. Mount it again on open.
    if (!root.open) return;
    const x = Math.max(0, (innerWidth - root.offsetWidth) / 2 - 8);
    const y = Math.max(0, (innerHeight - root.offsetHeight) / 2 - 8);
    root.style.setProperty('--design-x', clamp(box.x, -x, x) + 'px');
    root.style.setProperty('--design-y', clamp(box.y, -y, y) + 'px');
  }
  function dragPointer(node, start, move, end) {
    node.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      event.preventDefault();
      node.setPointerCapture(event.pointerId);
      const origin = start(event);
      const update = e => move(origin, e.clientX - event.clientX, e.clientY - event.clientY);
      const stop = () => {
        node.removeEventListener('pointermove', update);
        end?.();
      };
      node.addEventListener('pointermove', update);
      node.addEventListener('lostpointercapture', stop, {once:true});
    });
  }
  function mountRoot(root) {
    if (mounted.has(root)) {
      root.dispatchEvent(new Event('design:geometry'));
      return;
    }
    mounted.add(root);
    let config = JSON.parse(root.dataset.interfaceDesign);
    const isTable = Boolean(config.applied.columns);
    const paint = layout => isTable ? tableLayout(root,layout) : dialogLayout(root,layout);
    let session = config.csrf || config.demo ? editing.get(config.id) : null;
    paint(config.applied);
    if (!isTable) {
      const fit = () => geometry(root, (session?.layout || config.applied).window);
      root.addEventListener('design:geometry', fit);

    }
    if (!config.csrf && !config.demo) return;
    let dragging = null;
    let busy = false;
    const pane = element('div', '', 'interface-designer');
    const commands = element('div', '', 'interface-designer-controls');
    const controls = element('div', '', 'interface-designer-controls');
    const status = element('span', '', 'interface-designer-status');
    status.setAttribute('role','status');
    pane.append(commands,controls,status);
    if (isTable) root.querySelector(':scope > .table-scroll').before(pane);
    else root.querySelector('.modal-body').prepend(pane);

    function current() { return session?.layout || config.applied; }
    function previewPaint() {
      const focus = document.activeElement;
      paint(current());
      if (focus !== document.activeElement && root.contains(focus)) focus.focus({preventScroll:true});
    }
    async function save(action) {
      if (busy) return;
      busy = true;
      commands.querySelectorAll('button').forEach(node => { node.disabled = true; });
      try {
        let state;
        if (config.demo) {
          state = {applied:config.applied,draft:config.draft,previous:config.previous};
          if (action === 'draft') state.draft = structuredClone(session.layout);
          if (action === 'apply') [state.applied,state.previous,state.draft] = [state.draft,state.applied,null];
          if (action === 'restore') [state.applied,state.previous] = [state.previous,state.applied];
        } else {
          const body = new URLSearchParams({_csrf:config.csrf,id:config.id,revision:config.revision,action});
          if (action === 'draft') body.set('layout', JSON.stringify(session.layout));
          const response = await fetch('/administration/interface-designer', {method:'POST',body});
          if (!response.ok) {
            const error = new DOMParser().parseFromString(await response.text(),'text/html').querySelector('[role="alert"]');
            throw new Error(error?.textContent || 'Не удалось сохранить компоновку.');
          }
          state = await response.json();
        }
        config = {...config,...state,applied:state.applied || config.applied};
        if (action !== 'draft') {
          session = null;
          editing.delete(config.id);
        } else session.saved = true;
        status.textContent = {draft:'Черновик сохранён.',apply:'Компоновка применена для всех ролей.',restore:'Предыдущая версия восстановлена.'}[action];
      } catch (error) {
        status.textContent = error.message;
      } finally {
        busy = false;
        render();
      }
    }
    function changed(rebuild = false) {
      session.saved = false;
      status.textContent = 'Есть несохранённые изменения.';
      if (rebuild) render();
      else previewPaint();
    }
    function move(list, from, to) {
      if (from === to || to < 0 || to >= list.length) return;
      list.splice(to,0,list.splice(from,1)[0]);
      changed(true);
    }
    function number(label, value, min, max, onChange) {
      const host = element('label', label, 'interface-designer-number');
      const input = element('input', '', 'field-input');
      Object.assign(input,{type:'number',value,min,max});
      input.addEventListener('change',() => {
        const result = clamp(input.valueAsNumber || 0,min,max);
        input.value = onChange(result);
        changed();
      });
      host.append(input);
      return host;
    }
    function itemEditor(group, labels, min, max) {
      const list = session.layout[group];
      const available = isTable ? list : list.filter(item => root.querySelector('[data-design-key="'+item.key+'"]'));
      const index = available.findIndex(item => item.key === session.selected[group]);
      const item = available[Math.max(0,index)];
      const selected = element('select', '', 'toolbar-select');
      selected.setAttribute('aria-label', {columns:'Колонка',sections:'Секция',fields:'Поле',actions:'Кнопка'}[group]);
      for (const entry of available) selected.add(new Option(labels?.[entry.key] || entry.label,entry.key));
      selected.value = item.key;
      selected.addEventListener('change',() => {
        session.selected[group] = selected.value;
        render();
        controls.querySelector('[aria-label="'+selected.getAttribute('aria-label')+'"]').focus();
      });
      const row = element('div','','interface-designer-controls');
      row.append(selected);
      if (group === 'columns') {
        const title = element('input','','field-input');
        title.value = item.label;
        title.maxLength = 80;
        title.setAttribute('aria-label','Заголовок колонки');
        title.addEventListener('change',() => {
          const label = title.value.trim();
          if (label) item.label = label;
          title.value = item.label;
          selected.selectedOptions[0].textContent = item.label;
          changed();
        });
        row.append(title);
      }
      row.append(number(max === 12 ? 'Доли из 12' : 'Ширина, px', item.width,min,max,value => {
        item.width = value && max!==12 ? Math.max(group==='columns'?48:64,value) : value;
        return item.width;
      }));
      const position = list.indexOf(item);
      for (const [delta,label] of [[-1,'Раньше'],[1,'Позже']]) {
        const neighbour = available[available.indexOf(item)+delta];
        const shift = button(label,() => move(list,position,list.indexOf(neighbour)));
        shift.disabled = !neighbour;
        row.append(shift);
      }
      controls.append(row);
    }
    function dragHandle(node, group, key) {
      const handle = element('button','↔','interface-designer-drag');
      handle.type = 'button';
      handle.draggable = true;
      handle.title = 'Перетащить';
      handle.setAttribute('aria-label','Выбрать для перемещения');
      handle.addEventListener('click',() => { session.selected[group]=key; render(); });
      handle.addEventListener('dragstart',event => {
        dragging = {group,key};
        event.dataTransfer.setData('text/plain',key);
        event.dataTransfer.effectAllowed = 'move';
      });
      handle.addEventListener('dragend',() => { dragging = null; });
      handle.addEventListener('dragover',event => { if (dragging?.group === group) event.preventDefault(); });
      handle.addEventListener('drop',event => {
        if (dragging?.group !== group) return;
        event.preventDefault();
        const list = session.layout[group];
        const from = list.findIndex(item => item.key === dragging.key);
        const to = list.findIndex(item => item.key === key);
        dragging = null;
        session.selected[group] = key;
        move(list,from,to);
      });
      node.prepend(handle);
    }
    function handles() {
      root.querySelectorAll('[data-design-handle-host]').forEach(node => node.remove());
      if (!session || session.preview) return;
      if (isTable) {
        for (const th of root.querySelectorAll('[data-design-table] th[data-design-key]')) {
          const key = th.dataset.designKey;
          const item = session.layout.columns.find(column => column.key === key);
          const host = element('span','','interface-designer-column-tools');
          host.dataset.designHandleHost = '';
          dragHandle(host,'columns',key);
          const edge = element('span','','interface-designer-column-edge');
          edge.title = 'Потяните границу колонки';
          dragPointer(edge,() => th.getBoundingClientRect().width,(width,dx) => {
            item.width = clamp(width+dx,48,1000);
            session.selected.columns = key;
            changed();
          },render);
          host.append(edge);
          th.append(host);
        }
        return;
      }
      for (const group of ['sections','fields']) {
        for (const {key} of session.layout[group]) {
          const item = root.querySelector('[data-design-key="'+key+'"]');
          if (!item) continue;
          const host = element('div','','interface-designer-item-handle');
          host.dataset.designHandleHost = '';
          dragHandle(host,group,key);
          item.prepend(host);
        }
      }
      const head = root.querySelector('.modal-head');
      const grip = element('span','✥','interface-designer-window-grip');
      grip.dataset.designHandleHost = '';
      grip.title = 'Переместить окно';
      const size = element('span','◢','interface-designer-window-size');
      size.dataset.designHandleHost = '';
      size.title = 'Изменить размер окна';
      dragPointer(grip,() => ({x:parseFloat(root.style.getPropertyValue('--design-x'))||0,y:parseFloat(root.style.getPropertyValue('--design-y'))||0}),(origin,dx,dy) => {
        Object.assign(session.layout.window,{x:clamp(origin.x+dx,-1600,1600),y:clamp(origin.y+dy,-1200,1200)});
        changed();
      },render);
      dragPointer(size,() => ({width:root.offsetWidth,height:root.offsetHeight}),(origin,dx,dy) => {
        Object.assign(session.layout.window,{width:clamp(origin.width+dx,320,1600),height:clamp(origin.height+dy,280,1200)});
        changed();
      },render);
      head.insertBefore(grip,head.lastElementChild);
      root.append(size);
    }
    function render() {
      commands.replaceChildren();
      controls.replaceChildren();
      const open = () => {
        session = {layout:structuredClone(config.draft || config.applied),preview:false,selected:{},saved:Boolean(config.draft)};
        editing.set(config.id, session);
        status.textContent = config.draft ? 'Открыт сохранённый черновик.' : '';
        render();
      };
      if (!session) {
        commands.append(button(config.draft?'Открыть черновик дизайна':'Редактировать дизайн',open));
        if (config.previous) commands.append(button('Вернуть предыдущую версию',() => save('restore')));
      } else {
        commands.append(
          button(session.preview?'К редактированию':'Предпросмотр',() => { session.preview=!session.preview; render(); }),
          button('Сохранить черновик дизайна',() => save('draft')),
          button('Применить для всех ролей',() => {
            if (!session.saved) { status.textContent='Сначала сохраните черновик дизайна.'; return; }
            save('apply');
          },true),
          button('Выйти из редактора',() => { editing.delete(config.id); session=null; status.textContent=''; render(); })
        );
        if (!session.preview) {
          if (isTable) itemEditor('columns',null,0,1000);
          else {
            const box = session.layout.window;
            const dimensions = element('div','','interface-designer-controls');
            for (const [key,label,min,max] of [['width','Ширина окна',0,1600],['height','Высота окна',0,1200],['x','По горизонтали',-1600,1600],['y','По вертикали',-1200,1200]]) {
              dimensions.append(number(label,box[key],min,max,value => {
                box[key] = value && key==='width' ? Math.max(320,value) : value && key==='height' ? Math.max(280,value) : value;
                return box[key];
              }));
            }
            controls.append(dimensions);
            itemEditor('sections', config.labels.sections,1,12);
            itemEditor('fields', config.labels.fields,1,12);
            itemEditor('actions', config.labels.actions,0,480);
          }
        }
      }
      previewPaint();
      handles();
    }
    render();
  }
  function mount(root = document) {
    if (root.matches?.('[data-interface-design]')) mountRoot(root);
    for (const node of root.querySelectorAll('[data-interface-design]')) mountRoot(node);
  }
  window.addEventListener('resize', () => {
    for (const dialog of document.querySelectorAll('dialog[open][data-interface-design]')) {
      dialog.dispatchEvent(new Event('design:geometry'));
    }
  });
  window.PlanexInterfaceDesign = {mount};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',() => mount());
  else mount();
})();
