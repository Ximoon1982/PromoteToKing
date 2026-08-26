#!/usr/bin/env python3
from __future__ import annotations
import importlib.util, tempfile, json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('club_server',ROOT/'serve_local.py')
mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)

def detail(mid,name,status='registration',boards=10):
    return {'@id':f'https://api.chess.com/pub/match/{mid}','url':f'https://www.chess.com/club/matches/{mid}','name':name,'status':status,'start_time':1700000000,'boards':boards,'teams':[{'name':'Promote to King','players':[{'username':'a','rating':1500,'timeout_percent':1.2}]},{'name':'Opponent','players':[{'username':'b','rating':1450,'timeout_percent':2.3}]}]}

with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp); history=root/'data/match-tracking/matches'; registry=root/'data/match-tracking/index.json'; logs=root/'logs'
    calls=[]
    index={'registered':[{'@id':'https://api.chess.com/pub/match/101','name':'1WL: League Match'},{'@id':'https://api.chess.com/pub/match/102','name':'Friendly match'}]}
    data={'101':detail('101','1WL: League Match'),'102':detail('102','Friendly match'),'303':detail('303','Manual friendly',boards=22),'404':detail('404','Finished match','finished',boards=18)}
    def fake(url):
        calls.append(url)
        if url.endswith('/club/promote-to-king/matches'): return index
        return data[url.rsplit('/',1)[-1]]
    mod.chess_json=fake
    refs=mod.automatic_league_references(registry)
    assert [x['matchId'] for x in refs['references']]==['101']
    assert len(calls)==1, calls  # no registered-match detail fetch during prefilter
    result=mod.follow_and_capture(history,registry,'https://www.chess.com/club/matches/manual-slug/303')
    assert result['match']['followed'] is True and (history/'303').is_dir()
    count_before=len(list((history/'303').glob('*.json')))
    mod.set_follow_state(registry,history,'303',False)
    assert len(list((history/'303').glob('*.json')))==count_before
    assert mod.read_follow_registry(registry)['matches']['303']['followed'] is False
    mod.save_snapshot(history,registry,data['404'],True,'test')
    removed=mod.remove_finished_data(history,registry)
    assert removed['deletedMatches']==1 and not (history/'404').exists()
    cron_result=mod.track_all(history,registry,logs,'cron')
    assert cron_result['taskType']=='match-tracking' and cron_result['taskId']=='track-upcoming-league-matches' and cron_result['runId']
    assert cron_result['startedAt'] and cron_result['endedAt'] and cron_result['storedMatchIds']==['101']
    cron_filtered=mod.read_task_logs(logs,mod.date.today(),mod.date.today(),'cron','', 'match-tracking')
    assert cron_filtered['summary']['runs']==1 and cron_filtered['entries'][0]['runId']==cron_result['runId']
    logs.unlink() if logs.is_file() else None
    if logs.is_dir():
        for child in logs.iterdir(): child.unlink()
    empty=mod.read_task_logs(logs,mod.date.today(),mod.date.today(),'','')
    assert empty['entries']==[] and empty['summary']['runs']==0
    logs.mkdir(parents=True,exist_ok=True)
    legacy={'event':'scheduled-task-run','entryType':'manual','status':'partial','startedAt':mod.stamp(),'endedAt':mod.stamp(),'durationMs':250,'registeredReferences':3,'recordedMatches':2,'skippedMatches':0,'failedMatches':1}
    (logs/f"{mod.date.today().isoformat()}.jsonl").write_text(json.dumps(legacy)+'\n')
    compatible=mod.read_task_logs(logs,mod.date.today(),mod.date.today(),'','')
    assert compatible['summary']=={'runs':1,'storedMatches':2,'failedMatches':1,'manualRuns':1,'cronRuns':0}
    assert compatible['entries'][0]['status']=='partial' and compatible['entries'][0]['legacySchema'] is True
    assert compatible['entries'][0]['taskType']=='legacy-tracking' and compatible['entries'][0]['runId']==''
    current=mod.task_entry('cron','partial',taskType='match-tracking',taskId='track-upcoming-league-matches',runId='tracking-run-123',startedAt='2026-08-02T10:00:00Z',endedAt='2026-08-02T10:00:03Z',trackedReferences=2,storedMatches=1,failedMatches=1,storedMatchIds=['101'],failedMatchIds=['102'])
    with (logs/f"{mod.date.today().isoformat()}.jsonl").open('a',encoding='utf-8') as handle: handle.write(json.dumps(current)+'\n')
    filtered=mod.read_task_logs(logs,mod.date.today(),mod.date.today(),'','', 'match-tracking')
    assert filtered['summary']['runs']==1 and filtered['entries'][0]['runId']=='tracking-run-123'
    assert filtered['entries'][0]['storedMatchIds']==['101'] and filtered['entries'][0]['failedMatchIds']==['102']
    assert filtered['entries'][0]['startedAt']=='2026-08-02T10:00:00Z' and filtered['entries'][0]['endedAt']=='2026-08-02T10:00:03Z'
print('Tracking feature tests passed.')
