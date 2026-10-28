(()=>{
 'use strict';
 const form=document.querySelector('form[data-form-action="agency"]');
 const source=document.getElementById('agency-phone-hints');if(!form||!source)return;
 const hints=JSON.parse(source.textContent),country=form.querySelector('[name="country"]');
 const fields=['business_phone','contact_number','mobile_number'];
 const businessChoice=form.querySelector('[name="include_business_phone"]');
 const sharing={mobile:form.querySelector('[name="business_is_mobile"]'),contact:form.querySelector('[name="business_is_contact"]')};
 function syncPhones(){
  const business=businessChoice.checked,businessPanel=form.querySelector('[data-phone-panel="business"]');
  businessPanel.hidden=!business;businessPanel.disabled=!business;businessChoice.setAttribute('aria-expanded',String(business));
  const used=[];
  for(const kind of ['mobile','contact']){
   const same=business&&sharing[kind].checked,choice=form.querySelector(`[name="include_${kind}_number"]`),separate=form.querySelector(`[data-phone-separate="${kind}"]`),panel=form.querySelector(`[data-phone-panel="${kind}"]`);
   separate.hidden=same;choice.disabled=same;panel.hidden=same||!choice.checked;panel.disabled=same||!choice.checked;
   choice.setAttribute('aria-expanded',String(!panel.hidden));form.querySelector(`[data-phone-different="${kind}"]`).textContent=business?'different ':'';
   if(same)used.push(kind);
  }
  form.querySelector('[data-phone-summary]').textContent=used.length?'Your business number will also be used as your '+used.join(' and ')+' number.':'';
 }
 form.querySelectorAll('[data-phone-choice]').forEach(input=>input.addEventListener('change',syncPhones));syncPhones();
 fields.forEach(name=>{const input=form.querySelector(`[name="${name}"]`);input.autocomplete=name==='mobile_number'?'mobile tel':'work tel';const hint=document.createElement('p');hint.className='field-hint';hint.id=input.id+'-phone-format';input.setAttribute('aria-describedby',hint.id);input.after(hint);});
 function update(){const info=hints[country.value];fields.forEach(name=>{const input=form.querySelector(`[name="${name}"]`),example=info?.[name==='mobile_number'?'mobile':'phone']||'';input.placeholder=example||'+ country code and number';document.getElementById(input.id+'-phone-format').textContent=info?`Country code ${info.code}. Example: ${example}. Enter a national number or include + and a country code for an international number.`:'Include + and the international country code. A valid phone number is required.';});}

 const opt=form.querySelector('[data-address-opt-in]'),panel=form.querySelector('[data-agency-address]'),status=form.querySelector('[data-address-status]');
 let ready=true,sequence=0,addressCountry=JSON.parse(document.getElementById('agency-address-schema').textContent).country;
 function visibility(){panel.hidden=!opt.checked;panel.disabled=!opt.checked||!ready;opt.setAttribute('aria-expanded',String(opt.checked));if(!opt.checked)status.textContent='';}
 function addressSchema(schema){
  Object.entries(schema.fields).forEach(([name,field])=>{
   const root=panel.querySelector(`[data-address-field="${name}"]`);root.hidden=!field.used;
   root.querySelector('[data-address-label]').textContent=field.label;root.querySelector('[data-address-required]').hidden=!field.required;
   let input=root.querySelector('[name]');
   if(name==='address_state'){
    const previous=schema.country===addressCountry?input.value:'',select=Object.keys(schema.states).length>0,replacement=document.createElement(select?'select':'input');
    replacement.id=input.id;replacement.name=name;
    if(select){replacement.append(new Option('Choose an option…',''));Object.entries(schema.states).forEach(([code,label])=>replacement.append(new Option(label,code)));replacement.value=Object.hasOwn(schema.states,previous)?previous:'';}
    else{replacement.value=previous;replacement.maxLength=120;}
    input.replaceWith(replacement);input=replacement;
   }
   input.required=field.required;input.disabled=!field.used;
   input.autocomplete=({address_line1:'address-line1',address_line2:'address-line2',address_city:'address-level2',address_state:'address-level1',address_postal_code:'postal-code'})[name];
  });
  addressCountry=schema.country;ready=true;visibility();status.textContent=opt.checked?'Address fields updated for the selected country.':'';
 }
 opt.addEventListener('change',visibility);
 country.addEventListener('change',async()=>{
  const request=++sequence;ready=false;visibility();if(opt.checked)status.textContent='Loading address format…';
  try{const response=await fetch('/reference/address-format?country='+encodeURIComponent(country.value),{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)throw Error();const schema=await response.json();if(request!==sequence)return;addressSchema(schema);}
  catch{if(request!==sequence)return;status.textContent=opt.checked?'Could not load address fields. Choose the country again or turn off the optional address.':'';}
 });
 form.addEventListener('submit',event=>{if(opt.checked&&!ready){event.preventDefault();status.textContent='Wait for the address format to load, or turn off the optional address.';}});
 addressSchema(JSON.parse(document.getElementById('agency-address-schema').textContent));
 country.addEventListener('change',update);update();
})();
