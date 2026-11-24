'use strict';
((root,factory)=>{
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else root.RentalNotices=api;
})(typeof window==='object'?window:globalThis,()=>{
  const tones={red:'danger',yellow:'warning',green:'success',blue:'info'};
  const fingerprint=event=>JSON.stringify([String(event.id),tones[event.severity]||'',String(event.where),String(event.dedupe_text??event.text),event.version??null]);
  function create({storage,component}){
    let catalog={};
    component?.setTemplateResolver(({eventKey,variables})=>{
      const template=catalog[eventKey];
      if(!template)return null;
      const fill=text=>String(text).replace(/\{([^{}]+)\}/g,(_,key)=>String(variables[key]??''));
      return {title:fill(template.title),message:fill(template.body_text)};
    });
    return Object.freeze({update(dto){
      if(!dto||dto.schema!==1||!dto.project||!dto.surface||!dto.identity||!Array.isArray(dto.events))return [];
      catalog=dto.catalog||{};
      // An epoch mismatch never silently establishes a new mute-all baseline.
      if(dto.epoch!==dto.bootstrap_epoch)return [];
      const key=['rental-top',dto.project,dto.surface,dto.identity,dto.epoch].join(':');
      let seen=[];
      try{const value=JSON.parse(storage.getItem(key)||'[]');if(Array.isArray(value))seen=value.filter(x=>typeof x==='string').slice(-2000);}catch{}
      const known=new Set(seen),shown=[];
      const show=event=>{
        if(!event||!tones[event.severity]||typeof event.text!=='string'||!event.text.trim())return;
        const identity=fingerprint(event);
        if(known.has(identity))return;
        known.add(identity);
        // Only the fixed rollout preimage is baseline. A new handoff is visible on first login.
        if(!Number.isSafeInteger(event.event_id)||event.event_id<=dto.bootstrap_event_id)return;
        const text={tone:tones[event.severity],title:event.title,message:event.text};
        if(typeof event.dedupe_text!=='string'){
          text.eventKey=event.event_key;
          text.variables=event.variables;
        }
        component?.show(text);
        shown.push(event);
      };
      for(const event of dto.events)show(event);
      show(dto.sender);
      try{storage.setItem(key,JSON.stringify([...known].slice(-2000)));}catch{}
      return shown;
    }});
  }
  return Object.freeze({create,fingerprint});
});
