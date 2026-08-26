#!/usr/bin/env python3
import json, time, hashlib
from collections import defaultdict

start=time.perf_counter()
rows=[]
# 120k match requests for 15k matches, eight independent sources each.
for mid in range(1,15001):
    for source in range(8):
        rows.append((f'match:{mid}',20,{'match_id':mid,'source':f'source-{source}'}))
# 20k player-match requests for 5k players, four sources each.
for uid in range(1,5001):
    for source in range(4):
        rows.append((f'player-matches:user{uid}',80,{'username':f'User{uid}','source':f'p{source}'}))
# 9,998 board requests for 4,999 boards, two independent sources each.
for bid in range(1,5000):
    key='board:'+hashlib.sha256(f'https://api.chess.com/pub/match/{bid}/1'.encode()).hexdigest()
    for source in range(2):
        rows.append((key,30,{'board_url':f'https://api.chess.com/pub/match/{bid}/1','source':f'b{source}'}))
# Two deadline requests for the same canonical resources, plus ordinary copies.
rows += [
    ('club-match-index',10,{'source':'ordinary'}),('club-match-index',-100,{'freshness_deadline':True}),
    ('club-members',10,{'source':'ordinary'}),('club-members',-100,{'freshness_deadline':True}),
]

canonical={}
for key,priority,payload in rows:
    if key not in canonical:
        canonical[key]={'priority':priority,'count':1,'sources':set([payload.get('source','')])}
    else:
        canonical[key]['count']+=1
        canonical[key]['priority']=min(canonical[key]['priority'],priority)
        if payload.get('source'): canonical[key]['sources'].add(payload['source'])

input_rows=len(rows)
active=len(canonical)
coalesced=input_rows-active
prioritized=sorted((v['priority'],k) for k,v in canonical.items())[:2]
# Model an arbitrarily large request flood while one generation is running.
generation=41; requested_generation=generation
for _ in range(100_000): requested_generation=max(generation+1,requested_generation)

result={
    'input_rows':input_rows,
    'active_canonical':active,
    'coalesced_requests':coalesced,
    'reduction_percent':round(coalesced/input_rows*100,3),
    'first_two_scheduler_items':[k for _,k in prioritized],
    'running_generation':generation,
    'requested_generation_after_100k_flood':requested_generation,
    'elapsed_ms':round((time.perf_counter()-start)*1000,2),
}
print(json.dumps(result,indent=2,sort_keys=True))
assert input_rows == 150002
assert active == 25001
assert coalesced == 125001
assert set(result['first_two_scheduler_items']) == {'club-match-index','club-members'}
assert requested_generation == generation+1
