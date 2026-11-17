/* Shared navigation counters: folders always sum the corresponding child counts. */
(function(root){
 'use strict';
 function count(value){
  value=value??0;
  if(!Number.isSafeInteger(value)||value<0)throw new TypeError('Menu counts must be non-negative safe integers');
  return value;
 }
 function aggregate(nodes,resolveLeaf,tones=['error','warning']){
  if(!Array.isArray(tones)||!tones.length||new Set(tones).size!==tones.length||tones.some(t=>!['error','warning','success','info'].includes(t)))throw new TypeError('Invalid menu severities');
  return nodes.map(node=>{
   if(Array.isArray(node.children)){
    const children=aggregate(node.children,resolveLeaf,tones);
    const counts=children.reduce((sum,child)=>Object.fromEntries(tones.map(t=>[t,count(sum[t]+child.counts[t])])),Object.fromEntries(tones.map(t=>[t,0])));
    return {...node,children,counts};
   }
   const source=resolveLeaf(node)||{};
   return {...node,counts:Object.fromEntries(tones.map(t=>[t,count(source[t])]))};
  });
 }
 // Static component specimen only. Colors here do not assign business events.
 function specimen({id='menu-counter-specimen',expanded=true}={}){
  if(!/^[a-z][a-z0-9-]*$/.test(id))throw new TypeError('Invalid menu specimen ID');
  const variants=[['error','is-alert','Красный'],['warning','is-warning','Жёлтый'],['success','is-success','Зелёный'],['info','is-info','Голубой']];
  const counters=sub=>'<span class="nav-counts">'+variants.map(([tone,klass,label])=>'<span class="'+(sub?'sub-count':'nav-count')+' '+klass+'" data-menu-variant="'+tone+'" aria-label="'+label+' вариант: 1" title="'+label+' вариант">1</span>').join('')+'</span>';
  const folder='<svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2H5a2 2 0 0 1 -2 -2V6a2 2 0 0 1 2 -2z"/></svg>';
  const chevron='<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6 -6 6"/></svg>';
  return '<div class="nav-group" data-menu-counter-specimen><div class="nav-section-label">Образец счётчиков</div><a href="#'+id+'" role="button" class="nav-item is-parent'+(expanded?' is-open':'')+'" data-folder="'+id+'" aria-expanded="'+expanded+'" aria-controls="'+id+'">'+folder+'<span class="nav-label">Папка</span>'+counters(false)+chevron+'</a><div class="nav-sub'+(expanded?' is-open':'')+'" id="'+id+'"'+(expanded?'':' inert')+'><div class="nav-sub-item"><span class="nav-label">Подпункт</span>'+counters(true)+'</div></div></div>';
 }
 // Identity and source adapters belong to the host. This controller never writes business state.
 function createController(options){
  const {projectId,surface,identityProvider,readCounts,applyCounts}=options;
  if(!/^[a-z0-9-]{1,80}$/i.test(projectId||'')||!/^[a-z0-9-]{1,80}$/i.test(surface||'')||typeof identityProvider!=='function'||typeof readCounts!=='function'||typeof applyCounts!=='function')throw new TypeError('Invalid menu controller adapters');
  const win=options.eventTarget||root,doc=options.document||root.document,clock=options.clock||root,intervalMs=options.intervalMs??30000;
  if(!Number.isFinite(intervalMs)||intervalMs<1000)throw new TypeError('Invalid refresh interval');
  const maxErrorIntervalMs=options.maxErrorIntervalMs??intervalMs;
  if(!Number.isFinite(maxErrorIntervalMs)||maxErrorIntervalMs<intervalMs)throw new TypeError('Invalid error refresh interval');
  let failedReads=0,pollSkips=0,explicitRead=false;
  let storage=options.storage;try{if(storage===undefined)storage=root.localStorage;}catch{storage=null;}
  const prefix='onecip:'+projectId+':menu-prefs:v1:'+surface+':',folderId=id=>typeof id==='string'&&/^[a-z][a-z0-9-]{0,95}$/i.test(id);
  const knownFolders=new Set((options.folderIds||[]).filter(folderId));
  let currentKey,expanded=new Set(),nav=null,generation=0,disposed=false,started=false,pageActive=true,timer=null,running=null,dirty=false;
  const listeners=[];
  function identityKey(){let identity;try{identity=identityProvider();}catch{return null;}const id=identity&&typeof identity==='object'?identity.id:identity;if(!['string','number'].includes(typeof id)||String(id).trim()===''||String(id).length>200)return null;return prefix+encodeURIComponent(String(id));}
  function readPrefs(key,fallback=new Set()){if(!key||!storage)return new Set(fallback);try{const value=JSON.parse(storage.getItem(key));if(value?.version!==1||!Array.isArray(value.expanded))return new Set(fallback);return new Set(value.expanded.slice(0,128).filter(id=>folderId(id)&&knownFolders.has(id)));}catch{return new Set(fallback);}}
  function setFolder(node,isOpen){node.setAttribute('aria-expanded',String(isOpen));node.classList.toggle('is-open',isOpen);const sub=node.nextElementSibling;if(sub?.classList.contains('nav-sub')){sub.classList.toggle('is-open',isOpen);sub.inert=!isOpen;}}
  function applyExpansion(){if(!nav)return;for(const node of nav.querySelectorAll('[data-folder]'))setFolder(node,expanded.has(node.dataset.folder));}
  function syncIdentity(){const key=identityKey();if(key!==currentKey){currentKey=key;expanded=readPrefs(key);generation++;applyExpansion();}return key;}
  function isExpanded(id){syncIdentity();return knownFolders.has(id)&&expanded.has(id);}
  function setExpanded(id,value){if(disposed||!folderId(id)||!knownFolders.has(id))return false;syncIdentity();expanded=readPrefs(currentKey,expanded);if(value)expanded.add(id);else expanded.delete(id);let persisted=false;if(currentKey&&storage)try{storage.setItem(currentKey,JSON.stringify({version:1,expanded:[...expanded]}));persisted=true;}catch{}applyExpansion();return persisted;}
  function toggle(id){return setExpanded(id,!isExpanded(id));}
  function mount(element){nav=element;syncIdentity();applyExpansion();return api;}
  const visible=()=>pageActive&&!doc?.hidden;
  function refresh(periodic){
    if(disposed||!visible())return Promise.resolve(false);
    if(!periodic)explicitRead=true;
    pollSkips=0;dirty=true;
    if(running)return running;
    running=Promise.resolve().then(async()=>{
      let applied=false;
      while(dirty&&!disposed&&visible()){
        dirty=false;syncIdentity();
        const stamp=generation,key=currentKey,periodicRead=!explicitRead;
        explicitRead=false;
        let value;
        try{value=await readCounts({periodic:periodicRead});}catch{value=null;}
        syncIdentity();
        if(disposed||!visible())break;
        if(stamp!==generation||key!==currentKey){dirty=true;explicitRead=true;continue;}
        if(value!==null&&value!==undefined){
          failedReads=0;pollSkips=0;
          try{applyCounts(value);applied=true;}catch{}
        }else{
          failedReads=Math.min(failedReads+1,8);
          pollSkips=Math.min(Math.ceil(maxErrorIntervalMs/intervalMs),2**failedReads)-1;
        }
      }
      return applied;
    }).finally(()=>{running=null;if(dirty&&!disposed&&visible())requestRefresh();});
    return running;
  }
  function requestRefresh(){return refresh(false);}
  function pause(){if(timer!==null){clock.clearInterval(timer);timer=null;}}
  // Timer ticks must not queue another read behind a slow request. Explicit refreshes
  // (a saved command, focus) still mark it dirty so that a fresh read follows it.
  function poll(){if(running)return;if(pollSkips>0){pollSkips--;return;}refresh(true);}
  function resume(){if(disposed||!visible()){pause();return;}if(timer===null)timer=clock.setInterval(poll,intervalMs);requestRefresh();}
  function listen(target,type,handler){if(!target?.addEventListener)return;target.addEventListener(type,handler);listeners.push([target,type,handler]);}
  function start(){if(disposed)return api;if(!started){started=true;listen(win,'storage',event=>{syncIdentity();if(event.key===currentKey||event.key===null){expanded=readPrefs(currentKey);applyExpansion();}if(event.key===null||(options.observedStorageKeys||[]).includes(event.key))requestRefresh();});listen(win,'focus',requestRefresh);listen(doc,'visibilitychange',()=>{if(visible())resume();else pause();});listen(win,'pagehide',()=>{pageActive=false;pause();});listen(win,'pageshow',()=>{pageActive=true;resume();});}resume();return api;}
  function destroy(){if(disposed)return;disposed=true;generation++;dirty=false;pause();for(const[target,type,handler]of listeners)target.removeEventListener(type,handler);listeners.length=0;nav=null;}
  const api=Object.freeze({isExpanded,setExpanded,toggle,mount,start,requestRefresh,destroy});return api;
 }
 // Restore with the same preference reader before the workspace is painted.
 function restoreNavigation(nav){
  if(!nav?.dataset.userId)return;
  nav.dataset.menuInitializing='true';
  const controller=createController({projectId:'f6e21e3c-d974-4604-82fb-333da66559d2',surface:'php-rental',identityProvider:()=>nav.dataset.userId,folderIds:[...nav.querySelectorAll('[data-folder]')].map(node=>node.dataset.folder),readCounts:async()=>null,applyCounts:()=>{}});
  controller.mount(nav).destroy();
  root.requestAnimationFrame(()=>root.requestAnimationFrame(()=>{delete nav.dataset.menuInitializing;}));
 }
 const api=Object.freeze({aggregate,specimen,createController,restoreNavigation});
 if(typeof module==='object'&&module.exports)module.exports=api;
 else root.UIMenuCounts=api;
})(typeof window==='object'?window:globalThis);
