#!/usr/bin/env python3
"""HTTP integration tests for the packaged PHP API, using a local Chess API mock."""
from __future__ import annotations
import json, os, shutil, socket, subprocess, tempfile, threading, time, urllib.error, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
def free_port():
    with socket.socket() as s: s.bind(("127.0.0.1",0)); return s.getsockname()[1]
def req(url,method="GET",payload=None,origin=None,kind=None):
    data=None if payload is None else json.dumps(payload).encode(); headers={"Accept":"application/json"}
    if data is not None: headers["Content-Type"]="application/json"
    if origin: headers["Origin"]=origin
    if kind: headers["X-Club-Tools-Request"]=kind
    request=urllib.request.Request(url,data=data,method=method,headers=headers)
    try:
      with urllib.request.urlopen(request,timeout=5) as response: return response.status,json.load(response)
    except urllib.error.HTTPError as error: return error.code,json.load(error)
def wait(url):
    for _ in range(80):
      try: urllib.request.urlopen(url,timeout=.3); return
      except Exception: time.sleep(.1)
    raise RuntimeError("server did not start")
def detail(mid,name,status="registration"):
    return {"@id":f"MOCK/match/{mid}","url":f"https://www.chess.com/club/matches/{mid}","name":name,"status":status,"start_time":1700000000,"boards":12,"teams":[{"name":"Promote to King","players":[{"username":"alpha","rating":1500,"timeout_percent":1.2}]},{"name":"Opponent","players":[{"username":"beta","rating":1450,"timeout_percent":2.3}]}]}
class Mock(BaseHTTPRequestHandler):
    def do_GET(self):
      if self.path=="/club/promote-to-king/matches": value={"registered":[{"@id":self.server.base+"/match/201","name":"PCL League Match"},{"@id":self.server.base+"/match/202","name":"Friendly match"}]}
      elif self.path=="/match/201": value=detail("201","PCL League Match")
      elif self.path=="/match/303": value=detail("303","Manual friendly")
      elif self.path=="/match/404": value=detail("404","Finished match","finished")
      else: self.send_error(404); return
      raw=json.dumps(value).encode(); self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def log_message(self,*_): pass

def main():
  if shutil.which("php") is None: print("PHP unavailable; skipped."); return
  mock=ThreadingHTTPServer(("127.0.0.1",0),Mock); mock.base=f"http://127.0.0.1:{mock.server_address[1]}"; mt=threading.Thread(target=mock.serve_forever,daemon=True); mt.start()
  with tempfile.TemporaryDirectory() as tmp:
    root=Path(tmp); shutil.copytree(ROOT/"api",root/"api"); shutil.copytree(ROOT/"server",root/"server"); (root/"data").mkdir(); (root/"data/server-config.json").write_text(json.dumps({"cronToken":"test-token"})); (root/"index.html").write_text("ok")
    legacy_dir=root/"data/match-history/777"; legacy_dir.mkdir(parents=True)
    legacy_dir.joinpath("20260101T000000000000Z.json").write_text(json.dumps({"schemaVersion":1,"trackedAt":"2026-01-01T00:00:00Z","matchId":"777","leagueAcronyms":["PCL"],"match":detail("777","PCL Legacy match")}))
    (root/"data/followed-matches.json").write_text(json.dumps({"schemaVersion":1,"revision":1,"matches":{"777":{"matchId":"777","name":"PCL Legacy match","followed":False}}}))
    (root/"data/followed-matches.json.bak").write_text((root/"data/followed-matches.json").read_text())
    today=time.strftime("%Y-%m-%d",time.gmtime()); (root/"logs/scheduled-tasks").mkdir(parents=True)
    legacy={"event":"scheduled-task-run","entryType":"scheduled","status":"error","startedAt":today+"T08:00:00Z","endedAt":today+"T08:00:02Z","durationMs":2000,"registeredReferences":3,"recordedMatches":2,"skippedMatches":0,"failedMatches":1}
    (root/"logs/scheduled-tasks"/f"{today}.jsonl").write_text(json.dumps(legacy)+"\n")
    port=free_port(); base=f"http://127.0.0.1:{port}"; env=os.environ.copy(); env["CLUB_TOOLS_CHESS_API_BASE"]=mock.base
    process=subprocess.Popen(["php","-S",f"127.0.0.1:{port}","-t",str(root)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
      wait(base+"/api/diagnostics/")
      status,logs=req(f"{base}/api/scheduled-task-logs/?from={today}&to={today}"); assert status==200 and logs["summary"]["runs"]==1 and logs["entries"][0]["status"]=="failed" and logs["entries"][0]["legacySchema"] is True
      status,refs=req(base+"/api/league-match-references/"); assert status==200 and [r["matchId"] for r in refs["references"]]==["201","777"]
      status,migrated=req(base+"/api/tracked-match-data/"); legacy=next(item for item in migrated["matches"] if item["matchId"]=="777"); assert status==200 and legacy["followed"] is True and legacy["fileCount"]==1
      assert not (root/"data/match-history").exists() and not (root/"data/followed-matches.json").exists() and not (root/"data/followed-matches.json.bak").exists() and (root/"data/match-tracking/matches/777").is_dir()
      status,followed_legacy=req(base+"/api/tracked-match-data/",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"777"}); assert status==201 and followed_legacy["captured"] is False and followed_legacy["match"]["followed"] is True
      status,added=req(base+"/api/tracked-match-data/",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"manual-303"}); assert status==201 and added["stored"]["matchId"]=="303"
      status,unfollowed=req(base+"/api/tracked-match-data/?mode=unfollow&match=303",method="DELETE",origin=base,kind="tracked-match-data"); assert status==200 and unfollowed["match"]["followed"] is False
      assert (root/"data/match-tracking/matches/303").is_dir()
      status,recorded=req(base+"/api/record-league-match/",method="POST",origin=base,kind="record-league-match",payload={"match":"201"}); assert status==201
      status,history=req(base+"/api/match-history/?match=201"); assert status==200 and history["fileCount"]>=1
      status,logged=req(base+"/api/scheduled-task-log/",method="POST",origin=base,kind="scheduled-task-log",payload={"source":"manual","status":"success","taskType":"match-tracking","taskId":"manual-record-active-matches","runId":"manual-test-run-1","startedAt":today+"T09:00:00Z","endedAt":today+"T09:00:01Z","trackedReferences":1,"storedMatches":1,"storedMatchIds":["201"]}); assert status==201 and logged["entry"]["runId"]=="manual-test-run-1"
      status,logs=req(f"{base}/api/scheduled-task-logs/?from={today}&to={today}"); assert status==200 and logs["summary"]["runs"]==2 and isinstance(logs["entries"],list)
      status,current_logs=req(f"{base}/api/scheduled-task-logs/?from={today}&to={today}&taskType=match-tracking"); assert status==200 and current_logs["summary"]["runs"]==1 and current_logs["entries"][0]["storedMatchIds"]==["201"]
      status,finished=req(base+"/api/tracked-match-data/",method="POST",origin=base,kind="tracked-match-data",payload={"action":"follow","match":"404"}); assert status==201
      status,deleted=req(base+"/api/tracked-match-data/?mode=finished-data",method="DELETE",origin=base,kind="tracked-match-data"); assert status==200 and deleted["deletedMatches"]==1
      print("PHP API integration tests passed.")
    finally:
      process.terminate(); process.wait(timeout=5); mock.shutdown(); mock.server_close(); mt.join(timeout=5)

if __name__=="__main__": main()
