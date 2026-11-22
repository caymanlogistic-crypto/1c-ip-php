/* Temporary presentation helper. Only fills visible editor fields; never submits or sends. */
(() => {
  'use strict';
  document.addEventListener('click',event=>{
    const button=event.target.closest('[data-party-demo-fill]');
    if(!button)return;
    const form=button.form;
    if(!form?.querySelector('[data-party-editor]'))return;
    const record=form.querySelector('input[name="id"]');
    if(record && record.value!=='0')return;
    const variant=button.dataset.partyDemoFill;
    if(!['company','entrepreneur'].includes(variant))return;
    const random=crypto.getRandomValues(new Uint32Array(2));
    const suffix=random[0].toString(16).toUpperCase().padStart(8,'0');
    const base='00'+String(random[1]%10000000).padStart(7,'0');
    const weights=[2,4,10,3,5,9,4,6,8];
    const inn=base+(weights.reduce((sum,w,i)=>sum+w*Number(base[i]),0)%11%10);
    const values={
      party_type:variant,
      name:'ДЕМО · ООО «Пример аренды '+suffix+'»',inn,
      address:'ДЕМО: г. Примерск, ул. Образцовая, д. 1, офис 10',
      postal_address:'ДЕМО: г. Примерск, ул. Образцовая, д. 1, офис 10',
      contact:'Иванов Иван Иванович (демо)',phone:'+7 (000) 000-00-01',email:'demo-'+suffix.toLowerCase()+'@example.test',
      additional_contact:'Петрова Анна Сергеевна (демо)',additional_phone:'+7 (000) 000-00-02',account_email:'finance-'+suffix.toLowerCase()+'@example.test',
      bank:'ДЕМО-БАНК — вымышленные реквизиты',bik:'000000000',account:'40702810000000000001',correspondent_account:'30101810000000000001',
      edo:'ДЕМО-оператор',edo_id:'DEMO-'+suffix
    };
    if(variant==='entrepreneur'){
      const base='00'+String(random[1]%100000000).padStart(8,'0');
      const digit=(text,weights)=>weights.reduce((sum,w,i)=>sum+w*Number(text[i]),0)%11%10;
      const first=base+digit(base,[7,2,4,10,3,5,9,4,6,8]);
      Object.assign(values,{
        name:'ДЕМО · ИП Соколов Алексей Андреевич '+suffix,
        inn:first+digit(first,[3,7,2,4,10,3,5,9,4,6,8]),
        address:'ДЕМО: г. Примерск, ул. Учебная, д. 2, офис 20',
        postal_address:'ДЕМО: г. Примерск, ул. Учебная, д. 2, офис 20',
        contact:'Соколов Алексей Андреевич (демо)',phone:'+7 (000) 000-00-11',email:'ip-'+suffix.toLowerCase()+'@example.test',
        additional_contact:'Соколова Мария Олеговна (демо)',additional_phone:'+7 (000) 000-00-12',account_email:'ip-finance-'+suffix.toLowerCase()+'@example.test',
        bank:'ДЕМО-БАНК ИП — вымышленные реквизиты',account:'40802810000000000002',correspondent_account:'30101810000000000002',
        edo:'ДЕМО-оператор ИП',edo_id:'DEMO-IP-'+suffix
      });
    }
    for(const [name,value] of Object.entries(values)){
      const input=form.querySelector('[name="'+name+'"]');
      if(!input)continue;
      if(name==='edo' && input.matches('select') && ![...input.options].some(option=>option.value===value)){
        input.add(new Option(value,value));
        window.RentalPickers?.refresh(form);
      }
      input.value=value;
      input.dispatchEvent(new Event('input',{bubbles:true}));
      input.dispatchEvent(new Event('change',{bubbles:true}));
    }
    for(const [name,checked] of Object.entries({postal_same_as_legal:true,document_recipient:true,additional_document_recipient:false})){
      const input=form.querySelector('input[type="checkbox"][name="'+name+'"]');
      if(input){input.checked=checked;input.dispatchEvent(new Event('change',{bubbles:true}));}
    }
    for(const panel of form.querySelectorAll('[data-form-disclosure]'))panel.open=true;
  });
})();
