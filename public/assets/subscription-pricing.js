(()=>{
    const form=document.querySelector('form[data-form-action="subscription_request"]');
    if(!form)return;
    const catalog=JSON.parse(document.getElementById('subscription-price-catalog').textContent);
    const output=document.getElementById('subscription-estimate');
    const update=()=>{
        const selected=[...form.querySelectorAll('input[name="modules[]"]:checked')].map(input=>input.value);
        if(!selected.length){output.textContent='Select modules to see your monthly estimate.';return;}
        const required=new Set(['people',...selected]);
        for(const key of required)for(const dependency of catalog.dependencies[key]||[])required.add(dependency);
        const workers=Number(form.elements.worker_count.value),preferred=form.elements.service_level.value;
        const priority=key=>key===preferred?0:key==='custom'?2:1;
        const candidates=Object.entries(catalog.packages).map(([key,p])=>({key,label:p.label,total:p.base_cents+[...required].filter(m=>!p.modules.includes(m)).reduce((sum,m)=>sum+catalog.module_cents[m],0)+Math.max(0,workers-p.included_workers)*catalog.extra_worker_cents}));
        candidates.sort((a,b)=>a.total-b.total||priority(a.key)-priority(b.key));
        const best=candidates[0];
        output.textContent=`Estimated monthly subtotal: USD ${(best.total/100).toFixed(2)} — ${best.label}, including required add-ons and worker coverage. Tax requires confirmation.`;
    };
    const packageSelect=form.elements.service_level;
    packageSelect.addEventListener('change',()=>{
        const key=packageSelect.value;
        // Custom keeps the current selection so the customer can tailor a package.
        if(key!=='custom' && catalog.packages[key]){
            const included=new Set(catalog.packages[key].modules);
            form.querySelectorAll('input[name="modules[]"]').forEach(input=>{input.checked=included.has(input.value);});
        }
    });
    form.addEventListener('change',update);update();
})();
