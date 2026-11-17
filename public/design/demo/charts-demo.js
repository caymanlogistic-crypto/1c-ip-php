'use strict';
const base={title:'Динамика показателей A и B',labels:['Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'],series:[{name:'Показатель A',values:[24,82,32,91,38,86,28,96]},{name:'Показатель B',values:[12,46,18,54,22,61,17,66]}]};
function render(){
 const mode=document.getElementById('chart-state').value,data=structuredClone(base);
 if(mode==='missing'){data.series[0].values[3]=null;data.series[1].values[5]=null;}
 if(mode==='single'){data.labels=['Ноя'];data.series.forEach(s=>s.values=[s.values[6]]);}
 if(mode==='zero')data.series.forEach(s=>s.values=s.values.map(()=>0));
 if(mode==='empty'){data.labels=[];data.series.forEach(s=>s.values=[]);}
 window.PlanexCharts.mount(document.getElementById('chart'),data);
 const rows=document.getElementById('chart-values');rows.replaceChildren();
 data.labels.forEach((label,i)=>{const tr=document.createElement('tr');[label,...data.series.map(s=>s.values[i]===null?'Нет данных':String(s.values[i]))].forEach(value=>{const td=document.createElement('td');td.textContent=value;tr.append(td);});rows.append(tr);});
 if(!data.labels.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=3;td.textContent='Нет наблюдений';tr.append(td);rows.append(tr);}
 document.getElementById('state-note').textContent=mode==='missing'?'Пропуски разрывают линию. Они не заменяются нулём.':mode==='zero'?'Ноль — известное значение, точки остаются на оси.':mode==='single'?'Одна точка показана в центре; соединяющей линии нет.':mode==='empty'?'Данные отсутствуют. Нулевой график не выдумывается.':'Числа в таблице совпадают с точками графика.';
}
document.getElementById('replay').addEventListener('click',render);
document.getElementById('chart-state').addEventListener('change',render);
render();
