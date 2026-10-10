(() => {
'use strict';

const config=window.PushLocalConfig||{};
const $=id=>document.getElementById(id);
const els={
 plugin:$('pushlocal-plugin'),pluginMeta:$('pushlocal-plugin-meta'),
 preflight:$('pushlocal-preflight'),preflightStatus:$('pushlocal-preflight-status'),preflightList:$('pushlocal-preflight-list'),preflightRefresh:$('pushlocal-preflight-refresh'),
 connect:$('pushlocal-connect'),forget:$('pushlocal-forget-folder'),folderName:$('pushlocal-folder-name'),ignoreMeta:$('pushlocal-ignore-meta'),
 scan:$('pushlocal-scan'),progress:$('pushlocal-progress'),results:$('pushlocal-results'),
 localCount:$('pushlocal-local-count'),remoteCount:$('pushlocal-remote-count'),changeCount:$('pushlocal-change-count'),
 newCount:$('pushlocal-new-count'),modifiedCount:$('pushlocal-modified-count'),remoteOnlyCount:$('pushlocal-remote-only-count'),
 newList:$('pushlocal-new-list'),modifiedList:$('pushlocal-modified-list'),remoteOnlyList:$('pushlocal-remote-only-list'),
 mirrorPanel:$('pushlocal-mirror-panel'),mirror:$('pushlocal-mirror-mode'),mirrorWarning:$('pushlocal-mirror-warning'),mirrorWarningText:$('pushlocal-mirror-warning-text'),
 deployPanel:$('pushlocal-deploy-panel'),deploySummary:$('pushlocal-deploy-summary'),deploy:$('pushlocal-deploy'),
 success:$('pushlocal-success'),successText:$('pushlocal-success-text'),error:$('pushlocal-error'),errorText:$('pushlocal-error-text'),browserWarning:$('pushlocal-browser-warning'),
 historyRefresh:$('pushlocal-history-refresh'),historyLoading:$('pushlocal-history-loading'),historyEmpty:$('pushlocal-history-empty'),history:$('pushlocal-history'),
 retentionMeta:$('pushlocal-retention-meta'),onboarding:$('pushlocal-onboarding'),onboardingClose:$('pushlocal-onboarding-close'),
 settingsStatus:$('pushlocal-settings-status'),settingKeep:$('pushlocal-setting-keep'),settingDays:$('pushlocal-setting-days'),settingHours:$('pushlocal-setting-hours'),settingsSave:$('pushlocal-settings-save')
};

const state={targets:[],target:null,handle:null,permission:'unknown',local:[],remote:[],diff:null,preflightReady:false,busy:false,ignore:[],settingsLoaded:false};
const defaults=['.git/','.github/','.idea/','.vscode/','node_modules/','.DS_Store','Thumbs.db','.pushlocalignore','.env','.env.*','*.log'];
const DB='pushlocal',STORE='pairs';

function cleanMessage(value,fallback='Server request failed.'){
 if(typeof value!=='string'||!value.trim())return fallback;
 const t=document.createElement('textarea');t.innerHTML=value;
 const d=document.createElement('div');d.innerHTML=t.value;
 const text=(d.textContent||d.innerText||t.value).replace(/\s+/g,' ').trim();
 if(/critical error on this website/i.test(text))return 'The PushLocal server check hit a PHP error. Click Run again and review the WordPress/PHP error log if it persists.';
 return text||fallback;
}
async function api(path,options={}){
 const res=await fetch(config.restBase+path,{...options,headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce,...(options.headers||{})},credentials:'same-origin'});
 const raw=await res.text();let payload={};
 if(raw){try{payload=JSON.parse(raw)}catch{payload={message:raw}}}
 if(!res.ok)throw new Error(cleanMessage(payload.message,`Request failed with HTTP ${res.status}`));
 return payload;
}
async function uploadApi(path,form){
 const res=await fetch(config.restBase+path,{method:'POST',body:form,headers:{'X-WP-Nonce':config.nonce},credentials:'same-origin'});
 const raw=await res.text();let payload={};
 if(raw){try{payload=JSON.parse(raw)}catch{payload={message:raw}}}
 if(!res.ok)throw new Error(cleanMessage(payload.message,`Upload failed with HTTP ${res.status}`));
 return payload;
}
function browserSupported(){return window.isSecureContext&&typeof window.showDirectoryPicker==='function'&&'indexedDB'in window}
function showError(m){els.errorText.textContent=m;els.error.hidden=false;els.success.hidden=true}
function clearError(){els.error.hidden=true;els.errorText.textContent=''}
function showSuccess(m){els.successText.textContent=m;els.success.hidden=false;els.error.hidden=true}
function clearSuccess(){els.success.hidden=true;els.successText.textContent=''}
function progress(m=''){els.progress.textContent=m}
function selectedChanges(){
 if(!state.diff)return[];
 const out=[...state.diff.added,...state.diff.modified];
 if(els.mirror.checked)out.push(...state.diff.remoteOnly.map(x=>({...x,operation:'delete',sha256:null,remote_sha256:x.sha256})));
 return out;
}
function busy(v){
 state.busy=v;
 els.plugin.disabled=v||!state.targets.length;
 els.connect.disabled=v||!state.target||!browserSupported();
 els.scan.disabled=v||!state.handle||state.permission!=='granted';
 els.deploy.disabled=v||!state.preflightReady||selectedChanges().length===0;
 els.historyRefresh.disabled=v;els.forget.disabled=v;
}
function openDb(){return new Promise((ok,fail)=>{const r=indexedDB.open(DB,1);r.onupgradeneeded=()=>{if(!r.result.objectStoreNames.contains(STORE))r.result.createObjectStore(STORE,{keyPath:'key'})};r.onsuccess=()=>ok(r.result);r.onerror=()=>fail(r.error)})}
function pairKey(plugin){return `${location.origin}|${plugin}`}
async function stored(plugin){const db=await openDb();return new Promise((ok,fail)=>{const tx=db.transaction(STORE,'readonly'),r=tx.objectStore(STORE).get(pairKey(plugin));r.onsuccess=()=>ok(r.result||null);r.onerror=()=>fail(r.error);tx.oncomplete=()=>db.close()})}
async function savePair(plugin,handle){const db=await openDb();return new Promise((ok,fail)=>{const tx=db.transaction(STORE,'readwrite');tx.objectStore(STORE).put({key:pairKey(plugin),handle,folderName:handle.name});tx.oncomplete=()=>{db.close();ok()};tx.onerror=()=>{db.close();fail(tx.error)}})}
async function forgetPair(plugin){const db=await openDb();return new Promise((ok,fail)=>{const tx=db.transaction(STORE,'readwrite');tx.objectStore(STORE).delete(pairKey(plugin));tx.oncomplete=()=>{db.close();ok()};tx.onerror=()=>{db.close();fail(tx.error)}})}
async function permission(handle,request=false){try{return request?await handle.requestPermission({mode:'read'}):await handle.queryPermission({mode:'read'})}catch{return'prompt'}}

function updateFolder(){
 if(!state.handle){els.folderName.textContent='Not connected';els.connect.textContent='Connect Local Folder';els.forget.hidden=true}
 else{els.folderName.textContent=state.handle.name;els.forget.hidden=false;els.connect.textContent=state.permission==='granted'?'Change Local Folder':'Reconnect Local Folder'}
}
async function loadTargets(){
 try{
  const r=await api('targets');state.targets=Array.isArray(r.targets)?r.targets:[];
  els.plugin.innerHTML='<option value="">Select a plugin…</option>';
  state.targets.forEach(t=>{const o=document.createElement('option');o.value=t.plugin_file;o.textContent=`${t.name}${t.version?` — ${t.version}`:''}`;els.plugin.appendChild(o)});
  const remembered=localStorage.getItem('pushlocal:lastPlugin');
  if(remembered&&state.targets.some(t=>t.plugin_file===remembered)){els.plugin.value=remembered;await targetChanged()}
  else busy(false);
 }catch(e){showError(e.message)}
}
async function targetChanged(){
 clearError();clearSuccess();state.target=state.targets.find(t=>t.plugin_file===els.plugin.value)||null;state.handle=null;state.permission='unknown';state.diff=null;state.ignore=[];
 els.results.hidden=true;els.mirrorPanel.hidden=true;els.deployPanel.hidden=true;updateFolder();
 if(!state.target){els.pluginMeta.textContent='';state.preflightReady=false;els.preflight.hidden=true;busy(false);return}
 localStorage.setItem('pushlocal:lastPlugin',state.target.plugin_file);
 els.pluginMeta.textContent=`${state.target.active?'Active':'Inactive'} · ${state.target.folder}/`;
 await runPreflight();
 try{const s=await stored(state.target.plugin_file);if(s?.handle){state.handle=s.handle;state.permission=await permission(state.handle);updateFolder();if(state.permission==='granted')await loadIgnore()}}catch{}
 busy(false);
}
async function runPreflight(){
 if(!state.target)return false;
 els.preflight.hidden=false;els.preflightStatus.textContent='Checking…';els.preflightList.innerHTML='';state.preflightReady=false;
 try{
  const r=await api('preflight',{method:'POST',body:JSON.stringify({plugin:state.target.plugin_file})});
  state.preflightReady=!!r.ready;els.preflightStatus.textContent=r.ready?'Ready':'Blocked';els.preflightStatus.className='pushlocal-preflight-status '+(r.ready?'is-ready':'is-blocked');
  (r.checks||[]).forEach(c=>{const li=document.createElement('li');li.className=`pushlocal-preflight-check is-${c.status||'info'}`;li.textContent=`${c.status==='pass'?'✓':c.status==='fail'?'×':c.status==='warning'?'!':'i'}  ${c.message||''}`;els.preflightList.appendChild(li)});
 }catch(e){els.preflightStatus.textContent='Failed';els.preflightStatus.className='pushlocal-preflight-status is-blocked';const li=document.createElement('li');li.textContent=e.message;els.preflightList.appendChild(li)}
 busy(state.busy);return state.preflightReady;
}
async function connect(){
 if(!state.target)return;
 try{
  if(state.handle&&state.permission!=='granted'){state.permission=await permission(state.handle,true);if(state.permission==='granted'){await savePair(state.target.plugin_file,state.handle);await loadIgnore();updateFolder();busy(false);return}}
  state.handle=await showDirectoryPicker({id:`pushlocal-${state.target.folder}`,mode:'read'});
  state.permission=await permission(state.handle);if(state.permission!=='granted')state.permission=await permission(state.handle,true);
  if(state.permission!=='granted')throw new Error('Read permission was not granted.');
  await savePair(state.target.plugin_file,state.handle);await loadIgnore();updateFolder();els.results.hidden=true;busy(false);
 }catch(e){if(e.name!=='AbortError')showError(e.message)}
}
async function forget(){if(!state.target)return;await forgetPair(state.target.plugin_file);state.handle=null;state.permission='unknown';state.diff=null;updateFolder();els.results.hidden=true;busy(false)}
function parseIgnore(t){return[...new Set(t.split(/\r?\n/).map(x=>x.trim()).filter(x=>x&&!x.startsWith('#')&&!x.startsWith('!')&&x.length<=200).slice(0,100))]}
async function loadIgnore(){
 state.ignore=[];
 try{const h=await state.handle.getFileHandle('.pushlocalignore'),f=await h.getFile();if(f.size>65536)throw new Error('.pushlocalignore exceeds 64 KB.');state.ignore=parseIgnore(await f.text())}catch(e){if(e.name!=='NotFoundError')throw e}
 els.ignoreMeta.textContent=state.ignore.length?`.pushlocalignore active: ${state.ignore.length} custom pattern${state.ignore.length===1?'':'s'}.`:'Default ignore rules active. No custom .pushlocalignore patterns loaded.';
}
function globBody(p){let r='';for(let i=0;i<p.length;i++){const c=p[i];if(c==='*'){if(p[i+1]==='*'){r+='.*';i++}else r+='[^/]*'}else if(c==='?')r+='[^/]';else r+=c.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')}return r}
function match(path,pattern){
 path=path.replaceAll('\\','/').replace(/^\/+/, '');const anchored=pattern.startsWith('/'),dir=pattern.endsWith('/'),clean=pattern.replace(/^\/+|\/+$/g,'');if(!clean)return false;
 const slash=clean.includes('/'),body=globBody(clean),one=c=>anchored?new RegExp(`^${body}$`,'u').test(c):!slash?new RegExp(`^${body}$`,'u').test(c.split('/').pop()||''):new RegExp(`(?:^|/)${body}$`,'u').test(c);
 if(!dir)return one(path);if(one(path))return true;const parts=path.split('/');for(let i=parts.length-1;i>0;i--)if(one(parts.slice(0,i).join('/')))return true;return false
}
function ignored(path){return[...defaults,...state.ignore].some(p=>match(path,p))}
async function digest(file){const b=await file.arrayBuffer(),d=await crypto.subtle.digest('SHA-256',b);return [...new Uint8Array(d)].map(x=>x.toString(16).padStart(2,'0')).join('')}
async function scanDir(handle,prefix='',out=[]){
 for await(const[name,h]of handle.entries()){
  const path=prefix?`${prefix}/${name}`:name;if(ignored(path))continue;
  if(h.kind==='directory'){await scanDir(h,path,out);continue}
  if(h.kind!=='file')continue;if(out.length>=5000)throw new Error('The local folder contains more than 5000 files.');
  const f=await h.getFile();if(f.size>10*1024*1024)throw new Error(`File exceeds 10 MB: ${path}`);
  out.push({path,size:f.size,sha256:await digest(f)});
 }return out
}
function compare(local,remote){
 const l=new Map(local.map(x=>[x.path,x])),r=new Map(remote.map(x=>[x.path,x])),added=[],modified=[],remoteOnly=[];
 for(const[path,x]of l){const y=r.get(path);if(!y)added.push({...x,operation:'new',remote_sha256:null});else if(x.sha256!==y.sha256)modified.push({...x,operation:'modified',remote_sha256:y.sha256})}
 for(const[path,x]of r)if(!l.has(path))remoteOnly.push(x);
 return{added,modified,remoteOnly}
}
function fill(el,items){el.innerHTML='';if(!items.length){el.innerHTML='<li class="pushlocal-file-list__empty">None</li>';return}items.slice(0,100).forEach(x=>{const li=document.createElement('li');li.textContent=typeof x==='string'?x:x.path;el.appendChild(li)})}
function formatBytes(n){return n<1024?`${n} B`:n<1048576?`${(n/1024).toFixed(1)} KB`:`${(n/1048576).toFixed(1)} MB`}
function refreshSummary(){
 const ops=selectedChanges();if(!ops.length){els.deployPanel.hidden=true;busy(false);return}
 const bytes=ops.filter(x=>x.operation!=='delete').reduce((s,x)=>s+x.size,0),deletes=ops.filter(x=>x.operation==='delete').length;
 els.deploySummary.textContent=`${ops.length} operation${ops.length===1?'':'s'} · ${formatBytes(bytes)} upload${deletes?` · ${deletes} deletion${deletes===1?'':'s'}`:''}`;
 els.deployPanel.hidden=false;busy(false);
}
function renderDiff(d){
 state.diff=d;els.localCount.textContent=state.local.length;els.remoteCount.textContent=state.remote.length;els.changeCount.textContent=d.added.length+d.modified.length;
 els.newCount.textContent=d.added.length;els.modifiedCount.textContent=d.modified.length;els.remoteOnlyCount.textContent=d.remoteOnly.length;
 fill(els.newList,d.added);fill(els.modifiedList,d.modified);fill(els.remoteOnlyList,d.remoteOnly);els.results.hidden=false;els.mirrorPanel.hidden=!d.remoteOnly.length;els.mirror.checked=false;els.mirrorWarning.hidden=true;refreshSummary()
}
async function scan(preserve=false){
 if(!preserve){clearError();clearSuccess()}if(!state.target||!state.handle)return;
 busy(true);els.results.hidden=true;
 try{
  state.permission=await permission(state.handle);if(state.permission!=='granted')throw new Error('Folder permission needs to be renewed. Click Reconnect Local Folder.');
  await loadIgnore();progress('Scanning local files…');state.local=await scanDir(state.handle);
  if(!new Set(state.local.map(x=>x.path)).has(state.target.main_file))throw new Error(`Expected main plugin file "${state.target.main_file}" was not found at the local folder root.`);
  progress('Scanning remote files…');const rr=await api('manifest',{method:'POST',body:JSON.stringify({plugin:state.target.plugin_file,ignore:state.ignore})});state.remote=rr.files||[];
  renderDiff(compare(state.local,state.remote));progress('Comparison complete.');
 }catch(e){showError(e.message);progress('');busy(false)}
}
async function localFile(path){const parts=path.split('/');let h=state.handle;for(let i=0;i<parts.length-1;i++)h=await h.getDirectoryHandle(parts[i]);return(await h.getFileHandle(parts.at(-1))).getFile()}
async function deploy(){
 clearError();clearSuccess();const changes=selectedChanges();if(!changes.length)return;
 const deletes=changes.filter(x=>x.operation==='delete').length;
 if(deletes&&!confirm(`Mirror Mode will delete ${deletes} remote-only file${deletes===1?'':'s'} after backup and verification. Continue?`))return;
 busy(true);let id=null;
 try{
  if(!await runPreflight())throw new Error('Environment check is blocking deployment.');
  const created=await api('deployments',{method:'POST',body:JSON.stringify({plugin:state.target.plugin_file,changes:changes.map(x=>({path:x.path,operation:x.operation,size:x.size,sha256:x.sha256,remote_sha256:x.remote_sha256}))})});id=created.id;
  const uploads=changes.filter(x=>x.operation!=='delete');
  for(let i=0;i<uploads.length;i++){const c=uploads[i];progress(`Uploading ${i+1}/${uploads.length}: ${c.path}`);const f=await localFile(c.path);if(await digest(f)!==c.sha256||f.size!==c.size)throw new Error(`Local file changed after comparison: ${c.path}`);const form=new FormData();form.append('path',c.path);form.append('file',f,'payload.blob');await uploadApi(`deployments/${id}/files`,form)}
  progress('Verifying and committing…');const done=await api(`deployments/${id}/commit`,{method:'POST',body:'{}'});showSuccess(`Deployment successful. ${done.files} operation${done.files===1?'':'s'} applied.`);dismissOnboarding();await loadHistory();await scan(true);progress('Deployment complete.');
 }catch(e){if(id){try{await api(`deployments/${id}/abort`,{method:'POST',body:JSON.stringify({reason:e.message})})}catch{}}showError(e.message);progress('');await loadHistory()}finally{busy(false)}
}
function mirrorChanged(){if(els.mirror.checked){els.mirrorWarningText.textContent=` ${state.diff.remoteOnly.length} remote-only file(s) will be deleted after backup and verification.`;els.mirrorWarning.hidden=false}else els.mirrorWarning.hidden=true;refreshSummary()}
function statusLabel(s){return({created:'Created',uploading:'Uploading',deploying:'Deploying',completed:'Completed',failed:'Failed',rollback_failed:'Needs attention',rolling_back:'Rolling back',rolled_back:'Rolled back',aborted:'Aborted'})[s]||s}
async function loadHistory(){
 els.historyLoading.hidden=false;els.history.hidden=true;els.historyEmpty.hidden=true;
 try{const r=await api('deployments'),items=r.deployments||[];els.history.innerHTML='';els.historyLoading.hidden=true;if(!items.length){els.historyEmpty.hidden=false;return}
  items.forEach(d=>{const card=document.createElement('article');card.className='pushlocal-history-item';card.innerHTML=`<div class="pushlocal-history-item__top"><div><strong>${d.plugin_name||d.plugin_file}</strong><div class="pushlocal-history-item__meta">${new Date(d.created_at).toLocaleString()} · ${d.files} file(s)</div></div><span class="pushlocal-status ${d.status==='completed'?'is-success':d.status==='rolled_back'?'is-neutral':'is-pending'}">${statusLabel(d.status)}</span></div><div class="pushlocal-history-item__summary">+ ${d.new_files} new · ~ ${d.modified_files} modified · − ${d.deleted_files||0} deleted</div><div class="pushlocal-history-item__actions"></div><div class="pushlocal-history-details" hidden></div>`;
   const actions=card.querySelector('.pushlocal-history-item__actions'),details=card.querySelector('.pushlocal-history-details');
   const view=document.createElement('button');view.className='button button-small';view.textContent='View files';view.onclick=async()=>{if(!details.dataset.loaded){const x=await api(`deployments/${d.id}`);details.innerHTML='<ul class="pushlocal-history-file-list">'+x.changes.map(c=>`<li><span>${c.operation==='new'?'+':c.operation==='delete'?'−':'~'}</span><code>${c.path}</code><span>${formatBytes(c.size)}</span></li>`).join('')+'</ul>';details.dataset.loaded='1'}details.hidden=!details.hidden;view.textContent=details.hidden?'View files':'Hide files'};actions.appendChild(view);
   if(d.can_rollback){const rb=document.createElement('button');rb.className='button button-small pushlocal-rollback-button';rb.textContent='Rollback';rb.onclick=async()=>{if(!confirm('Rollback this deployment? PushLocal will stop if server files changed afterward.'))return;busy(true);try{const x=await api(`deployments/${d.id}/rollback`,{method:'POST',body:'{}'});showSuccess(`Rollback successful. ${x.files} file(s) restored.`);await loadHistory();if(state.target?.plugin_file===d.plugin_file&&state.handle)await scan(true)}catch(e){showError(e.message)}finally{busy(false)}};actions.appendChild(rb)}
   els.history.appendChild(card)});
  els.history.hidden=false;
 }catch(e){els.historyLoading.hidden=true;showError(e.message)}
}
async function loadRetention(){try{const r=await api('retention'),p=r.policy||{};els.retentionMeta.textContent=` Backups: latest ${p.keep_latest||10}, max ${p.max_age_days||30} days.`}catch{}}
async function loadSettings(){try{const r=await api('settings'),s=r.settings||{};els.settingKeep.value=s.keep_latest??10;els.settingDays.value=s.max_age_days??30;els.settingHours.value=s.abandoned_upload_hours??24;state.settingsLoaded=true;if(!config.canManageSettings){els.settingKeep.disabled=els.settingDays.disabled=els.settingHours.disabled=els.settingsSave.disabled=true;els.settingsStatus.textContent='Administrator only'}}catch{els.settingsStatus.textContent='Could not load settings'}}
async function saveSettings(){if(!config.canManageSettings)return;els.settingsSave.disabled=true;try{await api('settings',{method:'POST',body:JSON.stringify({keep_latest:Number(els.settingKeep.value),max_age_days:Number(els.settingDays.value),abandoned_upload_hours:Number(els.settingHours.value)})});els.settingsStatus.textContent='Saved';await loadRetention()}catch(e){showError(e.message)}finally{els.settingsSave.disabled=false}}
function dismissOnboarding(){localStorage.setItem('pushlocal:onboardingDismissed','1');els.onboarding.hidden=true}
function init(){
 if(!browserSupported())els.browserWarning.hidden=false;
 els.onboarding.hidden=localStorage.getItem('pushlocal:onboardingDismissed')==='1';
 els.plugin.addEventListener('change',targetChanged);els.connect.addEventListener('click',connect);els.forget.addEventListener('click',forget);els.scan.addEventListener('click',()=>scan());els.deploy.addEventListener('click',deploy);els.mirror.addEventListener('change',mirrorChanged);els.preflightRefresh.addEventListener('click',runPreflight);els.historyRefresh.addEventListener('click',loadHistory);els.settingsSave.addEventListener('click',saveSettings);els.onboardingClose.addEventListener('click',dismissOnboarding);
 loadTargets();loadHistory();loadRetention();loadSettings();
}
init();
})();