(() => {
  'use strict';
  const one=(root,s)=>root.querySelector(s);
  const all=(root,s)=>[...root.querySelectorAll(s)];
  const labelTarget=(source,target)=>{const label=document.querySelector(`label[for="${CSS.escape(source.id)}"]`);if(!target.id)target.id=source.id+'-control';if(label)label.htmlFor=target.id;};
  const hideSource=(source)=>{const required=source.required;source.required=false;source.type='hidden';return required;};
  const chip=(name,remove)=>{const span=document.createElement('span');span.className='selected-tag';const text=document.createElement('span');text.textContent=name;const button=document.createElement('button');button.type='button';button.textContent='×';button.setAttribute('aria-label','Remove '+name);button.addEventListener('click',remove);span.append(text,button);return span;};

  all(document,'[data-choice]').forEach(root=>{
    const source=one(root,'[data-choice-value]'),select=one(root,'[data-choice-select]'),other=one(root,'[data-choice-other]');const required=hideSource(source);select.hidden=false;select.required=required;labelTarget(source,select);
    const current=source.value;const known=all(select,'option').find(o=>o.value.toLocaleLowerCase()===current.toLocaleLowerCase()&&o.value!=='__other__');
    select.value=current?(known?known.value:'__other__'):'';if(current&&!known)other.value=current;
    function sync(){const custom=select.value==='__other__';other.hidden=!custom;other.required=custom&&required;source.value=custom?other.value.trim():select.value;}
    select.addEventListener('change',()=>{sync();if(select.value==='__other__')other.focus();});other.addEventListener('input',sync);root.closest('form').addEventListener('submit',sync);sync();
  });

  all(document,'[data-tag-picker]').forEach(root=>{
    const source=one(root,'[data-tag-value]'),ui=one(root,'[data-tag-ui]'),search=one(root,'[data-tag-search]'),optionsBox=one(root,'[data-tag-options]'),selectedBox=one(root,'[data-selected-tags]'),custom=one(root,'[data-tag-custom]'),message=one(root,'[data-tag-message]');
    const options=JSON.parse(root.dataset.options),max=Number(source.maxLength)||500;let chosen=source.value.split(',').map(x=>x.trim()).filter(Boolean);
    chosen=[...new Map(chosen.map(value=>{const canonical=options.find(o=>o.toLocaleLowerCase()===value.toLocaleLowerCase())||value;return [canonical.toLocaleLowerCase(),canonical];})).values()];
    hideSource(source);ui.hidden=false;labelTarget(source,search);const boxes=[];
    const menu=document.createElement('details');menu.className='tag-menu';menu.dataset.tagMenu='';const summary=document.createElement('summary');summary.textContent=({skills:'Choose skills',qualifications:'Choose qualifications',languages:'Choose languages',care_settings:'Choose care settings',availability:'Choose available days and times'})[source.name]||'Choose options';const panel=document.createElement('div');panel.className='tag-menu-panel';
    [...ui.childNodes].filter(node=>node!==selectedBox&&node!==message).forEach(node=>panel.append(node));const done=document.createElement('button');done.type='button';done.className='secondary tag-done';done.textContent='Done choosing';done.addEventListener('click',()=>{menu.open=false;summary.focus();});panel.append(done);menu.append(summary,panel);ui.insertBefore(menu,message);
    options.forEach(option=>{const label=document.createElement('label');label.className='tag-option';const input=document.createElement('input');input.type='checkbox';input.checked=chosen.includes(option);input.setAttribute('aria-label',option);const span=document.createElement('span');span.textContent=option;label.append(input,span);optionsBox.append(label);boxes.push({option,input,label});input.addEventListener('change',()=>{if(input.checked){if(!add(option))input.checked=false;}else remove(option);});});
    function render(){source.value=chosen.join(', ');selectedBox.replaceChildren(...chosen.map(value=>chip(value,()=>remove(value))));boxes.forEach(x=>x.input.checked=chosen.includes(x.option));}
    function info(text,error=false){message.textContent=text;message.classList.toggle('field-invalid',error);}
    function add(raw){const values=raw.split(',').map(x=>x.trim()).filter(Boolean).map(v=>options.find(o=>o.toLocaleLowerCase()===v.toLocaleLowerCase())||v);const next=[...new Map([...chosen,...values].map(v=>[v.toLocaleLowerCase(),v])).values()];if(next.join(', ').length>max){info('Too many selections. Remove an item or shorten your custom text.',true);return false;}chosen=next;render();info('Selection added. Save the form to keep your changes.');return true;}
    function remove(value){chosen=chosen.filter(v=>v!==value);render();info(chosen.length?'Selection updated. Save the form to keep your changes.':'Choose from the list or add your own.');}
    function customAdd(){if(!custom.value.trim()){custom.focus();return;}if(add(custom.value)){custom.value='';custom.focus();}}
    one(root,'[data-tag-add]').addEventListener('click',customAdd);custom.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();customAdd();}});
    summary.addEventListener('click',()=>{if(!menu.open)search.value='';boxes.forEach(x=>x.label.hidden=false);});search.addEventListener('input',()=>{const q=search.value.toLocaleLowerCase();boxes.forEach(x=>x.label.hidden=!x.option.toLocaleLowerCase().includes(q));});search.addEventListener('keydown',event=>{if(event.key==='Enter')event.preventDefault();});
    root.closest('form').addEventListener('submit',event=>{if(custom.value.trim()&&!add(custom.value)){event.preventDefault();custom.focus();}else custom.value='';render();});render();
  });

  all(document,'[data-duration]').forEach(root=>{
    const source=one(root,'[data-duration-value]'),amount=one(root,'[data-duration-amount]'),unit=one(root,'[data-duration-unit]');const required=hideSource(source);one(root,'[data-duration-ui]').hidden=false;amount.required=required;labelTarget(source,amount);
    const factor=()=>root.dataset.base==='seconds'?({hours:3600,minutes:60,seconds:1}[unit.value]):({hours:60,minutes:1}[unit.value]);
    function sync(){amount.setCustomValidity('');if(amount.value===''){source.value='';return;}const value=Number(amount.value)*factor();if(!Number.isFinite(value)||Math.abs(value-Math.round(value))>0.00001){amount.setCustomValidity('Choose a duration in whole '+root.dataset.base+'.');return;}source.value=String(Math.round(value));}
    amount.addEventListener('input',sync);unit.addEventListener('change',sync);root.closest('form').addEventListener('submit',event=>{sync();if(!amount.checkValidity()){event.preventDefault();amount.reportValidity();}});sync();
  });

  const referenceCache=new Map();
  async function places(level,params){const query=new URLSearchParams({level,...params});const key=query.toString();if(!referenceCache.has(key))referenceCache.set(key,fetch('/reference/locations?'+key,{credentials:'same-origin',headers:{Accept:'application/json'}}).then(async response=>{if(!response.ok)throw Error('Location choices could not load.');const data=await response.json();if(!Array.isArray(data.items))throw Error('Please sign in again to load location choices.');return data.items;}).catch(error=>{referenceCache.delete(key);throw error;}));return referenceCache.get(key);}
  all(document,'[data-location-picker]').forEach(root=>{
    const source=one(root,'[data-location-value]'),ui=one(root,'[data-location-ui]'),country=one(root,'[data-geo-country]'),state=one(root,'[data-geo-state]'),county=one(root,'[data-geo-county]'),city=one(root,'[data-geo-city]'),selected=one(root,'[data-geo-selected]'),message=one(root,'[data-geo-message]');
    const multiple=root.dataset.multiple==='true',required=hideSource(source);let chosen=JSON.parse(root.dataset.initial||'[]'),generation=0,dirty=false;ui.hidden=false;labelTarget(source,country);
    function fill(select,rows,placeholder){select.replaceChildren(new Option(placeholder,''),...rows.map(row=>{const o=new Option(row.name,row.code);if(row.county)o.dataset.county=row.county;return o;}));select.disabled=rows.length===0;}
    function reset(select,label){fill(select,[],label);}
    function text(select){return select.selectedOptions[0]?.textContent||'';}
    function render(){source.value=chosen.map(x=>x.code).join(', ');selected.replaceChildren(...chosen.map(item=>chip(item.label||item.name,()=>{chosen=chosen.filter(x=>x.code!==item.code);render();})));country.setCustomValidity('');}
    async function refresh(level){const epoch=++generation;dirty=true;message.classList.remove('field-invalid');country.setCustomValidity('');const params={country:country.value,state:state.value,county:county.value};
      if(level==='country'){reset(state,country.value?'Loading states…':'Choose a country first');reset(county,'Choose a state first');reset(city,'Choose a state first');params.state='';params.county='';}
      if(level==='state'){reset(county,state.value?'Loading counties…':'Choose a state first');reset(city,state.value?'Loading cities…':'Choose a state first');params.county='';}
      if(level==='county')reset(city,'Loading cities…');
      if(!country.value)return;
      try{
        if(level==='country'){const rows=await places('states',{country:country.value});if(epoch!==generation)return;fill(state,rows,rows.length?'Entire country / choose a state':'No state or province listed');if(rows.length){message.textContent='Choose a state, or use the entire country.';return;}}
        if(level==='state' && state.value){const rows=await places('counties',{country:country.value,state:state.value});if(epoch!==generation)return;fill(county,rows,rows.length?'All counties / choose a county':'No county or district listed');}
        const rows=await places('cities',{country:country.value,state:state.value,county:county.value});if(epoch!==generation)return;fill(city,rows,county.value?'Entire county / choose a city':state.value?'Entire state / choose a city':'Choose a city if listed');
        message.textContent=rows.length?'Narrow your choice if you wish, then click '+(multiple?'Add work area.':'Use this work area.'):'You can select the broader area. Some small places are not listed.';
      }catch(error){if(epoch===generation){message.textContent=error.message+' Please try the selection again.';message.classList.add('field-invalid');}}
    }
    function addArea(){if(!country.value){country.setCustomValidity('Choose a country for your preferred work area.');country.reportValidity();return false;}country.setCustomValidity('');const code=city.value||county.value||state.value||country.value;const names=[city.value?text(city):'',county.value?text(county):'',state.value?text(state):'',text(country)].filter(Boolean);const entry={code,label:names.join(', '),country:country.value,state:state.value,county:county.value,city:city.value};if(multiple){if(chosen.length>=20&&!chosen.some(x=>x.code===code)){message.textContent='You can add up to 20 work areas.';message.classList.add('field-invalid');return false;}if(!chosen.some(x=>x.code===code))chosen.push(entry);}else chosen=[entry];render();dirty=false;message.textContent='Work area added. Save the form to keep your selection.';return true;}
    country.addEventListener('change',()=>refresh('country'));state.addEventListener('change',()=>refresh('state'));county.addEventListener('change',()=>refresh('county'));city.addEventListener('change',()=>{dirty=true;});one(root,'[data-geo-add]').addEventListener('click',addArea);
    root.closest('form').addEventListener('submit',event=>{if((dirty&&country.value)||(!chosen.length&&required)){if(!addArea()){event.preventDefault();return;}}render();});render();
  });

  const dialog=one(document,'#field-help-dialog');let previousFocus=null;
  if(dialog){document.addEventListener('click',event=>{const trigger=event.target.closest('[data-help-title]');if(trigger){event.preventDefault();previousFocus=trigger;one(dialog,'#field-help-title').textContent=trigger.dataset.helpTitle;one(dialog,'#field-help-text').textContent=trigger.dataset.helpText;one(dialog,'#field-help-example').textContent=trigger.dataset.helpExample;dialog.showModal();one(dialog,'[data-help-close]').focus();}if(event.target.closest('[data-help-close]'))dialog.close();});dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});dialog.addEventListener('close',()=>previousFocus?.focus());}
  // Bespoke login, invitation, disclosure and search forms use the same help pattern.
  const manifestNode=one(document,'#field-help-catalog');const manifest=manifestNode?JSON.parse(manifestNode.textContent):{};
  all(document,'form input:not([type=hidden]),form select,form textarea').forEach(control=>{
    if(control.closest('[data-choice],[data-tag-picker],[data-location-picker],[data-duration]'))return;
    const label=control.id?document.querySelector(`label[for="${CSS.escape(control.id)}"]`):control.closest('label');
    if(!label||control.closest('[data-inline-help]')||label.closest('.field')?.querySelector('.field-help')||label.closest('.consent-field')?.querySelector('.field-help')||label.querySelector('.field-help'))return;
    const key=(control.name||'').replace(/\[\]$/,'');const title=label.textContent.trim();const help=manifest[key]||{text:'Choose or enter the information described by this field for the current record.',example:'Use a clear, factual value relevant to '+title.toLocaleLowerCase()+'.'};
    const button=document.createElement('button');button.type='button';button.className='field-help';button.textContent='?';button.setAttribute('aria-label','Help with '+title);button.setAttribute('aria-haspopup','dialog');button.dataset.helpTitle=title;button.dataset.helpText=help.text;button.dataset.helpExample=help.example;label.append(' ',button);
  });
})();
