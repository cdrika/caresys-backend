(()=>{
 'use strict';
 const form=document.querySelector('form[data-form-action="agency"]');
 const source=document.getElementById('agency-phone-hints');if(!form||!source)return;
 const hints=JSON.parse(source.textContent),country=form.querySelector('[name="country"]');
 const fields=['business_phone','contact_number','mobile_number'];
 fields.forEach(name=>{const input=form.querySelector(`[name="${name}"]`);input.autocomplete=name==='mobile_number'?'mobile tel':'work tel';const hint=document.createElement('p');hint.className='field-hint';hint.id=input.id+'-phone-format';input.setAttribute('aria-describedby',hint.id);input.after(hint);});
 function update(){const info=hints[country.value];fields.forEach(name=>{const input=form.querySelector(`[name="${name}"]`),example=info?.[name==='mobile_number'?'mobile':'phone']||'';input.placeholder=example||'+ country code and number';document.getElementById(input.id+'-phone-format').textContent=info?`Country code ${info.code}. Example: ${example}. Enter a national number or include + and a country code for an international number.`:'Include + and the international country code. A valid phone number is required.';});}
 country.addEventListener('change',update);update();
})();
