<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Local test device · CareSys</title>
<style nonce="<?= esc($nonce,'attr') ?>">
*{box-sizing:border-box}body{margin:0;background:#f4f7f3;color:#173c38;font:16px/1.6 system-ui,sans-serif}main{max-width:620px;margin:40px auto;padding:0 20px}section{background:white;border:1px solid #dce6e2;border-radius:20px;padding:30px;margin:20px 0}h1{font-size:28px;line-height:1.25}label{display:block;font-weight:600;margin-top:20px}input{width:100%;padding:13px;border:1px solid #c5d5cd;border-radius:8px;font:inherit;margin:8px 0 18px}button{background:#167b70;color:white;border:0;border-radius:8px;padding:13px 20px;font:600 15px system-ui;cursor:pointer}button:disabled{opacity:.6;cursor:wait}a{color:#116e65}small{color:#526a63}.badge{font-size:12px;letter-spacing:.06em;color:#116e65}#pairing-code{font:600 clamp(20px,5vw,29px) monospace;letter-spacing:.1em;text-align:center;background:#edf5f0}#status{padding:16px;background:#edf5f0;border-radius:10px}.error{color:#a33217!important;background:#fff0e9!important}[hidden]{display:none!important}
</style></head><body><main><section><span class="badge">CARESYS · LOCAL DEVELOPMENT ONLY</span><h1>Local test device</h1><p>This tab acts as a mobile device. Generate a code here, approve it in your CareSys workspace, then return here to check the connection.</p>
<form id="start-form"><label for="device-name">Device name</label><input id="device-name" name="device_name" value="My local test device" maxlength="60" pattern="[a-zA-Z0-9 _-]{1,60}" required><button id="start" type="submit">Create pairing code</button></form>
<div id="pairing" hidden><label for="pairing-code">Your real pairing code</label><input id="pairing-code" readonly aria-describedby="countdown"><p id="countdown"></p><a id="approval-link" target="_blank" rel="noopener noreferrer">Open approval page ↗</a><p>Enter the code above on the approval page. Check the device name and confirm your password. Keep this tab open while it waits for approval.</p></div>
<p id="status" role="status" aria-live="polite">Ready to create a pairing request.</p><button id="disconnect" type="button" hidden>Disconnect test device</button>
</section><p><small>Credentials stay in this tab's memory and are never displayed or saved in browser storage. Disconnect when finished. The access token expires after 15 minutes.</small></p></main>
<script nonce="<?= esc($nonce,'attr') ?>">
(()=>{'use strict';
const form=document.querySelector('#start-form'),button=document.querySelector('#start'),panel=document.querySelector('#pairing'),status=document.querySelector('#status'),disconnect=document.querySelector('#disconnect');
let secret=null,token=null,timer=null,clock=null,deadline=0,busy=false;
const show=(message,error=false)=>{status.textContent=message;status.classList.toggle('error',error);};
const stop=()=>{clearTimeout(timer);clearInterval(clock);secret=null;};
const call=(path,options={})=>fetch('/mobile/v1/'+path,{...options,credentials:'omit',cache:'no-store'});
const tick=()=>{const seconds=Math.max(0,Math.ceil((deadline-Date.now())/1000));document.querySelector('#countdown').textContent=`Code expires in ${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')}.`;if(!seconds){stop();panel.hidden=true;button.disabled=false;show('This code expired. Create a new pairing code to try again.',true);}};
async function poll(){if(!secret||Date.now()>=deadline)return;
try{const response=await call('pairing/token',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({device_code:secret})});
if(response.status===428){timer=setTimeout(poll,5000);return;}
if(response.status===429){show('Waiting for approval. Pausing briefly because the request limit was reached.');timer=setTimeout(poll,Math.max(5,Number(response.headers.get('Retry-After'))||60)*1000);return;}
const payload=await response.json();if(!response.ok)throw new Error(payload.error?.message||'Pairing failed. Create a new code.');
token=payload.data.access_token;stop();panel.hidden=true;disconnect.hidden=false;
const check=await call('me/profile',{headers:{Authorization:'Bearer '+token}});
if(!check.ok)throw new Error('Device approved, but the API access check failed. Disconnect and try again.');
show('Connected. This test device successfully accessed your mobile profile through the isolated API.');
}catch(error){stop();button.disabled=Boolean(token);show(error.message,true);}}
form.addEventListener('submit',async event=>{event.preventDefault();if(busy||token)return;busy=true;button.disabled=true;stop();panel.hidden=true;show('Creating a new pairing request…');
try{const response=await call('pairing/requests',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({device_name:document.querySelector('#device-name').value})});const payload=await response.json();if(!response.ok)throw new Error(response.status===429?'Too many requests. Wait a minute before creating another code.':payload.error?.message||'Unable to create a pairing request.');
const request=payload.data;secret=request.device_code;deadline=Date.now()+request.expires_in*1000;document.querySelector('#pairing-code').value=request.user_code;
const target=new URL(request.verification_uri);if(target.protocol!=='https:')throw new Error('The approval page must use HTTPS.');document.querySelector('#approval-link').href=target.href;
panel.hidden=false;show('Waiting for you to approve this code in your CareSys workspace.');tick();clock=setInterval(tick,1000);timer=setTimeout(poll,Math.max(5,request.interval)*1000);
}catch(error){stop();button.disabled=false;show(error.message,true);}finally{busy=false;}});
disconnect.addEventListener('click',async()=>{if(!token)return;disconnect.disabled=true;try{const response=await call('auth/token',{method:'DELETE',headers:{Authorization:'Bearer '+token}});if(!response.ok&&response.status!==401)throw new Error('Unable to disconnect. Try again.');token=null;disconnect.hidden=true;button.disabled=false;show('Disconnected. The test device no longer has API access.');}catch(error){show(error.message,true);}finally{disconnect.disabled=false;}});
window.addEventListener('pagehide',()=>{stop();if(token){void call('auth/token',{method:'DELETE',headers:{Authorization:'Bearer '+token},keepalive:true});token=null;}});
})();
</script></body></html>
