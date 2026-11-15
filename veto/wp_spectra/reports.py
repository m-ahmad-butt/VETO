"""Immutable PDF/JSON report pairs from a consistent retained-evidence snapshot."""
from copy import deepcopy
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import threading
import uuid
from xml.sax.saxutils import escape

from .app_store import MAX_ARTIFACT,atomic_json,read_json
from .investigation import build_inspection,compare_control
from .scanner import now
from .scanner import RESOURCES

REPORT_VERSION='1.0.0'
ARTIFACTS=('static.json','static.partial.json','dynamic.json','dynamic.partial.json','recovery.json')


def encoded(value):return (json.dumps(value,ensure_ascii=True,sort_keys=True,indent=2)+'\n').encode('utf-8')
def digest(value):return hashlib.sha256(value).hexdigest()


def capture(service,job_id):
    """Check signatures on both sides of a read; never mix changing artifacts."""
    directory=service.store.directory(job_id)
    for _ in range(3):
        job=service.store.get(job_id)
        if not job:raise ValueError('Analysis not found')
        if job['status'] in ('queued','running'):raise ValueError('Wait for this analysis to finish before exporting its retained outcome')
        before={name:(directory/name).read_bytes() for name in ARTIFACTS if (directory/name).exists()}
        evidence={key:json.loads(before[name]) if name in before else None for key,name in (
            ('static','static.json'),('partial_static','static.partial.json'),('dynamic','dynamic.json'),('partial_dynamic','dynamic.partial.json'))}
        progress=service.store.progress(job_id)
        after={name:(directory/name).read_bytes() for name in ARTIFACTS if (directory/name).exists()}
        latest=service.store.get(job_id)
        if before!=after or latest['updated_at']!=job['updated_at']:continue
        recovery=json.loads(before['recovery.json']) if 'recovery.json' in before else None
        model=build_inspection(job,evidence,progress,recovery)
        selected={}
        for final,partial in (('static.json','static.partial.json'),('dynamic.json','dynamic.partial.json')):
            name=final if final in before else partial
            if name in before:selected[name]=json.loads(before[name])
        if recovery is not None:selected['recovery.json']=recovery
        return {'job':job,'model':model,'artifacts':selected,'manifest':[
            {'name':name,'sha256':digest(before[name]),'canonical_json_sha256':digest(encoded(selected[name])),'size_bytes':len(before[name])} for name in selected]}
    raise ValueError('Evidence changed during capture; retry the export')


def bundle(service,job_id,control_id=None):
    source=capture(service,job_id)
    comparison=None;control=None
    if control_id:
        control=capture(service,control_id)
        comparison=compare_control(source['model'],control['model'])
    keep=('id','filename','mode','sha256','size_bytes','status','stage','detail','created_at','updated_at','finished_at','analysis_id','plugin_name','cleanup_verified','control_for')
    model=source['model']
    value={'report_schema_version':REPORT_VERSION,'export_id':str(uuid.uuid4()),'generated_at':now(),
           'job':{key:source['job'].get(key) for key in keep},'overview':deepcopy(model['overview']),
           'records':[{key:item for key,item in record.items() if key!='raw'} for record in model['records']],
           'workflow':[record['raw'] for record in model['records'] if record['source']=='workflow'],
           'artifacts':source['artifacts'],'source_artifact_manifest':source['manifest'],
           'comparison':comparison,'control':None,
           'presentation':{'pdf':'Readable report with every record indexed. Long index fields are explicitly excerpted; JSON preserves full evidence and raw artifacts.',
                           'unicode':'PDF uses escaped codepoints for unsupported non-ASCII text; JSON preserves exact values.',
                           'snapshot':'Assessment and evidence are frozen at export creation; later policy or evidence changes do not rewrite this pair.'}}
    if control:
        value['control']={'job':{key:control['job'].get(key) for key in keep},'overview':deepcopy(control['model']['overview']),
                          'artifacts':control['artifacts'],'source_artifact_manifest':control['manifest']}
    validate_bundle(value)
    return value


def validate_bundle(value):
    from jsonschema import Draft202012Validator,FormatChecker
    schema=json.loads((RESOURCES/'report.schema.json').read_text(encoding='utf-8'))
    Draft202012Validator(schema,format_checker=FormatChecker()).validate(value)
    if value.get('report_schema_version')!=REPORT_VERSION:raise ValueError('Unsupported report schema')
    uuid.UUID(value['export_id']);uuid.UUID(value['job']['id'])
    records=value['records'];overview=value['overview'];counts=overview['counts']
    for kind in ('static','runtime','workflow'):
        if sum(record['source']==kind for record in records)!=counts[kind]:raise ValueError('Export counts do not match records')
    if len({r['record_id'] for r in records})!=len(records):raise ValueError('Duplicate export record IDs')
    for artifact in value['source_artifact_manifest']:
        if digest(encoded(value['artifacts'][artifact['name']]))!=artifact['canonical_json_sha256']:raise ValueError('Exported artifact differs from its canonical content checksum')
    for record in records:
        trace=record['trace']
        if trace['job_id']!=value['job']['id']:raise ValueError('Record belongs to another job')
        if record['source']=='workflow':
            original=next((item for item in value['workflow'] if item['seq']==trace['progress_seq']),None)
            if not original or original['detail']!=record['rationale']:raise ValueError('Workflow evidence reference mismatch')
            continue
        node=value['artifacts'][trace['artifact']]
        for part in trace['json_pointer'].strip('/').split('/'):
            node=node[int(part)] if isinstance(node,list) else node[part]
        if record['source']=='static':
            if node['finding_id']!=record['record_id'][2:]:raise ValueError('Static evidence reference mismatch')
            if record['evidence']!=[node['evidence']]:raise ValueError('Static evidence content mismatch')
        elif node['event_id']!=record['record_id'][2:]:raise ValueError('Runtime evidence reference mismatch')
        elif record['evidence']!=node['evidence'] or record['before']!=node['before'] or record['after']!=node['after']:raise ValueError('Runtime evidence content mismatch')
    if value['job']['status']!='completed' and overview['assessment']['code']!='incomplete':raise ValueError('Incomplete jobs cannot export a completed assessment')


def ascii_text(value):
    text=str(value)
    text=text.translate(str.maketrans({'\u2014':' - ','\u2013':'-','\u2011':'-','\u2019':"'",'\u2018':"'",'\u201c':'"','\u201d':'"','\u2026':'...','\u2022':'-'}))
    return ''.join(c if c in '\n\t' or 32<=ord(c)<127 else c.encode('unicode_escape').decode('ascii') for c in text)


def pdf_bytes(report,json_sha256):
    from reportlab.lib import colors
    from reportlab.lib.enums import TA_LEFT
    from reportlab.lib.pagesizes import A4
    from reportlab.lib.styles import ParagraphStyle
    from reportlab.platypus import SimpleDocTemplate,Paragraph,Spacer,PageBreak,Table,TableStyle,KeepTogether
    if len(report['records'])>10000:raise ValueError('Readable PDF budget exceeded: maximum 10,000 indexed records per export')
    out=io.BytesIO();green=colors.HexColor('#17614c');ink=colors.HexColor('#203c31');muted=colors.HexColor('#667b6c')
    styles={
        'title':ParagraphStyle('title',fontName='Helvetica-Bold',fontSize=25,leading=31,textColor=ink,spaceAfter=15),
        'h1':ParagraphStyle('h1',fontName='Helvetica-Bold',fontSize=15,leading=20,textColor=green,spaceBefore=16,spaceAfter=10,keepWithNext=True),
        'h2':ParagraphStyle('h2',fontName='Helvetica-Bold',fontSize=11,leading=15,textColor=ink,spaceBefore=10,spaceAfter=6,keepWithNext=True),
        'body':ParagraphStyle('body',fontName='Helvetica',fontSize=9,leading=13.5,textColor=ink,spaceAfter=7,splitLongWords=True),
        'small':ParagraphStyle('small',fontName='Helvetica',fontSize=7.5,leading=11,textColor=muted,spaceAfter=5,splitLongWords=True),
        'code':ParagraphStyle('code',fontName='Courier',fontSize=7.5,leading=11,textColor=ink,spaceAfter=8,splitLongWords=True),
    }
    def p(text,kind='body'):return Paragraph(escape(ascii_text(text)).replace('\n','<br/>'),styles[kind])
    def excerpt(text,limit=260):
        text=ascii_text(text)
        return text if len(text)<=limit else text[:limit]+' [excerpt; full value in JSON]'
    story=[]
    def add(text,kind='body'):story.append(p(text,kind))
    def heading(text):add(text,'h1')
    overview=report['overview'];assessment=overview['assessment'];job=report['job']
    add('VETO / WP SPECTRA','small');add('Plugin investigation report','title')
    add(job['plugin_name'] or job['filename'],'h2');add(assessment['label'],'h1')
    add(assessment['summary']);add(assessment['scope'])
    if assessment['code']=='incomplete':add('INCOMPLETE ANALYSIS: retained evidence is partial. No completed safety conclusion is available.','h2')
    for reason in assessment['incomplete_reasons']:add('- '+reason)
    rows=[('Report ID',report['export_id']),('Generated (UTC)',report['generated_at']),('Job ID',job['id']),('Engine analysis ID',job['analysis_id'] or 'Not available'),
          ('Submitted package',job['filename']),('Status / mode',job['status']+' / '+job['mode']),('Submitted / finished',str(job['created_at'])+' / '+str(job['finished_at'])),
          ('Package SHA-256',job['sha256']),('Review policy',assessment['policy_version']),('Static / runtime / workflow records',f"{overview['counts']['static']} / {overview['counts']['runtime']} / {overview['counts']['workflow']}")]
    table=Table([[p(key,'small'),p(value,'body')] for key,value in rows],colWidths=[127,364],hAlign='LEFT')
    table.setStyle(TableStyle([('VALIGN',(0,0),(-1,-1),'TOP'),('BACKGROUND',(0,0),(-1,-1),colors.HexColor('#f3f7ef')),('BOX',(0,0),(-1,-1),.5,colors.HexColor('#dce5d6')),('LEFTPADDING',(0,0),(-1,-1),10),('RIGHTPADDING',(0,0),(-1,-1),10),('TOPPADDING',(0,0),(-1,-1),6),('BOTTOMPADDING',(0,0),(-1,-1),4)]))
    story.append(table);story.append(Spacer(1,12))
    add('Companion JSON SHA-256: '+json_sha256,'code')
    add('Completion is not proof of safety. Counts describe recorded evidence, not numbers of threats. The matching JSON contains complete retained artifacts and all source references.','small')
    story.append(PageBreak());heading('1. Scope, execution and coverage')
    for item in overview['coverage']:add(item['category'].upper()+': '+item['state'])
    add('Sandbox cleanup: '+('not applicable (static-only; no plugin execution)' if job['mode']=='static' else 'verified' if overview['cleanup_verified'] else 'not verified'))
    static_name=next((name for name in report['artifacts'] if name.startswith('static')),None)
    static=report['artifacts'].get(static_name,{})
    if static_name and static_name.endswith('.partial.json'):static=static.get('report',{})
    if static:
        add('Static execution configuration','h2');add(json.dumps(static.get('configuration',{}),sort_keys=True,indent=2),'code')
    add('Retained artifact identities','h2')
    for artifact in report['source_artifact_manifest']:add(artifact['name']+' / '+artifact['sha256']+' / '+str(artifact['size_bytes'])+' bytes','code')
    dynamic_name=next((name for name in report['artifacts'] if name.startswith('dynamic')),None)
    dynamic=report['artifacts'].get(dynamic_name,{})
    if dynamic_name and dynamic_name.endswith('.partial.json'):dynamic=dynamic.get('report',{})
    if dynamic:
        add('Execution configuration','h2');add(json.dumps(dynamic.get('configuration',{}),sort_keys=True,indent=2),'code')
        for role,image in dynamic.get('images',{}).items():add(role+': '+str(image.get('reference'))+' / '+str(image.get('id')),'small')
        for stage in dynamic.get('stages',[]):add(stage['stage']+': '+stage['status']+'; '+stage['started_at']+' to '+str(stage['finished_at']),'small')
    else:add('No sandbox execution evidence was retained. Static-only runs do not establish runtime coverage.')
    if overview['errors']:
        add('Recorded failures','h2')
        for error in overview['errors']:add(error['source']+' / '+error['stage']+' / '+error['code']);add(excerpt(error['message'],1200),'code')
    heading('2. Explainable review reasons')
    by_id={r['record_id']:r for r in report['records']}
    if not assessment['factors']:add('No elevated review rule matched in the available scope. This does not establish safety.')
    for factor in assessment['factors']:
        add(factor['policy_id']+' / '+factor['severity'].upper()+' / '+factor['title'],'h2')
        add(factor['explanation']);add('Limit: '+factor['limitation']);add('Supporting records: '+', '.join(factor['record_ids']),'code')
        for record_id in factor['record_ids']:
            record=by_id[record_id]
            if record['source']=='runtime':
                add(record_id+' / '+record['resource'],'small')
                add('Before: '+str(record['before'] if record['before'] is not None else 'Not captured / absent as described by the collector'),'code')
                add('After: '+str(record['after'] if record['after'] is not None else 'Not captured / absent as described by the collector'),'code')
    heading('3. Static findings and evidence')
    findings=[r for r in report['records'] if r['source']=='static']
    if not findings:add('No retained static findings. Read scope and coverage before interpreting this absence.')
    for record in findings:
        add(record['record_id']+' / '+record['rule_id']+' / '+record['severity'].upper()+' / '+record['title'],'h2')
        add('Resource: '+record['resource']+'; line '+str(record['line'])+'; byte offset '+str(record['byte_offset'])+'; occurrences '+str(record['occurrence_count']))
        if record['trace']['file_sha256']:add('File SHA-256: '+record['trace']['file_sha256'],'code')
        add(record['rationale']);add('Limit: '+record['limitation'])
        for evidence in record['evidence']:add(evidence['id']+': '+evidence['text'],'code')
        add('Source: '+record['trace']['artifact']+' '+record['trace']['json_pointer'],'small')
    heading('4. Observed resources')
    for resource in overview['resources']:add(resource['category']+' / '+excerpt(resource['resource'],220)+' / observations: '+str(resource['observations']),'small')
    if not overview['resources']:add('No runtime resources recorded in the available scope.')
    if report['comparison']:
        heading('5. Clean-control comparison');comparison=report['comparison']
        add('Reference job: '+comparison['control_job_id'])
        add('Environment compatibility: '+('matching recorded settings' if comparison['compatible'] else 'DIFFERENT: descriptive comparison only'))
        for mismatch in comparison['mismatches']:add('- '+mismatch)
        if not comparison['subject_complete']:add('Subject execution is incomplete; missing observations are not evidence of absence.')
        for group in comparison['groups']:add(group['category']+' / '+group['action']+' / '+excerpt(group['resource_pattern'],150)+' / subject '+str(group['subject_count'])+' / control '+str(group['control_count'])+' / '+group['classification'],'small')
        for limitation in comparison['limitations']:add(limitation,'small')
    else:heading('5. Clean-control comparison');add('No control was selected for this export. No control-based interpretation is implied.')
    heading('6. Policy and interpretation limits')
    for rule in overview['policy']:add(rule['id']+' - '+rule['title'],'h2');add(rule['condition']);add('Limit: '+rule['limitation'])
    for limitation in overview['limitations']:add('- '+limitation,'small')
    add(report['presentation']['pdf'],'small');add(report['presentation']['unicode'],'small')
    story.append(PageBreak());heading('7. Complete chronological record index')
    add(f"Index records: {len(report['records'])}. Every retained static, runtime and workflow record is represented below. Long resource and evidence fields are explicitly excerpted; use the companion JSON for exact values and raw records.")
    for record in report['records']:
        block_start=len(story)
        add(record['record_id']+' / '+record['source']+' / '+record['category']+' / '+record['severity'],'h2')
        add(record['timestamp']+' / '+record['stage']+' / '+record['action']+' / '+record['title'],'small')
        add('Resource: '+excerpt(record['resource'],240),'small')
        trace=record['trace'];add('Source: '+trace['artifact']+' '+str(trace.get('json_pointer') or 'progress row '+str(trace.get('progress_seq'))),'small')
        for evidence in record['evidence']:add(evidence['id']+': '+excerpt(evidence['text'],280),'code')
        record_block=story[block_start:];del story[block_start:];story.append(KeepTogether(record_block))
    def decorate(canvas,doc):
        canvas.saveState();width,height=A4
        canvas.setStrokeColor(colors.HexColor('#dbe5d5'));canvas.line(52,height-40,width-52,height-40)
        canvas.setFont('Helvetica',8);canvas.setFillColor(muted);canvas.drawString(52,height-30,'VETO: WP Spectra / Investigation evidence')
        canvas.drawString(52,30,report['export_id']);canvas.drawRightString(width-52,30,'Page '+str(doc.page))
        canvas.restoreState()
    doc=SimpleDocTemplate(out,pagesize=A4,leftMargin=52,rightMargin=52,topMargin=58,bottomMargin=50,
                         title='VETO investigation report '+report['export_id'],author='VETO: WP Spectra',pageCompression=1)
    doc.build(story,onFirstPage=decorate,onLaterPages=decorate)
    data=out.getvalue()
    if len(data)>32*1024**2:raise ValueError('Generated PDF exceeds the 32 MiB limit')
    return data


class ReportStore:
    def __init__(self,service):self.service=service;self.lock=threading.Lock()
    def root(self,job_id):return self.service.store.directory(job_id)/'exports'
    def list(self,job_id):
        self.service.store.directory(job_id)
        root=self.root(job_id)
        if not root.exists():return []
        result=[]
        for path in root.iterdir():
            if path.is_dir() and not path.name.startswith('.'):
                value=read_json(path/'manifest.json')
                if value:result.append(value)
        return sorted(result,key=lambda x:x['created_at'],reverse=True)
    def create(self,job_id,control_id=None):
        if not self.lock.acquire(blocking=False):raise ValueError('Another report is being generated; retry shortly')
        temporary=None
        try:
            root=self.root(job_id)
            if root.exists() and len(list(root.iterdir()))>=30:raise ValueError('This job already has 30 saved exports; use an existing pair')
            report=bundle(self.service,job_id,control_id)
            json_data=encoded(report)
            if len(json_data)>MAX_ARTIFACT:raise ValueError('Report JSON exceeds the 64 MiB export limit')
            pdf_data=pdf_bytes(report,digest(json_data))
            root.mkdir(exist_ok=True)
            temporary=root/('.'+report['export_id']+'.pending');temporary.mkdir()
            files={'report.json':json_data,'report.pdf':pdf_data}
            manifest={'export_id':report['export_id'],'job_id':job_id,'created_at':report['generated_at'],
                      'report_schema_version':REPORT_VERSION,'policy_version':report['overview']['assessment']['policy_version'],
                      'job_status':report['job']['status'],'assessment_code':report['overview']['assessment']['code'],
                      'counts':report['overview']['counts'],'control_job_id':control_id,
                      'files':{name:{'sha256':digest(data),'size_bytes':len(data)} for name,data in files.items()}}
            for name,data in files.items():
                with (temporary/name).open('xb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
            atomic_json(temporary/'manifest.json',manifest)
            os.replace(temporary,root/report['export_id']);temporary=None
            return manifest
        finally:
            try:
                if temporary is not None and temporary.exists():
                    if not temporary.resolve().is_relative_to(self.root(job_id).resolve()):raise RuntimeError('Temporary report path escaped its export directory')
                    shutil.rmtree(temporary)
            finally:self.lock.release()
    def download(self,job_id,export_id,filename):
        if str(uuid.UUID(export_id))!=export_id or filename not in ('report.json','report.pdf'):raise ValueError('Invalid report download')
        root=self.root(job_id)/export_id
        manifest=read_json(root/'manifest.json')
        if not manifest or manifest['job_id']!=job_id:raise ValueError('Report not found')
        data=(root/filename).read_bytes()
        if digest(data)!=manifest['files'][filename]['sha256']:raise ValueError('Saved report checksum differs; download refused')
        return data,'application/pdf' if filename.endswith('.pdf') else 'application/json'
