'use strict';
(() => {
  const mounted = new WeakSet();
  const editing = new Map();
  const shortcuts = new WeakMap();
  const groupLabels = {columns:'Колонки',sections:'Секции',fields:'Поля',actions:'Кнопки'};
  const alignmentLabels = {'':'По умолчанию',left:'Слева',center:'По центру',right:'Справа'};
  const windowLabels = {width:'Ширина окна',height:'Высота окна',x:'По горизонтали',y:'По вертикали'};
  const endpoint = '/administration/interface-designer';

  function differences(before, after, labels) {
    const result = [];
    const change = (name, from, to) => {
      if (from !== to) result.push(name + ': ' + from + ' → ' + to);
    };
    for (const [key, caption] of Object.entries(windowLabels)) {
      if (!before.window) break;
      const display = value => value === 0 && ['width','height'].includes(key) ? 'Авто' : value + ' px';
      change(caption, display(before.window[key]), display(after.window[key]));
    }
    for (const [group, caption] of Object.entries(groupLabels)) {
      if (!before[group]) continue;
      const original = new Map(before[group].map(item => [item.key,item]));
      const title = key => labels?.[group]?.[key] || original.get(key).label;
      const oldKeys = before[group].map(item => item.key);
      const newKeys = after[group].map(item => item.key);
      if (oldKeys.join() !== newKeys.join()) change(caption + ' · порядок', oldKeys.map(title).join(' / '), newKeys.map(title).join(' / '));
      for (const item of after[group]) {
        const was = original.get(item.key);
        const name = title(item.key);
        if (group==='columns') {
          change(name + ' · заголовок', was.label, item.label);
          change(name + ' · выравнивание', alignmentLabels[was.align ?? ''], alignmentLabels[item.align ?? '']);
        }
        const display = value => !value ? 'Авто' : value + (['sections','fields'].includes(group) ? ' из 12' : ' px');
        change(name + ' · ширина', display(was.width), display(item.width));
      }
    }
    return result;
  }
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
  function alignCell(cell, alignment) {
    if (alignment) cell.dataset.designAlign = alignment;
    else delete cell.dataset.designAlign;
  }
  function summary(row, partyAlign) {
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
      if (cell === party) {
        alignCell(start, partyAlign);
        if (content.parentNode !== start) start.append(content);
      }
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
        if (item) {
          th.querySelector('[data-design-label]').textContent = item.label;
          alignCell(th, item.align);
        }
      }
      for (const row of table.tBodies[0].rows) {
        reorder(row, layout.columns);
        for (const cell of row.cells) alignCell(cell, byKey.get(cell.dataset.designKey)?.align);
        if (row.hasAttribute('data-design-summary')) summary(row, byKey.get('party').align);
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
      let cancelled = false;
      const cancel = () => { cancelled = true; };
      const stop = () => {
        node.removeEventListener('pointermove', update);
        node.removeEventListener('pointercancel', cancel);
        end?.(cancelled);
      };
      node.addEventListener('pointercancel', cancel);
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
    let undoButton, redoButton;
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
    async function request(action, layout = null, replaceDraft = false) {
      const body = new URLSearchParams({_csrf:config.csrf,id:config.id,revision:config.revision,action});
      if (layout !== null) body.set('layout', JSON.stringify(layout));
      if (replaceDraft) body.set('replace_draft','1');
      const response = await fetch(endpoint, {method:'POST',body});
      if (!response.ok) {
        const error = new DOMParser().parseFromString(await response.text(),'text/html').querySelector('[role="alert"]');
        throw new Error(error?.textContent || 'Не удалось обработать компоновку.');
      }
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Сеанс завершён. Войдите снова.');
      return response.json();
    }
    async function run(task) {
      if (busy) return;
      busy = true;
      pane.inert = true;
      root.toggleAttribute('data-design-saving',true);
      try {
        await task();
      } catch (error) {
        status.textContent = error.message;
      } finally {
        busy = false;
        pane.inert = false;
        root.removeAttribute('data-design-saving');
        render();
      }
    }
    function endSession() {
      session = null;
      editing.delete(config.id);
    }
    function startSession(layout, details = {}) {
      session = {layout:structuredClone(layout),preview:false,selected:{},undo:[],redo:[],...details};
      editing.set(config.id, session);
    }
    function saved() {
      return config.draft && differences(config.draft,session.layout,config.labels).length===0;
    }
    function save(action) {
      const importDraft = action === 'import-draft';
      const isDraft = importDraft || action === 'draft';
      run(async () => {
        let state;
        if (config.demo) {
          state = {applied:config.applied,draft:config.draft,previous:config.previous};
          if (isDraft) state.draft = structuredClone(session.layout);
          if (action === 'apply') [state.applied,state.previous,state.draft] = [state.draft,state.applied,null];
          if (action === 'restore') [state.applied,state.previous] = [state.previous,state.applied];
        } else {
          const layout = importDraft ? session.imported : isDraft ? session.layout : null;
          state = await request(action,layout,importDraft && session.replaceDraft);
        }
        config = {...config,...state,applied:state.applied || config.applied};
        if (!isDraft) endSession();
        else if (importDraft) startSession(state.draft,{preview:true});
        status.textContent = isDraft ? 'Черновик сохранён.' :
          action === 'apply' ? 'Компоновка применена для всех ролей.' : 'Предыдущая версия восстановлена.';
      });
    }
    function historyControls() {
      if (!undoButton) return;
      undoButton.disabled = !session.undo.length;
      redoButton.disabled = !session.redo.length;
    }
    function noteEdit(before, rebuild = false) {
      if (differences(before,session.layout,config.labels).length) {
        session.undo.push(before);
        session.redo.length = 0;
        status.textContent = saved() ? 'Открыт сохранённый черновик.' : 'Есть несохранённые изменения.';
      }
      if (rebuild) render();
      else {
        previewPaint();
        historyControls();
      }
    }
    function traverseHistory(redo) {
      if (!session || session.imported || busy) return;
      const source = redo ? session.redo : session.undo;
      const target = redo ? session.undo : session.redo;
      if (!source.length) return;
      target.push(session.layout);
      session.layout = source.pop();
      status.textContent = redo ? 'Изменение повторено.' : 'Изменение отменено.';
      render();
      const control = redo ? redoButton : undoButton;
      (control.disabled ? (redo ? undoButton : redoButton) : control).focus({preventScroll:true});
    }
    shortcuts.set(root,traverseHistory);

    function draftImport(file) {
      run(async () => {
        if (file.size>16384) throw new Error('Файл компоновки слишком велик.');
        let document;
        try { document = JSON.parse(await file.text()); }
        catch { throw new Error('Файл должен содержать корректный JSON.'); }
        let result;
        if (config.demo) {
          if (document?.schema!==1 || document.screen!==config.id) throw new Error('Файл предназначен для другого примера или версии.');
          for (const [key, value] of Object.entries(config.applied)) {
            const incoming = document.layout?.[key];
            if (Array.isArray(value)) {
              const keys = incoming?.map?.(item => item?.key).sort().join();
              if (keys!==value.map(item => item.key).sort().join()
                  || incoming.some(item => !Number.isInteger(item.width) || (key==='columns' &&
                    (typeof item.label!=='string' || ('align' in item && !['left','center','right'].includes(item.align)))))) {
                throw new Error('Проверьте состав компоновки.');
              }
            } else if (!Object.keys(value).every(field => Number.isInteger(incoming?.[field]))) {
              throw new Error('Проверьте размеры окна.');
            }
          }
          result = {layout:document.layout,hasDraft:Boolean(config.draft)};
        } else result = await request('import-preview',document);
        startSession(result.layout,{preview:true,imported:document,hasDraft:result.hasDraft,replaceDraft:false});
        status.textContent = '';
      });
    }
    function exportLink() {
      if (config.demo) {
        return button('Экспорт применённого дизайна',() => {
          const bytes = JSON.stringify({schema:1,screen:config.id,layout:config.applied},null,2);
          const url = URL.createObjectURL(new Blob([bytes],{type:'application/json'}));
          const download = element('a');
          download.href = url;
          download.download = 'planex-'+config.id+'.json';
          download.click();
          setTimeout(() => URL.revokeObjectURL(url),1000);
        });
      }
      const link = element('a','Экспорт применённого дизайна','btn btn-secondary');
      link.href = endpoint+'?export='+encodeURIComponent(config.id);
      return link;
    }
    function fileInput() {
      const label = element('label','Загрузить JSON','interface-designer-file');
      const file = element('input');
      file.type = 'file';
      file.accept = '.json,application/json';
      file.addEventListener('change',() => {
        if (file.files[0]) draftImport(file.files[0]);
      });
      label.append(file);
      return label;
    }
    function importReview() {
      const changes = differences(config.applied,session.layout,config.labels);
      const review = element('div','','interface-designer-review');
      review.append(element('strong',changes.length ? 'Отличия от применённого дизайна' : 'Отличий от применённого дизайна нет.'));
      if (changes.length) {
        const list = element('ul');
        changes.forEach(change => list.append(element('li',change)));
        review.append(list);
      }
      if (session.hasDraft) {
        const label = element('label','Заменить существующий черновик при сохранении','registry-ui-checkbox');
        const replace = element('input');
        replace.type = 'checkbox';
        replace.checked = session.replaceDraft;
        const confirm = commands.querySelector('[data-design-import-save]');
        confirm.disabled = !replace.checked;
        replace.addEventListener('change',() => {
          session.replaceDraft = replace.checked;
          confirm.disabled = !replace.checked;
        });
        label.prepend(replace);
        review.append(label);
      }
      controls.append(review);
    }
    function pointerEdit(node, start, update) {
      let before;
      dragPointer(node,() => {
        before = structuredClone(session.layout);
        return start();
      },(origin,dx,dy) => {
        update(origin,dx,dy);
        previewPaint();
      },cancelled => {
        if (cancelled) session.layout = before;
        noteEdit(before,true);
      });
    }
    function move(list, from, to) {
      if (from === to || to < 0 || to >= list.length) return;
      const before = structuredClone(session.layout);
      list.splice(to,0,list.splice(from,1)[0]);
      noteEdit(before,true);
    }
    function number(label, value, min, max, onChange) {
      const host = element('label', label, 'interface-designer-field');
      const input = element('input', '', 'field-input');
      Object.assign(input,{type:'number',value,min,max});
      input.addEventListener('change',() => {
        const result = clamp(input.valueAsNumber || 0,min,max);
        const before = structuredClone(session.layout);
        input.value = onChange(result);
        noteEdit(before);
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
          const before = structuredClone(session.layout);
          const label = title.value.trim();
          if (label) item.label = label;
          title.value = item.label;
          selected.selectedOptions[0].textContent = item.label;
          noteEdit(before);
        });
        row.append(title);
      }
      row.append(number(max === 12 ? 'Доли из 12' : 'Ширина, px', item.width,min,max,value => {
        item.width = value && max!==12 ? Math.max(group==='columns'?48:64,value) : value;
        return item.width;
      }));
      if (group === 'columns') {
        const label = element('label','Выравнивание','interface-designer-field');
        const alignment = element('select','','toolbar-select');
        alignment.setAttribute('aria-label','Выравнивание');
        for (const [value,caption] of Object.entries(alignmentLabels)) alignment.add(new Option(caption,value));
        alignment.value = item.align ?? '';
        alignment.addEventListener('change',() => {
          const before = structuredClone(session.layout);
          if (alignment.value) item.align = alignment.value;
          else delete item.align;
          noteEdit(before);
        });
        label.append(alignment);
        row.append(label);
      }
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
          pointerEdit(edge,() => ({pixels:th.getBoundingClientRect().width,width:item.width}),(origin,dx) => {
            item.width = dx ? clamp(origin.pixels+dx,48,1000) : origin.width;
            session.selected.columns = key;
          });
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
      pointerEdit(grip,() => ({
        settings:{...session.layout.window},
        x:parseFloat(root.style.getPropertyValue('--design-x'))||0,
        y:parseFloat(root.style.getPropertyValue('--design-y'))||0
      }),(origin,dx,dy) => {
        Object.assign(session.layout.window,{
          x:dx ? clamp(origin.x+dx,-1600,1600) : origin.settings.x,
          y:dy ? clamp(origin.y+dy,-1200,1200) : origin.settings.y
        });
      });
      pointerEdit(size,() => ({settings:{...session.layout.window},width:root.offsetWidth,height:root.offsetHeight}),(origin,dx,dy) => {
        Object.assign(session.layout.window,{
          width:dx ? clamp(origin.width+dx,320,1600) : origin.settings.width,
          height:dy ? clamp(origin.height+dy,280,1200) : origin.settings.height
        });
      });
      head.insertBefore(grip,head.lastElementChild);
      root.append(size);
    }
    function render() {
      commands.replaceChildren();
      controls.replaceChildren();
      undoButton = redoButton = null;
      const exit = button('Выйти из редактора',() => { endSession(); status.textContent=''; render(); });
      const open = () => {
        startSession(config.draft || config.applied);
        status.textContent = config.draft ? 'Открыт сохранённый черновик.' : '';
        render();
      };
      if (!session) {
        commands.append(button(config.draft?'Открыть черновик дизайна':'Редактировать дизайн',open),exportLink(),fileInput());
        if (config.previous) commands.append(button('Вернуть предыдущую версию',() => save('restore')));
      } else if (session.imported) {
        const confirm = button('Сохранить черновик дизайна',() => save('import-draft'),true);
        confirm.dataset.designImportSave = '';
        commands.append(confirm,exit);
        importReview();
      } else {
        undoButton = button('Отменить изменение',() => traverseHistory(false));
        redoButton = button('Повторить изменение',() => traverseHistory(true));
        undoButton.setAttribute('aria-keyshortcuts','Control+Z');
        redoButton.setAttribute('aria-keyshortcuts','Control+Shift+Z');
        historyControls();
        commands.append(undoButton,redoButton,
          button(session.preview?'К редактированию':'Предпросмотр',() => { session.preview=!session.preview; render(); }),
          button('Сохранить черновик дизайна',() => save('draft')),
          button('Применить для всех ролей',() => {
            if (!saved()) { status.textContent='Сначала сохраните черновик дизайна.'; return; }
            save('apply');
          },true),
          exit
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
  document.addEventListener('keydown', event => {
    if (event.defaultPrevented || !event.ctrlKey || event.altKey || event.metaKey
        || (event.code!=='KeyZ' && event.key.toLowerCase()!=='z')
        || event.target.closest?.('input,textarea,select,[contenteditable]')) return;
    const dialog = document.querySelector('dialog[open]');
    const root = dialog || event.target.closest?.('[data-interface-design]')
      || [...document.querySelectorAll('[data-interface-design]')].find(node => node.querySelector('[aria-keyshortcuts]'));
    const shortcut = shortcuts.get(root);
    if (!shortcut || !root.querySelector('[aria-keyshortcuts]')) return;
    event.preventDefault();
    shortcut(event.shiftKey);
  });
  window.addEventListener('resize', () => {
    for (const dialog of document.querySelectorAll('dialog[open][data-interface-design]')) {
      dialog.dispatchEvent(new Event('design:geometry'));
    }
  });
  window.PlanexInterfaceDesign = {mount};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',() => mount());
  else mount();
})();
