"""Evidence presentation and explicit review policy, independent of the UI.

No malware probability, verdict of safety, or causal attribution is inferred.
Raw findings/events are retained behind exact artifact/pointer references.
"""
from collections import Counter,defaultdict
from datetime import datetime,timezone
import json
import re
from urllib.parse import urlsplit,urlunsplit

POLICY_VERSION='1.0.0'
POLICY=[
    {'id':'REVIEW-S1','title':'Static review indicators','condition':'A configured static rule has medium or high severity.','limitation':'A matching pattern is not proof of reachable or malicious code.'},
    {'id':'REVIEW-R1','title':'Administrator privilege change','condition':'An account snapshot shows a new administrator, or a transition into administrator privileges.','limitation':'Provisioning tools can legitimately create administrators. Identity and authorization require review.'},
    {'id':'REVIEW-R2','title':'Sensitive WordPress file change','condition':'A file is written, deleted or moved into a core/configuration path, or a PHP file outside the submitted plugin, after installation.','limitation':'Legitimate maintenance may change these files; an event does not establish intent.'},
    {'id':'COVERAGE-C1','title':'Incomplete observation takes precedence','condition':'The job or a required analysis stage is incomplete, evidence is inconsistent, or required coverage/cleanup is unverified.','limitation':'Recorded review indicators remain visible even when a final assessment cannot be completed.'},
    {'id':'CONTROL-C1','title':'Control patterns do not erase evidence','condition':'A selected control also records a normalized runtime pattern.','limitation':'Shared patterns may have different values or causes; matching never discounts review indicators.'},
]
CATEGORIES=('file','database','account','cron','network')


def parsed(value):
    if isinstance(value,str):
        try:return json.loads(value)
        except (ValueError,TypeError):return value
    return value


def administrator(value):
    value=parsed(value)
    if not isinstance(value,dict):return False
    roles=value.get('roles','')
    if isinstance(roles,dict):return roles.get('administrator') is True
    # Match an enabled capability, not merely the word in an unrelated field.
    return bool(re.search(r's:13:"administrator";b:1;',str(roles)))


def runtime_review(event):
    category,action=event.get('category'),event.get('action')
    if category=='account' and action in ('created','modified') and administrator(event.get('after')) and not administrator(event.get('before')):
        return 'REVIEW-R1','high','Administrator privilege change observed'
    if category=='file' and event.get('execution_stage') not in ('baseline','install') and action in ('created','modified','closed_after_write','deleted','moved_to'):
        path=str(event.get('resource','')).replace('\\','/').lstrip('/').casefold()
        sensitive=path in ('wp-config.php','.htaccess') or path.startswith(('wp-admin/','wp-includes/','wp-content/mu-plugins/'))
        outside_php=path.endswith(('.php','.phtml','.php3','.php4','.php5')) and not path.startswith('wp-content/plugins/submission/')
        if sensitive or outside_php:return 'REVIEW-R2','medium','Sensitive WordPress file change observed'
    return None,'info',None


def explanation(event):
    category=event.get('category')
    collector=event.get('collector')
    action=event.get('action')
    if collector=='inotify':
        return ('The separate observer recorded a kernel file-system notification.',
                'This captures the path and action, including transient events; file contents and before/after values may not have been captured.')
    if collector=='mysql-general-log':
        return ('The database server logged a modifying SQL statement being requested.',
                'This is an attempted statement, not proof of success. Snapshot differences provide separate evidence of persistent changes.')
    if category=='network':
        meanings={
            'http_blocked':'A WordPress HTTP request was intercepted and blocked before establishing that HTTP connection.',
            'http_allowed':'The WordPress HTTP hook allowed a request to the controlled internal endpoint; this alone does not prove receipt.',
            'controlled_http_received':'The separate controlled endpoint received an HTTP request.',
            'dns_blocked':'The DNS sink recorded a lookup and returned NXDOMAIN without external forwarding.',
        }
        return meanings.get(action,'A network-related observation was recorded.'),('WordPress hooks can be spoofed or disabled. DNS evidence is not proof of a TCP connection; selected monitoring is not exhaustive syscall tracing.')
    descriptions={'file':'File hashes or existence changed between snapshots.','database':'Table content/schema hashes or row counts changed between snapshots.',
                  'account':'User identity or role state changed between direct SQL snapshots.','cron':'The scheduled-task state changed between direct SQL snapshots.'}
    return descriptions.get(category,'A runtime observation was recorded.'),'Snapshots can miss states reverted between samples. Association with execution does not establish malicious intent.'


def source_trace(job,artifact,pointer,analysis_id,evidence_ids=(),file_hash=None):
    return {'job_id':job['id'],'artifact':artifact,'json_pointer':pointer,'analysis_id':analysis_id,
            'package_sha256':job['sha256'],'file_sha256':file_hash,'evidence_ids':list(evidence_ids)}


def timestamp_key(record):
    try:return datetime.fromisoformat(record['timestamp'].replace('Z','+00:00')).timestamp(),record['record_id']
    except (ValueError,TypeError,KeyError,AttributeError):return float('inf'),record['record_id']


def build_inspection(job,evidence,progress,recovery=None):
    static_final=evidence.get('static')
    dynamic_final=evidence.get('dynamic')
    static=static_final or (evidence.get('partial_static') or {}).get('report')
    dynamic=dynamic_final or (evidence.get('partial_dynamic') or {}).get('report')
    static_artifact='static.json' if static_final else 'static.partial.json'
    dynamic_artifact='dynamic.json' if dynamic_final else 'dynamic.partial.json'
    static_prefix='' if static_final else '/report'
    dynamic_prefix='' if dynamic_final else '/report'
    integrity=[]
    from .__main__ import validate_report
    from .dynamic import validate_dynamic
    for title,value,validator in (('Static',static_final,validate_report),('Dynamic',dynamic_final,validate_dynamic)):
        if value:
            try:validator(value)
            except Exception as exc:integrity.append(title+' evidence does not satisfy its declared contract: '+type(exc).__name__)
    for title,value in (('Static',static),('Dynamic',dynamic)):
        if value:
            source_static=value.get('static_result',{}) if title=='Dynamic' else value
            if source_static.get('package',{}).get('sha256') not in (None,job['sha256']):integrity.append(title+' package hash differs from the submission record')
            if job.get('analysis_id') and value.get('analysis_id')!=job['analysis_id']:integrity.append(title+' analysis ID differs from the submission record')
    if static and dynamic and static.get('analysis_id')!=dynamic.get('analysis_id'):integrity.append('Static and dynamic evidence analysis IDs differ')
    is_control=job['mode']=='control'
    records=[]
    factors=[]
    inventory={item['path']:item['sha256'] for item in (static or {}).get('inventory',[])}
    for index,finding in enumerate((static or {}).get('static_findings',[])):
        record={'record_id':'S:'+finding['finding_id'],'source':'static','category':'static','severity':finding['severity'],
                'stage':'scanning','timestamp':finding['observed_at'],'title':finding['title'],'resource':finding.get('path') or '(package)',
                'rule_id':finding['rule_id'],'engine':finding['engine'],'action':'indicator','line':finding['line'],'byte_offset':finding['byte_offset'],
                'occurrence_count':finding['occurrence_count'],'before':None,'after':None,'rationale':finding['rationale'],'limitation':finding['limitation'],
                'evidence':[finding['evidence']],'raw':finding,
                'trace':source_trace(job,static_artifact,f'{static_prefix}/static_findings/{index}',(static or {}).get('analysis_id'),[finding['evidence']['id']],inventory.get(finding.get('path')))}
        records.append(record)
        if not is_control and finding['severity'] in ('medium','high'):
            factors.append({'policy_id':'REVIEW-S1','severity':finding['severity'],'title':finding['title'],
                            'explanation':finding['rationale'],'limitation':finding['limitation'],'record_ids':[record['record_id']]})
    runtime_records=[]
    grouped={}
    for index,event in enumerate((dynamic or {}).get('runtime_events',[])):
        policy_id,severity,title=runtime_review(event)
        rationale,limitation=explanation(event)
        record={'record_id':'R:'+event['event_id'],'source':'runtime','category':event['category'],'severity':severity,
                'stage':event['execution_stage'],'timestamp':event['observed_at'],'title':event['action'].replace('_',' ').capitalize(),
                'resource':event['resource'],'rule_id':policy_id,'engine':event['collector'],'action':event['action'],'line':None,'byte_offset':None,
                'occurrence_count':1,'before':event['before'],'after':event['after'],'rationale':rationale,'limitation':limitation,
                'evidence':event['evidence'],'raw':event,
                'trace':source_trace(job,dynamic_artifact,f'{dynamic_prefix}/runtime_events/{index}',(dynamic or {}).get('analysis_id'),[item['id'] for item in event['evidence']])}
        records.append(record);runtime_records.append(record)
        if policy_id and not is_control:
            key=policy_id,event['category'],event['resource']
            if key not in grouped:
                grouped[key]={'policy_id':policy_id,'severity':severity,'title':title,'explanation':rationale,
                              'limitation':next(rule['limitation'] for rule in POLICY if rule['id']==policy_id),'record_ids':[]}
            grouped[key]['record_ids'].append(record['record_id'])
    factors.extend(grouped.values())
    for index,event in enumerate(progress):
        records.append({'record_id':'P:'+str(event['seq']),'source':'workflow','category':'workflow','severity':'info',
                        'stage':event['stage'],'timestamp':event['observed_at'],'title':event['stage'].replace('_',' ').capitalize(),
                        'resource':'Analysis workflow','action':'stage_update','rule_id':None,'engine':'job-service','line':None,'byte_offset':None,
                        'occurrence_count':1,'before':None,'after':None,'rationale':event['detail'],
                        'limitation':'Workflow updates describe orchestration, not plugin behavior.','evidence':[{'id':'P'+str(event['seq']),'text':event['detail']}],
                        'raw':event,'trace':{'job_id':job['id'],'artifact':'history.sqlite3','json_pointer':None,'progress_seq':event['seq'],
                                           'analysis_id':job.get('analysis_id'),'package_sha256':job['sha256'],'file_sha256':None,'evidence_ids':['P'+str(event['seq'])]}})
    records.sort(key=timestamp_key)
    incomplete=[]
    if job['status']!='completed':incomplete.append('Job status is '+job['status']+'; this is not a completed analysis.')
    if not static_final or static_final.get('status')!='completed':incomplete.append('Static analysis did not produce a completed final result.')
    if job['mode']!='static':
        if not dynamic_final or dynamic_final.get('status')!='completed':incomplete.append('Sandbox execution did not produce a completed final result.')
        missing=[category for category in CATEGORIES if not (dynamic or {}).get('coverage',{}).get(category)]
        if missing:incomplete.append('Required runtime coverage is unverified: '+', '.join(missing)+'.')
        if not (dynamic or {}).get('cleanup',{}).get('verified') and not (recovery or {}).get('verified'):
            incomplete.append('Sandbox cleanup is not verified.')
        if job.get('cleanup_verified')==0:incomplete.append('The job record still requires sandbox cleanup recovery.')
    incomplete.extend(integrity)
    # These labels are review decisions, never a probability or proof of safety.
    if incomplete:code,label='incomplete','Incomplete analysis — review available evidence'
    elif is_control:code,label='control_observation','Clean-control observation'
    elif factors:code,label='review_recommended','Review recommended'
    elif records and any(record['source']!='workflow' for record in records):code,label='observations_only','Capabilities and activity observed'
    else:code,label='no_indicators_observed','No configured indicators observed'
    scope='Static inspection only; the plugin was not executed.' if job['mode']=='static' else ('No submitted plugin was installed in this control run.' if is_control else 'Static inspection and selected sandbox behavior.')
    summary={'incomplete':'A final review conclusion is unavailable. Retained indicators and observations remain useful.',
             'control_observation':'This records normal WordPress activity under the selected test sequence. It is a comparison reference, not a plugin verdict.',
             'review_recommended':'One or more explicit review rules matched. Inspect the linked evidence and intended plugin functionality.',
             'observations_only':'The configured checks recorded capabilities or activity without triggering an elevated review rule.',
             'no_indicators_observed':'No configured static indicator was recorded in the completed scope. This does not establish safety.'}[code]
    coverage=[]
    for category in ('static',*CATEGORIES):
        state=('completed' if static_final and static_final.get('status')=='completed' else 'incomplete') if category=='static' else ('not_run' if job['mode']=='static' else 'completed' if (dynamic or {}).get('coverage',{}).get(category) else 'incomplete')
        coverage.append({'category':category,'state':state})
    errors=[{'source':'static',**error} for error in (static or {}).get('errors',[])]+[{'source':'dynamic',**error} for error in (dynamic or {}).get('errors',[])]
    limits=list(dict.fromkeys([*(static or {}).get('limitations',[]),*(dynamic or {}).get('limitations',[]),
        'Severity and review rules are explainable heuristics, not a malware probability or validation of intent.',
        'Recorded timestamps are observation times; ordering does not prove causation.',
        'A clean-control pattern match never proves that an individual event is benign.']))
    resources=Counter((record['category'],record['resource']) for record in runtime_records)
    return {'overview':{'job_id':job['id'],'filename':job['filename'],'plugin_name':job.get('plugin_name'),'status':job['status'],'mode':job['mode'],
            'assessment':{'policy_version':POLICY_VERSION,'code':code,'label':label,'summary':summary,'scope':scope,'factors':factors,'incomplete_reasons':incomplete,
                          'priority':'undetermined' if incomplete else 'elevated' if any(f['severity']=='high' for f in factors) else 'review' if factors else 'observational'},
            'counts':{'static':len((static or {}).get('static_findings',[])),'runtime':len(runtime_records),'workflow':len(progress),
                      'categories':dict(Counter(record['category'] for record in runtime_records)),'affected_resources':len(resources),'inventory_files':len(inventory)},
            'coverage':coverage,'limitations':limits,'errors':errors,'integrity_issues':integrity,'policy':POLICY,
            'resources':[{'category':category,'resource':resource,'observations':count} for (category,resource),count in sorted(resources.items())],
            'stages':sorted({record['stage'] for record in records}),'cleanup_verified':(dynamic or {}).get('cleanup',{}).get('verified') or (recovery or {}).get('verified',False),
            'partial':not bool(static_final) or (job['mode']!='static' and not bool(dynamic_final))},
            'records':records,'dynamic':dynamic}


def query_records(model,*,source='',category='',severity='',stage='',q='',offset=0,limit=50):
    if source not in ('','static','runtime','workflow'):raise ValueError('Invalid evidence source')
    if category not in ('','static','workflow',*CATEGORIES):raise ValueError('Invalid category')
    if severity not in ('','high','medium','low','info'):raise ValueError('Invalid severity')
    if len(q)>200 or len(stage)>80 or not 0<=offset<=1000000 or not 1<=limit<=100:raise ValueError('Invalid evidence query')
    tokens=q.casefold().split()
    matches=[]
    for record in model['records']:
        if any(value and record[key]!=value for key,value in (('source',source),('category',category),('severity',severity),('stage',stage))):continue
        haystack=json.dumps(record,ensure_ascii=False).casefold() if tokens else ''
        if tokens and not all(token in haystack for token in tokens):continue
        matches.append(record)
    return {'items':matches[offset:offset+limit],'total':len(matches),'offset':offset,'limit':limit}


def runtime_signature(record):
    resource=record['resource']
    if record['category']=='cron':resource=re.sub(r'^\d+:','',resource)
    elif record['category']=='account':
        account=parsed(record['after']) or parsed(record['before'])
        if isinstance(account,dict):resource=account.get('user_login',resource)
    elif record['category']=='network':
        try:
            parts=urlsplit(resource)
            if parts.scheme:resource=urlunsplit((parts.scheme.casefold(),parts.netloc.casefold(),parts.path,'',''))
            else:resource=resource.casefold().rstrip('.')
        except ValueError:pass
    elif record['engine']=='mysql-general-log':
        text=' '.join(str(item.get('text','')) for item in record['evidence'])
        match=re.match(r'\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|TRUNCATE\s+TABLE)\s+`?([\w.]+)',text,re.I)
        if match:resource=match[1].upper()+' '+match[2].casefold()
    return record['category'],record['action'],resource,record['engine']


def compare_control(subject,control):
    if subject['overview']['job_id']==control['overview']['job_id']:raise ValueError('Choose a separate clean-control run')
    if control['overview']['mode']!='control' or control['overview']['assessment']['code']!='control_observation':raise ValueError('Choose a completed, fully observed clean-control run')
    if not subject['dynamic'] or not control['dynamic']:raise ValueError('Both runs need retained sandbox observations')
    if control['dynamic'].get('mode')!='clean_control':raise ValueError('The selected run did not execute as a clean control')
    mismatches=[]
    for role in ('sandbox','database','cli'):
        a=subject['dynamic'].get('images',{}).get(role,{}).get('id')
        b=control['dynamic'].get('images',{}).get(role,{}).get('id')
        if not a or a!=b:mismatches.append(role+' image differs or is unrecorded')
    for key in ('action_timeout','observation_seconds'):
        if subject['dynamic'].get('configuration',{}).get(key)!=control['dynamic'].get('configuration',{}).get(key):mismatches.append(key+' differs')
    groups=[]
    a=defaultdict(list);b=defaultdict(list)
    for record in subject['records']:
        if record['source']=='runtime':a[runtime_signature(record)].append(record['record_id'])
    for record in control['records']:
        if record['source']=='runtime':b[runtime_signature(record)].append(record['record_id'])
    for key in sorted(a.keys()|b.keys()):
        groups.append({'category':key[0],'action':key[1],'resource_pattern':key[2],'collector':key[3],
                       'subject_count':len(a[key]),'control_count':len(b[key]),'subject_record_ids':a[key],'control_record_ids':b[key],
                       'classification':'also_observed_in_control' if a[key] and b[key] else 'not_observed_in_control' if a[key] else 'control_only'})
    return {'subject_job_id':subject['overview']['job_id'],'control_job_id':control['overview']['job_id'],
            'compatible':not mismatches,'mismatches':mismatches,'subject_complete':subject['overview']['assessment']['code']!='incomplete',
            'policy_unchanged':True,'groups':groups,
            'counts':dict(Counter(group['classification'] for group in groups)),
            'limitations':['Patterns compare category, action, normalized resource and collector, not identical contents or causes.',
                'Cron timestamps and HTTP query strings are normalized; SQL patterns use operation/table when available.',
                'Similar patterns may contain different values; absence from one control is not proof that the plugin caused an event.',
                'No review indicator or incomplete-coverage warning is suppressed by this comparison.']}
