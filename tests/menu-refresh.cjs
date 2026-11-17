'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const Menu=require('../public/assets/source-menu-counts.js');
const Notices=require('../public/assets/rental-notices.js');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
class Events {
  listeners=new Map();
  addEventListener(type,handler){if(!this.listeners.has(type))this.listeners.set(type,new Set());this.listeners.get(type).add(handler);}
  removeEventListener(type,handler){this.listeners.get(type)?.delete(handler);}
  emit(type,event={}){for(const handler of this.listeners.get(type)||[])handler(event);}
}
class Clock {
  jobs=new Map(); next=0;
  setInterval(fn,ms){this.jobs.set(++this.next,{fn,ms});return this.next;}
  clearInterval(id){this.jobs.delete(id);}
  async tick(){for(const {fn}of [...this.jobs.values()])fn();await flush();}
}
function fixture(readCounts,extra={}){
  const clock=new Clock(),doc=new Events(),win=new Events(),applied=[];
  doc.hidden=false;
  const controller=Menu.createController({projectId:'test',surface:'test',identityProvider:()=>1,
    intervalMs:2000,clock,document:doc,eventTarget:win,storage:null,readCounts,applyCounts:v=>applied.push(v),...extra});
  return {controller,clock,doc,win,applied};
}
test('visible cabinet polls every 2 seconds; hidden cabinet stops and resumes immediately',async()=>{
  let reads=0;
  const f=fixture(async()=>++reads);
  f.controller.start();await flush();
  assert.equal(reads,1);assert.equal([...f.clock.jobs.values()][0].ms,2000);
  for(let i=0;i<30;i++)await f.clock.tick();
  assert.equal(reads,31); // initial read + 30 reads per visible minute
  f.doc.hidden=true;f.doc.emit('visibilitychange');
  assert.equal(f.clock.jobs.size,0);
  await f.clock.tick();assert.equal(reads,31);
  f.doc.hidden=false;f.doc.emit('visibilitychange');await flush();
  assert.equal(reads,32);
  f.win.emit('focus');await flush();assert.equal(reads,33);
  f.controller.destroy();assert.equal(f.clock.jobs.size,0);
});
test('slow requests never overlap or accumulate timer-driven catch-up reads',async()=>{
  let reads=0,resolveRead;
  const f=fixture(()=>{reads++;return new Promise(resolve=>{resolveRead=resolve;});});
  f.controller.start();await flush();
  for(let i=0;i<15;i++)await f.clock.tick();
  assert.equal(reads,1);
  resolveRead({fresh:true});await flush();
  assert.equal(reads,1);assert.equal(f.applied.length,1);
  await f.clock.tick();assert.equal(reads,2);
  resolveRead({fresh:true});await flush();f.controller.destroy();
});
test('saved action refreshes immediately; saves during an old request coalesce into one fresh read',async()=>{
  let reads=0,resolveRead;
  const f=fixture(()=>{reads++;return new Promise(resolve=>{resolveRead=resolve;});});
  f.controller.start();await flush();
  f.controller.requestRefresh();f.controller.requestRefresh();
  resolveRead({old:true});await flush();
  assert.equal(reads,2);
  resolveRead({saved:true});await flush();
  assert.equal(f.applied.at(-1).saved,true);
  f.controller.requestRefresh();await flush();assert.equal(reads,3);
  resolveRead({savedAgain:true});await flush();f.controller.destroy();
});
test('request error does not create a tight retry loop',async()=>{
  let reads=0;
  const f=fixture(async()=>{reads++;throw new Error('offline');});
  f.controller.start();await flush();assert.equal(reads,1);
  await flush();assert.equal(reads,1);
  await f.clock.tick();assert.equal(reads,2);f.controller.destroy();
});
test('repeated polling and registry refresh show each popup once; changed cause is shown',()=>{
  const values=new Map(),shown=[];
  const storage={getItem:k=>values.get(k)||null,setItem:(k,v)=>values.set(k,v)};
  const component={show:value=>shown.push(value),setTemplateResolver(){}};
  let notices=Notices.create({storage,component});
  const dto={schema:1,project:'test',surface:'test',identity:'1',epoch:'test',bootstrap_epoch:'test',
    bootstrap_event_id:1008,catalog:{},events:[{id:'task:1009',event_id:1009,severity:'yellow',where:'approvals',text:'Проверьте договор',version:[1]}],
    sender:{id:'sender:1010',event_id:1010,severity:'green',where:'approvals',text:'Договор направлен',version:[1]}};
  for(let i=0;i<60;i++)notices.update(dto);
  assert.equal(shown.length,2);
  notices=Notices.create({storage,component});notices.update(dto);assert.equal(shown.length,2);
  dto.events[0]={...dto.events[0],severity:'red',text:'Истёк срок проверки'};
  notices.update(dto);assert.equal(shown.length,3);assert.equal(shown.at(-1).tone,'danger');
});
test('failed background reads back off up to 30s; own action bypasses backoff and success restores 2s',async()=>{
  let reads=0,offline=true;
  const f=fixture(async()=>{reads++;return offline?null:{};},{maxErrorIntervalMs:30000});
  f.controller.start();await flush();assert.equal(reads,1);
  await f.clock.tick();assert.equal(reads,1);
  await f.clock.tick();assert.equal(reads,2); // first retry after 4 seconds
  for(let i=0;i<3;i++)await f.clock.tick();assert.equal(reads,2);
  await f.clock.tick();assert.equal(reads,3); // second retry after 8 seconds
  offline=false;await f.controller.requestRefresh();assert.equal(reads,4);
  await f.clock.tick();assert.equal(reads,5);f.controller.destroy();
});
test('workspace polls at 2s, runs payment control at 30s or after save, and skips unchanged DOM',async()=>{
  const document=new Events(),window=new Events(),clock=new Clock();document.hidden=false;
  let removed=0,appended=0,reads=0,fullReads=0,controller,now=100000;
  const link={dataset:{page:'approvals'},querySelector:selector=>selector.includes('nav-counts')?{remove(){removed++;}}:null,
    append(){appended++;}};
  const navigation={dataset:{userId:'1',countLabels:'{}'},querySelectorAll:selector=>selector==='[data-page]'?[link]:[]};
  document.getElementById=id=>id==='navigation'?navigation:null;
  document.querySelectorAll=()=>[];
  document.querySelector=()=>null;
  document.createElement=()=>({children:[],dataset:{},setAttribute(){},append(child){this.children.push(child);}});
  window.UIMenuCounts={createController:options=>{
    assert.equal(options.intervalMs,2000);
    controller=Menu.createController({...options,clock,document,eventTarget:window,storage:null});return controller;
  }};
  const context={window,document,WeakMap,URL,Date:{now:()=>now},AbortSignal:{timeout:ms=>{assert.equal(ms,10000);return 'bounded';}},
    fetch:async(url,options)=>{assert.ok(['/api/menu-counts','/api/menu-counts?control=read'].includes(url));if(url==='/api/menu-counts')fullReads++;assert.equal(options.signal,'bounded');reads++;
      return {ok:true,headers:{get:()=> 'application/json'},json:async()=>({pages:{approvals:[0,1,0,0]},folders:{}})};}};
  vm.runInNewContext(fs.readFileSync(require.resolve('../public/assets/workspace-ui.js'),'utf8'),context);
  await flush();assert.equal(reads,1);assert.equal(appended,1);
  for(let i=0;i<30;i++){now+=2000;await clock.tick();}
  assert.equal(reads,31);assert.equal(appended,1);assert.equal(removed,1);
  assert.equal(fullReads,3); // initial + 30s + 60s; other 28 polls are read-only
  await controller.requestRefresh();assert.equal(fullReads,4);
  controller.destroy();
});
test('save during periodic read upgrades the coalesced follow-up to an explicit refresh',async()=>{
  const reasons=[];let resolveRead;
  const f=fixture(({periodic})=>{reasons.push(periodic);return new Promise(resolve=>{resolveRead=resolve;});});
  f.controller.start();await flush();resolveRead({});await flush();
  await f.clock.tick();assert.deepEqual(reasons,[false,true]);
  f.controller.requestRefresh();resolveRead({});await flush();
  assert.deepEqual(reasons,[false,true,false]);resolveRead({});await flush();f.controller.destroy();
});
