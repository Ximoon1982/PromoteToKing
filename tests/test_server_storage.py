#!/usr/bin/env python3
"""HTTP integration tests for the dependency-free local server."""
from __future__ import annotations
import importlib.util, json, tempfile, threading, urllib.error, urllib.parse, urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("club_server",ROOT/"serve_local.py")
mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod)

def req(url,method="GET",payload=None,origin=None,kind=None):
    data=None if payload is None else json.dumps(payload).encode()
    headers={"Accept":"application/json"}
    if data is not None: headers["Content-Type"]="application/json"
    if origin: headers["Origin"]=origin
    if kind: headers["X-Club-Tools-Request"]=kind
    request=urllib.request.Request(url,data=data,method=method,headers=headers)
    try:
        with urllib.request.urlopen(request,timeout=5) as response: return response.status,json.load(response)
    except urllib.error.HTTPError as error: return error.code,json.load(error)

def detail(mid,name,status="registration",boards=10):
    return {"@id":f"https://api.chess.com/pub/match/{mid}","url":f"https://www.chess.com/club/matches/{mid}","name":name,"status":status,"start_time":1700000000,"boards":boards,"teams":[{"name":"Promote to King","players":[{"username":"alpha","rating":1500,"timeout_percent":1.2}]},{"name":"Opponent","players":[{"username":"beta","rating":1450,"timeout_percent":2.3}]}]}

def main():
  calls=[]
  index={"registered":[{"@id":"https://api.chess.com/pub/match/101","name":"1WL: League Match"},{"@id":"https://api.chess.com/pub/match/102","name":"Friendly match"}]}
  details={"101":detail("101","1WL: League Match"),"303":detail("303","Manual friendly",boards=22),"404":detail("404","PCL Finished","finished",18)}
  def fake(url):
      calls.append(url)
      if url.endswith("/club/promote-to-king/matches"): return index
      identifier=url.rsplit("/",1)[-1]
      if identifier=="777": raise RuntimeError("Chess.com returned HTTP 404")
      return details[identifier]
  mod.chess_json=fake
  with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp); (root/"index.html").write_text("<!doctype html><title>test</title>"); (root/"data").mkdir()
    (root/"data/server-config.json").write_text(json.dumps({"cronToken":"test-token"}))
    legacy_dir=root/"data/match-history/777"; legacy_dir.mkdir(parents=True)
    legacy_dir.joinpath("20260101T000000000000Z.json").write_text(json.dumps({"schemaVersion":1,"trackedAt":"2026-01-01T00:00:00Z","matchId":"777","leagueAcronyms":["PCL"],"match":detail("777","PCL Legacy match")}))
    (root/"data/followed-matches.json").write_text(json.dumps({"schemaVersion":1,"revision":1,"matches":{"777":{"matchId":"777","name":"PCL Legacy match","followed":False}}}))
    (root/"data/followed-matches.json.bak").write_text((root/"data/followed-matches.json").read_text())
    server=mod.create_server(root,port=0); thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
    base=f"http://127.0.0.1:{server.server_address[1]}"
    try:
      today=mod.now().date().isoformat()
      status,logs=req(f"{base}/api/scheduled-task-logs?from={today}&to={today}")
      assert status==200 and logs["entries"]==[] and logs["summary"]["runs"]==0

      status,refs=req(base+"/api/league-match-references")
      assert status==200 and [r["matchId"] for r in refs["references"]]==["101","777"]
      assert len(calls)==1, calls
      status,migrated=req(base+"/api/tracked-match-data")
      legacy=next(item for item in migrated["matches"] if item["matchId"]=="777")
      assert status==200 and legacy["followed"] is True and legacy["fileCount"]==1
      assert not (root/"data/match-history").exists()
      assert not (root/"data/followed-matches.json").exists()
      assert not (root/"data/followed-matches.json.bak").exists()
      assert (root/"data/match-tracking/matches/777").is_dir()
      status,followed_legacy=req(base+"/api/tracked-match-data",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"777"})
      assert status==201 and followed_legacy["captured"] is False and followed_legacy["match"]["followed"] is True

      status,added=req(base+"/api/tracked-match-data",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"manual-friendly-303"})
      assert status==201 and added["stored"]["matchId"]=="303"
      assert (root/"data/match-tracking/matches/303").is_dir()

      status,inventory=req(base+"/api/tracked-match-data")
      assert status==200 and any(m["matchId"]=="303" and m["followed"] for m in inventory["matches"])

      before=len(list((root/"data/match-tracking/matches/303").glob("*.json")))
      status,unfollowed=req(base+"/api/tracked-match-data?mode=unfollow&match=303",method="DELETE",origin=base,kind="tracked-match-data")
      assert status==200 and unfollowed["match"]["followed"] is False
      assert len(list((root/"data/match-tracking/matches/303").glob("*.json")))==before

      status,followed=req(base+"/api/tracked-match-data",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"303"})
      assert status==201 and followed["match"]["followed"] is True

      status,recorded=req(base+"/api/record-league-match",method="POST",origin=base,kind="record-league-match",payload={"match":"101"})
      assert status==201 and recorded["stored"]["matchId"]=="101"
      status,history=req(base+"/api/match-history?match=101")
      assert status==200 and history["fileCount"]>=1 and history["snapshots"][0]["match"]["teams"][0]["players"][0]["rating"]==1500

      status,logged=req(base+"/api/scheduled-task-log",method="POST",origin=base,kind="scheduled-task-log",payload={"source":"manual","status":"success","trackedReferences":1,"storedMatches":1,"durationMs":123})
      assert status==201
      status,logs=req(f"{base}/api/scheduled-task-logs?from={today}&to={today}")
      assert status==200 and isinstance(logs["entries"],list) and logs["summary"]["runs"]==1

      mod.save_snapshot(root/"data/match-tracking/matches",root/"data/match-tracking/index.json",details["404"],True,"test")
      status,deleted=req(base+"/api/tracked-match-data?mode=finished-data",method="DELETE",origin=base,kind="tracked-match-data")
      assert status==200 and deleted["deletedMatches"]==1 and not (root/"data/match-tracking/matches/404").exists()

      for path in ["/data/match-tracking/index.json","/logs/scheduled-tasks/"]:
        try: urllib.request.urlopen(base+path,timeout=5); raise AssertionError("protected storage exposed")
        except urllib.error.HTTPError as error: assert error.code==404
      print("Local server integration tests passed.")
    finally:
      server.shutdown(); server.server_close(); thread.join(timeout=5)

if __name__=="__main__": main()
