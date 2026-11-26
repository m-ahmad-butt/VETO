'use strict';
const $ = id => document.getElementById(id);
const terminal = new Set(['completed','rejected','failed','interrupted']);
const labels = {queued:'Queued',starting:'Starting',validating:'Validating',scanning:'Scanning',preparing_sandbox:'Preparing sandbox',install:'Installing',activate:'Activating',frontend:'Frontend test',admin:'Admin test',cron:'Scheduled tasks',idle:'Observing',cleaning_up:'Cleaning up',reporting:'Saving results',completed:'Completed',rejected:'Rejected',failed:'Failed',interrupted:'Interrupted',worker_error:'Worker stopped'};
let csrf='', selected=null, offset=0, total=0, busy=false, lastAnnouncement='', polling=false;
const assessmentCache=new Map();
const validId = value => /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(value);
function route(){const parts=location.pathname.split('/').filter(Boolean);const view=parts[0]==='history'?'history':'analysis';const id=parts.length===2&&validId(parts[1])?parts[1]:null;return {view,id};}
function showRoute(){const current=route();selected=current.id;const detail=current.view==='history'&&!!current.id;
  $('analysis-view').classList.toggle('hidden',current.view!=='analysis');$('history-view').classList.toggle('hidden',current.view!=='history'||detail);$('detail-view').classList.toggle('hidden',!detail);
  document.querySelector('.activity').classList.toggle('hidden',current.view!=='analysis'||!current.id);
  $('current-view-title').textContent=detail?'Analysis details':current.view==='history'?'Analysis history':'Plugin analysis';document.title='VETO '+$('current-view-title').textContent;
  for(const link of document.querySelectorAll('[data-nav]'))link.classList.toggle('active',link.dataset.nav===current.view);
}
function navigate(path){history.pushState({},'',path);showRoute();window.scrollTo(0,0);return refresh();}
const element = (tag, text, className) => {const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(className)node.className=className;return node;};
const date = value => value ? new Date(value).toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
const badge = status => element('span',labels[status]||status,'badge '+status);
const modeName = mode => mode==='control'?'Clean WordPress control':mode==='full'?'Static + sandbox':'Static only';
async function api(path, options={}) {const response=await fetch(path,{cache:'no-store',...options});const body=await response.json();if(!response.ok)throw new Error(body.error||'Request failed');return body;}
function setConnection(ok){$('connection').className='connection'+(ok?'':' offline');$('connection').replaceChildren(element('span',undefined,'status-dot'),document.createTextNode(ok?'Service online':'Reconnecting…'));}
function group(stage){return ['install','activate','frontend','admin','cron','idle'].includes(stage)?'executing':stage;}
async function select(id){if(!validId(id))return;await navigate('/history/'+id);}
function renderJob(job,assessment){
  $('activity-empty').classList.add('hidden');$('activity-detail').classList.remove('hidden');
  $('job-status').className='badge '+job.status;$('job-status').textContent=(labels[job.status]||job.status).toUpperCase();
  $('selected-name').textContent=job.plugin_name||job.filename;$('selected-file').textContent=job.filename+' · '+(job.size_bytes/1024).toFixed(1)+' KiB';
  $('selected-id').textContent=job.id;$('selected-hash').textContent=job.sha256;$('selected-time').textContent=date(job.created_at);
  $('selected-mode').textContent=modeName(job.mode);
  for(const notice of [$('security-notice'),$('detail-security-notice')]){
    if(assessment?.code==='review_recommended'){
      notice.replaceChildren(element('strong','Potentially unsafe. Review before installation'),element('p',`VETO found ${assessment.factors.length} review reason${assessment.factors.length===1?'':'s'}. Do not install this plugin until you inspect the evidence.`));
      notice.classList.remove('hidden');
    }else if(assessment?.code==='incomplete'){
      notice.replaceChildren(element('strong','Analysis incomplete. Do not install yet'),element('p','Review the available evidence and resolve the incomplete analysis before deciding whether to install.'));
      notice.classList.remove('hidden');
    }else notice.classList.add('hidden');
  }
  const stages=[['queued','Queued'],['validating','Validate'],['scanning','Scan'],...(job.mode!=='static'?[['preparing_sandbox','Prepare'],['executing','Execute'],['cleaning_up','Clean up']]:[]),['reporting','Save']];
  const visited=new Set(job.progress.map(event=>group(event.stage))),current=group(job.stage);
  const issue=terminal.has(job.status)&&job.status!=='completed';
  const lastWork=[...job.progress].reverse().find(event=>!terminal.has(event.stage)&&!['reporting','cleaning_up'].includes(event.stage));
  $('pipeline').replaceChildren(...stages.map(([key,title])=>{const node=element('li',title,visited.has(key)?'done':'');if(key===current&&!terminal.has(job.status))node.className='current';if(issue&&lastWork&&key===group(lastWork.stage))node.classList.add('issue');return node;}));
  $('outcome-title').textContent=labels[job.stage]||job.stage;
  $('outcome-detail').textContent=job.detail;
  $('outcome').className='outcome'+(issue?' issue':'');
  const evidence=job.evidence, chips=[];
  const evidenceLink=(text,view)=>{const button=element('button',text,'evidence-link');button.type='button';button.addEventListener('click',async()=>{await navigate('/history/'+job.id);window.VetoInvestigation?.open(view);});return button;};
  if(evidence.static_saved&&job.mode!=='control')chips.push(evidenceLink(`${evidence.static_findings} static findings saved`,'static'));
  else if(job.mode==='control')chips.push(element('span','Source plugin not installed'));
  else if(evidence.partial_static_saved)chips.push(element('span','Partial static evidence retained'));
  if(evidence.dynamic_saved||evidence.partial_dynamic_saved)chips.push(evidenceLink(`${evidence.runtime_events} runtime events saved`,'runtime'));
  if(evidence.partial_dynamic_saved&&!evidence.dynamic_saved)chips.push(element('span','Partial evidence retained'));
  if(job.cleanup_verified===1&&job.mode!=='static'&&(evidence.dynamic_saved||evidence.partial_dynamic_saved))chips.push(element('span','Sandbox cleanup verified'));
  else if(job.mode!=='static'&&terminal.has(job.status)&&!evidence.dynamic_saved&&!evidence.partial_dynamic_saved)chips.push(element('span','Sandbox not started'));
  if(job.cleanup_verified===0)chips.push(element('span','Cleanup needs attention'));
  $('evidence-summary').replaceChildren(...chips);
  $('progress-log').replaceChildren(...job.progress.map(event=>{const node=element('li');node.append(element('time',date(event.observed_at)),element('span',(labels[event.stage]||event.stage)+' · '+event.detail));return node;}));
  $('retry-cleanup').classList.toggle('hidden',!(job.cleanup_verified===0&&terminal.has(job.status)));
  const announcement=job.id+job.stage+job.status;if(lastAnnouncement!==announcement){$('job-announcement').textContent=(labels[job.stage]||job.stage)+'. '+job.detail;lastAnnouncement=announcement;}
  window.VetoInvestigation?.selectJob(job);
  window.VetoReports?.selectJob(job);
}
async function refresh(){
  if(polling)return;polling=true;
  try{
    const result=await api(`/api/jobs?limit=25&offset=${offset}`);total=result.counts.total;
    const notice=result.queue_paused?'The queue is paused until sandbox cleanup is verified. Select the affected analysis and retry cleanup.':result.service_error?'The worker service needs attention. Your saved analyses remain available.':'';
    $('service-notice').textContent=notice;$('service-notice').classList.toggle('hidden',!notice);
    $('history-body').replaceChildren(...result.jobs.map(job=>{
      const row=element('tr',undefined,job.id===selected?'selected':'');const packageCell=element('td');const button=element('button',job.plugin_name||job.filename,'row-select');button.type='button';button.setAttribute('aria-pressed',String(job.id===selected));button.addEventListener('click',()=>select(job.id));packageCell.append(button,element('span',job.filename,'row-sub'));row.append(packageCell,element('td',modeName(job.mode)),element('td',date(job.created_at)));
      const status=element('td');status.append(badge(job.status));row.append(status,element('td',job.id.slice(0,8),'mono'));return row;
    }));
    $('history-empty').classList.toggle('hidden',result.jobs.length>0);
    $('history-range').textContent=total?`${offset+1}–${Math.min(offset+25,total)} of ${total} records`:'0 records';
    $('previous-page').disabled=offset===0;$('next-page').disabled=offset+25>=total;
    if(selected){try{const job=await api('/api/jobs/'+selected);let assessment=null;if(terminal.has(job.status)){if(!assessmentCache.has(job.id))assessmentCache.set(job.id,(await api('/api/jobs/'+job.id+'/investigation')).assessment);assessment=assessmentCache.get(job.id);}renderJob(job,assessment);}catch(error){if(!result.jobs.some(job=>job.id===selected)){history.replaceState({},'','/history');showRoute();}else throw error;}}
    setConnection(true);
  }catch(error){setConnection(false);}finally{polling=false;}
}
function fileChanged(){const file=$('plugin-file').files[0];$('file-label').textContent=file?file.name:'Drop a plugin ZIP here';$('upload-error').classList.add('hidden');}
$('plugin-file').addEventListener('change',fileChanged);
for(const type of ['dragenter','dragover'])$('dropzone').addEventListener(type,event=>{event.preventDefault();$('dropzone').classList.add('dragging');});
for(const type of ['dragleave','drop'])$('dropzone').addEventListener(type,event=>{event.preventDefault();$('dropzone').classList.remove('dragging');});
$('dropzone').addEventListener('drop',event=>{if(event.dataTransfer.files.length){$('plugin-file').files=event.dataTransfer.files;fileChanged();}});
for(const input of document.querySelectorAll('input[name=mode]'))input.addEventListener('change',()=>{$('mode-note').textContent=document.querySelector('input[name=mode]:checked').value==='full'?'Docker must be running on the analysis server for sandbox mode.':'The plugin will not be executed. Docker is not needed.';});
$('upload-form').addEventListener('submit',async event=>{
  event.preventDefault();if(busy)return;
  const file=$('plugin-file').files[0];
  const showError=message=>{$('upload-error').textContent=message;$('upload-error').classList.remove('hidden');};
  if(!file){showError('Choose a plugin ZIP first.');return;}
  if(!file.name.toLowerCase().endsWith('.zip')||file.size===0||file.size>50*1024*1024){showError('Choose a non-empty ZIP no larger than 50 MiB.');return;}
  busy=true;$('submit-button').disabled=true;$('submit-button').textContent='Uploading…';$('upload-error').classList.add('hidden');$('upload-progress').classList.remove('hidden');$('upload-progress').value=0;
  try{
    const bootstrap=await api('/api/bootstrap');csrf=bootstrap.csrf_token;
    const mode=document.querySelector('input[name=mode]:checked').value;
    const response=await new Promise((resolve,reject)=>{const xhr=new XMLHttpRequest();xhr.open('POST','/api/jobs?mode='+mode);xhr.timeout=120000;xhr.setRequestHeader('Content-Type','application/zip');xhr.setRequestHeader('X-VETO-Token',csrf);xhr.setRequestHeader('X-VETO-Filename',encodeURIComponent(file.name));xhr.upload.onprogress=progress=>{if(progress.lengthComputable)$('upload-progress').value=progress.loaded/progress.total*100;};xhr.onload=()=>{try{const body=JSON.parse(xhr.responseText);if(xhr.status!==202)reject(new Error(body.error||'Upload failed'));else resolve(body);}catch(error){reject(new Error('Could not read the upload response. Refresh history before trying again.'));}};xhr.onerror=()=>reject(new Error('Connection lost. Check history before resubmitting.'));xhr.ontimeout=()=>reject(new Error('Upload timed out. Check history before resubmitting.'));xhr.send(file);});
    offset=0;await navigate('/analysis/'+response.job.id);
  }catch(error){showError(error.message);}finally{busy=false;$('submit-button').disabled=false;$('submit-button').replaceChildren(document.createTextNode('Start analysis '),element('span','↗'));$('upload-progress').classList.add('hidden');}
});
$('retry-cleanup').addEventListener('click',async()=>{try{const bootstrap=await api('/api/bootstrap');await api('/api/jobs/'+selected+'/retry-cleanup',{method:'POST',headers:{'X-VETO-Token':bootstrap.csrf_token}});await refresh();}catch(error){$('outcome-detail').textContent=error.message;}});
$('previous-page').addEventListener('click',()=>{offset=Math.max(0,offset-25);refresh();});
$('next-page').addEventListener('click',()=>{offset+=25;refresh();});
$('open-investigation').addEventListener('click',async()=>{if(selected){await navigate('/history/'+selected);window.VetoInvestigation?.open('explorer');}});
for(const link of document.querySelectorAll('[data-nav],.detail-back'))link.addEventListener('click',event=>{event.preventDefault();navigate(link.getAttribute('href'));});
window.addEventListener('popstate',()=>{showRoute();refresh();});
if(validId(location.hash.slice(1)))history.replaceState({},'','/history/'+location.hash.slice(1));
else if(location.hash==='#history')history.replaceState({},'','/history');
showRoute();refresh();setInterval(refresh,1200);
