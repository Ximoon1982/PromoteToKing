#!/usr/bin/env python3
"""Read-only, low-request Chess.com PubAPI probe. No secrets or production writes."""
import collections, json, urllib.error, urllib.request, time
BASE="https://api.chess.com/pub"
MATCH="31195524"
TARGETS=[
 ("team_france_index",f"{BASE}/club/team-france/matches"),
 ("live_detail",f"{BASE}/match/live/{MATCH}"),
 ("daily_detail",f"{BASE}/match/{MATCH}"),
]
def get(label,url):
    headers={"Accept":"application/json","User-Agent":"PromoteToKing-ReadOnly-API-Diagnostics/2.16.1 contact admin@promotetoking.org",
             "Cache-Control":"no-cache"}
    request=urllib.request.Request(url,headers=headers)
    try:
        with urllib.request.urlopen(request,timeout=18) as r:
            body=r.read(3_000_000)
            status=r.status
            print(f"FETCH {label} status={status} bytes={len(body)} content_type={r.headers.get('content-type')} age={r.headers.get('age')}")
            return json.loads(body)
    except urllib.error.HTTPError as exc:
        print(f"FETCH {label} HTTP={exc.code} retry_after={exc.headers.get('Retry-After')} content_type={exc.headers.get('content-type')}")
    except Exception as exc:
        print(f"FETCH {label} ERROR={type(exc).__name__}: {str(exc)[:300]}")
    return None
def short(row):
    if not isinstance(row,dict): return {"type":type(row).__name__}
    return {k:row.get(k) for k in ("id","match_id","@id","url","name","time_class","start_time","status","opponent") if k in row}
index=get(*TARGETS[0])
if isinstance(index,dict):
    print("INDEX top-level keys:",list(index))
    for bucket,value in index.items():
        if not isinstance(value,list): continue
        classes=collections.Counter(str(x.get("time_class","<missing>")) for x in value if isinstance(x,dict))
        liveurl=sum("/match/live/" in str(x.get("@id","")) or "/matches/live/" in str(x.get("url","")) for x in value if isinstance(x,dict))
        matches=[short(x) for x in value if isinstance(x,dict) and (MATCH in json.dumps(x) or "/match/live/" in str(x.get("@id","")) or str(x.get("time_class","")).lower()=="live")]
        print("BUCKET",bucket,"total=",len(value),"time_class=",dict(classes),"live_url_count=",liveurl)
        print("  EXAMPLES",json.dumps(matches[:12],ensure_ascii=False))
        for x in value:
            if isinstance(x,dict) and MATCH in json.dumps(x):
                print("  TARGET_ROW",json.dumps(x,ensure_ascii=False)[:2800])
else: print("INDEX_UNAVAILABLE")
time.sleep(1)
live=get(*TARGETS[1])
if isinstance(live,dict):
    keys=["@id","name","url","start_time","status","boards","time_class"]
    print("LIVE_DETAIL",json.dumps({k:live.get(k) for k in keys if k in live},ensure_ascii=False))
    print("LIVE_SETTINGS",json.dumps(live.get("settings"),ensure_ascii=False))
    teams=live.get("teams")
    print("LIVE_TEAMS_TYPE",type(teams).__name__,"keys" if isinstance(teams,dict) else "",list(teams) if isinstance(teams,dict) else "")
    if isinstance(teams,dict):
        for key,val in teams.items():
            if isinstance(val,dict):print("  TEAM",key,json.dumps({k:val.get(k) for k in ("@id","url","name") if k in val}))
    elif isinstance(teams,list):
        for key,val in enumerate(teams):
            if isinstance(val,dict):print("  TEAM",key,json.dumps({k:val.get(k) for k in ("@id","url","name") if k in val}))
else:print("LIVE_DETAIL_UNAVAILABLE")
time.sleep(1)
daily=get(*TARGETS[2])
if isinstance(daily,dict): print("DAILY_DETAIL",json.dumps({k:daily.get(k) for k in ("@id","url","name","status","time_class") if k in daily}))
else: print("DAILY_DETAIL_UNAVAILABLE")
