from pathlib import Path
import json, statistics

root=Path(__file__).resolve().parents[1]
directory=root/'.cache/resource-probe-live'
php=[json.loads(line) for line in (directory/'php.jsonl').read_text().splitlines() if line]
samples=json.loads((directory/'os.json').read_text(encoding='utf-8-sig'))
connected=next(row for row in php if row['phase']=='connected')
finished=next(row for row in reversed(php) if row['phase']=='finished')
logical=32

def window(values):
    if len(values)<2:return None
    duration=values[-1]['elapsed_seconds']-values[0]['elapsed_seconds']
    cpu=values[-1]['cpu_seconds']-values[0]['cpu_seconds']
    def memory(key):
        v=[row[key]/1048576 for row in values]
        return {'min_mib':round(min(v),2),'median_mib':round(statistics.median(v),2),'max_mib':round(max(v),2)}
    instantaneous=[100*(b['cpu_seconds']-a['cpu_seconds'])/(b['elapsed_seconds']-a['elapsed_seconds']) for a,b in zip(values,values[1:])]
    return {'seconds':round(duration,3),'samples':len(values),'cpu_seconds':round(cpu,5),'cpu_percent_one_core':round(100*cpu/duration,4),'cpu_percent_machine_32_logical':round(100*cpu/duration/logical,5),'peak_cpu_percent_one_second':round(max(instantaneous),3),'working_set':memory('working_set_bytes'),'private_commit':memory('private_bytes')}

startup=[row for row in samples if row['elapsed_seconds']<=connected['elapsed_seconds']+15]
steady_start=connected['elapsed_seconds']+35
idle=[row for row in samples if row['elapsed_seconds']>=steady_start and row['phase']=='idle']
idle_php=[row for row in php if row['elapsed_seconds']>=steady_start and row['phase']=='idle']
result={
    'environment':{'os':'Windows','php':'8.4.25','xdebug_mode':'off','logical_processors':logical,'session':'default','test_messages_sent':0},
    'startup':window(startup),'steady_connected':window(idle),
    'steady_php':{'used_mib':{'min':min(row['php_used_mib'] for row in idle_php),'max':max(row['php_used_mib'] for row in idle_php)},'allocated_mib':sorted({row['php_allocated_mib'] for row in idle_php}),'peak_mib_entire_probe':max(row['php_peak_mib'] for row in php),'received_messages_during_steady':idle_php[-1]['messages']-idle_php[0]['messages'],'received_nodes_during_steady':idle_php[-1]['nodes']-idle_php[0]['nodes']},
    'session_after_probe':finished
}
(directory/'summary.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps(result,indent=2))
