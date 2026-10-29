const fs=require('node:fs'),crypto=require('node:crypto');
const {chromium}=require(process.env.CARESYS_NODE_MODULES+'/playwright');
const out='Documentation/Test/Local Billing';fs.mkdirSync(out+'/screenshots',{recursive:true});
const stamp=Date.now(),fixture={username:'qa.reg.'+stamp,email:'qa.reg.'+stamp+'@example.invalid',password:'CareQa!'+crypto.randomBytes(16).toString('hex'),agency_name:'QA Local Billing '+stamp};
const manifest='build/qa/local-billing-session.local.json';fs.writeFileSync(manifest,JSON.stringify(fixture));
const checks=[];let browser;
function check(name,ok){checks.push({name,passed:ok});if(!ok)throw Error(name);}
(async()=>{browser=await chromium.launch({headless:true,channel:'msedge',args:['--no-proxy-server']});try{
const ctx=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1050}}),p=await ctx.newPage();
await p.goto('https://localhost:8444/register',{waitUntil:'domcontentloaded'});await p.locator('[name=username]').fill(fixture.username);await p.locator('[name=email]').fill(fixture.email);await p.locator('[name=password]').fill(fixture.password);await p.locator('[name=password_confirm]').fill(fixture.password);await p.getByRole('button',{name:'Create personal account',exact:true}).click();await p.waitForLoadState('networkidle');
await p.goto('https://localhost:8444/app/agencies');const f=p.locator('form[data-form-action=agency]');await f.locator('[name=name]').fill(fixture.agency_name);await f.locator('[name=country]').selectOption('US');await f.locator('[name=official_email]').fill(fixture.email);await f.getByRole('button',{name:'Register agency',exact:true}).click();await p.waitForLoadState('networkidle');fixture.agency_ref=p.url().match(/agency\/([a-f0-9]{32})\/billing/)?.[1];fs.writeFileSync(manifest,JSON.stringify(fixture));check('Disposable agency registered',!!fixture.agency_ref);
check('Local test controls enabled',await p.getByRole('button',{name:'Simulate card setup',exact:true}).isEnabled());await p.screenshot({path:out+'/screenshots/01-test-mode.png',fullPage:true});
async function action(name){const b=p.getByRole('button',{name,exact:true});await b.locator('xpath=ancestor::form').locator('[name=confirm]').check();await b.click();await p.waitForLoadState('networkidle');}
await action('Simulate card setup');check('Simulated setup requires no card',(await p.locator('main').innerText()).includes('TEST ending 0000'));
await p.locator('input[name="modules[]"][value=scheduling]').check();await p.getByRole('button',{name:'Request subscription quote',exact:true}).click();await p.waitForLoadState('networkidle');check('Trial starts with module invoice',(await p.locator('main').innerText()).includes('Free trial active'));
const link=p.getByRole('link',{name:'Download PDF',exact:true}).first();const pdf=await p.request.get(new URL(await link.getAttribute('href'),p.url()).href);check('Invoice downloads',pdf.status()===200);check('Invoice clearly labelled test only',(await pdf.body()).includes(Buffer.from('TEST ONLY')));await p.screenshot({path:out+'/screenshots/02-trial-invoice.png',fullPage:true});
await action('End test trial now');check('Trial expires and locks account',(await p.locator('main').innerText()).includes('Trial or paid access has ended'));await p.screenshot({path:out+'/screenshots/03-expired.png',fullPage:true});
await action('Approve simulated invoice payment');check('Explicit simulated payment unlocks',(await p.locator('main').innerText()).includes('Subscription active'));await p.screenshot({path:out+'/screenshots/04-approved.png',fullPage:true});
}finally{await browser.close();fs.writeFileSync(out+'/browser-results.json',JSON.stringify({checks,passed:checks.length===8&&checks.every(c=>c.passed),executed_at:new Date().toISOString()},null,2));}console.log(JSON.stringify(checks));})().catch(e=>{console.error(e.message);process.exit(1)});


