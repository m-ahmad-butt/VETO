/* Reports are immutable pairs. Untrusted names and labels remain text nodes. */
(() => {
  'use strict';
  const $=id=>document.getElementById(id),node=(tag,text,cls)=>{const item=document.createElement(tag);if(text!==undefined)item.textContent=text;if(cls)item.className=cls;return item;};
  let job=null,key='',revision=0,busy=false,controlsKey='';
  async function api(path,options={}){const response=await fetch(path,{cache:'no-store',...options});const data=await response.json();if(!response.ok)throw new Error(data.error||'Report operation failed');return data;}
  const path=()=>'/api/jobs/'+job.id+'/exports';
  function controlOptions(controls,previous,jobId){
    if(job?.id!==jobId)return;
    const eligible=controls.filter(control=>control.status==='completed'&&control.id!==jobId);
    const signature=jobId+eligible.map(control=>control.id).join(',');
    if(signature===controlsKey)return;controlsKey=signature;
    const empty=node('option','No comparison included');empty.value='';$('report-control').replaceChildren(empty);
    for(const control of eligible){const option=node('option',control.id.slice(0,8)+' · '+new Date(control.created_at).toLocaleString());option.value=control.id;$('report-control').append(option);}
    $('report-control').value=previous;$('report-control').disabled=job.mode==='static'||job.mode==='control';
  }
  function render(exports,jobId){
    const list=$('report-list');list.replaceChildren();
    if(!exports.length){list.append(node('p','No report snapshots saved for this analysis yet.','empty-evidence'));return;}
    for(const report of exports){const item=node('article',undefined,'saved-report'),left=node('div');left.append(node('strong',new Date(report.created_at).toLocaleString()),node('p',report.job_status+' · '+report.assessment_code.replaceAll('_',' ')+' · policy '+report.policy_version),node('small','Snapshot '+report.export_id));const links=node('div',undefined,'report-downloads');
      for(const [name,label] of [['report.pdf','Download PDF'],['report.json','Download JSON']]){const link=node('a',label);link.href='/api/jobs/'+jobId+'/exports/'+report.export_id+'/'+name;link.setAttribute('download','');links.append(link);}item.append(left,links);list.append(item);
    }
  }
  async function selectJob(value){
    const next=value.id+value.status+value.updated_at;if(next===key)return;const changed=job?.id!==value.id;key=next;job=value;
    const current=++revision,jobId=job.id;$('create-report').disabled=busy||['queued','running'].includes(job.status);
    $('report-status').textContent=['queued','running'].includes(job.status)?'Wait for this analysis to finish. Progress and partial evidence remain available below.':'Exports preserve this outcome, including any failures, incomplete coverage and review limitations.';
    try{
      const [saved,controls]=await Promise.all([api(path()),api('/api/controls')]);if(current!==revision)return;
      render(saved.exports,jobId);controlOptions(controls.controls,changed?'':$('report-control').value,jobId);
    }catch(error){if(current===revision){key='';$('report-status').textContent=error.message;}}
  }
  $('create-report').addEventListener('click',async()=>{
    if(!job||busy)return;busy=true;const jobId=job.id,current=revision;$('create-report').disabled=true;$('report-status').textContent='Creating the PDF and JSON from one saved evidence snapshot…';
    const control=$('report-control').disabled?'':$('report-control').value;
    try{const token=(await api('/api/bootstrap')).csrf_token;
      await api('/api/jobs/'+jobId+'/exports',{method:'POST',headers:{'X-VETO-Token':token,'Content-Type':'application/json'},body:JSON.stringify(control?{control_job_id:control}:{})});
      if(current!==revision)return;const saved=await api('/api/jobs/'+jobId+'/exports');render(saved.exports,jobId);$('report-status').textContent='Report snapshot saved. Both downloads remain available after restart and sandbox cleanup.';
    }catch(error){if(current===revision)$('report-status').textContent=error.message;}
    finally{busy=false;$('create-report').disabled=!job||['queued','running'].includes(job.status);}
  });
  window.VetoReports={selectJob};
  setInterval(async()=>{if(job&&!busy){const jobId=job.id;try{const controls=await api('/api/controls');controlOptions(controls.controls,$('report-control').value,jobId);}catch(error){/* Main connection indicator handles service outages. */}}},10000);
})();
