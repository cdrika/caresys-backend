const {chromium,request}=require(process.env.CARESYS_NODE_MODULES+'/playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
(async()=>{
 const api='https://127.0.0.1:8443';const web='https://localhost:8444';
 const native=await request.newContext({ignoreHTTPSErrors:true});
 const deviceName='QA local mobile '+Date.now();let issuedToken;
 const created=await native.post(api+'/mobile/v1/pairing/requests',{data:{device_name:deviceName}});
 assert.equal(created.status(),201,'Pairing start');
 const pairing=(await created.json()).data;
 assert.equal(pairing.verification_uri,web+'/mobile-enrollment');
 const pending=await native.post(api+'/mobile/v1/pairing/token',{data:{device_code:pairing.device_code}});
 assert.equal(pending.status(),428,'Approval required');
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try {
  const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1280,height:1000}});
  const page=await context.newPage();const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.goto(pairing.verification_uri);
  await page.locator('[name=email]').fill('candidate@caresys.test');
  await page.locator('[name=password]').fill('CareSysDemo!2026');
  await Promise.all([page.waitForURL('**/mobile-enrollment'),page.locator('button[type=submit]').click()]);
  assert.equal(await page.locator('h1').innerText(),'Connect your mobile device');
  // Remove only synthetic QA sessions left by an interrupted earlier run.
  while(await page.locator('.pairing-session').filter({hasText:'QA local mobile'}).count()) {
   await page.locator('.pairing-session').filter({hasText:'QA local mobile'}).first().getByRole('button',{name:'Revoke access'}).click();
   await page.waitForURL('**/mobile-enrollment/revoke');
  }
  await page.goto(web+'/mobile-enrollment');
  await page.locator('[name=user_code]').fill(pairing.user_code);
  await Promise.all([page.waitForURL('**/mobile-enrollment/review'),page.locator('button[type=submit]').first().click()]);
  assert.equal(await page.locator('.pairing-device h2').innerText(),deviceName);
  await page.locator('[name=password]').fill('CareSysDemo!2026');
  await page.locator('[name=confirm]').check();
  await Promise.all([page.waitForURL('**/mobile-enrollment/approve'),page.getByRole('button',{name:'Approve this device'}).click()]);
  assert.match(await page.locator('[role=status]').innerText(),/Device approved/);
  const redeemed=await native.post(api+'/mobile/v1/pairing/token',{data:{device_code:pairing.device_code}});
  assert.equal(redeemed.status(),200,'Token delivery');
  const token=(await redeemed.json()).data.access_token;
  issuedToken=token;
  const profile=await native.get(api+'/mobile/v1/me/profile',{headers:{Authorization:'Bearer '+token}});
  assert.equal(profile.status(),200,'Authenticated mobile profile');
  assert.match(profile.headers()['cache-control'],/no-store/);
  const replay=await native.post(api+'/mobile/v1/pairing/token',{data:{device_code:pairing.device_code}});
  assert.equal(replay.status(),400,'Single-use pairing');
  const cookie=await native.get(api+'/mobile/v1/me/profile',{headers:{Authorization:'Bearer '+token,Cookie:'caresys_session=test'}});
  assert.equal(cookie.status(),400,'No cookie fallback');
  assert.equal((await native.get(api+'/login')).status(),404,'API host denies login');
  assert.equal((await native.get(api+'/assets/caresys.css')).status(),404,'API host denies assets');
  assert.equal((await native.get(web+'/mobile/v1/me/profile')).status(),404,'Web host denies API');
  assert.equal((await native.get(web+'/index.php/mobile/v1/me/profile')).status(),404,'Web host denies front-controller API aliases');
  await page.goto(web+'/mobile-enrollment');
  const row=page.locator('.pairing-session').filter({hasText:deviceName});
  await Promise.all([page.waitForURL('**/mobile-enrollment/revoke'),row.getByRole('button',{name:'Revoke access'}).click()]);
  assert.match(await page.locator('[role=status]').innerText(),/revoked/);
  const revoked=await native.get(api+'/mobile/v1/me/profile',{headers:{Authorization:'Bearer '+token}});
  assert.equal(revoked.status(),401,'Revocation applies immediately');
  fs.mkdirSync('build/qa/mobile-pairing',{recursive:true});
  await page.screenshot({path:'build/qa/mobile-pairing/desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});
  await page.goto(web+'/mobile-enrollment');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'No mobile overflow');
  await page.screenshot({path:'build/qa/mobile-pairing/mobile.png',fullPage:true});
  assert.deepEqual(errors,[],'No browser errors');
  console.log('PASS: pairing, browser approval, token use, replay prevention, cookie rejection, host isolation, revocation, desktop/mobile layout.');
 } finally {if(issuedToken)await native.delete(api+'/mobile/v1/auth/token',{headers:{Authorization:'Bearer '+issuedToken}});await browser.close();await native.dispose();}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
