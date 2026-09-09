from __future__ import annotations
import json, shutil, socket, subprocess, time, urllib.error, urllib.request
from pathlib import Path
import pytest
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]

def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1",0));return s.getsockname()[1]

def multipart(fields,file_path):
    boundary="----p2ktrophytest";parts=[]
    for key,value in fields.items():parts.append(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{key}\"\r\n\r\n{value}\r\n".encode())
    parts.append(f"--{boundary}\r\nContent-Disposition: form-data; name=\"artwork\"; filename=\"image.png\"\r\nContent-Type: image/png\r\n\r\n".encode()+file_path.read_bytes()+b"\r\n")
    parts.append(f"--{boundary}--\r\n".encode())
    return b"".join(parts),f"multipart/form-data; boundary={boundary}"

@pytest.fixture
def server(tmp_path):
    php=shutil.which("php")
    if not php:pytest.skip("PHP CLI unavailable")
    data=tmp_path/"data";router=tmp_path/"router.php";store=ROOT/"server/trophy-gallery/src/TrophyGalleryStore.php"
    router.write_text("<?php\n"+f"require {json.dumps(str(store))};$s=new P2K\\TrophyGallery\\TrophyGalleryStore({json.dumps(str(data))});$a=$_GET['a']??'';try{{if($a==='save'){{$b=json_decode(file_get_contents('php://input'),true);$o=$s->save($b,$b['revision']??null);}}elseif($a==='upload'){{$o=$s->uploadAndAssign($_FILES['artwork'],$_POST['trophy_id'],$_POST['slot'],$_POST['source'],(int)$_POST['revision']);}}elseif($a==='duplicate'){{$b=json_decode(file_get_contents('php://input'),true);$o=$s->duplicate($b['id'],$b['revision']);}}elseif($a==='delete'){{$b=json_decode(file_get_contents('php://input'),true);$o=$s->delete($b['id'],$b['revision']);}}elseif($a==='audit'){{$b=json_decode(file_get_contents('php://input'),true);$o=$s->audit((bool)$b['purge']);}}else{{$o=$s->catalogue(false);}}echo json_encode(['ok'=>true,'out'=>$o]);}}catch(Throwable $e){{http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}}",encoding="utf-8")
    port=free_port();proc=subprocess.Popen([php,"-S",f"127.0.0.1:{port}",str(router)],cwd=tmp_path,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for _ in range(40):
        try:urllib.request.urlopen(f"http://127.0.0.1:{port}/?a=list",timeout=.2);break
        except Exception:time.sleep(.05)
    else:proc.terminate();raise AssertionError("PHP test server did not start")
    yield f"http://127.0.0.1:{port}/",data
    proc.terminate();proc.wait(timeout=5)

def call(base,action,payload=None,file=None):
    if file:
        body,ctype=multipart(payload,file);request=urllib.request.Request(f"{base}?a={action}",data=body,headers={"Content-Type":ctype})
    else:
        body=json.dumps(payload or {}).encode();request=urllib.request.Request(f"{base}?a={action}",data=body,headers={"Content-Type":"application/json"})
    try:return json.loads(urllib.request.urlopen(request).read())
    except urllib.error.HTTPError as error:return json.loads(error.read())

def test_media_lifecycle(server,tmp_path):
    base,data=server;png=tmp_path/"image.png";Image.new("RGBA",(8,8),(200,100,20,255)).save(png)
    saved=call(base,"save",{"status":"draft","league":"OWL","title":"One","revision":0})["out"];rid=saved["value"]["id"];rev=saved["revision"]
    vignette=call(base,"upload",{"trophy_id":rid,"slot":"vignette","source":"upload","revision":rev},png)["out"];rev=vignette["revision"];vid=vignette["value"]["media"]["id"]
    modal=call(base,"upload",{"trophy_id":rid,"slot":"modal","source":"engraving","revision":rev},png)["out"];rev=modal["revision"];mid=modal["value"]["media"]["id"]
    assert vid!=mid and len(list((data/"artwork").glob("*")))==2
    replaced=call(base,"upload",{"trophy_id":rid,"slot":"vignette","source":"engraving","revision":rev},png)["out"];rev=replaced["revision"];new_vid=replaced["value"]["media"]["id"]
    assert new_vid!=vid and not list((data/"artwork").glob(f"{vid}.*")) and list((data/"artwork").glob(f"{mid}.*"))
    failed=call(base,"upload",{"trophy_id":rid,"slot":"vignette","source":"upload","revision":0},png)
    assert not failed["ok"] and list((data/"artwork").glob(f"{new_vid}.*")) and len(list((data/"artwork").glob("*")))==2
    duplicate=call(base,"duplicate",{"id":rid,"revision":rev})["out"];rev=duplicate["revision"];copy=duplicate["value"]
    assert copy["vignette_media_id"] not in ("",new_vid) and copy["modal_media_id"] not in ("",mid)
    assert len(list((data/"artwork").glob("*")))==4
    rev=call(base,"delete",{"id":rid,"revision":rev})["out"]["revision"]
    assert len(list((data/"artwork").glob("*")))==2
    catalog=json.loads((data/"catalog.json").read_text());assert catalog["records"][0]["id"]==copy["id"] and len(catalog["media"])==2
    orphan=data/"artwork"/("a"*32+".png");orphan.write_bytes(png.read_bytes())
    assert call(base,"audit",{"purge":False})["out"]["orphan_files"]==[orphan.name]
    cleaned=call(base,"audit",{"purge":True})["out"]
    assert cleaned["purged"]==1 and not orphan.exists() and len(list((data/"artwork").glob("*")))==2

def test_invalid_image_keeps_previous_slot(server,tmp_path):
    base,data=server;png=tmp_path/"ok.png";Image.new("RGB",(4,4)).save(png);bad=tmp_path/"bad.png";bad.write_text("not an image")
    saved=call(base,"save",{"status":"draft","league":"OWL","title":"Safe","revision":0})["out"]
    uploaded=call(base,"upload",{"trophy_id":saved["value"]["id"],"slot":"vignette","source":"upload","revision":saved["revision"]},png)["out"]
    failed=call(base,"upload",{"trophy_id":saved["value"]["id"],"slot":"vignette","source":"upload","revision":uploaded["revision"]},bad)
    assert not failed["ok"] and len(list((data/"artwork").glob("*")))==1
