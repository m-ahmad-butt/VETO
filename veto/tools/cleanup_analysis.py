"""Recover resources after a forced process/host shutdown, by exact analysis UUID.

Usage: python -m tools.cleanup_analysis --list
       python -m tools.cleanup_analysis --analysis-id UUID
Never uses docker prune or touches resources without the exact ownership label.
"""
import argparse
import json
import time
import uuid

from wp_spectra.dynamic import Docker,DockerError


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--list',action='store_true')
    group.add_argument('--analysis-id',type=lambda value:str(uuid.UUID(value)))
    args=parser.parse_args()
    docker=Docker(time.monotonic()+120)
    for kind,command in (('container',['ps','-aq']),('volume',['volume','ls','-q']),('network',['network','ls','-q'])):
        expression='label=veto.analysis_id'+('='+args.analysis_id if args.analysis_id else '')
        code,text,error=docker.run(*command,'--filter',expression)
        for identifier in text.split():
            record=json.loads(docker.run(kind,'inspect',identifier)[1])[0]
            labels=record.get('Config',{}).get('Labels',{}) if kind=='container' else record.get('Labels',{})
            owner=labels.get('veto.analysis_id')
            if args.list:
                print(json.dumps({'kind':kind,'id':identifier,'analysis_id':owner}))
            elif owner==args.analysis_id:
                docker.run(kind,'rm',*(['-f'] if kind=='container' else []),identifier)
                print(f'Removed {kind} {identifier}')
            else:
                raise DockerError('Ownership changed; refusing cleanup')
    return 0


if __name__=='__main__':raise SystemExit(main())
