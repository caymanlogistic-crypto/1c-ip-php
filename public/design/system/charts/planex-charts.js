/* Source geometry: PLANEX company_manager_statistics.php, pinned in SOURCE.json.
   Only the source popup's bounded left coordinate is set programmatically; all visual rules are shared CSS. */
(function () {
'use strict';
const NS='http://www.w3.org/2000/svg', W=920,H=171.6,L=58,R=58,T=12,B=27.6,PW=W-L-R,PH=H-T-B;
let serial=0;
const number=n=>Number(n).toFixed(1);
const format=n=>new Intl.NumberFormat('ru-RU',{maximumFractionDigits:1}).format(n);
function el(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
function svg(tag,attrs){const e=document.createElementNS(NS,tag);for(const k in attrs)e.setAttribute(k,String(attrs[k]));return e;}
function path(points) {
 if(!points.length)return '';
 let d='M'+number(points[0].x)+' '+number(points[0].y);
 const clamp=y=>Math.max(T,Math.min(T+PH,y));
 for(let i=0;i<points.length-1;i++){
  const a=points[Math.max(0,i-1)],b=points[i],c=points[i+1],e=points[Math.min(points.length-1,i+2)];
  d+=' C'+number(b.x+(c.x-a.x)/6*.82)+' '+number(clamp(b.y+(c.y-a.y)/6*.82))+' '+number(c.x-(e.x-b.x)/6*.82)+' '+number(clamp(c.y-(e.y-b.y)/6*.82))+' '+number(c.x)+' '+number(c.y);
 }
 return d;
}
function validate(data) {
 if(data?.colorOffset!==undefined&&(!Number.isInteger(data.colorOffset)||data.colorOffset<0||data.colorOffset>2))throw Error('График: индекс цвета от 0 до 2.');
 if(!data||!Array.isArray(data.labels)||data.labels.length>12||!data.labels.every(v=>typeof v==='string')||!Array.isArray(data.series)||data.series.length>3)throw Error('График: до 12 периодов и 3 рядов.');
 for(const s of data.series)if(typeof s.name!=='string'||!Array.isArray(s.values)||s.values.length!==data.labels.length||s.values.some(v=>v!==null&&(!Number.isFinite(v)||v<0)))throw Error('График: нужны неотрицательные значения; null означает отсутствие данных.');
}
function mount(root,data) {
 validate(data);root.replaceChildren();root.classList.add('planex-chart');const id='planex-chart-'+(++serial);
 if(!data.labels.length||!data.series.some(s=>s.values.some(v=>v!==null))){root.append(el('p','Нет наблюдений за выбранный период.','panel-section registry-ui-secondary'));return;}
 const all=data.series.flatMap(s=>s.values).filter(v=>v!==null),max=Math.max(1,...all)*1.12;
 const x=i=>L+(data.labels.length===1?PW/2:PW*i/(data.labels.length-1));
 const body=el('div',undefined,'planex-chart__body'),chart=svg('svg',{viewBox:'0 0 '+W+' '+H,role:'img','aria-labelledby':id+'-title'});
 const title=svg('title',{id:id+'-title'});title.textContent=data.title||'Динамика показателей';chart.append(title);
 const defs=svg('defs',{});chart.append(defs);
 for(let i=0;i<=4;i++){const y=T+PH*i/4;chart.append(svg('line',{x1:L,y1:y,x2:W-R,y2:y,class:'grid-line'}));const label=svg('text',{x:L-8,y:y+4,'text-anchor':'end',class:'axis-label'});label.textContent=format(max*(1-i/4));chart.append(label);}
 const guide=svg('line',{x1:0,x2:0,y1:T,y2:T+PH,class:'planex-chart__guide','aria-hidden':'true'});
 const groups=[],colors=['var(--chart-1)','var(--chart-2)','var(--chart-3)'];
 data.series.forEach((series,si)=>{
  const ci=(si+(data.colorOffset||0))%3;
  const gradient=svg('linearGradient',{id:id+'-area-'+si,x1:0,y1:0,x2:0,y2:1});
  for(const pair of [['0','.22'],['.72','.05'],['1','0']])gradient.append(svg('stop',{offset:pair[0],'stop-color':colors[ci],'stop-opacity':pair[1]}));defs.append(gradient);
  let segment=[];const segments=[];
  series.values.forEach((value,i)=>{if(value===null){if(segment.length)segments.push(segment);segment=[];}else segment.push({x:x(i),y:T+PH-value/max*PH,value,index:i});});if(segment.length)segments.push(segment);
  segments.forEach(pts=>{
   const d=path(pts);
   if(pts.length>1)chart.append(svg('path',{d:d+' L'+number(pts.at(-1).x)+' '+number(T+PH)+' L'+number(pts[0].x)+' '+number(T+PH)+' Z',fill:'url(#'+id+'-area-'+si+')',class:'planex-chart__area','aria-hidden':'true'}));
   chart.append(svg('path',{d,fill:'none',stroke:colors[ci],'stroke-width':si===0?3:2,'stroke-linecap':'round','stroke-linejoin':'round',pathLength:1,class:'planex-chart__line'+(si?' is-secondary':''),'data-series':ci,'aria-hidden':'true'}));
   pts.forEach(p=>{
    const g=svg('g',{transform:'translate('+number(p.x)+' '+number(p.y)+')',tabindex:0,role:'img','aria-label':series.name+' · '+data.labels[p.index]+' — '+format(p.value),class:'planex-chart__node','data-series':ci,'data-node':p.index});
    g.append(svg('circle',{r:si===0?4:3.5}));chart.append(g);groups.push({node:g,index:p.index});
   });
  });
 });
 chart.append(guide);
 data.labels.forEach((label,i)=>{const e=svg('text',{x:x(i),y:H-7.2,'text-anchor':'middle',class:'axis-label'});e.textContent=label;chart.append(e);});
 const popup=el('aside',undefined,'planex-chart__popup');popup.id=id+'-tooltip';popup.setAttribute('role','tooltip');groups.forEach(g=>g.node.setAttribute('aria-describedby',popup.id));popup.setAttribute('aria-hidden','true');
 const legend=el('div',undefined,'planex-chart__legend');
 data.series.forEach((s,i)=>{const span=el('span');span.dataset.series=(i+(data.colorOffset||0))%3;span.append(el('i'),el('span',s.name));legend.append(span);});
 body.append(chart,popup);root.append(body,legend);
 let focused=null,hovered=null;
 function show(index) {
  const visible=index!==null;guide.classList.toggle('is-visible',visible);popup.classList.toggle('is-visible',visible);popup.setAttribute('aria-hidden',String(!visible));
  groups.forEach(g=>g.node.classList.toggle('is-active',g.index===index));
  if(!visible)return;guide.setAttribute('x1',x(index));guide.setAttribute('x2',x(index));popup.replaceChildren(el('strong',data.labels[index],'planex-chart__popup-title'));
  data.series.forEach((s,i)=>{
   const row=el('div',undefined,'planex-chart__popup-item');row.dataset.series=(i+(data.colorOffset||0))%3;
   const name=el('strong',s.name,'planex-chart__popup-name');name.setAttribute('aria-label',s.name);
   const values=el('div',undefined,'planex-chart__popup-values'),value=s.values[index],previous=index?s.values[index-1]:null;
   const delta=value!==null&&previous!==null?value-previous:null;
   const percent=delta!==null&&previous!==0?100*delta/previous:null;
   for(const [caption,amount,suffix] of [['Значение',value,''],['Изм.',delta,''],['Изм., %',percent,'%']]){
    const item=el('span');item.append(el('b',amount===null?'—':format(amount)+suffix),el('small',caption));values.append(item);
   }
   row.append(name,values);popup.append(row);
  });
  const svgRect=chart.getBoundingClientRect(),bodyRect=body.getBoundingClientRect();
  const lineX=svgRect.left-bodyRect.left+x(index)/W*svgRect.width,popupWidth=popup.getBoundingClientRect().width;
  let left=lineX-popupWidth-12;
  if(left<4)left=Math.min(lineX+12,bodyRect.width-popupWidth-4);
  popup.style.left=Math.max(4,left)+'px';
 }
 groups.forEach(g=>{
  g.node.addEventListener('focus',()=>{focused=g.index;show(focused);});
  g.node.addEventListener('blur',()=>{focused=null;show(hovered);});
  g.node.addEventListener('keydown',e=>{
   if(e.key==='Escape'){hovered=null;focused=null;show(null);g.node.blur();return;}
   if(e.key==='ArrowLeft'||e.key==='ArrowRight'){e.preventDefault();const i=groups.indexOf(g)+(e.key==='ArrowRight'?1:-1);groups[Math.max(0,Math.min(groups.length-1,i))].node.focus();}
  });
 });
 chart.addEventListener('pointermove',e=>{const r=chart.getBoundingClientRect(),sx=(e.clientX-r.left)/r.width*W,sy=(e.clientY-r.top)/r.height*H;hovered=sx>=L&&sx<=W-R&&sy>=T&&sy<=T+PH?data.labels.reduce((best,_,i)=>Math.abs(x(i)-sx)<Math.abs(x(best)-sx)?i:best,0):null;show(focused??hovered);});
 chart.addEventListener('pointerleave',()=>{hovered=null;show(focused);});
 return {destroy:()=>root.replaceChildren()};
}
window.PlanexCharts=Object.freeze({mount,path,validate});
}());
