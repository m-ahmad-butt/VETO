"""Ownership-checked recovery of a single interrupted application's sandbox."""
import json
import time
import uuid
from .dynamic import Docker


def cleanup_owned(analysis_id):
    analysis_id=str(uuid.UUID(analysis_id))
    docker=Docker(time.monotonic()+120)
    result={'verified':False,'removed':[],'remaining':[]}
    for kind,command in (('container',['ps','-aq']),('volume',['volume','ls','-q']),('network',['network','ls','-q'])):
        try:
            _,text,_=docker.run(*command,'--filter','label=veto.analysis_id='+analysis_id,timeout=10)
            for identifier in text.split():
                record=json.loads(docker.run(kind,'inspect',identifier,timeout=10)[1])[0]
                labels=record.get('Config',{}).get('Labels',{}) if kind=='container' else record.get('Labels',{})
                if labels.get('veto.analysis_id')!=analysis_id:
                    raise RuntimeError('Ownership changed; cleanup refused')
                docker.run(kind,'rm',*(['-f'] if kind=='container' else []),identifier,timeout=20)
                result['removed'].append(kind+':'+identifier)
            _,text,_=docker.run(*command,'--filter','label=veto.analysis_id='+analysis_id,timeout=10)
            if text.strip():result['remaining'].append(kind+':'+text.strip())
        except Exception as exc:
            result['remaining'].append(kind+': '+str(exc)[:500])
    result['verified']=not result['remaining']
    return result
