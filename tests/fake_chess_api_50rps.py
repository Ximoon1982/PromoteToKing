#!/usr/bin/env python3
import argparse, json, threading, time
from collections import deque
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

class State:
    def __init__(self, cap):
        self.cap=cap; self.lock=threading.Lock(); self.arrivals=deque(); self.all=[]; self.accepted=0; self.rate_limited=0; self.permanent=0
    def admit(self, path):
        now=time.monotonic()
        with self.lock:
            while self.arrivals and now-self.arrivals[0]>=1.0: self.arrivals.popleft()
            current=len(self.arrivals)
            self.arrivals.append(now); self.all.append(now)
            if current>=self.cap:
                self.rate_limited+=1; return False,current+1
            self.accepted+=1; return True,current+1
    def summary(self):
        with self.lock:
            xs=list(self.all)
        peak=0; j=0
        for i,t in enumerate(xs):
            while t-xs[j]>=1.0: j+=1
            peak=max(peak,i-j+1)
        return {'arrivals':len(xs),'accepted':self.accepted,'rate_limited':self.rate_limited,'permanent_4xx':self.permanent,'peak_rolling_1s_arrivals':peak,'cap':self.cap}

STATE=None
class Handler(BaseHTTPRequestHandler):
    protocol_version='HTTP/1.1'
    def log_message(self,*args): pass
    def do_GET(self):
        global STATE
        parsed=urlparse(self.path); path=parsed.path; q=parse_qs(parsed.query)
        if path == '/__summary':
            body=json.dumps(STATE.summary()).encode(); self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body); return
        ok,_=STATE.admit(path)
        if not ok:
            body=b'{"error":"rate limited"}'
            self.send_response(429); self.send_header('Retry-After','1'); self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body); return
        # endpoint-specific service time: slow match details, faster indexes/profiles
        if path.startswith('/pub/match/'):
            delay=0.010
        elif path.endswith('/matches'):
            delay=0.006
        elif path.endswith('/members'):
            delay=0.007
        elif '/stats' in path:
            delay=0.008
        elif '/games/' in path:
            delay=0.009
        else:
            delay=0.006
        time.sleep(delay)
        if 'permanent404' in q:
            STATE.permanent+=1
            body=b'{"error":"not found"}'
            self.send_response(404)
        else:
            body=json.dumps({'ok':True,'path':path,'page':q.get('page',[''])[0]}).encode()
            self.send_response(200)
        self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)

if __name__=='__main__':
    ap=argparse.ArgumentParser(); ap.add_argument('--port',type=int,default=18765); ap.add_argument('--cap',type=int,default=50); ap.add_argument('--summary-file',required=True)
    args=ap.parse_args(); STATE=State(args.cap)
    server=ThreadingHTTPServer(('127.0.0.1',args.port),Handler)
    try: server.serve_forever()
    except KeyboardInterrupt: pass
    finally:
        with open(args.summary_file,'w') as f: json.dump(STATE.summary(),f)
