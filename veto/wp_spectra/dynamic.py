"""Phase 2 host controller. Docker operations use argv, never shell strings.

Only explicitly run-owned, labeled resources are destroyed. No host filesystem or
Docker socket is mounted into the submitted plugin's container.
"""
from dataclasses import asdict, dataclass
import io
import json
from pathlib import Path
import secrets
import subprocess
import tarfile
import tempfile
import threading
import time
import uuid

from .intake import Limits, stage_archive
from .scanner import RESOURCES, analyze, now


@dataclass(frozen=True)
class DynamicConfig:
    sandbox_image: str = 'veto-sandbox:phase2'
    database_image: str = 'mysql:8.0'
    cli_image: str = 'wordpress:cli-php8.3'
    startup_timeout: int = 120
    action_timeout: int = 15
    observation_seconds: int = 3
    job_timeout: int = 300

    def __post_init__(self):
        for key in ('startup_timeout','action_timeout','observation_seconds','job_timeout'):
            if type(getattr(self,key)) is not int or not 1 <= getattr(self,key) <= 900:
                raise ValueError(f'{key} must be an integer between 1 and 900')


class DockerError(RuntimeError):
    pass


def bounded_process(argv, *, data=None, timeout=30, max_output=32*1024*1024):
    """Drain pipes concurrently with a hard output cap; no shell or disk spool."""
    process=subprocess.Popen(argv,stdin=subprocess.PIPE if data is not None else subprocess.DEVNULL,
                             stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    streams=[bytearray(),bytearray()]
    overflow=threading.Event()
    lock=threading.Lock()
    def reader(pipe,index):
        try:
            while chunk:=pipe.read1(65536):
                with lock:
                    if sum(map(len,streams))+len(chunk)>max_output:
                        overflow.set()
                        process.kill()
                        break
                    streams[index].extend(chunk)
        finally:
            pipe.close()
    threads=[threading.Thread(target=reader,args=(process.stdout,0),daemon=True),threading.Thread(target=reader,args=(process.stderr,1),daemon=True)]
    for thread in threads:thread.start()
    def writer():
        try:
            process.stdin.write(data)
        except (BrokenPipeError,OSError):pass
        finally:process.stdin.close()
    if data is not None:
        thread=threading.Thread(target=writer,daemon=True)
        thread.start()
        threads.append(thread)
    try:
        process.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)
        raise
    finally:
        for thread in threads:thread.join(timeout=5)
    if overflow.is_set():raise DockerError('Docker command output exceeded 32 MiB; analysis incomplete')
    return subprocess.CompletedProcess(argv,process.returncode,bytes(streams[0]),bytes(streams[1]))


class Docker:
    def __init__(self, deadline, secret_values=()):
        self.deadline = deadline
        self.secrets = secret_values

    def run(self, *args, timeout=30, data=None, check=True, cleanup=False):
        remaining = timeout if cleanup else min(timeout, self.deadline-time.monotonic())
        if remaining <= 0:
            raise TimeoutError('Whole analysis deadline exceeded')
        try:
            process = bounded_process(['docker', *map(str,args)], data=data, timeout=remaining)
        except subprocess.TimeoutExpired as exc:
            raise TimeoutError(f'Docker operation exceeded {remaining:.1f}s') from exc
        text = process.stdout.decode('utf-8','replace')
        error = process.stderr.decode('utf-8','replace')
        for value in self.secrets:
            text, error = text.replace(value,'[REDACTED]'), error.replace(value,'[REDACTED]')
        if check and process.returncode:
            raise DockerError(error[-1500:] or text[-1500:] or 'Docker command failed')
        return process.returncode, text, error


def validate_dynamic(report):
    from jsonschema import Draft202012Validator, FormatChecker
    from .__main__ import validate_report
    schema = json.loads((RESOURCES/'dynamic-result.schema.json').read_text())
    Draft202012Validator(schema,format_checker=FormatChecker()).validate(report)
    validate_report(report['static_result'])
    if report['static_result']['analysis_id']!=report['analysis_id']:
        raise ValueError('Static and dynamic analysis IDs must agree')
    event_schema = json.loads((RESOURCES/'runtime-event.schema.json').read_text())
    validator = Draft202012Validator(event_schema,format_checker=FormatChecker())
    ids = set()
    for event in report['runtime_events']:
        validator.validate(event)
        if event['analysis_id'] != report['analysis_id'] or event['event_id'] in ids:
            raise ValueError('Runtime event correlation/uniqueness mismatch')
        ids.add(event['event_id'])
    if report['status'] == 'completed' and (report['errors'] or not report['cleanup']['verified'] or not all(report['coverage'].values())):
        raise ValueError('Completed dynamic analysis requires full declared coverage and verified cleanup')
    if report['status']=='completed' and (not report['baseline'] or not report['final_snapshot'] or not report['observer_health'].get('ready') or report['observer_health'].get('errors')):
        raise ValueError('Completed analysis requires baseline, final state and healthy observer')


def observe(source, *, config=None, control=False, static_result=None, on_progress=None, on_checkpoint=None):
    """Static preflight, fresh sandbox, bounded execution, evidence and cleanup.

    control=True exercises an empty clean WordPress instance using the same setup.
    A source package remains required for matching the static input contract but
    is not installed in control mode. Never use production credentials/data.
    """
    config = config or DynamicConfig()
    static = static_result if static_result is not None else analyze(source)
    if static_result is not None:
        from .__main__ import validate_report
        validate_report(static_result)
    analysis_id = static['analysis_id']
    prefix = 'veto-' + uuid.UUID(analysis_id).hex[:12]
    dbpass, rootpass, adminpass = [secrets.token_hex(16) for _ in range(3)]
    docker = Docker(time.monotonic()+config.job_timeout,(dbpass,rootpass,adminpass))
    result = {'dynamic_schema_version':'1.0.0','analysis_id':analysis_id,'started_at':now(),'finished_at':None,
        'status':'failed','mode':'clean_control' if control else 'plugin','static_result':static,
        'configuration':asdict(config),'images':{},'stages':[],'runtime_events':[], 'baseline':None,'final_snapshot':None,
        'coverage':{category:False for category in ('file','database','account','cron','network')},
        'observer_health':{},'isolation':{},'errors':[],'diagnostics':{},
        'cleanup':{'verified':False,'resources':[],'remaining':[]},
        'limitations':[
            'Observed activity is not by itself proof of maliciousness; compare clean controls.',
            'Inotify captures transient events but not the deleted contents; watch-creation races are possible.',
            'Database audit records attempted mutation SQL; successful state changes require snapshot corroboration.',
            'Network coverage includes WordPress HTTP hooks, controlled HTTP receipts and DNS sink attempts, not complete syscall tracing.',
            'Plugins can spoof or disable application HTTP telemetry; external collectors are separate.',
            'Direct-IP or alternative-protocol failures without DNS/HTTP telemetry may not produce network evidence.',
            'Containers share the Docker Linux kernel; this prototype is not a VM-level malware detonation platform.',
            'Only the predefined actions and finite observation window are exercised.',
        ]}
    containers, volumes, networks = [], [], []
    stage_name = 'preflight'
    wp, observer, database = prefix+'-wp',prefix+'-observer',prefix+'-db'
    sitevol, guardvol, dbvol, network = [prefix+'-'+suffix for suffix in ('site','guard','data','net')]
    state = None
    def notify(name, detail):
        if on_progress:
            try:on_progress(name,detail)
            except Exception as exc:
                result['errors'].append({'stage':'progress','code':type(exc).__name__,'message':str(exc)[:500]})

    def synchronize(current):
        if not current:return
        result['observer_health']={'ready':current['ready'],'errors':current['errors'],'heartbeats':current['heartbeats']}
        result['baseline']=current.get('baseline')
        result['final_snapshot']=current.get('latest')
        result['runtime_events']=[]
        for index,event in enumerate(current['events'][baseline_event_count:],1):
            result['runtime_events'].append({'event_schema_version':'1.0.0','event_id':f'R{index:06}','analysis_id':analysis_id,
                'observed_at':event['time'],'category':event['category'],'action':event['action'],'resource':event['resource'],
                'before':event['before'],'after':event['after'],'collector':event['collector'],'execution_stage':event['stage'],
                'evidence':[{'id':f'RE{index:06}','text':event['detail']}], 'attribution':'unattributed' if control else 'associated_with_plugin_execution'})

    def checkpoint():
        if on_checkpoint:
            try:on_checkpoint(result)
            except Exception as exc:
                result['errors'].append({'stage':'checkpoint','code':type(exc).__name__,'message':str(exc)[:500]})

    baseline_event_count=0
    label = ['--label',f'veto.analysis_id={analysis_id}']
    standard = ['--security-opt','no-new-privileges:true','--log-driver','local','--log-opt','max-size=5m','--log-opt','max-file=2']

    def run_container(name,image,command,*,memory='384m',caps=(),mounts=(),extra=(),user=None,detach=False,timeout=30,data=None,net=True):
        containers.append(name)
        args = ['run','--name',name,*label,*standard,'--cap-drop','ALL','--memory',memory,'--memory-swap',memory,'--cpus','1','--pids-limit','96','--read-only',
                '--tmpfs','/tmp:rw,nosuid,nodev,size=64m,mode=1777']
        for cap in caps: args += ['--cap-add',cap]
        for mount in mounts: args += ['--mount',mount]
        args += ['--network',network] if net else ['--network','none']
        if user: args += ['--user',user]
        if detach: args += ['-d']
        else: args += ['--rm']
        if data is not None: args += ['-i']
        args += list(extra) + ['--entrypoint',command[0], image,*command[1:]]
        return docker.run(*args,timeout=timeout,data=data)

    def observer_api(path,payload=None,timeout=25,final=False):
        code = "import urllib.request,json,sys; data=None if len(sys.argv)<3 else sys.argv[2].encode(); print(urllib.request.urlopen('http://127.0.0.1:8081'+sys.argv[1],data=data,timeout=20).read().decode())"
        args = ['exec',observer,'python3','-c',code,path]
        if payload is not None: args += [json.dumps(payload)]
        return json.loads(docker.run(*args,timeout=timeout,cleanup=final)[1])

    def stage(name,function):
        nonlocal stage_name
        stage_name=name
        notify(name,'Executing controlled '+name+' stage')
        entry={'stage':name,'started_at':now(),'finished_at':None,'status':'failed','detail':''}
        result['stages'].append(entry)
        try:
            observer_api('/stage',{'stage':name})
            value=function()
            observer_api('/snapshot',{'label':name})
            entry['status']='completed'
            entry['detail']=str(value or '')[-1500:]
        finally:
            entry['finished_at']=now()
            try:
                synchronize(observer_api('/state',timeout=5,final=True))
                checkpoint()
            except Exception as exc:
                result['errors'].append({'stage':'checkpoint','code':type(exc).__name__,'message':str(exc)[:500]})

    cli_count=0
    def cli(*args,timeout=None):
        nonlocal cli_count
        cli_count += 1
        return run_container(prefix+f'-cli-{cli_count}',config.cli_image,
            ['wp','--path=/var/www/html',*args],user='33:33',memory='256m',
            mounts=[f'type=volume,src={sitevol},dst=/var/www/html',f'type=volume,src={guardvol},dst=/var/www/html/wp-content/mu-plugins,readonly'],
            extra=['--dns',observer_ip,'-e',f'VETO_DB_PASSWORD={dbpass}'],timeout=timeout or config.action_timeout)[1]

    def inspect(kind,name):
        code, output, error=docker.run(kind,'inspect',name,check=False,cleanup=True,timeout=10)
        if code==0:return json.loads(output)[0]
        lower=error.lower()
        if any(text in lower for text in ('no such container','no such volume','no such network','no such object')) or (kind=='network' and 'not found' in lower):
            return None
        raise DockerError('Could not verify resource state: '+error[-500:])

    def cleanup():
        # Never remove a pre-existing/unrelated resource: exact label ownership check.
        for kind,names in (('container',containers),('volume',volumes),('network',networks)):
            for name in reversed(names):
                try:
                    record=inspect(kind,name)
                    if record is None: continue
                    labels=record.get('Config',{}).get('Labels',{}) if kind=='container' else record.get('Labels',{})
                    if labels.get('veto.analysis_id') != analysis_id:
                        result['cleanup']['remaining'].append(name+': ownership mismatch')
                        continue
                    command=[kind,'rm']+(['-f'] if kind=='container' else [])+[name]
                    docker.run(*command,cleanup=True,timeout=20)
                    result['cleanup']['resources'].append(name)
                    if inspect(kind,name) is not None:
                        result['cleanup']['remaining'].append(name)
                except Exception as exc:
                    result['cleanup']['remaining'].append(name+': '+str(exc)[:200])
        result['cleanup']['verified']=not result['cleanup']['remaining']

    try:
        if static['status'] != 'completed':
            result['status']='rejected'
            raise ValueError('Static preflight did not complete; plugin was not executed')
        if not control and len(static['plugin_metadata']) != 1:
            result['status']='rejected'
            raise ValueError('Dynamic execution requires exactly one conventional plugin main file')
        if not control:
            main_parts=static['plugin_metadata'][0]['main_file'].split('/')
            if len(main_parts)==2 and any(not item['path'].startswith(main_parts[0]+'/') for item in static['inventory']):
                result['status']='rejected'
                raise ValueError('ZIP must contain only the selected plugin folder; extra top-level files are unsupported')
        stage_name='initialization'
        notify('preparing_sandbox','Starting a fresh isolated WordPress environment')
        if docker.run('info','--format','{{.OSType}}',timeout=10)[1].strip() != 'linux':
            raise DockerError('Docker must run Linux containers')
        for role,image in (('sandbox',config.sandbox_image),('database',config.database_image),('cli',config.cli_image)):
            record=json.loads(docker.run('image','inspect',image,timeout=10)[1])[0]
            result['images'][role]={'reference':image,'id':record['Id'],'repo_digests':record.get('RepoDigests',[])}
        networks.append(network)
        docker.run('network','create','--internal','--opt','com.docker.network.bridge.gateway_mode_ipv4=isolated',*label,network)
        for name,size in ((sitevol,'384m'),(guardvol,'1m'),(dbvol,'768m')):
            volumes.append(name)
            docker.run('volume','create',*label,'--driver','local','--opt','type=tmpfs','--opt','device=tmpfs','--opt',f'o=size={size},nosuid,nodev',name)
        # tmpfs local volumes lose contents when their last mount disappears.
        # Keep all three mounted throughout setup and every short-lived CLI run.
        run_container(prefix+'-keeper',config.sandbox_image,['python3','-c','import time; time.sleep(3600)'],
            detach=True,net=False,user='65534:65534',memory='64m',
            mounts=[f'type=volume,src={sitevol},dst=/site,readonly',f'type=volume,src={guardvol},dst=/guard,readonly',f'type=volume,src={dbvol},dst=/data,readonly'])
        run_container(prefix+'-initialize',config.sandbox_image,['python3','/opt/veto/initialize.py'],
            caps=('CHOWN','DAC_OVERRIDE','FOWNER'),net=False,user='0:0',memory='256m',
            mounts=[f'type=volume,src={sitevol},dst=/site',f'type=volume,src={guardvol},dst=/guard'])
        run_container(database,config.database_image,['docker-entrypoint.sh','mysqld','--general-log=1','--general-log-file=/var/lib/mysql/general.log',
            '--log-output=FILE','--max-connections=30','--performance-schema=OFF','--innodb-buffer-pool-size=128M','--innodb-redo-log-capacity=64M'],
            detach=True,memory='768m',caps=('CHOWN','DAC_OVERRIDE','FOWNER','SETGID','SETUID'),
            mounts=[f'type=volume,src={dbvol},dst=/var/lib/mysql'],extra=['--network-alias','database','--tmpfs','/var/run/mysqld:rw,mode=1777',
                '-e','MYSQL_DATABASE=veto','-e','MYSQL_USER=veto','-e',f'MYSQL_PASSWORD={dbpass}','-e',f'MYSQL_ROOT_PASSWORD={rootpass}'])
        ready=time.monotonic()+config.startup_timeout
        while True:
            code,_,_=docker.run('exec',database,'mysqladmin','--host=127.0.0.1','ping','--silent',timeout=5,check=False)
            if code==0: break
            if time.monotonic()>ready: raise TimeoutError('MySQL startup timed out')
            time.sleep(.5)
        run_container(observer,config.sandbox_image,['python3','/opt/veto/observer.py'],detach=True,user='0:0',caps=('DAC_READ_SEARCH','NET_BIND_SERVICE'),
            mounts=[f'type=volume,src={sitevol},dst=/site,readonly',f'type=volume,src={guardvol},dst=/site/wp-content/mu-plugins,readonly',f'type=volume,src={dbvol},dst=/mysql,readonly'],
            extra=['--network-alias','observer','--dns','127.0.0.1','-e',f'VETO_DB_PASSWORD={dbpass}'])
        observer_record=inspect('container',observer)
        observer_ip=observer_record['NetworkSettings']['Networks'][network]['IPAddress']
        for _ in range(30):
            try:
                state=observer_api('/state',timeout=3)
                if state['ready']: break
            except (DockerError,TimeoutError):
                time.sleep(.2)
        else: raise RuntimeError('Observer startup failed')
        run_container(wp,config.sandbox_image,['apache2-foreground'],detach=True,user='33:33',memory='512m',
            mounts=[f'type=volume,src={sitevol},dst=/var/www/html',f'type=volume,src={guardvol},dst=/var/www/html/wp-content/mu-plugins,readonly'],
            extra=['--network-alias','wordpress','--dns',observer_ip,'-e',f'VETO_DB_PASSWORD={dbpass}','--tmpfs','/run:rw,mode=1777,size=16m',
                '--tmpfs','/var/lock/apache2:rw,mode=1777,size=4m'])
        cli('core','install','--url=http://wordpress:8080','--title=VETO Disposable Test','--admin_user=veto_admin',
            '--admin_password='+adminpass,'--admin_email=admin@example.invalid','--skip-email',timeout=45)
        cli('rewrite','structure','/%postname%/')
        # Baseline is captured after trusted WordPress initialization, before installation.
        state=observer_api('/state')
        if state['errors']: raise RuntimeError('; '.join(state['errors']))
        observer_api('/snapshot',{'label':'baseline'})
        # Drop setup noise only; collectors remain running for the observed stages.
        state=observer_api('/state')
        baseline_event_count=len(state['events'])
        result['baseline']=state['baseline']
        checkpoint()
        wp_record=inspect('container',wp)
        net_record=inspect('network',network)
        result['isolation']={'internal_network':net_record['Internal'],'privileged':wp_record['HostConfig']['Privileged'],
            'gateway_mode':net_record.get('Options',{}).get('com.docker.network.bridge.gateway_mode_ipv4'),
            'user':wp_record['Config']['User'],'cap_drop':wp_record['HostConfig']['CapDrop'],'read_only_rootfs':wp_record['HostConfig']['ReadonlyRootfs'],
            'memory_bytes':wp_record['HostConfig']['Memory'],'pids_limit':wp_record['HostConfig']['PidsLimit'],
            'published_ports':wp_record['HostConfig'].get('PortBindings') or {},'mounts':[{'type':m['Type'],'destination':m['Destination'],'writable':m['RW']} for m in wp_record['Mounts']],
            'volume_options':{name:inspect('volume',name)['Options'] for name in volumes},'dns_sink':observer_ip,
            'no_new_privileges':'no-new-privileges:true' in wp_record['HostConfig']['SecurityOpt']}
        if (not result['isolation']['internal_network'] or result['isolation']['gateway_mode']!='isolated' or result['isolation']['privileged'] or result['isolation']['user']!='33:33'
            or not result['isolation']['read_only_rootfs'] or result['isolation']['published_ports']
            or result['isolation']['cap_drop']!=['ALL'] or not result['isolation']['no_new_privileges']
            or any(m['type']=='bind' for m in result['isolation']['mounts'])):
            raise RuntimeError('Sandbox isolation assertion failed')
        if not control:
            with tempfile.TemporaryDirectory(prefix='veto-install-') as private:
                package,inventory,root=stage_archive(source,Path(private),Limits())
                if package['sha256']!=static['package']['sha256']: raise ValueError('Submission changed after static preflight')
                main=static['plugin_metadata'][0]['main_file']
                parts=main.split('/')
                install_root=root/parts[0] if len(parts)==2 else root
                main_relative=parts[-1]
                def install():
                    docker.run('exec',wp,'mkdir','-p','/var/www/html/wp-content/plugins/submission')
                    content=io.BytesIO()
                    with tarfile.open(fileobj=content,mode='w') as archive:
                        for path in sorted(install_root.rglob('*')):
                            if path.is_file(): archive.add(path,arcname=path.relative_to(install_root).as_posix(),recursive=False)
                    docker.run('exec','-i',wp,'tar','--no-same-owner','--no-same-permissions','-xf','-','-C','/var/www/html/wp-content/plugins/submission',data=content.getvalue())
                    return f'Installed validated copy as submission/{main_relative}'
                stage('install',install)
            stage('activate',lambda:cli('plugin','activate','submission/'+main_relative))
        else:
            stage('install',lambda:'Clean control: no plugin installed')
            stage('activate',lambda:'Clean control: no plugin activated')
        for name in ('frontend','admin'):
            stage(name,lambda name=name:docker.run('exec','-e',f'VETO_ADMIN_PASSWORD={adminpass}','-e',f'VETO_REQUEST_TIMEOUT={config.action_timeout}',
                observer,'python3','/opt/veto/driver.py',name,timeout=config.action_timeout+2)[1])
        stage('cron',lambda:cli('cron','event','run','--due-now'))
        def idle():
            end=time.monotonic()+config.observation_seconds
            index=0
            while time.monotonic()<end:
                if time.monotonic()>docker.deadline: raise TimeoutError('Whole analysis deadline exceeded')
                time.sleep(min(.5,max(0,end-time.monotonic())))
                observer_api('/snapshot',{'label':'idle-latest'})
                index+=1
            return f'Observed for {config.observation_seconds} seconds; {index} additional snapshots'
        stage('idle',idle)
        state=observer_api('/state')
        result['coverage']={key:True for key in result['coverage']}
        if state['errors']: raise RuntimeError('; '.join(state['errors']))
        result['status']='completed'
    except (Exception,KeyboardInterrupt) as exc:
        result['errors'].append({'stage':stage_name,'code':type(exc).__name__,'message':str(exc)[:1500]})
    finally:
        notify('cleaning_up','Preserving available evidence and removing the disposable sandbox')
        for name in (wp,observer,database):
            if name in containers:
                try:
                    _,out,err=docker.run('logs','--tail','60',name,check=False,cleanup=True,timeout=5)
                    result['diagnostics'][name]= (out+err)[-12000:]
                except Exception as exc:
                    result['diagnostics'][name]=str(exc)[:512]
        if observer in containers:
            try:
                state=observer_api('/state',timeout=5,final=True)
            except Exception as exc:
                result['errors'].append({'stage':'evidence','code':type(exc).__name__,'message':str(exc)[:500]})
        if state:
            synchronize(state)
            if state['errors']:
                result['coverage']={key:False for key in result['coverage']}
                if not result['errors']:result['errors'].append({'stage':'observer','code':'CollectorFailure','message':'; '.join(state['errors'])})
        checkpoint()
        cleanup()
        if result['cleanup']['remaining']:
            result['errors'].append({'stage':'cleanup','code':'CleanupFailure','message':'; '.join(result['cleanup']['remaining'])})
        if result['errors'] and result['status']=='completed':result['status']='failed'
        result['finished_at']=now()
        checkpoint()
    return result
