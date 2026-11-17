(()=>{'use strict';
const root=document.getElementById('edb-routes'), hidden=document.getElementById('edb-json'), status=document.getElementById('edb-status');
let routes=[], forms=[], templates=[];
try{routes=JSON.parse(hidden.value)||[]}catch(e){routes=[]}
const el=(tag,attrs={},text='')=>{const n=document.createElement(tag);Object.entries(attrs).forEach(([k,v])=>{if(k==='class')n.className=v;else n.setAttribute(k,v)});if(text)n.textContent=text;return n};
const request=async(action,id)=>{const body=new URLSearchParams({action,nonce:EDB.nonce,...(id?{id}: {})});const res=await fetch(EDB.ajax,{method:'POST',credentials:'same-origin',body});const json=await res.json();if(!json.success)throw new Error(typeof json.data==='string'?json.data:'API error');return json.data};
const sync=()=>{hidden.value=JSON.stringify(routes)};
let hydrating=false;
const hydrate=async()=>{if(hydrating)return;hydrating=true;try{status.textContent='Gespeicherte Formulare und Vorlagen werden geladen …';const [a,b]=await Promise.all([request('edb_catalog'),request('edb_template')]);forms=a.forms||[];templates=b.templates||[];render();for(let i=0;i<routes.length;i++){if(!routes[i].template_id)continue;try{const d=await request('edb_template',routes[i].template_id);routes[i]._fields=d.fields||[];routes[i]._roles=d.roles||[];}catch(e){status.textContent='Vorlage '+routes[i].template_id+': '+e.message;}}render();if(!status.textContent.startsWith('Vorlage '))status.textContent='Gespeicherte Zuordnungen geladen.';}catch(e){status.textContent='Automatisches Laden fehlgeschlagen: '+e.message+' – gespeicherte Zuordnungen bleiben erhalten.';render();}finally{hydrating=false}};
const select=(options,value,change)=>{const s=el('select');options.forEach(([v,t])=>s.append(el('option',{value:v},t)));s.value=String(value??'');s.addEventListener('change',()=>change(s.value));return s};
const input=(value,change,placeholder='')=>{const i=el('input',{type:'text',value:value??'',placeholder});i.addEventListener('input',()=>change(i.value));return i};
const row=(container,label,control)=>{const d=el('div',{style:'display:flex;align-items:center;gap:12px;margin:8px 0'});const l=el('label',{style:'min-width:180px'},label);d.append(l,control);container.append(d)};
const refresh=async(i)=>{const r=routes[i];if(!r||!r.template_id)return;try{status.textContent='Vorlage wird geladen …';const data=await request('edb_template',r.template_id);r._fields=data.fields;r._roles=data.roles;status.textContent='';render()}catch(e){status.textContent=e.message}};
function render(){root.replaceChildren();routes.forEach((r,i)=>{
 const box=el('div',{style:'border:1px solid #ccd0d4;background:white;padding:16px;margin:12px 0;max-width:900px'});const head=el('h3',{},`Zuordnung ${i+1}`);box.append(head);
 const provider=r.provider||'elementor';row(box,'Formularsystem',select([['elementor','Elementor Pro'],['cf7','Contact Form 7'],['gravity','Gravity Forms']],provider,v=>{r.provider=v;r.form_name='';r.fields={};sync();render()}));const providerForms=forms.filter(f=>(f.provider||'elementor')===provider);const formOpts=[['','– Formular auswählen –'],...providerForms.map(f=>[f.name,`${f.title||f.name} (${f.name})`])];if(r.form_name&&!formOpts.some(x=>x[0]===r.form_name))formOpts.push([r.form_name,r.form_name+' (gespeichert)']);
 row(box,'Formular',select(formOpts,r.form_name,v=>{r.form_name=v;sync();render()}));
 const tempOpts=[['','– DocuSeal-Vorlage auswählen –'],...templates.map(t=>[t.id,`${t.name} (#${t.id})`])];if(r.template_id&&!tempOpts.some(x=>String(x[0])===String(r.template_id)))tempOpts.push([r.template_id,`#${r.template_id} (gespeichert)`]);
 row(box,'DocuSeal-Dokument',select(tempOpts,r.template_id,v=>{r.template_id=Number(v)||0;delete r._fields;delete r._roles;sync();refresh(i)}));
 const load=el('button',{type:'button',class:'button'},'Dokumentfelder laden');load.onclick=()=>refresh(i);box.append(load);
 const roles=r._roles||[];row(box,'Unterzeichnerrolle',roles.length?select(roles.map(x=>[x,x]),r.role||roles[0],v=>{r.role=v;sync()}):input(r.role||'',v=>{r.role=v;sync()},'z. B. Erste Partei'));
 const ef=((providerForms.find(f=>f.name===r.form_name)||{}).fields||[]).filter(f=>!['step','html','recaptcha','recaptcha_v3','honeypot'].includes(String(f.type||'').toLowerCase()));const meta=[['__edb_meta_ip','Meta: IP-Adresse (Server)'],['__edb_meta_timestamp','Meta: Unix-Timestamp (Server)'],['__edb_meta_browser','Meta: Browser / User-Agent (Server)'],['__edb_contract_id','Automatisch: Vertrags-ID (SH-JJJJ-NNNNNN)']];const opts=[['','– Feld auswählen –'],...ef.map(f=>[f.id,`${f.label} (${f.id})`]),...meta];
 function fieldSelect(label,key){const current=r[key]||'';const o=opts.slice();if(current&&!o.some(x=>x[0]===current))o.push([current,current+' (gespeichert)']);row(box,label,select(o,current,v=>{r[key]=v;sync()}))}
 fieldSelect('E-Mail-Feld','email_field');fieldSelect('Name des Unterzeichners','name_field');
 const check=el('input',{type:'checkbox'});check.checked=r.send_email!==false;check.onchange=()=>{r.send_email=check.checked;sync()};row(box,'DocuSeal-E-Mail senden',check);
 const fields=(r._fields||Object.keys(r.fields||{}).map(name=>({name}))).filter(f=>f.name);
 r.rules=Array.isArray(r.rules)?r.rules:[];
 const operands=[['','– Feld auswählen –'],...ef.map(f=>[f.id,`${f.label} (Elementor: ${f.id})`]),...meta,...fields.map(f=>[f.name,`${f.name} (berechnetes DocuSeal-Feld)`])];
 const modes=[['direct','Formular / Metadaten'],['condition','Wenn → Dann'],['pipeline','Mehrstufige Berechnung']];
 const operations=[['add','Addition (+)'],['subtract','Subtraktion (−)'],['multiply','Multiplikation (×)'],['divide','Division (÷)'],['percent','Prozentwert (%)'],['increase','Prozentual erhöhen'],['decrease','Prozentual reduzieren'],['round','Runden'],['ceil','Aufrunden'],['floor','Abrunden'],['min','Minimum'],['max','Maximum'],['abs','Absolutwert'],['power','Potenz'],['mod','Rest (Modulo)']];
 const makeControl=(container,label,control)=>{const wrap=el('label',{style:'display:inline-flex;align-items:center;gap:6px;margin:5px 10px 5px 0'});wrap.append(el('span',{},label),control);container.append(wrap)};
 r.pipelines=r.pipelines||{};
 if(fields.length){box.append(el('h4',{},'Feldzuordnung und Berechnung'));
 box.append(el('p',{},'Jedes DocuSeal-Feld kann direkt, bedingt oder über eine mehrstufige Berechnung befüllt werden. Zwischenergebnisse sind wiederverwendbar.'));
 fields.forEach(f=>{
  const fieldName=f.name;const rowBox=el('div',{style:'border-top:1px solid #ddd;padding:12px 0'});
  rowBox.append(el('strong',{},`${fieldName}${f.required?' *':''}`));rowBox.append(el('br'));
  const matching=r.rules.filter(x=>x.target===fieldName);
  const pipeline=r.pipelines[fieldName];
  const mode=pipeline?'pipeline':matching.length?'condition':'direct';
  const modeSelect=select(modes,mode,v=>{
   r.rules=r.rules.filter(x=>x.target!==fieldName);delete r.pipelines[fieldName];r.fields=r.fields||{};
   if(v==='condition'){delete r.fields[fieldName];r.rules.push({type:'condition',source:'',equals:'',value:'',target:fieldName})}
   if(v==='pipeline'){delete r.fields[fieldName];r.pipelines[fieldName]={steps:[{op:'multiply',a:{type:'field',value:''},b:{type:'constant',value:'1'}}]}}
   sync();render();
  });makeControl(rowBox,'Quellfunktion',modeSelect);
  if(mode==='direct'){
   const current=(r.fields||{})[fieldName]||'';const o=opts.slice();if(current&&!o.some(x=>x[0]===current))o.push([current,current+' (gespeichert)']);
   makeControl(rowBox,'Formularfeld',select(o,current,v=>{r.fields=r.fields||{};if(v)r.fields[fieldName]=v;else delete r.fields[fieldName];sync()}));
  }else if(mode==='condition'){
   matching.forEach(rule=>{
    const line=el('div',{style:'padding:8px;background:#f6f7f7;margin:5px 0'});
    makeControl(line,'Wenn',select(operands,rule.source||'',v=>{rule.source=v;sync()}));
    makeControl(line,'gleich',input(rule.equals||'',v=>{rule.equals=v;sync()},'Wert'));
    makeControl(line,'dann',input(rule.value||'',v=>{rule.value=v;sync()},'Ergebnis'));
    const remove=el('button',{type:'button',class:'button button-link-delete'},'Entfernen');remove.onclick=()=>{r.rules.splice(r.rules.indexOf(rule),1);if(!r.rules.some(x=>x.target===fieldName))r.rules.push({type:'condition',source:'',equals:'',value:'',target:fieldName});sync();render()};line.append(remove);rowBox.append(line);
   });
   const add=el('button',{type:'button',class:'button button-small'},'+ Bedingung hinzufügen');add.onclick=()=>{r.rules.push({type:'condition',source:'',equals:'',value:'',target:fieldName});sync();render()};rowBox.append(add);
  }else{
   const steps=r.pipelines[fieldName].steps;
   const operand=(value,change,allowPrevious)=>{
    const wrap=el('span',{style:'display:inline-flex;gap:5px;align-items:center;flex-wrap:wrap'});
    const spec=value||{type:'constant',value:''};
    const types=[['constant','Fester Wert'],['field','Formular / Meta'],['result','Berechnetes DocuSeal-Feld']];if(allowPrevious)types.push(['previous','Vorheriger Schritt']);
    const t=select(types,spec.type||'constant',v=>{change({type:v,value:''});render()});wrap.append(t);
    if(spec.type==='field'||spec.type==='result'){
     const list=spec.type==='field'?opts:[['','– Ergebnis auswählen –'],...fields.filter(x=>x.name!==fieldName).map(x=>[x.name,x.name])];
     wrap.append(select(list,spec.value||'',v=>change({type:spec.type,value:v})));
    }else if(spec.type==='constant')wrap.append(input(spec.value||'',v=>change({type:'constant',value:v}),'Zahl'));
    return wrap;
   };
   steps.forEach((step,idx)=>{
    const line=el('div',{style:'border:1px solid #dcdcde;background:#f6f7f7;padding:10px;margin:8px 0'});
    line.append(el('strong',{},`Rechenschritt ${idx+1}`));
    const move=(direction)=>{const next=idx+direction;if(next<0||next>=steps.length)return;[steps[idx],steps[next]]=[steps[next],steps[idx]];sync();render()};
    const up=el('button',{type:'button',class:'button button-small'},'↑');up.disabled=idx===0;up.onclick=()=>move(-1);
    const down=el('button',{type:'button',class:'button button-small'},'↓');down.disabled=idx===steps.length-1;down.onclick=()=>move(1);
    const del=el('button',{type:'button',class:'button button-small'},'Entfernen');del.disabled=steps.length===1;del.onclick=()=>{steps.splice(idx,1);sync();render()};
    line.append(' ',up,' ',down,' ',del,el('br'));
    makeControl(line,'Operation',select(operations,step.op||'add',v=>{step.op=v;sync();render()}));line.append(el('br'));
    makeControl(line,'Wert A',operand(step.a,v=>{step.a=v;sync()},idx>0));line.append(el('br'));
    if(!['ceil','floor','abs'].includes(step.op))makeControl(line,step.op==='round'?'Nachkommastellen':'Wert B',operand(step.b,v=>{step.b=v;sync()},idx>0));
    rowBox.append(line);
   });
   const add=el('button',{type:'button',class:'button'},'+ Rechenschritt hinzufügen');add.onclick=()=>{if(steps.length>=50){alert('Maximal 50 Schritte pro Feld.');return}steps.push({op:'add',a:{type:'previous',value:''},b:{type:'constant',value:'0'}});sync();render()};rowBox.append(add);
   rowBox.append(el('p',{class:'description'},'Ergebnis jedes Schritts kann im nächsten Schritt verwendet werden. Andere Zielfelder können auf das Endergebnis zugreifen.'));
  }
  box.append(rowBox);
 });}
 const del=el('button',{type:'button',class:'button button-link-delete'},'Zuordnung entfernen');del.onclick=()=>{routes.splice(i,1);sync();render()};box.append(del);root.append(box)
 });sync()}
 document.getElementById('edb-add').onclick=()=>{routes.push({provider:'elementor',form_name:'',template_id:0,role:'',email_field:'',name_field:'',send_email:true,fields:{}});render()};
 document.getElementById('edb-load').onclick=hydrate;
 document.querySelector('form input[name="action"][value="edb_save"]').form.addEventListener('submit',()=>{routes.forEach(r=>{delete r._fields;delete r._roles});sync()});
 render();
 hydrate();
})();
