/* Approved registry UX. Opt in once per list; never attach a second feedback controller. */
(function(root){
 'use strict';
 function createSaveFeedback({workspace,tone='default',onHidden=({label})=>root.PlanexNotifications.show({tone:'success',title:'Изменения сохранены',message:label+'. Запись скрыта текущим фильтром.'})}){
 if(!['default','neutral'].includes(tone))throw new TypeError('Unknown save feedback tone');
 const flashClass=tone==='neutral'?'registry-ui-save-flash--neutral':'registry-ui-save-flash';
 const getWorkspace=typeof workspace==='function'?workspace:()=>workspace;
let savedRowFeedback=null;
function clearSavedRowFeedback(){if(!savedRowFeedback)return;const f=savedRowFeedback;clearTimeout(f.timer);cancelAnimationFrame(f.frame);f.row.removeEventListener('pointerenter',f.retime);f.row.removeEventListener('pointerleave',f.retime);if(f.ownsFlash)f.row.classList.remove(flashClass);for(const animation of f.animations)animation.cancel();if(f.scroller)f.scroller.scrollTo({top:f.scroller.scrollTop,left:f.scroller.scrollLeft,behavior:'instant'});savedRowFeedback=null;}
function savedRow(kind,id,label){
 clearSavedRowFeedback();const workspace=getWorkspace(),row=[...workspace.querySelectorAll('tr[data-save-kind][data-save-id]')].find(r=>r.dataset.saveKind===kind&&r.dataset.saveId===String(id));
 if(!row){onHidden({kind,id,label});return;}
 const f={row,timer:null,frame:null,scroller:null,ownsFlash:!row.classList.contains(flashClass),animations:new Set(),phase:'hold',fadeUntil:0};savedRowFeedback=f;
 // Retiming the existing 70 ms background transition: no new styles or keyframes.
 f.retime=()=>{if(!f.ownsFlash||matchMedia('(prefers-reduced-motion: reduce)').matches)return;const milliseconds=f.phase==='fade'?Math.max(1,f.fadeUntil-performance.now()):180;for(const cell of row.cells)for(const animation of cell.getAnimations()){if(!(animation instanceof CSSTransition)||animation.transitionProperty!=='background-color'||f.animations.has(animation))continue;const duration=Number(animation.effect.getComputedTiming().duration),remaining=duration-Number(animation.currentTime||0);if(remaining>0){f.animations.add(animation);animation.updatePlaybackRate(remaining/milliseconds);}}};
 row.addEventListener('pointerenter',f.retime);row.addEventListener('pointerleave',f.retime);
 const scroller=workspace,rect=row.getBoundingClientRect(),bounds=scroller.getBoundingClientRect(),head=row.closest('table').tHead.getBoundingClientRect();
 if(rect.top<Math.max(bounds.top,head.bottom)||rect.bottom>Math.min(innerHeight,bounds.bottom)){f.scroller=scroller;row.scrollIntoView({block:'nearest',inline:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});}
 f.frame=requestAnimationFrame(()=>{f.frame=requestAnimationFrame(()=>{if(savedRowFeedback!==f||!row.isConnected)return;if(f.ownsFlash)row.classList.add(flashClass);f.retime();f.timer=setTimeout(()=>{if(savedRowFeedback!==f)return;f.phase='fade';f.fadeUntil=performance.now()+800;if(f.ownsFlash)row.classList.remove(flashClass);f.retime();f.timer=setTimeout(()=>{if(savedRowFeedback===f)clearSavedRowFeedback();},850);},1600);});});
}
 return Object.freeze({show:savedRow,clear:clearSavedRowFeedback});
 }
function disableHoverHints(root){const elements=[...(root.matches?.('[title],[data-tooltip]')?[root]:[]),...root.querySelectorAll('[title],[data-tooltip]')];for(const element of elements){const title=element.getAttribute('title');if(title&&!element.hasAttribute('aria-label')&&!element.hasAttribute('aria-labelledby')&&!element.textContent.trim()&&element.matches('button,a,[role=button],[role=link],input[type=button],input[type=submit]'))element.setAttribute('aria-label',title);element.removeAttribute('title');element.removeAttribute('data-tooltip');}}
 function observeHoverHints(scope=document.body){
  disableHoverHints(scope);
  const observer=new MutationObserver(records=>{for(const record of records){if(record.type==='attributes')disableHoverHints(record.target);else for(const node of record.addedNodes)if(node.nodeType===1)disableHoverHints(node);}});
  observer.observe(scope,{subtree:true,childList:true,attributes:true,attributeFilter:['title','data-tooltip']});
  return ()=>observer.disconnect();
 }
 root.UIRegistryUX=Object.freeze({createSaveFeedback,disableHoverHints,observeHoverHints});
})(globalThis);
