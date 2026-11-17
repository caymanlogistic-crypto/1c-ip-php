(()=>{
'use strict';
const D=JSON.parse(document.getElementById('demo-data').textContent);
const work=document.getElementById('workspace'),nav=document.getElementById('page-navigation');
const saveFeedback=window.UIRegistryUX.createSaveFeedback({workspace:work});
window.UIRegistryUX.observeHoverHints(document.body);
const E=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:2}).format(n)+' ₽';
const amountRules=Object.freeze({min:0,step:'0.01'});
function documentLabel(record){return 'Документ №'+(record.docNumber||record.id);}
const icons=name=>(D.icons[name]||D.icons['file-text']).replace('<svg','<svg aria-hidden="true" focusable="false"');
const navIcon=name=>icons(name).replace(/class="[^"]*"/,'class="nav-icon"');
const state={page:'objects',mode:'normal',current:1001,guide:false,previewColors:false,origin:null,editing:null,formBaseline:null,formModel:null,dialogReturnFocus:null,navigationRevision:0};
const rows=Array.from({length:120},(_,i)=>({id:1001+i,name:['Объект «Альфа»','Демонстрационная запись','Объект с длинным наименованием, которое сохраняется полностью в карточке','Набор параметров','Объект «Бета»','Запись без дополнительного значения'][i%6]+(i>5?' · '+(i+1):''),category:['Категория A','Категория B','Категория C'][i%3],rentable:i%7!==2,owner:['Анна П.','Иван С.','Мария К.'][i%3],date:'01.11.2026',status:['new','active','done','attention'][i%4],amount:12500+i*375,actual:i%3?8000+i*120:0,count:2+i%8,note:i%5?['Проверить наименование','Категория подтверждена','Добавлено описание','Данные для согласования'][i%4]:'',time:'09:30',tags:[],parameters:{a:true,b:false,partial:'mixed'},variant:'a',related:'',attachments:[],deleted:false,issues:{error:i===1?1:0,warning:i%4===3?1:0}}));
const pages={objects:{title:'Реестр объектов',summary:'Универсальный список: поиск, фильтры, статусы и действия с записями.',icon:'users-group'},documents:{title:'Документы',summary:'Пример числовых данных, сроков, итогов и состояний выполнения.',icon:'file-text'},operations:{title:'Операции',summary:'Пример истории изменений: объект, действие, значения и источник.',icon:'arrows-exchange'},card:{title:'Карточка объекта',summary:'Сведения, связанные записи, документы и история действий.',icon:'user'},form:{title:'Создание и редактирование',summary:'Пример полей и состояний формы на нейтральных данных.',icon:'file-text'}};
pages.messages={title:'Сообщения и попапы',summary:'Рабочие примеры ошибок, предупреждений, подтверждений и уведомлений.',icon:'file-text'};
pages.validation={title:'Ошибки и предупреждения',summary:'Проверка нейтральных записей: красная ошибка и жёлтое предупреждение в рабочем контексте.',icon:'file-text'};
const statusLabel={new:'Новый',active:'В работе',done:'Завершён',attention:'Требует внимания'};
const statusClass={new:'registry-ui-missing',active:'registry-ui-success',done:'registry-ui-secondary',attention:'registry-ui-attention'};
function header(page,actions=''){return '<div class="page-head registry-ui-head"><div class="page-head-left"><span class="rp-identity-icon">'+icons(page.icon)+'</span><span class="rp-head-text"><h1 class="page-title">'+page.title+'</h1><span class="page-summary">'+page.summary+'</span></span></div><div class="page-head-actions">'+actions+'</div></div>';}
function primary(label,action){return '<button class="btn btn-primary" type="button" data-action="'+action+'">'+label+'</button>';}
function secondary(label,action){return '<button class="btn btn-secondary" type="button" data-action="'+action+'">'+label+'</button>';}
function tabs(kind){return '<div class="registry-ui-tabs" role="tablist" aria-label="Статус записей">'+[['all','Все записи'],['new','Новые'],['active','В работе'],['done','Завершённые'],['attention','Требуют внимания']].map(([id,label],i)=>'<button id="'+kind+'-tab-'+id+'" type="button" role="tab" aria-controls="'+kind+'-results" tabindex="'+(!i?'0':'-1')+'" data-registry-tab="'+id+'" data-status="'+id+'" aria-selected="'+(!i)+'" aria-pressed="'+(!i)+'">'+label+' <b data-count-status="'+id+'">0</b></button>').join('')+'</div>';}
function filteredRows(data,{query='',category='all',tab='all',showComplete=false,kind='objects',sort='date'}={}){
 const q=String(query).toLocaleLowerCase('ru');
 const result=data.filter(r=>!r.deleted&&(category==='all'||category===r.category)&&(tab==='all'||r.status===tab)&&(kind==='objects'||tab==='done'||showComplete||r.status!=='done')&&[r.id,r.docNumber,kind==='documents'?documentLabel(r):'',r.name,r.category,r.owner,r.date,r.time,r.count,r.amount,r.actual,money(r.amount),money(r.actual),money(r.amount-r.actual),r.rentable===false?'Недоступен':'Доступен',statusLabel[r.status]].join(' ').toLocaleLowerCase('ru').includes(q));
 if(sort==='name')result.sort((a,b)=>a.name.localeCompare(b.name,'ru'));else if(sort==='id')result.sort((a,b)=>a.id-b.id);
 return result;
}
function activateStatusTab(tab){
 const root=tab.closest('[data-page]');
 root.querySelectorAll('[data-registry-tab]').forEach(t=>{const selected=t===tab;t.setAttribute('aria-pressed',String(selected));t.setAttribute('aria-selected',String(selected));t.tabIndex=selected?0:-1;});
 root.querySelector('[role=tabpanel]')?.setAttribute('aria-labelledby',tab.id);
 renderRows(root.dataset.page);
}
function activateCardTab(tab){
 const parent=tab.closest('[data-card-tabs]');
 parent.querySelectorAll('[data-card-tab]').forEach(t=>{const selected=t===tab;t.classList.toggle('is-active',selected);t.setAttribute('aria-selected',String(selected));t.tabIndex=selected?0:-1;});
 parent.querySelectorAll('[data-card-pane]').forEach(p=>{const selected=p.dataset.cardPane===tab.dataset.cardTab;p.classList.toggle('is-active',selected);p.hidden=!selected;});
}

function toolbar(kind){return '<div class="table-toolbar registry-ui-toolbar"><div class="registry-ui-checkboxes"><div class="found-label">Найдено: <b data-found>0</b></div>'+(kind==='documents'||kind==='operations'?'<span class="filter-sep" aria-hidden="true"></span><label class="registry-ui-checkbox"><input type="checkbox" data-registry-field="showComplete" data-registry-default="false">'+(kind==='operations'?'Показать завершённые':'Показать выполненные')+'</label>':'')+'</div><div class="toolbar-right"><button class="registry-ui-reset" type="button" hidden>Сбросить фильтр</button><select class="toolbar-select" data-registry-field="category" data-registry-default="all" aria-label="Категория"><option value="all">Все категории</option><option>Категория A</option><option>Категория B</option><option>Категория C</option></select><select class="toolbar-select" data-registry-field="sort" data-registry-filter="false" data-registry-default="date" data-table-reference-select="'+kind+'-sort" aria-label="Сортировка"><option value="date">По дате добавления</option><option value="name">По наименованию</option><option value="id">По номеру</option></select><div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-registry-field="query" data-registry-default="" placeholder="Поиск по всему реестру" aria-label="Поиск по всему реестру"></div></div></div>';}
function registry(kind){
 const p=pages[kind];const hs=kind==='objects'?['ID','Наименование','Категория','Ответственный','Дата','Доступность','Статус']:kind==='documents'?['ID','Документ','Участник','Срок','План','Факт','Остаток','Статус']:['ID события','Объект','Операция','Количество','Значение','Связанные объекты','Дата','Ответственный'];
 const klass=kind==='documents'?' registry-ui-table--finance':kind==='operations'?' registry-ui-table--placement registry-ui-table--placement-blocks':'';
 return '<section class="demo-page" data-page="'+kind+'" data-registry-managed-scroll data-registry-id="universal-'+kind+'" data-registry-state-scope="synthetic:ui-review:v2:'+kind+'" '+(kind==='objects'?'':'hidden')+'>'+header(p,secondary('Открыть карточку','card')+primary(kind==='documents'?'Создать документ':kind==='operations'?'Добавить изменение':'Создать объект',kind==='documents'?'create-document':kind==='operations'?'create-operation':'create-object'))+tabs(kind)+'<div id="'+kind+'-results" role="tabpanel" aria-labelledby="'+kind+'-tab-all" class="table-card registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll">'+toolbar(kind)+'<div class="table-scroll" role="region" tabindex="0" aria-label="'+p.title+': прокрутка таблицы"><table class="table registry-ui-table registry-ui-data registry-ui-data--columns'+klass+'">'+(kind==='objects'?'':'<colgroup>'+hs.map((_,i)=>'<col'+(i===0?' class="registry-ui-id"':i===1?' class="registry-ui-trip-cell"':'')+'>').join('')+'</colgroup>')+'<thead><tr>'+hs.map((h,i)=>'<th '+(i===0?'class="registry-ui-id"':kind==='operations'&&(i===3||i===4)?'class="registry-ui-number"':'')+'>'+h+'</th>').join('')+'</tr></thead><tbody data-body="'+kind+'"></tbody><tfoot data-totals="'+kind+'"></tfoot></table></div></div><div class="demo-table-state" data-special-state hidden></div></section>';
}
function cell(value,cls=''){return '<td class="'+cls+'"><div class="registry-ui-value">'+value+'</div></td>';}
function renderRows(kind){saveFeedback.clear();
 const root=document.querySelector('[data-page="'+kind+'"]');if(!root)return;
 const value=n=>root.querySelector('[data-registry-field="'+n+'"]')?.value;
 const q=(value('query')||'').toLocaleLowerCase('ru'),category=value('category')||'all',tab=root.querySelector('[data-registry-tab][aria-pressed=true]')?.dataset.registryTab||'all';
 const show=root.querySelector('[data-registry-field=showComplete]')?.checked;
 let result=filteredRows(rows,{query:q,category,tab,showComplete:show,kind,sort:value('sort')});
 if(state.mode==='empty')result=[];
 root.querySelector('[data-found]').textContent=result.length;renderMenuCounts();
 root.querySelectorAll('[data-count-status]').forEach(b=>b.textContent=filteredRows(rows,{query:q,category,tab:b.dataset.countStatus,showComplete:show,kind}).length);
 const body=root.querySelector('tbody');
 const renderRow=r=>{
  let content='';
  if(kind==='objects')content=cell('#'+r.id,'registry-ui-id')+cell('<span class="registry-ui-name" title="'+E(r.name)+'">'+E(r.name)+'</span><span class="registry-ui-secondary">'+E(r.note||'')+'</span>')+cell(r.category)+cell(r.owner)+cell(r.date)+cell(r.rentable===false?'<strong class="registry-ui-error">Недоступен</strong>':'Доступен')+cell('<span class="registry-ui-status '+(r.issues.error?'registry-ui-error':statusClass[r.status])+'">'+(r.issues.error?'Ошибка данных':statusLabel[r.status])+'</span>');
  else if(kind==='documents')content=cell('#'+r.id,'registry-ui-id')+cell('<span class="registry-ui-primary registry-ui-trip" title="'+E(documentLabel(r))+'">'+E(documentLabel(r))+'</span><span class="registry-ui-secondary">'+r.date+'</span>')+cell('<span class="registry-ui-name">Участник '+String.fromCharCode(65+r.id%3)+'</span><span class="registry-ui-secondary">'+r.owner+'</span>')+cell('<span class="registry-ui-due '+(r.status==='attention'?'registry-ui-attention':'')+'"><span class="registry-ui-primary">05.11.2026</span><span class="registry-ui-secondary">'+(r.status==='attention'?'Требует проверки':'Срок исполнения')+'</span></span>')+cell(money(r.amount),'registry-ui-money')+cell(money(r.actual)+'<span class="registry-ui-secondary '+(r.status==='attention'?'registry-ui-attention':'')+'">'+(r.status==='attention'?'действие недоступно':'ожидает исполнения')+'</span>','registry-ui-money registry-ui-paid-status')+cell(money(r.amount-r.actual),'registry-ui-money')+cell('<span class="registry-ui-status '+(r.issues.error?'registry-ui-error':statusClass[r.status])+'">'+(r.issues.error?'Ошибка данных':statusLabel[r.status])+'</span>');
  else content=cell('С-'+r.id,'registry-ui-id registry-ui-open-detail')+cell('<span class="registry-ui-inline-identity"><span class="registry-ui-primary">#'+r.id+'&nbsp;</span><span class="registry-ui-name" title="'+E(r.name)+'">'+E(r.name)+'</span></span>')+cell('<span class="registry-ui-primary registry-ui-operation--'+(r.id%2?'addition':'exclusion')+'">'+(r.id%2?'Добавление':'Исключение')+'</span>')+cell(r.count,'registry-ui-number')+cell(r.id%5?new Intl.NumberFormat('ru-RU').format(r.amount):'<span class="registry-ui-missing">—</span>','registry-ui-number')+cell('<span class="registry-ui-primary">1001, 1002, 1003</span>')+cell(r.date)+cell(r.owner);
  return '<tr tabindex="0" aria-label="Открыть '+E(kind==='documents'?documentLabel(r):r.name)+'" data-open="'+r.id+'" data-save-kind="'+kind+'" data-save-id="'+r.id+'" class="'+(r.issues.error?'registry-ui-soft-error':r.issues.warning?'registry-ui-warning':r.status==='done'?'registry-ui-muted':'')+'">'+content+'</tr>';
 };const emptyHTML='<tr><td colspan="'+(kind==='objects'?7:8)+'"><div class="empty-state"><h3 class="empty-title">Записи не найдены</h3><p class="empty-desc">'+(rows.some(r=>!r.deleted)?'Измените условия поиска или сбросьте фильтр.':'Создайте первую демонстрационную запись.')+'</p>'+(rows.some(r=>!r.deleted)?secondary('Сбросить фильтр','reset-table:'+kind):primary('Создать запись',kind==='documents'?'create-document':'create-object'))+'</div></td></tr>';
 window.UIRegistryViewport.mount(root,{rows:result,keyOf:r=>r.id,renderRow,identity:r=>({kind,id:r.id}),signature:JSON.stringify([kind,q,category,tab,!!show,value('sort'),state.mode]),emptyHTML,onAppend:()=>{applyCapabilities(root);window.PlanexRegistry?.textRoles(root);}});
 const foot=root.querySelector('tfoot');foot.innerHTML=kind==='documents'?'<tr>'+cell('Итого · '+result.length)+cell('')+cell('')+cell('')+cell(money(result.reduce((s,r)=>s+r.amount,0)),'registry-ui-money')+cell(money(result.reduce((s,r)=>s+r.actual,0)),'registry-ui-money')+cell(money(result.reduce((s,r)=>s+r.amount-r.actual,0)),'registry-ui-money')+cell('')+'</tr>':'';
 const table=root.querySelector('.registry-ui-card'),special=root.querySelector('[data-special-state]');
 table.hidden=state.mode==='loading'||state.mode==='error';special.hidden=!table.hidden;
 if(state.mode==='loading')special.innerHTML='<div class="sk-table"><div class="sk-thead"><span class="skeleton sk-cell" style="width:40%"></span></div>'+Array.from({length:8},()=>'<div class="sk-insp"><div class="skeleton sk-text"></div><div class="skeleton sk-text"></div></div>').join('')+'</div>';
 if(state.mode==='error')special.innerHTML='<div class="form-alert alert-error" role="alert"><span class="alert-mark">×</span><div class="alert-body"><div class="alert-body-title">Не удалось загрузить записи</div><div class="alert-body-sub">Демонстрационное состояние ошибки.</div></div>'+secondary('Повторить','retry')+'</div>';
 applyCapabilities(root);
 if(window.PlanexRegistry)window.PlanexRegistry.textRoles(root);
}
function formPage(){return '<section class="demo-page" data-page="form" hidden>'+header(pages.form,secondary('Отменить','back-to-origin')+primary('Сохранить','submit-form'))+'<form id="object-form" class="table-card registry-ui-card demo-form-width"><section class="panel-section"><h2 class="section-title">Основные сведения</h2><div class="form-grid-2"><label class="field"><span class="field-label">Наименование<span class="req">*</span></span><input class="field-input" name="name" required placeholder="Наименование объекта"><span class="field-msg">Обязательное поле</span></label><label class="field"><span class="field-label">Категория</span><select class="field-select" name="category"><option>Категория A</option><option>Категория B</option><option>Категория C</option></select><span class="field-msg">Выберите значение</span></label><label class="field"><span class="field-label">Дата</span><input class="field-input" type="date" name="date" value="2026-11-01"></label><label class="field"><span class="field-label">Время</span><input class="field-input" type="time" name="time" value="09:30"></label><label class="field"><span class="field-label">Количество</span><span class="number-input"><button class="number-btn" type="button" data-step="-1" aria-label="Уменьшить количество">−</button><input class="number-field" type="number" name="count" value="5" min="0" aria-label="Количество"><button class="number-btn" type="button" data-step="1" aria-label="Увеличить количество">+</button></span></label><label class="field"><span class="field-label">Сумма</span><span class="input-group"><input class="field-input" type="number" name="amount" min="'+amountRules.min+'" step="'+amountRules.step+'" value="12500"><span class="input-affix is-suffix">₽</span></span></label><label class="field"><span class="field-label">Комментарий</span><textarea class="field-textarea" name="note" maxlength="160" data-counter placeholder="Дополнительная информация"></textarea><span class="char-counter">0 / 160</span></label><div class="field"><label class="field-label" for="tags">Метки</label><div class="multiselect"><div class="ms-field"><span class="ms-tag">Метка A<button class="ms-tag-remove" type="button" aria-label="Удалить метку A">×</button></span><input class="ms-input" id="tags" placeholder="Добавить метку · Enter"></div></div><span class="field-msg">Произвольные значения</span></div></div></section><section class="panel-section"><h2 class="section-title">Выбор и параметры</h2><div class="form-grid-2"><fieldset class="demo-fieldset"><legend class="field-label">Параметры</legend><div class="checkbox-group"><label class="checkbox-item"><input type="checkbox" name="paramA" checked><span class="checkbox-mark"></span><span class="checkbox-label">Параметр A</span></label><label class="checkbox-item"><input type="checkbox" name="paramB"><span class="checkbox-mark"></span><span class="checkbox-label">Параметр B</span></label><label class="checkbox-item"><input type="checkbox" name="paramPartial" data-indeterminate><span class="checkbox-mark"></span><span class="checkbox-label">Частичный выбор</span></label></div></fieldset><fieldset class="demo-fieldset"><legend class="field-label">Вариант</legend><div class="radio-group"><label class="radio-item"><input type="radio" name="variant" value="a" checked><span class="radio-mark"></span><span class="radio-text"><span class="radio-label">Вариант A</span><span class="radio-desc">Пояснение к варианту</span></span></label><label class="radio-item"><input type="radio" name="variant" value="b"><span class="radio-mark"></span><span class="radio-text"><span class="radio-label">Вариант B</span></span></label></div></fieldset><div class="entity-lookup"><label class="field"><span class="field-label">Связанный объект</span><input class="field-input" name="related" role="combobox" aria-autocomplete="list" data-lookup placeholder="Поиск объекта" autocomplete="off" aria-expanded="false" aria-controls="lookup-list"></label><div class="lookup-dropdown" id="lookup-list" role="listbox" aria-label="Результаты поиска"></div><span class="field-msg" data-lookup-status>Введите название или номер</span></div><label class="field"><span class="field-label">Только чтение</span><input class="field-input" value="#1001" readonly><span class="field-msg">Значение формируется системой</span></label></div></section><section class="panel-section"><h2 class="section-title">Состояния полей</h2><p class="field-msg">Образцы оформления состояний: эти значения не относятся к сохраняемой записи.</p><div class="form-grid-2"><label class="field is-error"><span class="field-label">Ошибка</span><input class="field-input" value="Неполное значение" aria-invalid="true" readonly><span class="field-msg">Уточните значение</span></label><label class="field is-warning"><span class="field-label">Предупреждение</span><input class="field-input" value="Требует проверки" readonly><span class="field-msg">Проверьте данные</span></label><label class="field is-success"><span class="field-label">Подтверждено</span><input class="field-input" value="Корректное значение" readonly><span class="field-msg">Проверка пройдена</span></label><label class="field"><span class="field-label">Недоступно</span><input class="field-input" value="Нет права изменения" disabled><span class="field-msg">Disabled</span></label></div></section><section class="panel-section"><h2 class="section-title">Вложения</h2><div class="file-dropzone" data-dropzone><b>Перетащите файлы сюда</b><label class="btn btn-secondary">Выбрать файлы<input class="file-input-hidden" type="file" multiple data-files></label><span class="field-msg">Файлы остаются на устройстве; сохраняются только названия в этой демонстрации</span></div><div class="file-list demo-file-list" data-file-list></div></section></form></section>';}
function cardPage(){return '<section class="demo-page ui-detail-card" data-page="card" hidden>'+header(pages.card,secondary('Назад к списку','back-to-origin')+primary('Изменить','edit'))+'<div class="tabs-page" data-card-tabs><div class="tab-bar" role="tablist" aria-label="Разделы карточки"><button class="tab-btn is-active" type="button" role="tab" id="card-tab-overview" aria-controls="card-pane-overview" tabindex="0" aria-selected="true" data-card-tab="overview">Обзор</button><button class="tab-btn" type="button" role="tab" id="card-tab-documents" aria-controls="card-pane-documents" tabindex="-1" aria-selected="false" data-card-tab="documents">Документы <span class="tab-badge">3</span></button><button class="tab-btn" type="button" role="tab" id="card-tab-history" aria-controls="card-pane-history" tabindex="-1" aria-selected="false" data-card-tab="history">История</button></div><div class="tab-pane is-active" data-card-pane="overview" id="card-pane-overview" aria-labelledby="card-tab-overview" role="tabpanel"><div class="demo-card-columns registry-ui-card-layout"><div class="table-card registry-ui-card"><section class="panel-section"><h2 class="section-title">Сведения об объекте</h2><div class="demo-value-pair" id="card-facts"></div></section><section class="panel-section"><h2 class="section-title">Описание</h2><p class="registry-ui-primary">Пример полной карточки с основными и дополнительными сведениями. Наименование и длинные значения сохраняются без потери текста.</p></section><section class="panel-section"><h2 class="section-title">Этапы работы</h2><div class="stepper"><div class="step is-done"><span class="step-circle">✓</span><span class="step-label">Создано</span></div><div class="step is-active"><span class="step-circle">2</span><span class="step-label">Проверка</span></div><div class="step"><span class="step-circle">3</span><span class="step-label">Готово</span></div></div></section></div><section class="panel-section table-card registry-ui-card registry-ui-card-actions"><h2 class="section-title">Действия</h2><div class="demo-stack">'+secondary('Просмотреть параметры','read')+secondary('Изменить параметры','value-edit')+secondary('Открыть полную форму','edit-full')+secondary('Создать документ','create-document')+'<button class="btn btn-danger" type="button" data-action="delete">Удалить объект</button>'+'</div></section></div></div><div class="tab-pane" data-card-pane="documents" id="card-pane-documents" aria-labelledby="card-tab-documents" role="tabpanel" hidden><section class="panel-section"><h2 class="section-title">Связанные документы</h2><div class="doc-list">'+['Документ A','Документ B','Документ C'].map((x,i)=>'<div class="doc-row"><span class="doc-type">DOC</span><div><div class="doc-title">'+x+'</div><div class="doc-meta">01.11.2026 · Анна П.</div></div><span class="doc-status doc-'+['ok','pending','warning'][i]+'">'+['Подтверждён','Ожидается','На проверке'][i]+'</span></div>').join('')+'</div></section></div><div class="tab-pane" data-card-pane="history" id="card-pane-history" aria-labelledby="card-tab-history" role="tabpanel" hidden><section class="panel-section"><h2 class="section-title">История действий</h2><div class="activity-list">'+[['09:30','Создан объект'],['09:35','Изменены параметры'],['09:40','Добавлен документ']].map(([time,text])=>'<div class="activity-row"><span class="activity-date">'+time+'</span><div class="activity-main"><span class="activity-title">'+text+'</span><span class="registry-ui-secondary">Анна П. · сегодня</span></div></div>').join('')+'</div></section></div></div></section>';}
const checkState={error:true,warning:true};
function validationPage(){return '<section class="demo-page" data-page="validation" hidden>'+header(pages.validation,secondary('Сбросить пример','reset-validation')+primary('Повторить проверку','run-validation'))+'<div class="demo-stack"><div class="demo-columns"><section class="panel-section table-card registry-ui-card"><h2 class="section-title">Красная ошибка</h2><div class="demo-stack"><div class="form-alert alert-error" role="alert" data-check-alert="error"><span class="alert-mark">×</span><div class="alert-body"><div class="alert-body-title">Не заполнено обязательное значение</div><div class="alert-body-sub">Объект #1002. Сохранение недоступно, пока ошибка не исправлена.</div></div></div><label class="field is-error" data-check-field="error"><span class="field-label">Наименование объекта <span class="req">*</span></span><input class="field-input" data-check-input="error" placeholder="Укажите наименование" aria-invalid="true" aria-describedby="check-error-msg"><span class="field-msg" id="check-error-msg">Укажите наименование, чтобы сохранить запись.</span></label><div class="demo-row">'+secondary('Исправить ошибку','fix-error')+secondary('Красное уведомление','notify-error')+'</div></div></section><section class="panel-section table-card registry-ui-card"><h2 class="section-title">Жёлтое предупреждение</h2><div class="demo-stack"><div class="form-alert alert-warning" role="status" data-check-alert="warning"><span class="alert-mark">!</span><div class="alert-body"><div class="alert-body-title">Значение требует проверки</div><div class="alert-body-sub">Объект #1004. Действие доступно после подтверждения значения.</div></div></div><label class="field is-warning" data-check-field="warning"><span class="field-label">Количество</span><input class="field-input" data-check-input="warning" type="number" value="120" aria-describedby="check-warning-msg"><span class="field-msg" id="check-warning-msg">Значение выше обычного. Подтвердите после проверки.</span></label><div class="demo-row">'+secondary('Подтвердить проверку','confirm-warning')+secondary('Жёлтое уведомление','notify-warning')+'</div></div></section></div><section class="table-card registry-ui-card"><div class="table-toolbar registry-ui-toolbar"><div class="found-label">Результаты проверки: <b>3</b></div><div class="toolbar-right"><span class="registry-ui-secondary" data-check-summary>1 ошибка · 1 предупреждение · 1 без замечаний</span></div></div><div class="table-scroll"><table class="table registry-ui-table registry-ui-data"><thead><tr><th>ID</th><th>Объект</th><th>Результат</th><th>Что требуется</th></tr></thead><tbody><tr class="registry-ui-soft-error" data-check-row="error"><td class="registry-ui-id">#1002</td><td class="registry-ui-name">Объект «Альфа»</td><td class="registry-ui-error" data-check-result="error">Ошибка</td><td data-check-detail="error">Заполнить обязательное значение</td></tr><tr class="registry-ui-warning" data-check-row="warning"><td class="registry-ui-id">#1004</td><td class="registry-ui-name">Объект «Бета»</td><td data-check-result="warning">Предупреждение</td><td data-check-detail="warning">Проверить и подтвердить количество</td></tr><tr class="registry-ui-soft-success"><td class="registry-ui-id">#1005</td><td class="registry-ui-name">Объект «Гамма»</td><td class="registry-ui-success">Без замечаний</td><td>Действия доступны</td></tr></tbody></table></div></section><div class="form-alert alert-info"><span class="alert-mark">i</span><div class="alert-body"><div class="alert-body-title">Цвет дополняется текстом и знаком</div><div class="alert-body-sub">Обе ситуации показаны в поле, сообщении и строке таблицы. Кнопки выше открывают стандартные всплывающие уведомления.</div></div></div></div></section>';}
function renderValidation(){
 const root=document.querySelector('[data-page="validation"]');
 for(const tone of ['error','warning']){
  const active=checkState[tone],field=root.querySelector('[data-check-field="'+tone+'"]'),alert=root.querySelector('[data-check-alert="'+tone+'"]'),row=root.querySelector('[data-check-row="'+tone+'"]');
  field.classList.toggle('is-'+tone,active);field.classList.toggle('is-success',!active);alert.hidden=!active;
  root.querySelector('[data-check-input="'+tone+'"]').setAttribute('aria-invalid',String(tone==='error'&&active));
  root.querySelector('#check-'+tone+'-msg').textContent=active?(tone==='error'?'Укажите наименование, чтобы сохранить запись.':'Значение выше обычного. Подтвердите после проверки.'):'Значение проверено.';
  row.className=active?(tone==='error'?'registry-ui-soft-error':'registry-ui-warning'):'registry-ui-soft-success';
  const result=root.querySelector('[data-check-result="'+tone+'"]');result.textContent=active?(tone==='error'?'Ошибка':'Предупреждение'):'Без замечаний';result.className=active?(tone==='error'?'registry-ui-error':''):'registry-ui-success';
  root.querySelector('[data-check-detail="'+tone+'"]').textContent=active?(tone==='error'?'Заполнить обязательное значение':'Проверить и подтвердить количество'):'Действия доступны';
 }
 const errorRecord=rows.find(r=>r.id===1002),warningRecord=rows.find(r=>r.id===1004);
 if(errorRecord)errorRecord.issues.error=Number(checkState.error);
 if(warningRecord){warningRecord.issues.warning=Number(checkState.warning);warningRecord.status=checkState.warning?'attention':'active';}
 ['objects','documents','operations'].forEach(renderRows);
 renderMenuCounts();
 root.querySelector('[data-check-summary]').textContent=Number(checkState.error)+' '+(checkState.error?'ошибка':'ошибок')+' · '+Number(checkState.warning)+' '+(checkState.warning?'предупреждение':'предупреждений')+' · '+(3-Number(checkState.error)-Number(checkState.warning))+' без замечаний';
}
work.innerHTML=['objects','documents','operations'].map(registry).join('')+cardPage()+formPage()+validationPage()+window.UIDemoMessages.renderPage({page:pages.messages,header,primary,secondary});
work.querySelector('[data-page=messages]').classList.add('ui-detail-card');
const folderIcon='<svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2H5a2 2 0 0 1 -2 -2V6a2 2 0 0 1 2 -2z"/></svg>';
const chevron='<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6 -6 6"/></svg>';
const menuTree=[
 {id:'section-main',label:'Основной раздел',section:true,children:[{id:'objects',page:'objects'}]},
 {id:'section-folders',label:'Примеры папок',section:true,children:[
  {id:'folder-records',label:'Записи',children:[{id:'documents',page:'documents'},{id:'operations',page:'operations'}]},
  {id:'folder-objects',label:'Объекты',children:[{id:'card',page:'card'},{id:'form',page:'form'}]}
 ]},
 {id:'section-control',label:'Контроль данных',section:true,children:[{id:'validation',page:'validation'},{id:'messages',page:'messages'}]}
];
function issueCountsForPage(node){
 if(['objects','documents','operations'].includes(node.page))return rows.filter(r=>!r.deleted).reduce((n,r)=>({error:n.error+r.issues.error,warning:n.warning+r.issues.warning}),{error:0,warning:0});
 if(node.page==='card')return current()?.issues||{error:0,warning:0};
 if(node.page==='form'){const form=document.getElementById('object-form');return {error:form.querySelectorAll('.field.is-error').length,warning:form.querySelectorAll('.field.is-warning').length};}
 if(node.page==='messages')return window.UIDemoMessages.getIssues?.()||{error:0,warning:0};
 if(node.page==='validation')return {error:Number(checkState.error),warning:Number(checkState.warning)};
 return {error:0,warning:0};
}
const menuTones=['error','warning','success','info'],menuToneLabels={error:'Ошибки',warning:'Предупреждения',success:'Положительные события',info:'Уведомления'},menuToneClasses={error:'is-alert',warning:'is-warning',success:'is-success',info:'is-info'};
function menuBadges(sub){const klass=sub?'sub-count':'nav-count';return '<span class="nav-counts">'+menuTones.map(tone=>'<span class="'+klass+' '+menuToneClasses[tone]+'" data-menu-severity="'+tone+'" aria-label="'+menuToneLabels[tone]+': 0" hidden>0</span>').join('')+'</span>'; }
function renderMenuNode(node,sub=false){
 if(node.section)return '<div class="nav-group"><div class="nav-section-label">'+E(node.label)+'</div>'+node.children.map(child=>renderMenuNode(child)).join('')+'</div>';
 if(node.children)return '<a href="#'+node.id+'" role="button" class="nav-item is-parent is-open" data-menu-node="'+node.id+'" data-folder="'+node.id+'" aria-expanded="true" aria-controls="'+node.id+'">'+folderIcon+'<span class="nav-label">'+E(node.label)+'</span>'+menuBadges(sub)+chevron+'</a><div class="nav-sub is-open" id="'+node.id+'">'+node.children.map(child=>renderMenuNode(child,true)).join('')+'</div>';
 const key=node.page,p=pages[key],label=key==='form'?'Форма объекта':key==='validation'?'Проверка данных':key==='messages'?'Сообщения':p.title;
 return '<a href="#'+key+'" class="'+(sub?'nav-sub-item':'nav-item')+'" data-menu-node="'+node.id+'" data-navigate="'+key+'" title="'+E(label)+'">'+(sub?'':navIcon(p.icon))+'<span class="nav-label">'+label+'</span>'+menuBadges(sub)+'</a>';
}
function applyMenuCounts(counted){
 function apply(nodes){for(const node of nodes){
  const el=nav.querySelector('[data-menu-node="'+node.id+'"]');
  if(el){el.dataset.errors=node.counts.error;el.dataset.warnings=node.counts.warning;for(const tone of menuTones){const badge=el.querySelector('[data-menu-severity="'+tone+'"]'),n=node.counts[tone],label=menuToneLabels[tone]+': '+n;badge.textContent=n;badge.hidden=n===0;badge.title=label;badge.setAttribute('aria-label',label);}}
  if(node.children)apply(node.children);
 }}
 apply(counted);
}
const menuController=window.UIMenuCounts.createController({projectId:'f6e21e3c-d974-4604-82fb-333da66559d2',surface:'canonical-demo',identityProvider:()=> 'demo-person-001',folderIds:['menu-counter-specimen','folder-records','folder-objects'],readCounts:()=>window.UIMenuCounts.aggregate(menuTree,issueCountsForPage,menuTones),applyCounts:applyMenuCounts});
function renderMenuCounts(){menuController.requestRefresh();}
nav.innerHTML=window.UIMenuCounts.specimen({expanded:menuController.isExpanded('menu-counter-specimen')})+menuTree.map(node=>renderMenuNode(node)).join('');
function toggleFolder(el){menuController.toggle(el.dataset.folder);}
menuController.mount(nav).start();

function current(){return rows.find(r=>r.id===state.current&&!r.deleted)||rows.find(r=>!r.deleted);}
function registryPage(page){return ['objects','documents','operations'].includes(page);}
function focusDescriptor(element=document.activeElement){
 const row=element?.closest?.('tr[data-open]');
 if(row)return {page:state.page,row:Number(row.dataset.open),menu:!!element.closest('[data-row-menu]')};
 if(element?.closest?.('dialog')?._returnFocus)return element.closest('dialog')._returnFocus;
 if(element?.closest?.('.demo-ctx-anchor'))return document.querySelector('.demo-ctx-anchor')?._returnFocus||null;
 if(element?.id)return {page:state.page,id:element.id};
 if(element?.dataset?.action)return {page:state.page,action:element.dataset.action};
 return {page:state.page};
}
function restoreFocus(key){
 if(document.querySelector('dialog[open]'))return;
 const root=document.querySelector('[data-page="'+(key?.page||state.page)+'"]');
 if(!root||root.hidden)return;
 let target=key?.id?document.getElementById(key.id):null;
 if(!target&&key?.field)target=root.querySelector('[name="'+key.field+'"]');
 if(key?.row){const row=root.querySelector('tr[data-open="'+key.row+'"]');target=key.menu?row?.querySelector('[data-row-menu]'):row;}
 if(!target&&key?.action)target=[...root.querySelectorAll('[data-action]')].find(b=>b.dataset.action===key.action);
 if(!target||!target.isConnected||target.disabled){target=root.querySelector('.page-title');if(target)target.tabIndex=-1;}
 target?.focus({preventScroll:true});
}
function captureOrigin(focus=focusDescriptor()){
 if(!registryPage(state.page))return;
 const root=document.querySelector('[data-page="'+state.page+'"]');
 state.origin={page:state.page,y:work.scrollTop,x:root.querySelector('.table-scroll')?.scrollLeft||0,focus};
}
function showCardFacts(){
 const r=current();
 const facts=r?[['Номер','#'+r.id],['Наименование',r.name],['Категория',r.category],['Ответственный',r.owner],['Дата',r.date],['Время',r.time||'—'],['Статус',statusLabel[r.status]],['Количество',r.count],['Сумма',money(r.amount)]]:[];
 if(r?.note)facts.push(['Комментарий',r.note]);
 if(r?.tags?.length)facts.push(['Метки',r.tags.join(', ')]);
 if(r?.related)facts.push(['Связанный объект',r.related]);
 if(r?.parameters)facts.push(['Параметры',[r.parameters.a?'A':'',r.parameters.b?'B':'',r.parameters.partial==='mixed'?'Частичный выбор':r.parameters.partial?'Дополнительный параметр':''].filter(Boolean).join(', ')||'Не выбраны']);
 if(r?.variant)facts.push(['Вариант',r.variant.toUpperCase()]);
 if(r?.attachments?.length)facts.push(['Вложения',r.attachments.map(f=>f.name).join(', ')]);
 document.getElementById('card-facts').innerHTML=r?facts.map(([a,b])=>'<span class="registry-ui-secondary">'+E(a)+'</span><span class="registry-ui-primary">'+E(b)+'</span>').join(''):'<span class="registry-ui-secondary">Объект не выбран. Создайте запись.</span>';
}
// Hidden registries temporarily lose their horizontal control. Wait for its existing
// observer-driven layout and font metrics before restoring a saved navigation point.
function afterPageLayout(page,callback){
 const revision=state.navigationRevision;let previous=null,frames=0;
 function settle(){
  if(state.page!==page||state.navigationRevision!==revision)return;
  const root=document.querySelector('[data-page="'+page+'"]');if(!root||root.hidden)return;
  window.PlanexRegistryInteractions?.sync?.(root);
  const layout=[work.scrollHeight,root.getBoundingClientRect().height,root.querySelector('.registry-ui-toolbar')?.getBoundingClientRect().height||0].join(':');
  if((previous!==null&&layout===previous)||++frames>=6){callback();return;}
  previous=layout;requestAnimationFrame(settle);
 }
 const ready=document.fonts?.ready;if(ready)ready.then(()=>requestAnimationFrame(settle));else requestAnimationFrame(settle);
}
function navigate(page,options={}){saveFeedback.clear();
 if(!pages[page])return false;
 if(state.page==='form'&&page!=='form'&&!options.force&&isFormDirty()){
  confirmDiscard(()=>{loadForm(state.formModel,state.editing);navigate(page,{...options,force:true});});return false;
 }
 if((page==='card'||page==='form')&&registryPage(state.page)&&!options.restoring)captureOrigin(options.originFocus||focusDescriptor());
 state.navigationRevision++;state.page=page;
 document.querySelectorAll('[data-page]').forEach(p=>p.hidden=p.dataset.page!==page);
 nav.querySelectorAll('[data-navigate]').forEach(a=>{a.classList.toggle('is-active',a.dataset.navigate===page);if(a.dataset.navigate===page)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});
 // Folder expansion is an explicit per-user choice; navigation never forces it open.
 document.title=pages[page].title+' · Универсальный демостенд';
 if(!options.preserveScroll)work.scrollTop=0;
 if(registryPage(page))renderRows(page);if(page==='validation')renderValidation();if(page==='card')showCardFacts();
 const visibleRoot=document.querySelector('[data-page="'+page+'"]');applyCapabilities(visibleRoot);window.PlanexRegistryInteractions?.sync?.(visibleRoot);
 renderMenuCounts();updateGuide();if(options.focusTarget)afterPageLayout(page,()=>restoreFocus(options.focusTarget));return true;
}
function returnToOrigin(){
 if(state.page==='form'&&isFormDirty()){confirmDiscard(()=>{loadForm(state.formModel,state.editing);returnToOrigin();});return;}
 const origin=state.origin||{page:'objects',y:0,x:0,focus:{page:'objects'}};
 if(!navigate(origin.page,{restoring:true}))return;
 afterPageLayout(origin.page,()=>{const root=document.querySelector('[data-page="'+origin.page+'"]');const scroller=root?.querySelector('.table-scroll');if(scroller)scroller.scrollLeft=origin.x;restoreFocus(origin.focus);work.scrollTop=origin.y;});
}
function isMutation(key){return ['create-object','create-document','create-operation','edit','edit-full','value-edit','delete','submit-form','confirm-delete','edit-values','fix-error','confirm-warning','reset-validation','run-validation'].includes(key);}
function allowMutation(){
 if(state.mode!=='readonly')return true;
 toast('warning','В режиме просмотра изменения недоступны.');return false;
}
function applyCapabilities(root){
 if(!root)return;const readonly=state.mode==='readonly';
 root.querySelectorAll('[data-action],[data-dialog-action],[data-menu-action]').forEach(b=>{const key=b.dataset.action||b.dataset.dialogAction||b.dataset.menuAction;if(isMutation(key))b.disabled=readonly;});
 root.querySelectorAll('[data-check-input]').forEach(input=>input.disabled=readonly);
 root.querySelectorAll('#object-form input,#object-form select,#object-form textarea,#object-form button').forEach(input=>{if(!input.hasAttribute('data-original-disabled'))input.dataset.originalDisabled=String(input.disabled);input.disabled=readonly||input.dataset.originalDisabled==='true';});
 const page=root.closest('[data-page]')||root;
 if(page.matches('[data-page]')){
  let notice=page.querySelector('[data-readonly-notice]');
  if(readonly&&!notice){notice=document.createElement('div');notice.className='form-alert alert-warning';notice.dataset.readonlyNotice='';notice.setAttribute('role','status');notice.innerHTML='<span class="alert-mark" aria-hidden="true">!</span><div class="alert-body"><div class="alert-body-title">Режим просмотра</div><div class="alert-body-sub">Записи доступны для чтения. Создание, изменение и удаление отключены.</div></div>';page.querySelector('.page-head')?.after(notice);}
  if(notice)notice.hidden=!readonly;
 }
}
navigate('objects');['objects','documents','operations'].forEach(renderRows);

function toast(tone,message){window.PlanexNotifications?.show({tone,message});}
function formSnapshot(form){
 return JSON.stringify([...form.querySelectorAll('input,select,textarea')].filter(i=>i.type!=='file'&&!i.readOnly&&!i.closest('.ui-date-field,.ui-time-field')).map(i=>[i.name||i.id||i.type,i.type==='checkbox'||i.type==='radio'?[i.checked,!!i.indeterminate]:i.value]).concat([...form.querySelectorAll('.ui-date-field input:invalid,.ui-time-field input:invalid')].map(i=>['invalid-picker',i.value]),[...form.querySelectorAll('.ms-tag')].map(t=>['tag',t.firstChild?.textContent||'']),[...form.querySelectorAll('[data-file-name]')].map(t=>['file',t.dataset.fileName])));
}
function isFormDirty(){const f=document.getElementById('object-form');return state.formBaseline!==null&&formSnapshot(f)!==state.formBaseline;}
function confirmDiscard(discard){
 if(document.querySelector('dialog[data-discard-confirmation][open]'))return;
 const dialog=openDialog('Несохранённые изменения','<p class="registry-ui-modal-text">Введённые изменения ещё не сохранены. Продолжить редактирование или отказаться от изменений?</p>','<button class="btn btn-secondary" type="button" data-close>Продолжить редактирование</button><button class="btn btn-danger" type="button" data-discard>Не сохранять</button>','registry-ui-modal--confirmation');
 dialog.dataset.discardConfirmation='';dialog.querySelector('[data-discard]').addEventListener('click',()=>{dialog.close();discard();});
}
function closeDialog(dialog){
 if(!dialog)return;const form=dialog.querySelector('form');
 if(form&&dialog._initialSnapshot!==formSnapshot(form)){confirmDiscard(()=>dialog.close());return;}
 dialog.close();
}
function openDialog(title,body,foot,variant='registry-ui-modal--reading',onSubmit=null){
 const d=document.createElement('dialog');d.className='modal registry-ui-modal '+variant+' demo-dialog';d.setAttribute('aria-label',title);
 d.innerHTML='<div class="modal-head"><h2 class="modal-title">'+E(title)+'</h2><button class="modal-close" type="button" data-close aria-label="Закрыть">×</button></div>'+(onSubmit?'<form class="registry-ui-modal-form">':'')+'<div class="modal-body">'+body+'</div><div class="modal-foot"><div class="modal-foot-actions">'+foot+'</div></div>'+(onSubmit?'</form>':'');
 const focus=state.dialogReturnFocus||focusDescriptor();state.dialogReturnFocus=null;d._returnFocus=focus;
 document.getElementById('dialog-root').append(d);
 d.addEventListener('close',()=>{d.remove();requestAnimationFrame(()=>restoreFocus(focus));},{once:true});
 d.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>closeDialog(d)));
 d.addEventListener('cancel',e=>{e.preventDefault();closeDialog(d);});
 if(onSubmit){const form=d.querySelector('form');d._initialSnapshot=formSnapshot(form);form.addEventListener('submit',e=>{e.preventDefault();onSubmit(d);});}
 applyCapabilities(d);d.showModal();return d;
}

const closeButton='<button class="btn btn-ghost" type="button" data-close>Отменить</button>';
const roomPickerField=()=>'<label class="field"><span class="field-label">Помещение</span><select class="field-select" name="room" data-rental-picker="room"><option value="">Выберите помещение</option>'+Array.from({length:20},(_,i)=>'<option value="'+(i+1)+'" data-name="Помещение '+(i+1)+'" data-type="Офис" data-area="12.5" data-available="true" data-description="Офис · 12.5 м² · Доступно">Помещение '+(i+1)+'</option>').join('')+'</select></label>';
const availabilityField=r=>'<label class="field"><span class="field-label">Доступность</span><select class="field-select" name="rentable"><option value="1"'+(r?.rentable!==false?' selected':'')+'>Доступен</option><option value="0"'+(r?.rentable===false?' selected':'')+'>Недоступен</option></select></label>';
function savedObject(r){refresh();if(state.page==='objects'){window.UIRegistryViewport.ensureRow(work,'objects',r.id);saveFeedback.show('objects',r.id,'Объект #'+r.id);}else toast('success','Объект #'+r.id+' сохранён.');}
function openModal(kind){
 if(isMutation(kind)&&!allowMutation())return;
 if(kind==='create-object'||kind==='create-operation'){
  const title=kind==='create-operation'?'Добавить изменение':'Создать объект';
  openDialog(title,'<div class="registry-ui-modal-grid">'+roomPickerField()+'<div class="field"><label class="field-label" for="new-object-name">Наименование</label><input class="field-input" name="name" id="new-object-name" required></div><div class="field"><label class="field-label" for="new-object-category">Категория</label><select class="field-select" name="category" id="new-object-category"><option>Категория A</option><option>Категория B</option><option>Категория C</option></select></div><div class="field"><label class="field-label" for="new-object-count">Количество</label><input class="field-input" type="number" name="count" id="new-object-count" min="0" value="1"></div><div class="field"><label class="field-label" for="new-object-amount">Сумма</label><input class="field-input" type="number" name="amount" id="new-object-amount" min="0" step="0.01" value="0"></div>'+availabilityField()+'</div>',closeButton+'<button class="btn btn-primary" type="submit">Сохранить</button>','registry-ui-modal--reading',d=>{
   if(!allowMutation())return;const r={...neutralFormModel(),id:Math.max(...rows.map(x=>x.id))+1,name:d.querySelector('[name=name]').value.trim(),category:d.querySelector('[name=category]').value,count:Number(d.querySelector('[name=count]').value),amount:Number(d.querySelector('[name=amount]').value),date:'02.11.2026',owner:'Анна П.',status:'new',actual:0,rentable:d.querySelector('[name=rentable]')?.value!=='0',deleted:false,issues:{error:0,warning:0}};
   rows.unshift(r);state.current=r.id;d.close();savedObject(r);
  });return;
 }
 const r=current();if(!r){toast('warning','Нет доступной записи. Создайте объект.');return;}
 if(kind==='read'){
  openDialog('Объект #'+r.id,'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading"><section class="registry-ui-modal-grid"><span class="page-eyebrow">Объект</span><p class="registry-ui-modal-text"><strong class="registry-ui-entity-id">#'+r.id+'</strong><br>'+E(r.name)+'</p></section><section class="registry-ui-modal-grid"><span class="page-eyebrow">Сведения</span><p class="registry-ui-modal-text">'+E(r.category)+'<br>'+E(r.owner)+'</p></section><section class="registry-ui-modal-preview"><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr><th>Параметр</th><th>Значение</th><th>Было</th><th>Стало</th></tr></thead><tbody><tr><td><span class="registry-ui-entity-id">#'+r.id+'</span></td><td>Количество</td><td>2</td><td>'+r.count+'</td></tr><tr><td><span class="registry-ui-entity-id">#'+r.id+'</span></td><td>Сумма</td><td>8 000 ₽</td><td>'+money(r.amount)+'</td></tr></tbody></table></div></section></div>',closeButton+'<button class="btn btn-secondary" data-dialog-action="open-card" type="button">Открыть карточку</button><button class="btn btn-primary" data-dialog-action="edit-values" type="button">Изменить параметры</button>');return;
 }
 if(kind==='edit'||kind==='value-edit'){
  openDialog('Параметры объекта #'+r.id,'<div class="registry-ui-modal-grid"><div class="field"><label class="field-label" for="edit-name">Наименование</label><input id="edit-name" name="name" class="field-input" required value="'+E(r.name)+'"></div>'+availabilityField(r)+'<label class="field"><span class="field-label">Категория</span><select class="field-select" name="category">'+['Категория A','Категория B','Категория C'].map(value=>'<option'+(value===r.category?' selected':'')+'>'+value+'</option>').join('')+'</select></label><span class="page-eyebrow">Объект <strong class="registry-ui-entity-id">#'+r.id+'</strong></span><section class="registry-ui-modal-preview"><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-compact-table registry-ui-compact-table--weight-single"><thead><tr><th>Параметр</th><th>Было</th><th>Стало</th></tr></thead><tbody><tr><td>Количество</td><td>'+r.count+'</td><td><input class="field-input" name="count" type="number" min="0" value="'+r.count+'" aria-label="Новое количество"></td></tr><tr><td>Сумма</td><td>'+money(r.amount)+'</td><td><input class="field-input" name="amount" type="number" min="'+amountRules.min+'" step="'+amountRules.step+'" value="'+r.amount+'" aria-label="Новая сумма"></td></tr></tbody></table></div></section><span class="field-msg" role="status">Изменения сохраняются в текущей демонстрации.</span></div>',closeButton+'<button class="btn btn-primary" type="submit">Сохранить изменения</button>','registry-ui-modal--weight-single',d=>{if(!allowMutation())return;r.name=d.querySelector('[name=name]').value;r.count=Number(d.querySelector('[name=count]').value);r.amount=Number(d.querySelector('[name=amount]').value);r.rentable=d.querySelector('[name=rentable]').value==='1';r.category=d.querySelector('[name=category]').value;d.close();savedObject(r);});return;
 }
 if(kind==='delete'){
  openDialog('Удаление объекта #'+r.id,'<div class="registry-ui-modal-confirm"><p class="registry-ui-modal-text">Будет удалён объект <strong class="registry-ui-entity-id">#'+r.id+'</strong> «'+E(r.name)+'».</p><p class="registry-ui-modal-secondary">Это действие изменяет только демонстрационные данные.</p></div>',closeButton+'<button class="btn btn-danger" type="button" data-dialog-action="confirm-delete">Удалить объект</button>','registry-ui-modal--confirmation');return;
 }
 if(kind==='create-document'){
  openDialog('Создать документ','<div class="registry-ui-modal-grid"><div class="field"><label class="field-label" for="doc-number">Номер документа<span class="req">*</span></label><input id="doc-number" name="number" class="field-input" required placeholder="Например, 1001"></div><div class="field"><label class="field-label" for="doc-date">Дата</label><input id="doc-date" name="date" class="field-input" type="date" value="2026-11-01"></div><div class="field"><label class="field-label" for="doc-time">Время</label><input id="doc-time" name="time" class="field-input" type="time" value="09:30"></div><div class="registry-ui-modal-grid registry-ui-modal-grid--reading"><section><span class="page-eyebrow">Связанный объект</span><p class="registry-ui-modal-text"><strong class="registry-ui-entity-id">#'+r.id+'</strong><br>'+E(r.name)+'</p></section><section><span class="page-eyebrow">Значение</span><p class="registry-ui-modal-summary">'+money(r.amount)+'</p></section></div></div>',closeButton+'<button class="btn btn-primary" type="submit">Создать документ</button>','registry-ui-modal--finance',d=>{if(!allowMutation())return;const n=d.querySelector('[name=number]').value;const id=Math.max(...rows.map(x=>x.id))+1;rows.unshift({...r,id,docNumber:n,name:'Документ №'+n,date:displayDate(d.querySelector('[name=date]').value),time:d.querySelector('[name=time]').value,status:'new',actual:0,deleted:false,issues:{error:0,warning:0}});state.current=id;d.close();refresh();toast('success','Документ №'+n+' создан.');});return;
 }
}
function refresh(){['objects','documents','operations'].forEach(renderRows);if(state.page==='card')showCardFacts();applyCapabilities(document.querySelector('[data-page="'+state.page+'"]'));}
function isoDate(value){if(/^\d{4}-\d{2}-\d{2}$/.test(value||''))return value;const m=/^(\d{2})\.(\d{2})\.(\d{4})$/.exec(value||'');return m?m[3]+'-'+m[2]+'-'+m[1]:'';}
function displayDate(value){const m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(value||'');return m?m[3]+'.'+m[2]+'.'+m[1]:value||'';}
function neutralFormModel(record){return record?{name:record.name,category:record.category,date:isoDate(record.date),time:record.time||'',count:record.count,amount:record.amount,note:record.note||'',tags:[...(record.tags||[])],parameters:{...(record.parameters||{a:false,b:false,partial:false})},variant:record.variant||'a',related:record.related||'',attachments:(record.attachments||[]).map(f=>({...f}))}:{name:'',category:'Категория A',date:'2026-11-01',time:'09:30',count:5,amount:12500,note:'',tags:['Метка A'],parameters:{a:true,b:false,partial:'mixed'},variant:'a',related:'',attachments:[]};}
function addTag(text){
 const input=document.querySelector('.ms-input');const label=String(text).trim();if(!label)return;
 const tag=document.createElement('span');tag.className='ms-tag';tag.append(document.createTextNode(label));
 const b=document.createElement('button');b.type='button';b.className='ms-tag-remove';b.setAttribute('aria-label','Удалить '+label);b.textContent='×';tag.append(b);input.before(tag);
}
function loadForm(model=null,editing=null){
 const f=document.getElementById('object-form'),m=model?JSON.parse(JSON.stringify(model)):neutralFormModel();state.editing=editing;state.formModel=m;
 for(const name of ['name','category','date','time','count','amount','note','related'])f.elements[name].value=m[name]??'';
 f.elements.paramA.checked=!!m.parameters.a;f.elements.paramB.checked=!!m.parameters.b;f.elements.paramPartial.checked=m.parameters.partial===true;f.elements.paramPartial.indeterminate=m.parameters.partial==='mixed';
 f.querySelectorAll('[name=variant]').forEach(i=>i.checked=i.value===m.variant);
 f.querySelectorAll('.ms-tag').forEach(t=>t.remove());m.tags.forEach(addTag);f.querySelector('.ms-input').value='';f.querySelector('[data-file-list]').replaceChildren();showFiles(m.attachments);
 f.querySelector('[data-counter]').closest('.field').querySelector('.char-counter').textContent=m.note.length+' / 160';
 const readonly=f.querySelector('input[readonly]');if(readonly)readonly.value=editing?'#'+editing:'После сохранения';
 const heading=document.querySelector('[data-page=form] .page-title');heading.textContent=editing?'Редактирование объекта #'+editing:'Создание объекта';
 for(const name of ['date','time'])f.elements[name].dispatchEvent(new Event('change',{bubbles:true}));
 window.UIDatePicker?.sync?.(f);window.UITimePicker?.sync?.(f);state.formBaseline=formSnapshot(f);renderMenuCounts();
}
function openFullForm(record=null){
 if(!allowMutation())return;
 const run=()=>{if(registryPage(state.page))captureOrigin();loadForm(neutralFormModel(record),record?.id||null);navigate('form',{force:true,focusTarget:{page:'form',field:'name'}});};
 if(isFormDirty())confirmDiscard(run);else run();
}
function readFormModel(f){
 const partial=f.elements.paramPartial;
 return {name:f.elements.name.value.trim(),category:f.elements.category.value,date:displayDate(f.elements.date.value),time:f.elements.time.value,count:Number(f.elements.count.value),amount:Number(f.elements.amount.value),note:f.elements.note.value,tags:[...f.querySelectorAll('.ms-tag')].map(t=>t.firstChild.textContent),parameters:{a:f.elements.paramA.checked,b:f.elements.paramB.checked,partial:partial.indeterminate?'mixed':partial.checked},variant:f.querySelector('[name=variant]:checked')?.value||'a',related:f.elements.related.value,attachments:[...f.querySelectorAll('[data-file-name]')].map(el=>({name:el.dataset.fileName,size:Number(el.dataset.fileSize)||0}))};
}
function closeRowMenu(restore=true){
 const menu=document.querySelector('.demo-ctx-anchor');if(!menu)return null;const key=menu._returnFocus;menu.remove();
 document.querySelectorAll('[data-row-menu][aria-expanded=true]').forEach(b=>b.setAttribute('aria-expanded','false'));
 if(restore)restoreFocus(key);return key;
}
function openRowMenu(button){
 closeRowMenu(false);state.current=Number(button.dataset.rowMenu);
 const menu=document.createElement('div');menu.className='ctx-menu demo-ctx-anchor';menu.setAttribute('role','menu');menu.setAttribute('aria-label','Действия с записью');menu._returnFocus=focusDescriptor(button);
 menu.innerHTML=[['read','Просмотреть'],['open-card','Открыть карточку'],['edit','Изменить'],['delete','Удалить']].map(([a,t])=>'<button class="ctx-item '+(a==='delete'?'is-danger':'')+'" type="button" role="menuitem"'+(state.mode==='readonly'&&isMutation(a)?' disabled':'')+' data-menu-action="'+a+'">'+t+'</button>').join('');
 document.body.append(menu);button.setAttribute('aria-expanded','true');const r=button.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(innerWidth-menu.offsetWidth-8,r.right-menu.offsetWidth))+'px';menu.style.top=Math.max(8,Math.min(innerHeight-menu.offsetHeight-8,r.bottom+4))+'px';menu.querySelector('button:not(:disabled)').focus();
}
function closeLookup(){const input=document.querySelector('[data-lookup]');document.getElementById('lookup-list').classList.remove('is-open');input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');}
function lookupOptions(){return [...document.querySelectorAll('#lookup-list [role=option]')];}
function lookupMoveIndex(key,index,count){
 if(!count)return -1;
 if(key==='Home')return 0;if(key==='End')return count-1;
 if(index<0)return key==='ArrowUp'?count-1:0;
 return (index+(key==='ArrowUp'?-1:1)+count)%count;
}
function focusLookup(index){const list=lookupOptions();if(!list.length)return;const i=(index+list.length)%list.length;list.forEach((item,n)=>item.setAttribute('aria-selected',String(n===i)));const input=document.querySelector('[data-lookup]');input.setAttribute('aria-activedescendant',list[i].id);list[i].focus();}

const roles={
 'registry.page-head':['Стандартная шапка','objects'],
 'registry.tabs':['Вкладки статусов','objects'],
 'registry.card':['Рабочий блок с общей рамкой','objects'],
 'registry.toolbar':['Панель таблицы','objects'],
 'registry.search':['Поиск','objects'],
 'registry.table':['Таблица и заголовки','objects'],
 'registry.sticky':['Закреплённая панель и заголовки','objects'],
 'registry.select':['Select: нормальное состояние, список и выбор','objects'],
 'registry.actions':['Кнопки шапки','objects'],
 'registry.checkbox':['Флажок панели','documents'],
 'registry.finance-table':['Таблица числовых показателей','documents'],
 'registry.checkbox-group':['Группа флажков','documents'],
 'registry.data':['Типографика данных','documents'],
 'registry.id':['Номер записи','objects'],
 'registry.trip':['Основная строка с длинным текстом','documents'],
 'registry.secondary':['Дополнительная строка','objects'],
 'registry.palette':['Смысловые цвета и состояния строки','objects'],
 'registry.paid-status':['Вторичная подпись состояния','documents'],
 'registry.status':['Статус записи','objects'],
 'registry.state':['Сброс условий','objects'],
 'registry.scroll':['Прокрутка широкого содержимого','documents'],
 'registry.placement-blocks':['Таблица истории операций','operations'],
 'registry.inline-identity':['Номер и наименование в одной строке','operations'],
 'registry.modal':['Общая оболочка попапа','objects','read'],
 'registry.modal-reading':['Попап просмотра','objects','read'],
 'registry.modal-finance':['Попап создания документа','documents','create-document'],
 'registry.modal-confirmation':['Попап подтверждения','objects','delete'],
 'registry.modal-weight-single':['Компактный попап редактирования','objects','value-edit'],
 'registry.modal-reading-grid':['Две группы данных в попапе','objects','read'],
 'registry.compact-table':['Таблица в попапе','objects','read'],
 'registry.compact-table-weight-single':['Матрица значений и полей','objects','value-edit'],
 'registry.entity-id':['Номер объекта в попапе','objects','read'],
 'notifications.toast':['Информация, успех, предупреждение и ошибка','objects','toast']
};
function showComponent(id){
 const config=roles[id];if(!config)return;
 document.querySelectorAll('dialog[open]').forEach(d=>d.close());state.mode='normal';navigate(config[1]);const exampleRoot=document.querySelector('[data-page="'+config[1]+'"]');exampleRoot?.querySelector('.registry-ui-reset')?.click();if(['objects','documents','operations'].includes(config[1]))renderRows(config[1]);
 if(config[2]==='toast'){document.documentElement.dataset.componentShown=id;document.documentElement.dataset.componentFound='true';toast('info','Новое задание. Раздел: Согласования. Ожидается проверка документа.');toast('success','Изменения сохранены.');toast('warning','Не все значения заполнены.');toast('danger','Не удалось выполнить действие.');return;}
 if(config[2])openModal(config[2]);
 if(id==='registry.state'){const q=document.querySelector('[data-page=objects] [data-registry-field=query]');q.value='Объект';q.dispatchEvent(new Event('input',{bubbles:true}));}
 const selector=D.catalog.find(c=>c.id===id)?.selector;
 requestAnimationFrame(()=>{const scope=config[2]?document.querySelector('dialog[open]'):document.querySelector('[data-page="'+config[1]+'"]');const target=scope?.matches(selector)?scope:scope?.querySelector(selector);if(target){target.scrollIntoView({block:'center',inline:'nearest'});}document.documentElement.dataset.componentShown=id;document.documentElement.dataset.componentFound=String(!!target);});
}
function openCatalog(){
 const row=c=>'<tr data-catalog-row><td><span class="registry-ui-primary">'+E(roles[c.id]?.[0]||c.id)+'</span><div class="registry-ui-secondary">'+E(c.description||'')+'</div></td><td><code>'+E(c.selector)+'</code><div class="registry-ui-secondary">'+E(pages[roles[c.id]?.[1]]?.title||'')+'</div></td><td><button class="btn btn-secondary" type="button" data-component-id="'+E(c.id)+'">Показать</button></td></tr>';
 const body='<div class="field"><label class="field-label" for="catalog-search">Найти компонент</label><input class="field-input" id="catalog-search" type="search" placeholder="Кнопка, таблица, попап или CSS-класс"></div><p class="registry-ui-modal-text">Нажмите «Показать», чтобы открыть пример. Селектор рядом можно выделить и скопировать в задачу агенту.</p><div class="table-scroll"><table class="table registry-ui-table registry-ui-compact-table registry-ui-compact-table--catalog"><thead><tr><th>Компонент и назначение</th><th>Селектор и пример</th><th>Действие</th></tr></thead><tbody>'+D.catalog.map(row).join('')+'</tbody></table></div><p class="field-msg" role="status" id="catalog-result-count"></p>';
 const d=openDialog('Каталог компонентов · '+D.catalog.length,body,closeButton);
 const rows=[...d.querySelectorAll('[data-catalog-row]')];
 const filter=()=>{const q=d.querySelector('#catalog-search').value.trim().toLocaleLowerCase('ru');let count=0;rows.forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase('ru').includes(q);if(!row.hidden)count++;});d.querySelector('#catalog-result-count').textContent=count?'Найдено: '+count+' из '+D.catalog.length:'Ничего не найдено. Измените запрос.';};
 d.querySelector('#catalog-search').addEventListener('input',filter);filter();d.querySelector('#catalog-search').focus();
}
function updateGuide(){
 const root=document.querySelector('[data-page="'+state.page+'"]');const definitions=[['.registry-ui-head','Стандартная шапка'],['.registry-ui-tabs','Вкладки'],['.registry-ui-toolbar','Панель таблицы'],['.registry-ui-table','Таблица'],['.page-title','Заголовок страницы'],['.registry-ui-search','Поиск'],['.field-input','Поле формы'],['.tab-bar','Вкладки карточки']];
 document.getElementById('guide-content').innerHTML=definitions.filter(([selector])=>root?.querySelector(selector)).map(([selector,name])=>{const el=root.querySelector(selector),s=getComputedStyle(el);return '<button class="btn btn-toolbar" type="button" data-inspect-selector="'+selector+'">'+name+'</button><span class="registry-ui-secondary">'+E(s.fontFamily.split(',')[0].replace(/"/g,''))+' · '+s.fontSize+' / '+s.fontWeight+' · '+s.lineHeight+'</span>';}).join('')+'<span class="registry-ui-secondary">Выберите элемент для перехода к нему на странице.</span>';
}
function openStates(){openDialog('Состояния примера','<div class="demo-state-options">'+[['normal','Обычное состояние'],['empty','Нет записей'],['loading','Загрузка'],['error','Ошибка загрузки'],['readonly','Только просмотр']].map(([id,label])=>secondary(label,'state:'+id)).join('')+'</div>',closeButton+secondary('Ошибки и предупреждения','show-validation'),'registry-ui-modal--confirmation');}
function openPalette(){
 const body='<div class="form-alert alert-info"><span class="alert-mark">i</span><div class="alert-body"><div class="alert-body-title">Общая палитра системы</div><div class="alert-body-sub">Смысловые группы цветов. Различия поверхностей, градиенты и грани сохранены; hover и производные оттенки вычисляются автоматически.</div></div></div><div class="field"><label class="field-label" for="palette-search">Найти цвет или роль</label><input class="field-input" id="palette-search" placeholder="Акцент, текст, граница…"></div><div class="demo-palette-list" id="palette-list"></div><span class="field-msg" role="status" id="palette-status">Цвета объединены по назначению: бренд, поверхности, текст и состояния.</span>';
 const d=openDialog('Палитра системы',body,closeButton+'<button class="btn btn-secondary" type="button" data-palette-reset>Исходная палитра</button><button class="btn btn-primary" type="button" data-palette-export>Скачать palette.css</button>');
 function draw(){const q=d.querySelector('#palette-search').value.toLocaleLowerCase('ru'),computed=getComputedStyle(document.documentElement),entries=Object.entries(D.palette).filter(([key,c])=>(key+' '+c.label+' '+c.group+' '+c.value).toLocaleLowerCase('ru').includes(q)),groups=[...new Set(Object.values(D.palette).map(c=>c.group))];d.querySelector('#palette-list').innerHTML=groups.map(group=>{const colors=entries.filter(([,c])=>c.group===group);if(!colors.length)return '';return '<section class="demo-palette-group"><h3 class="section-title">'+E(group)+'</h3>'+colors.map(([key,c])=>{const value=computed.getPropertyValue('--'+key).trim();return '<label class="demo-palette-row"><span class="demo-palette-swatch" style="background:var(--'+key+')" aria-hidden="true"></span><span class="field-label">'+E(c.label)+'</span><input class="field-input" data-palette-token="'+key+'" value="'+E(value)+'" aria-label="'+E(c.label)+'"></label>';}).join('')+'</section>';}).join('');}
 d.querySelector('#palette-search').addEventListener('input',draw);draw();
 d.addEventListener('input',e=>{const key=e.target.dataset.paletteToken;if(!key)return;try{window.UIPalette.set(key,e.target.value);e.target.closest('label').classList.remove('is-error');d.querySelector('#palette-status').textContent='Черновик применён только в этой вкладке витрины.';state.previewColors=true;}catch{e.target.closest('label').classList.add('is-error');d.querySelector('#palette-status').textContent='Введите корректный цвет: HEX, rgb() или rgba().';}});
 d.querySelector('[data-palette-reset]').addEventListener('click',()=>{window.UIPalette.reset();state.previewColors=false;draw();d.querySelector('#palette-status').textContent='Исходная палитра восстановлена.';});
 d.querySelector('[data-palette-export]').addEventListener('click',()=>{const s=getComputedStyle(document.documentElement);const css='/* Единая палитра системы. Цвета без изменения структуры CSS. */\n:root {\n'+[...new Set(Object.values(D.palette).map(c=>c.group))].map(group=>'  /* '+group+' */\n'+Object.entries(D.palette).filter(([,c])=>c.group===group).map(([k])=>'  --'+k+': '+s.getPropertyValue('--'+k).trim()+';').join('\n')).join('\n\n')+'\n}\n';const url=URL.createObjectURL(new Blob([css],{type:'text/css;charset=utf-8'})),a=document.createElement('a');a.href=url;a.download='palette.css';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);d.querySelector('#palette-status').textContent='Палитра подготовлена. Файл цветов приложения — public/design/system/palette.css. Передайте изменение агенту для внедрения.';});
}
document.getElementById('all-elements').addEventListener('click',openCatalog);
document.getElementById('demo-logout').addEventListener('click',()=>toast('success','В приложении эта кнопка завершает сеанс. Здесь показан пример оформления.'));
document.getElementById('inspect-page').addEventListener('click',()=>{state.guide=!state.guide;document.getElementById('guide').hidden=!state.guide;document.getElementById('inspect-page').setAttribute('aria-pressed',String(state.guide));updateGuide();});
document.getElementById('guide-close').addEventListener('click',()=>{state.guide=false;document.getElementById('guide').hidden=true;document.getElementById('inspect-page').setAttribute('aria-pressed','false');});
document.getElementById('page-states').addEventListener('click',openStates);
document.getElementById('palette-open').addEventListener('click',openPalette);
function action(key){
 if(isMutation(key)&&!allowMutation())return;
 if(key==='back-to-origin'){returnToOrigin();return;}
 if(key.startsWith('reset-table:')){const kind=key.slice(12);document.querySelector('[data-page="'+kind+'"] .registry-ui-reset')?.click();return;}
 if(window.UIDemoMessages.handleAction(key,{openDialog,toast,primary,secondary,closeButton}))return;
 if(['fix-error','confirm-warning','run-validation','reset-validation','notify-error','notify-warning','show-validation'].includes(key)){
  if(key==='show-validation'){document.querySelectorAll('dialog[open]').forEach(d=>d.close());navigate('validation');return;}
  if(key==='notify-error'){toast('danger','Не заполнено обязательное значение. Исправьте ошибку и повторите действие.');return;}
  if(key==='notify-warning'){toast('warning','Значение выше обычного. Проверьте данные перед продолжением.');return;}
  const input=document.querySelector('[data-check-input=error]');
  if(key==='fix-error'){input.value='Объект «Альфа»';checkState.error=false;toast('success','Ошибка исправлена. Наименование заполнено.');}
  if(key==='confirm-warning'){checkState.warning=false;toast('success','Количество проверено и подтверждено.');}
  if(key==='reset-validation'){input.value='';document.querySelector('[data-check-input=warning]').value='120';checkState.error=true;checkState.warning=true;}
  if(key==='run-validation'){checkState.error=!input.value.trim();if(checkState.error)toast('danger','Проверка не пройдена. Заполните наименование.');else if(checkState.warning)toast('warning','Проверьте количество и подтвердите значение.');else toast('success','Проверка пройдена. Замечаний нет.');}
  renderValidation();return;
 }

 if(key.startsWith('state:')){state.mode=key.slice(6);document.querySelectorAll('dialog[open]').forEach(d=>d.close());if(!['objects','documents','operations'].includes(state.page))navigate('objects');refresh();toast(state.mode==='error'?'danger':state.mode==='readonly'?'warning':'success',state.mode==='readonly'?'Включён режим просмотра.':state.mode==='error'?'Показано состояние ошибки.':'Состояние примера изменено.');return;}
 if(pages[key]){if(key==='form'&&state.formBaseline===null)loadForm();navigate(key);return;}
 if(key==='create-object'){openModal('create-object');return;}
 if(key==='edit-full'){openFullForm(current());return;}
 if(key==='create-operation'){openModal('create-operation');return;}
 if(['edit','read','value-edit','delete','create-document'].includes(key)){openModal(key);return;}
 if(key==='submit-form'){document.getElementById('object-form').requestSubmit();return;}
 if(key==='retry'){state.mode='normal';refresh();toast('success','Записи загружены.');}
}
document.addEventListener('click',e=>{
 const folder=e.target.closest('[data-folder]');if(folder){e.preventDefault();toggleFolder(folder);return;}
 const link=e.target.closest('[data-navigate]');if(link){e.preventDefault();if(link.dataset.navigate==='form'&&state.formBaseline===null)loadForm();navigate(link.dataset.navigate);return;}
 const b=e.target.closest('button');
 if(b?.dataset.action){action(b.dataset.action);return;}
 if(b?.dataset.componentId){showComponent(b.dataset.componentId);return;}
 if(b?.dataset.inspectSelector){const el=document.querySelector('[data-page="'+state.page+'"]')?.querySelector(b.dataset.inspectSelector);if(el){el.scrollIntoView({block:'center'});}return;}
 if(b?.dataset.dialogAction){const key=b.dataset.dialogAction;if(isMutation(key)&&!allowMutation())return;if(key==='open-card'){const d=b.closest('dialog'),originFocus=d._returnFocus;d.close();navigate('card',{originFocus,focusTarget:{page:'card'}});}if(key==='edit-values'){b.closest('dialog').close();openModal('value-edit');}if(key==='confirm-delete'){const r=current();r.deleted=true;b.closest('dialog').close();if(state.page==='card')returnToOrigin();refresh();toast('success','Объект #'+r.id+' удалён.');}return;}
 if(b?.dataset.rowMenu){openRowMenu(b);return;}
 if(b?.dataset.menuAction){const originFocus=closeRowMenu(false);if(b.dataset.menuAction==='open-card'){state.dialogReturnFocus=null;navigate('card',{originFocus,focusTarget:{page:'card'}});}else{state.dialogReturnFocus=originFocus;openModal(b.dataset.menuAction);}return;}
 if(b?.dataset.cardTab){activateCardTab(b);return;}

 if(b?.hasAttribute('data-step')){if(!allowMutation())return;const input=b.parentElement.querySelector('input');input.value=Math.max(0,Number(input.value||0)+Number(b.dataset.step));input.dispatchEvent(new Event('input',{bubbles:true}));return;}
 if(b?.classList.contains('ms-tag-remove')){if(allowMutation())b.closest('.ms-tag').remove();return;}
 if(b?.classList.contains('file-remove')){if(allowMutation())b.closest('.file-item').remove();return;}
 if(b?.dataset.lookupChoice){const i=document.querySelector('[data-lookup]');i.value=b.dataset.lookupChoice;closeLookup();i.focus();return;}
 const tab=e.target.closest('[data-registry-tab]');if(tab){activateStatusTab(tab);return;}
 const row=e.target.closest('tr[data-open]');if(row&&!e.target.closest('button,input,select,a')){state.current=Number(row.dataset.open);row.focus({preventScroll:true});}
});
// Records open with a double click or Enter; no actions column.
document.addEventListener('dblclick',e=>{
 const scenario=e.target.closest('tr[data-message-scenario]');if(scenario){action('msg:'+scenario.dataset.messageScenario);return;}
 const component=e.target.closest('tr[data-component-id]');if(component){showComponent(component.dataset.componentId);return;}
 const row=e.target.closest('tr[data-open]');if(row&&!e.target.closest('button,input,select,a')){state.current=Number(row.dataset.open);row.focus({preventScroll:true});openModal(state.page==='objects'&&state.mode!=='readonly'?'edit':'read');}
});
document.addEventListener('input',e=>{
 const root=e.target.closest('[data-page]');if(root?.dataset.page==='form'&&e.target.name==='name'&&e.target.validity.valid){e.target.closest('.field').classList.remove('is-error');renderMenuCounts();}if(e.target.matches('[data-check-input]')){if(state.mode==='readonly')return;if(e.target.dataset.checkInput==='error')checkState.error=!e.target.value.trim();else checkState.warning=true;renderValidation();}if(e.target.matches('[data-registry-field]')&&root)renderRows(root.dataset.page);
 if(e.target.matches('[data-counter]'))e.target.closest('.field').querySelector('.char-counter').textContent=e.target.value.length+' / '+e.target.maxLength;
 if(e.target.matches('[data-lookup]')){const q=e.target.value.trim().toLocaleLowerCase('ru'),list=document.getElementById('lookup-list');const found=rows.filter(r=>(r.id+' '+r.name).toLocaleLowerCase('ru').includes(q)).slice(0,5);list.innerHTML=found.map((r,index)=>'<button id="lookup-option-'+r.id+'" tabindex="-1" type="button" class="lookup-item" aria-selected="false" role="option" data-lookup-choice="'+E('#'+r.id+' '+r.name)+'"><span class="lookup-avatar">'+r.id.toString().slice(-2)+'</span><span class="lookup-info"><span class="lookup-name">'+E(r.name)+'</span><span class="lookup-meta">#'+r.id+' · '+r.category+'</span></span></button>').join('');list.classList.toggle('is-open',!!q&&found.length>0);e.target.setAttribute('aria-expanded',String(!!q&&found.length>0));document.querySelector('[data-lookup-status]').textContent=found.length?'Найдено: '+found.length:'Ничего не найдено';}
});
document.addEventListener('change',e=>{const root=e.target.closest('[data-page]');if(e.target.matches('[data-registry-field]')&&root){renderRows(root.dataset.page);}if(e.target.matches('[data-files]')&&allowMutation())showFiles(e.target.files);});
document.getElementById('object-form').addEventListener('submit',e=>{
 e.preventDefault();if(!allowMutation())return;const f=e.target,model=readFormModel(f);if(!model.name)return;
 const existing=state.editing?rows.find(r=>r.id===state.editing&&!r.deleted):null;
 const r=existing||{id:Math.max(...rows.map(r=>r.id))+1,owner:'Анна П.',status:'new',actual:0,deleted:false,issues:{error:0,warning:0}};
 Object.assign(r,model);if(!existing)rows.unshift(r);state.current=r.id;loadForm(neutralFormModel(r),r.id);refresh();navigate('card',{force:true,focusTarget:{page:'card'}});toast('success','Объект #'+r.id+(existing?' обновлён.':' создан.'));
});

document.getElementById('object-form').addEventListener('invalid',e=>{e.target.closest('.field')?.classList.add('is-error');const msg=e.target.closest('.field')?.querySelector('.field-msg');if(msg){msg.textContent='Заполните обязательное поле.';msg.setAttribute('role','alert');}renderMenuCounts();},true);
document.querySelectorAll('[data-indeterminate]').forEach(i=>i.indeterminate=true);loadForm();
document.querySelector('.ms-input').addEventListener('keydown',e=>{if(e.key!=='Enter')return;e.preventDefault();if(!allowMutation())return;const text=e.target.value.trim();if(!text)return;addTag(text);e.target.value='';});
function showFiles(files){
 const list=document.querySelector('[data-file-list]');
 for(const f of files){const row=document.createElement('div');row.className='file-item';row.dataset.fileName=String(f.name);row.dataset.fileSize=String(Number(f.size)||0);const badge=document.createElement('span');badge.className='file-type-badge';badge.textContent=(f.name.split('.').pop()||'FILE').slice(0,4).toUpperCase();const info=document.createElement('div');info.className='file-info';const name=document.createElement('div');name.className='file-name';name.textContent=f.name;const meta=document.createElement('div');meta.className='file-meta';meta.textContent=Math.ceil((Number(f.size)||0)/1024)+' КБ · локальный файл';info.append(name,meta);const status=document.createElement('span');status.className='doc-status doc-ok';status.textContent='Выбран';const remove=document.createElement('button');remove.type='button';remove.className='file-remove';remove.textContent='×';remove.setAttribute('aria-label','Убрать '+f.name);row.append(badge,info,status,remove);list.append(row);}
}

const drop=document.querySelector('[data-dropzone]');drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('is-dragover');});drop.addEventListener('dragleave',()=>drop.classList.remove('is-dragover'));drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('is-dragover');if(allowMutation())showFiles(e.dataTransfer.files);});
document.addEventListener('keydown',e=>{
 if(e.target.closest('[data-folder]')&&e.key===' '){e.preventDefault();toggleFolder(e.target.closest('[data-folder]'));return;}
 const tab=e.target.closest('[role=tab]');
 if(tab&&['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){
  const tabs=[...tab.closest('[role=tablist]').querySelectorAll('[role=tab]')];let i=tabs.indexOf(tab);i=e.key==='Home'?0:e.key==='End'?tabs.length-1:(i+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;const next=tabs[i];e.preventDefault();if(next.dataset.registryTab)activateStatusTab(next);else activateCardTab(next);next.focus();return;
 }
 const menu=e.target.closest('.demo-ctx-anchor');
 if(menu){
  const items=[...menu.querySelectorAll('[role=menuitem]:not(:disabled)')];const i=items.indexOf(document.activeElement);
  if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)){e.preventDefault();const index=e.key==='Home'?0:e.key==='End'?items.length-1:(i+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;items[index]?.focus();return;}
  if(e.key==='Escape'){e.preventDefault();closeRowMenu();return;}
  if(e.key==='Tab'){e.preventDefault();const key=closeRowMenu();const trigger=document.querySelector('[data-page="'+key.page+'"] [data-row-menu="'+key.row+'"]');const all=[...document.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]')].filter(n=>n.getClientRects().length&&!n.closest('[hidden]'));const index=all.indexOf(trigger);all[index+(e.shiftKey?-1:1)]?.focus();return;}
 }
 if(e.key==='Escape')closeRowMenu();
 if(e.target.matches('tr[data-open]')&&(e.key==='Enter'||e.key===' ')){e.preventDefault();state.current=Number(e.target.dataset.open);openModal('read');return;}
 const lookup=e.target.matches('[data-lookup]'),option=e.target.closest('#lookup-list [role=option]');
 if(lookup||option){
  const options=lookupOptions(),index=options.indexOf(e.target);
  if(e.key==='Escape'){e.preventDefault();closeLookup();document.querySelector('[data-lookup]').focus();return;}
  if(['ArrowDown','ArrowUp','Home','End'].includes(e.key)&&document.getElementById('lookup-list').classList.contains('is-open')){e.preventDefault();focusLookup(lookupMoveIndex(e.key,index,options.length));return;}
  if(option&&(e.key==='Enter'||e.key===' ')){e.preventDefault();option.click();return;}
  if(e.key==='Tab')closeLookup();
 }
});
document.addEventListener('pointerdown',e=>{if(!e.target.closest('.demo-ctx-anchor,[data-row-menu]'))closeRowMenu(false);if(!e.target.closest('.entity-lookup'))closeLookup();});
window.addEventListener('demo:issues-change',renderMenuCounts);
window.addEventListener('beforeunload',e=>{if(isFormDirty()||[...document.querySelectorAll('dialog[open]')].some(d=>d.querySelector('form')&&d._initialSnapshot!==formSnapshot(d.querySelector('form')))){e.preventDefault();e.returnValue='';}});

window.addEventListener('palette:change',updateGuide);document.documentElement.dataset.demoReady='true';
})();
