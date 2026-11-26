/* Phase 3B: evidence stays data. Never insert package text as HTML. */
(() => {
  'use strict';
  const byId=id=>document.getElementById(id);
  const h=(tag,text,cls)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;};
  const panel=byId('investigation-panel');
  let recordRequest=0;
  const categories={file:'Files',database:'Database',account:'Accounts',cron:'Scheduled tasks',network:'Network',static:'Static indicators',workflow:'Workflow'};
  const state={job:null,key:'',overview:null,view:'explorer',offset:0,q:'',category:'',severity:'',stage:'',source:'',control:'',comparison:null,compareOffset:0,revision:0,querySerial:0,loading:false,lastLoad:0};
  async function request(path,options={}){const response=await fetch(path,{cache:'no-store',...options});const value=await response.json();if(!response.ok)throw new Error(value.error||'Could not load evidence');return value;}
  const base=()=>'/api/jobs/'+state.job.id;
  const readable=value=>String(value||'').replaceAll('_',' ');
  const when=value=>value?new Date(value).toLocaleString([],{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'}):'Not captured';
  const pretty=value=>{if(value===null||value===undefined)return 'Not captured';if(typeof value==='string'){try{return JSON.stringify(JSON.parse(value),null,2);}catch(error){return value;}}return JSON.stringify(value,null,2);};
  function severity(value){return h('span',String(value).toUpperCase(),'severity '+value);}
  function message(text,cls='inspection-note'){return h('p',text,cls);}
  function note(text){const node=h('div',text,'inspection-warning');return node;}
  function recordButton(id,jobId=state.job.id,label=id){const button=h('button',label,'record-link');button.type='button';button.addEventListener('click',()=>openRecord(jobId,id));return button;}
  function sectionTitle(text){return h('h3',text);}
  function block(title,value){const node=h('section',undefined,'evidence-block');node.append(h('h3',title),h('pre',pretty(value)));return node;}
  function definition(label,value){const item=h('div');item.append(h('dt',label),h('dd',String(value??'Not captured')));return item;}
  async function openRecord(jobId,id){
    const requestId=++recordRequest;
    const dialog=byId('record-dialog');byId('record-title').textContent='Loading evidence';byId('record-content').replaceChildren(message('Reading the saved source record…'));
    if(!dialog.open)dialog.showModal();
    try{
      const record=await request('/api/jobs/'+jobId+'/records/'+encodeURIComponent(id));
      if(!dialog.open||requestId!==recordRequest)return;
      byId('record-title').textContent=record.title;
      const body=byId('record-content');body.replaceChildren();
      const heading=h('div',undefined,'factor-heading');heading.append(severity(record.severity),h('span',record.record_id,'mono'));body.append(heading);
      body.append(message(record.rationale,'record-description'),message('Interpretation limit: '+record.limitation,'record-description'));
      const meta=h('dl',undefined,'record-meta-grid');
      for(const [label,value] of [['Resource',record.resource],['Category',categories[record.category]||record.category],['Recorded at',when(record.timestamp)],['Execution stage',readable(record.stage)],['Rule / collector',record.rule_id||record.engine],['Collector / engine',record.engine],['Line / byte offset',record.line!==null?`${record.line} / ${record.byte_offset}`:'Not applicable'],['Occurrences',record.occurrence_count]])meta.append(definition(label,value));
      body.append(meta);
      if(record.source==='runtime'){
        const values=h('div',undefined,'before-after');
        const before=record.before===null?(record.action==='created'&&record.engine==='baseline-diff'?'Absent from the earlier snapshot':'Not captured by this collector'):record.before;
        const after=record.after===null?(record.action==='deleted'&&record.engine==='baseline-diff'?'Absent from the later snapshot':'Not captured by this collector'):record.after;
        values.append(block('Before',before),block('After',after));body.append(values);
      }
      for(const evidence of record.evidence)body.append(block('Supporting evidence · '+evidence.id,evidence.text));
      const trace=h('details',undefined,'record-provenance');trace.append(h('summary','Source reference and original record'));
      trace.append(message('This reference points to the retained artifact or persisted workflow row. It is not a link to an executable file.'),block('Source reference',record.trace),block('Original saved record',record.raw));body.append(trace);
    }catch(error){if(requestId===recordRequest){byId('record-title').textContent='Evidence unavailable';byId('record-content').replaceChildren(note(error.message));}}
  }
  byId('close-record').addEventListener('click',()=>byId('record-dialog').close());
  byId('record-dialog').addEventListener('click',event=>{if(event.target===byId('record-dialog')){const r=event.target.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)event.target.close();}});

  function overview(){
    const model=state.overview,a=model.assessment;panel.replaceChildren();
    const heading=h('div',undefined,'review-heading'),left=h('div');left.append(h('h3',a.label),message(a.summary));heading.append(left,h('span',readable(a.priority)+' priority','review-label '+a.code));panel.append(heading,message(a.scope,'scope-line'));
    if(a.incomplete_reasons.length){const warning=note('A completed assessment is unavailable:');const list=h('ul');for(const reason of a.incomplete_reasons)list.append(h('li',reason));warning.append(list);panel.append(warning);}
    if(model.mode==='control')panel.append(note('This run did not install the submitted plugin. Source-package static findings are retained for traceability and are excluded from this control observation’s assessment.'));
    const counts=h('div',undefined,'inspection-counts');
    for(const [value,label] of [[model.counts.static,model.mode==='control'?'Source-package indicators':'Static indicators'],[model.counts.runtime,'Runtime observations'],[model.counts.affected_resources,'Observed resources'],[a.factors.length,'Review reasons']]){const item=h('div');item.append(h('strong',String(value)),h('span',label));counts.append(item);}panel.append(counts);
    panel.append(message('Review reasons are explicit rules with supporting records. Activity counts are not threat counts. No malware probability or safe/unsafe verdict is assigned.'));
    if(a.factors.length){const factors=h('ul',undefined,'factor-list');for(const factor of a.factors){const item=h('li',undefined,'factor'),title=h('div',undefined,'factor-heading');title.append(severity(factor.severity),h('strong',factor.title));item.append(title,h('p',factor.explanation),h('small','Limit: '+factor.limitation));const refs=h('div');for(const id of factor.record_ids)refs.append(recordButton(id));item.append(refs,h('small','Policy '+factor.policy_id));factors.append(item);}panel.append(factors);}
    else panel.append(message('No elevated review rule matched the available evidence. Missing coverage, delayed behavior, and unexercised code can still hide relevant activity.'));
    const policy=h('details',undefined,'policy-details');policy.append(h('summary','How this assessment works · policy '+a.policy_version));
    for(const rule of model.policy){const item=h('div',undefined,'policy-rule');item.append(h('strong',rule.id+' · '+rule.title),h('p',rule.condition),h('p','Limit: '+rule.limitation));policy.append(item);}panel.append(policy);
    const resources=h('details',undefined,'affected-resources');resources.append(h('summary','Observed resources · '+model.counts.affected_resources));const list=h('ul');for(const resource of model.resources.slice(0,100)){const item=h('li');item.append(h('strong',(categories[resource.category]||resource.category)+' · '),document.createTextNode(resource.resource+' · '+resource.observations+' observations'));list.append(item);}resources.append(list);if(model.resources.length>100)resources.append(message('Showing the first 100 resources. The searchable Runtime activity view contains all recorded resources.'));panel.append(resources);
  }
  function coverage(){
    panel.replaceChildren(sectionTitle('What was observed, and what was not'));
    panel.append(message('Completed means the declared collector finished without a detected failure. It does not mean every possible behavior was observable.'));
    const grid=h('div',undefined,'coverage-grid');for(const item of state.overview.coverage){const card=h('div',undefined,'coverage-card');card.append(h('strong',categories[item.category]||item.category),h('span',readable(item.state),item.state));grid.append(card);}panel.append(grid);
    if(state.overview.mode!=='static')panel.append(message('Sandbox cleanup: '+(state.overview.cleanup_verified?'verified':'not verified')));
    for(const issue of state.overview.integrity_issues)panel.append(note(issue));
    if(state.overview.errors.length){panel.append(sectionTitle('Recorded failures'));for(const error of state.overview.errors)panel.append(block(error.source+' / '+error.stage+' / '+error.code,error.message));}
    panel.append(sectionTitle('Interpretation and monitoring limits'));const list=h('ul',undefined,'limit-list');for(const limit of state.overview.limitations)list.append(h('li',limit));panel.append(list);
  }
  function dropdown(label,id,options,value){const container=h('label',label);const select=h('select');select.id=id;for(const [key,title] of options){const option=h('option',title);option.value=key;select.append(option);}select.value=value;container.append(select);return container;}
  function recordsView(){
    panel.replaceChildren();
    const title={explorer:'Search and investigate events',static:'Code indicators before execution',runtime:'Recorded runtime activity',timeline:'Chronological evidence and execution stages'}[state.view];
    const explorer=state.view==='explorer';
    if(explorer){const intro=h('div',undefined,'explorer-intro');intro.append(h('span','EVENT EXPLORER','explorer-kicker'),sectionTitle(title),message('Search saved static findings, runtime observations, and workflow updates. Open an event to inspect its fields and source evidence.'));panel.append(intro);}
    else{panel.append(sectionTitle(title));panel.append(message(state.view==='static'?'These are indicators, not proof of reachable or malicious behavior. Open a record for its rule, location, evidence and limitation.':state.view==='runtime'?'Observations include normal WordPress activity. Open a record to inspect the collector, before/after state and source reference.':'Times show when evidence was recorded. Workflow updates describe the test sequence; ordering does not establish causation.'));}
    const form=h('form',undefined,'evidence-filters'+(explorer?' explorer-filters':''));
    const search=h('label',explorer?'Search events':'Search evidence','search-field'),input=h('input');input.type='search';input.id='evidence-search';input.maxLength=200;input.placeholder=explorer?'Search paths, SQL, rules, or event text':'Resource, rule, account or evidence text';input.value=state.q;search.append(input);form.append(search);
    form.append(dropdown('Severity','evidence-severity',[['','All severities'],['high','High'],['medium','Medium'],['low','Low'],['info','Informational']],state.severity));
    if(state.view!=='static')form.append(dropdown('Category','evidence-category',[['','All categories'],...Object.entries(categories).filter(([key])=>state.view==='timeline'||explorer||!['static','workflow'].includes(key))],state.category));
    if(state.view==='timeline'||explorer)form.append(dropdown('Source','evidence-source',[['','All sources'],['static','Static findings'],['runtime','Runtime observations'],['workflow','Workflow updates']],state.source));
    form.append(dropdown('Stage','evidence-stage',[['','All stages'],...state.overview.stages.map(stage=>[stage,readable(stage)])],state.stage));
    const apply=h('button',explorer?'Search events':'Apply filters');apply.type='submit';form.append(apply);panel.append(form);
    const results=h('div');results.id='evidence-results';panel.append(results);
    form.addEventListener('submit',event=>{event.preventDefault();state.q=input.value;state.severity=byId('evidence-severity').value;state.category=byId('evidence-category')?.value||'';state.stage=byId('evidence-stage').value;state.source=byId('evidence-source')?.value||'';state.offset=0;loadRecords();});
    loadRecords();
  }
  function drawExplorerSummary(result,target){
    const wrap=h('section',undefined,'explorer-summary');
    const heading=h('div',undefined,'explorer-summary-heading');heading.append(h('strong',`${result.total} matching events`));
    const start=result.histogram?.start,end=result.histogram?.end;
    heading.append(h('span',start?`${when(new Date(start*1000).toISOString())} – ${when(new Date(end*1000).toISOString())}`:'No recorded timestamps'));
    wrap.append(heading);
    const chart=h('div',undefined,'explorer-chart');chart.setAttribute('role','img');chart.setAttribute('aria-label','Event frequency across the selected time range');
    const counts=result.histogram?.counts||[],max=Math.max(1,...counts);
    for(let index=0;index<counts.length;index++){const slot=h('span',undefined,'explorer-bar-slot'),bar=h('span',undefined,'explorer-bar');bar.style.height=`${Math.max(2,counts[index]/max*100)}%`;bar.title=`Time bucket ${index+1}: ${counts[index]} events`;slot.append(bar);chart.append(slot);}
    wrap.append(chart);
    target.append(wrap);
  }
  function drawExplorerEvents(result,target,jobId){
    const workspace=h('div',undefined,'siem-workspace'),sidebar=h('aside',undefined,'siem-sidebar'),main=h('div',undefined,'siem-main');
    sidebar.append(h('strong','Fields','siem-sidebar-title'),h('span','CATEGORY','siem-sidebar-label'));
    const facets=h('div',undefined,'siem-field-list');
    for(const [key,count] of Object.entries(result.facets||{}).sort((a,b)=>b[1]-a[1])){const button=h('button',undefined,'siem-field'+(state.category===key?' active':''));button.type='button';button.append(h('span',categories[key]||key),h('b',String(count)));button.addEventListener('click',()=>{state.category=state.category===key?'':key;const select=byId('evidence-category');if(select)select.value=state.category;state.offset=0;loadRecords();});facets.append(button);}
    sidebar.append(facets,h('span','EVENT FIELDS','siem-sidebar-label'));
    const fields=h('ul',undefined,'siem-field-names');for(const name of ['timestamp','source','category','stage','rule / collector','action','resource'])fields.append(h('li',name));sidebar.append(fields);
    const header=h('div',undefined,'explorer-events-heading');header.append(h('strong',`Events (${result.total})`),h('span',`${result.offset+1}–${Math.min(result.offset+result.limit,result.total)} shown`));main.append(header);
    const columns=h('div',undefined,'siem-columns');columns.append(h('span','Time'),h('span','Event'));main.append(columns);
    const list=h('div',undefined,'explorer-events');
    for(const record of result.items){
      const event=h('details',undefined,'siem-event'+(['medium','high'].includes(record.severity)?' flagged':'')),summary=h('summary');
      summary.append(h('time',when(record.timestamp)));
      const content=h('span',undefined,'siem-event-content'),heading=h('span',undefined,'siem-event-heading');heading.append(severity(record.severity),h('strong',record.title),h('small',`${record.source} · ${record.category} · ${readable(record.stage)}`));content.append(heading);
      const evidence=String(record.evidence?.[0]?.text||record.rationale||'').replace(/\s+/g,' ').trim();
      content.append(h('span',`${record.resource}  ·  ${evidence.slice(0,180)}${evidence.length>180?'…':''}`,'siem-event-excerpt'));summary.append(content);event.append(summary);
      const body=h('div',undefined,'siem-event-body'),fields=h('dl',undefined,'siem-event-fields');
      for(const [label,value] of [['record_id',record.record_id],['source',record.source],['category',record.category],['stage',readable(record.stage)],['rule',record.rule_id||record.engine],['action',readable(record.action)],['resource',record.resource]])fields.append(definition(label,value));body.append(fields);
      if(evidence){const raw=h('details',undefined,'siem-raw');raw.append(h('summary','Show raw event text'),h('pre',String(record.evidence?.[0]?.text||'').slice(0,4000)));body.append(raw);}
      body.append(recordButton(record.record_id,jobId,'Full evidence and provenance →'));event.append(body);list.append(event);
    }
    main.append(list);
    const pager=h('div',undefined,'record-pager');pager.append(h('span',`${result.offset+1}–${Math.min(result.offset+result.limit,result.total)} of ${result.total} events`));const buttons=h('div');for(const [label,delta,disabled] of [['← Previous',-25,state.offset===0],['Next →',25,state.offset+25>=result.total]]){const button=h('button',label,'page-button');button.type='button';button.disabled=disabled;button.addEventListener('click',()=>{state.offset=Math.max(0,state.offset+delta);loadRecords();});buttons.append(button);}pager.append(buttons);main.append(pager);
    workspace.append(sidebar,main);target.append(workspace);
    target.append(message('These are recorded observations, not automatic proof of malicious intent. Review findings and coverage before installation.','explorer-caveat'));
  }
  async function loadRecords(){
    const serial=++state.querySerial,jobId=state.job.id,view=state.view;
    const target=byId('evidence-results');if(!target)return;target.replaceChildren(message('Loading saved evidence…'));
    const query=new URLSearchParams({source:view==='timeline'||view==='explorer'?state.source:view,category:state.category,severity:state.severity,stage:state.stage,q:state.q,offset:String(state.offset),limit:'25'});
    try{
      const result=await request('/api/jobs/'+jobId+'/records?'+query);
      if(serial!==state.querySerial||jobId!==state.job?.id||view!==state.view)return;
      target.replaceChildren();
      if(view==='explorer'){
        drawExplorerSummary(result,target);
        if(!result.total){target.append(h('p','No events match these filters. Missing events do not establish plugin safety.','empty-evidence'));return;}
        drawExplorerEvents(result,target,jobId);
        return;
      }
      if(!result.total){target.append(h('p','No records match these filters. This does not establish plugin safety.','empty-evidence'));return;}
      const scroll=h('div',undefined,'table-scroll'),table=h('table',undefined,'record-table'),head=h('thead'),tr=h('tr');
      for(const title of ['Recorded at','Evidence record','Category / stage','Severity','Resource'])tr.append(h('th',title));head.append(tr);table.append(head);
      const body=h('tbody');
      for(const record of result.items){const row=h('tr'),cell=h('td',undefined,'title-cell');cell.append(recordButton(record.record_id,jobId,record.title),h('small',record.record_id+' · '+(record.rule_id||record.engine)));const context=h('td');context.append(h('span',categories[record.category]||record.category),h('small',readable(record.stage)));const level=h('td');level.append(severity(record.severity));row.append(h('td',when(record.timestamp)),cell,context,level,h('td',record.resource,'mono'));body.append(row);}table.append(body);scroll.append(table);target.append(scroll);
      const pager=h('div',undefined,'record-pager');pager.append(h('span',`${result.offset+1}–${Math.min(result.offset+result.limit,result.total)} of ${result.total} records`));const buttons=h('div');for(const [text,delta,disabled] of [['← Previous',-25,state.offset===0],['Next →',25,state.offset+25>=result.total]]){const button=h('button',text,'page-button');button.type='button';button.disabled=disabled;button.addEventListener('click',()=>{state.offset=Math.max(0,state.offset+delta);loadRecords();});buttons.append(button);}pager.append(buttons);target.append(pager);
    }catch(error){if(jobId===state.job?.id&&serial===state.querySerial)target.replaceChildren(note(error.message));}
  }
  async function comparisonView(){
    state.comparison=null;
    panel.replaceChildren(sectionTitle('Compare with a clean WordPress run'),message('A clean control exercises the same test sequence without installing the submitted plugin. Similar activity can be ordinary WordPress behavior; matching patterns never erase review indicators.'));
    const actions=h('div',undefined,'control-actions'),label=h('label','Clean-control reference','control-picker'),select=h('select');select.id='control-select';label.append(select);actions.append(label);
    const create=h('button','Run clean control','secondary');create.type='button';create.id='create-control';create.disabled=state.overview.mode!=='full'||!state.overview.counts.runtime;actions.append(create);panel.append(actions);
    const status=h('p',undefined,'inspection-note');status.id='control-status';panel.append(status);
    if(state.overview.mode==='static')status.textContent='Static-only runs have no runtime observations to compare.';
    const results=h('div');results.id='comparison-results';panel.append(results);
    select.addEventListener('change',()=>{state.control=select.value;state.compareOffset=0;loadComparison();});
    create.addEventListener('click',async()=>{create.disabled=true;try{const bootstrap=await request('/api/bootstrap');const result=await request(base()+'/control',{method:'POST',headers:{'X-VETO-Token':bootstrap.csrf_token}});state.control=result.job.id;status.textContent='Clean control queued. The submitted plugin will not be installed. Progress is also available in history.';await loadControls();}catch(error){status.textContent=error.message;create.disabled=false;}});
    await loadControls();
  }
  async function loadControls(){
    if(state.view!=='comparison'||!state.job)return;
    const jobId=state.job.id;
    try{
      const response=await request('/api/controls');if(state.view!=='comparison'||state.job?.id!==jobId)return;
      const select=byId('control-select');if(!select)return;
      const options=response.controls.filter(control=>control.id!==jobId);
      options.sort((a,b)=>Number(b.control_for===jobId)-Number(a.control_for===jobId));
      const empty=h('option','Choose a completed clean control');empty.value='';select.replaceChildren(empty);
      for(const control of options){const option=h('option',`${control.id.slice(0,8)} · ${when(control.created_at)} · ${control.status}${control.control_for===jobId?' · for this submission':''}`);option.value=control.id;option.disabled=control.status!=='completed';select.append(option);}
      const active=options.some(control=>control.control_for===jobId&&!['completed','failed','rejected','interrupted'].includes(control.status));
      byId('create-control').disabled=state.overview.mode!=='full'||!state.overview.counts.runtime||active;
      select.value=state.control;
      const selected=options.find(control=>control.id===state.control);
      if(selected&&selected.status!=='completed'){byId('control-status').textContent='Selected clean control: '+selected.status+'. No plugin is installed in that run.';return;}
      const signature=state.control+':'+(selected?.updated_at||'');
      if(state.control&&signature!==state.comparison){state.comparison=signature;loadComparison();}
    }catch(error){if(byId('control-status'))byId('control-status').textContent=error.message;}
  }
  async function loadComparison(){
    const target=byId('comparison-results'),controlId=state.control,jobId=state.job.id;if(!target)return;
    if(!controlId){target.replaceChildren();return;}
    target.replaceChildren(message('Comparing recorded patterns…'));
    try{
      const comparison=await request('/api/jobs/'+jobId+'/comparison?control='+encodeURIComponent(controlId));
      if(state.view!=='comparison'||state.job?.id!==jobId||state.control!==controlId)return;
      drawComparison(comparison,target);
    }catch(error){if(state.job?.id===jobId&&state.view==='comparison'){state.comparison=null;target.replaceChildren(note(error.message));}}
  }
  function drawComparison(comparison,target){
      const jobId=comparison.subject_job_id,controlId=comparison.control_job_id;
      target.replaceChildren();byId('control-status').textContent='Comparison reference: '+controlId;
      if(!comparison.compatible)target.append(note('Environments differ. Treat this comparison as descriptive only: '+comparison.mismatches.join('; ')));
      if(!comparison.subject_complete)target.append(note('The plugin analysis is incomplete. Only retained observations are compared; missing activity must not be treated as absent.'));
      target.append(message(`Patterns also observed in control: ${comparison.counts.also_observed_in_control||0}. Not observed in this control: ${comparison.counts.not_observed_in_control||0}. Control-only patterns: ${comparison.counts.control_only||0}.`,'comparison-stats'));
      for(const text of comparison.limitations)target.append(message(text));
      const scroll=h('div',undefined,'table-scroll'),table=h('table',undefined,'comparison-table'),thead=h('thead'),head=h('tr');for(const text of ['Pattern','Plugin run','Control run','Supporting records'])head.append(h('th',text));thead.append(head);table.append(thead);const body=h('tbody');
      for(const pattern of comparison.groups.slice(state.compareOffset,state.compareOffset+25)){const row=h('tr'),name=h('td');name.append(h('strong',(categories[pattern.category]||pattern.category)+' · '+readable(pattern.action)),h('span',pattern.resource_pattern,'mono'),h('span',readable(pattern.classification),'pattern-label'));const refs=h('td');for(const id of pattern.subject_record_ids.slice(0,3))refs.append(recordButton(id,jobId,'Plugin '+id));for(const id of pattern.control_record_ids.slice(0,3))refs.append(recordButton(id,controlId,'Control '+id));row.append(name,h('td',String(pattern.subject_count)),h('td',String(pattern.control_count)),refs);body.append(row);}table.append(body);scroll.append(table);target.append(scroll);
      const pager=h('div',undefined,'record-pager');pager.append(h('span',comparison.groups.length?`${state.compareOffset+1}–${Math.min(state.compareOffset+25,comparison.groups.length)} of ${comparison.groups.length} patterns`:'No recorded patterns'));const buttons=h('div');for(const [label,delta,disabled] of [['← Previous',-25,state.compareOffset===0],['Next →',25,state.compareOffset+25>=comparison.groups.length]]){const button=h('button',label,'page-button');button.type='button';button.disabled=disabled;button.addEventListener('click',()=>{state.compareOffset=Math.max(0,state.compareOffset+delta);drawComparison(comparison,target);});buttons.append(button);}pager.append(buttons);target.append(pager,message('Up to three references per side are shown for each pattern. All original records remain available in the filtered runtime view.'));
  }
  function render(){
    if(!state.overview)return;
    byId('investigation-panel').setAttribute('aria-labelledby','tab-'+state.view);
    for(const button of document.querySelectorAll('.investigation-tabs button')){const active=button.dataset.view===state.view;button.setAttribute('aria-selected',String(active));button.tabIndex=active?0:-1;}
    if(state.view==='overview')overview();else if(state.view==='coverage')coverage();else if(state.view==='comparison')comparisonView();else recordsView();
  }
  function changeView(view){state.view=view;state.offset=0;state.category='';state.severity='';state.stage='';state.source=view==='explorer'?(state.overview?.mode==='static'?'static':'runtime'):'';state.q='';state.querySerial++;render();}
  for(const button of document.querySelectorAll('.investigation-tabs button')){
    button.addEventListener('click',()=>changeView(button.dataset.view));
    button.addEventListener('keydown',event=>{const buttons=[...document.querySelectorAll('.investigation-tabs button')],index=buttons.indexOf(button);let next;if(event.key==='ArrowRight')next=(index+1)%buttons.length;else if(event.key==='ArrowLeft')next=(index-1+buttons.length)%buttons.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=buttons.length-1;else return;event.preventDefault();buttons[next].focus();changeView(buttons[next].dataset.view);});
  }
  async function selectJob(job){
    const key=job.id+':'+job.updated_at+':'+JSON.stringify(job.evidence);
    if(key===state.key&&(job.status!=='running'||Date.now()-state.lastLoad<5000))return;
    const changed=state.job?.id!==job.id;state.job=job;state.key=key;state.lastLoad=Date.now();
    if(changed){state.overview=null;state.view='explorer';state.offset=0;state.q='';state.category='';state.severity='';state.stage='';state.source=job.mode==='static'?'static':'runtime';state.control='';state.comparison=null;state.querySerial++;panel.replaceChildren(message('Loading retained evidence…'));}
    const revision=++state.revision;
    byId('investigation-empty').classList.add('hidden');byId('investigation-content').classList.remove('hidden');
    byId('investigation-subject').textContent=job.plugin_name||job.filename;byId('investigation-job').textContent=job.id;
    try{
      const model=await request('/api/jobs/'+job.id+'/investigation');if(revision!==state.revision)return;
      state.overview=model;byId('investigation-scope').textContent=model.assessment.code==='incomplete'?'INCOMPLETE COVERAGE':model.mode==='static'?'STATIC ONLY':model.mode==='control'?'CLEAN CONTROL':'STATIC + SANDBOX';
      const alert=byId('investigation-alert');alert.textContent=model.assessment.incomplete_reasons.join(' ');alert.classList.toggle('hidden',!model.assessment.incomplete_reasons.length);
      const editing=!changed&&panel.contains(document.activeElement)&&['INPUT','SELECT'].includes(document.activeElement.tagName);if(!editing)render();
    }catch(error){if(revision===state.revision){state.key='';panel.replaceChildren(note('Could not inspect this saved evidence: '+error.message));}}
  }
  window.VetoInvestigation={selectJob,open(view='explorer'){changeView(view);byId('investigation').scrollIntoView({behavior:'smooth',block:'start'});}};
  setInterval(()=>{if(state.view==='comparison')loadControls();},3000);
})();
