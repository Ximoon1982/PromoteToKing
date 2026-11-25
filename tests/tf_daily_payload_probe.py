import json, urllib.request, pathlib, time
out=pathlib.Path("tf-daily-probe");out.mkdir(exist_ok=True)
ids="1917385 1906335 1892869 1943109 1929451 1982828 1969506 1957232 1975824 1963564 1950343 1936535 1924689 1911839 1899285 1995318 1966090 1951001 1959684 1936579 1932115 1911027 1899295".split()
def get(url):
    for i in range(3):
        try:
            with urllib.request.urlopen(urllib.request.Request(url,headers={"User-Agent":"TeamFrance-Leaderboard diagnostic (+https://www.promotetoking.org)","Accept":"application/json"}),timeout=20) as r: return json.load(r)
        except Exception as e:
            print(url, str(e),flush=True);time.sleep(2+i)
    return None
for id in ids:
    j=get("https://api.chess.com/pub/match/"+id)
    if not j:continue
    (out/(id+".json")).write_text(json.dumps(j))
    print(id,j.get("status"),j.get("boards"),[(k,t.get("score"),len(t.get("players",[])),len(t.get("fair_play_removals",[]))) for k,t in j.get("teams",{}).items()],flush=True)
    time.sleep(1)
    if id in ("1917385","1906335","1982828"):
        team=next((t for t in j.get("teams",{}).values() if "team-france" in str(t.get("@id",""))),{})
        for p in team.get("players",[])[:3]:
            b=p.get("board")
            if isinstance(b,str) and b.startswith("https://api.chess.com/pub/match/"):
                d=get(b)
                if d:(out/(id+"-board-"+b.rsplit("/",1)[-1]+".json")).write_text(json.dumps(d))
                time.sleep(1)
