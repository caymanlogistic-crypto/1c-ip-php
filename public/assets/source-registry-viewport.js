/* Shared logical-result cursor and progressive registry DOM. Page owns filtering, totals and permissions. */
(function(root){'use strict';
 let serial=0;
 function createCursor({rows=[],keyOf=(row,index)=>index,size=50}){let token=0,data=[],keys=new Map(),loaded=0;size=Math.max(1,Math.floor(Number(size))||50);
  const reset=rows=>{token=++serial;data=[];keys=new Map();loaded=0;rows.forEach((row,index)=>{const key=String(keyOf(row,index));if(keys.has(key))return;keys.set(key,data.length);data.push({row,key});});return token;};
  const next=(expected=token)=>{if(expected!==token)return[];const end=Math.min(loaded+size,data.length),out=data.slice(loaded,end).map(x=>x.row);loaded=end;return out;};
  const ensure=key=>{const index=keys.get(String(key));if(index===undefined||index<loaded)return[];const out=data.slice(loaded,index+1).map(x=>x.row);loaded=index+1;return out;};
  reset(rows);return Object.freeze({get token(){return token;},get total(){return data.length;},get loaded(){return loaded;},next,reset,ensure,keyAt:index=>data[index]?.key,findKey:predicate=>data.find(x=>predicate(x.row))?.key});
 }
 const controllers=new WeakMap(),active=new Set();
 const within=(scope,node)=>scope===node||!!scope?.contains?.(node);
 function mount(scope,{rows,keyOf=(row,index)=>index,renderRow,identity,signature='',restore=null,size=50,onAppend=()=>{},emptyHTML=''}={}){
  const old=controllers.get(scope);if(old){if(!restore&&old.signature===signature)restore=old.snapshot();old.dispose();}
  const card=scope.matches?.('.registry-ui-scroll')?scope:scope.querySelector('.registry-ui-scroll'),wrap=card?.querySelector(':scope>.table-scroll,:scope>.rp-v010-table-wrap'),table=wrap?.querySelector('table'),body=table?.tBodies[0];if(!body)throw Error('Registry viewport requires one table body.');
  const owner=card.closest('main.content')||root,cursor=createCursor({rows,keyOf,size}),token=cursor.token,rowNodes=new Map();let disposed=false,frame=0,observer=null,last=null;
  const bounds=()=>owner===root?{top:0,bottom:root.innerHeight}:owner.getBoundingClientRect();
  const controller={root:scope,card,signature,cursor,snapshot,dispose,ensureRow,mountSticky};controllers.set(scope,controller);active.add(controller);
  function mountSticky(){if(!disposed)root.PlanexRegistry?.mount(card,{managedMarkup:true});}
  function schedule(){if(disposed||frame||cursor.loaded>=cursor.total)return;frame=requestAnimationFrame(()=>{frame=0;if(disposed||cursor.token!==token)return;append(cursor.next(token));});}
  function inspectEdge(){if(last&&last.getBoundingClientRect().top<=bounds().bottom+180)schedule();}
  function observe(){observer?.disconnect();last=body.lastElementChild;if(!last||cursor.loaded>=cursor.total)return;if(observer)observer.observe(last);else inspectEdge();}
  function append(batch){if(disposed||!batch.length)return;const holder=document.createElement('tbody');holder.innerHTML=batch.map(renderRow).join('');const added=[...holder.rows];if(added.length!==batch.length)throw Error('One logical registry row must render one table row.');const start=cursor.loaded-batch.length;added.forEach((node,index)=>{const key=cursor.keyAt(start+index);node.dataset.registryRowKey=key;rowNodes.set(key,node);body.append(node);});onAppend(added,table);observe();}
  function ensureRow(kind,id){const key=cursor.findKey(row=>{const x=identity?.(row);return x&&x.kind===kind&&String(x.id)===String(id);});if(key===undefined)return false;append(cursor.ensure(key));return rowNodes.has(key);}
  function snapshot(){const b=bounds(),top=Math.max(b.top,table.tHead?.getBoundingClientRect().bottom||b.top),anchor=[...rowNodes].find(([,node])=>node.getBoundingClientRect().bottom>top);return{signature,loaded:cursor.loaded,anchor:anchor?.[0]||null,offset:anchor?anchor[1].getBoundingClientRect().top-b.top:null,scrollTop:owner===root?root.scrollY:owner.scrollTop,ownerX:owner===root?root.scrollX:owner.scrollLeft,wrapX:wrap.scrollLeft};}
  function dispose(){if(disposed)return;disposed=true;cancelAnimationFrame(frame);observer?.disconnect();owner.removeEventListener('scroll',inspectEdge);root.removeEventListener('resize',inspectEdge);root.PlanexRegistry?.unmount(card);controllers.delete(scope);active.delete(controller);rowNodes.clear();}
  if(typeof IntersectionObserver!=='undefined')observer=new IntersectionObserver(entries=>{if(!disposed&&entries.some(x=>x.isIntersecting))schedule();},{root:owner===root?null:owner,rootMargin:'0px 0px 180px 0px'});
  else{owner.addEventListener('scroll',inspectEdge,{passive:true});root.addEventListener('resize',inspectEdge,{passive:true});}
  body.innerHTML=cursor.total?'':emptyHTML;const initial=restore?.signature===signature?Math.max(size,restore.loaded||0):size;while(cursor.loaded<Math.min(initial,cursor.total))append(cursor.next(token));mountSticky();
  if(restore?.signature===signature){if(restore.anchor)append(cursor.ensure(restore.anchor));wrap.scrollLeft=restore.wrapX||0;if(owner===root)root.scrollTo(restore.ownerX||0,restore.scrollTop||0);else{owner.scrollLeft=restore.ownerX||0;owner.scrollTop=restore.scrollTop||0;}const anchor=rowNodes.get(restore.anchor);if(anchor&&restore.offset!==null){const delta=anchor.getBoundingClientRect().top-bounds().top-restore.offset;if(owner===root)root.scrollBy(0,delta);else owner.scrollTop+=delta;}}
  observe();return controller;
 }
 function snapshot(scope){return Object.fromEntries([...active].filter(c=>within(scope,c.root)).map(c=>[c.root.dataset.registryId,c.snapshot()]));}
 function destroy(scope){for(const c of [...active])if(within(scope,c.root))c.dispose();for(const node of [...(scope.matches?.('[data-registry-id]')?[scope]:[]),...scope.querySelectorAll('[data-registry-id]')])root.PlanexRegistry?.unmount(node);}
 function ensureRow(scope,kind,id){for(const c of active)if(within(scope,c.root)&&c.ensureRow(kind,id))return true;return false;}
 const api=Object.freeze({createCursor,mount,snapshot,destroy,ensureRow,inspect:scope=>[...active].filter(c=>!scope||within(scope,c.root)).map(c=>({id:c.root.dataset.registryId,loaded:c.cursor.loaded,total:c.cursor.total,token:c.cursor.token}))});root.UIRegistryViewport=api;if(typeof module!=='undefined')module.exports=api;
 if(typeof document!=='undefined')document.addEventListener('DOMContentLoaded',()=>{for(const c of active)c.mountSticky();},{once:true});
})(globalThis);
