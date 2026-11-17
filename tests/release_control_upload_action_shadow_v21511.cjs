"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const vm=require("node:vm");
const script=fs.readFileSync("server/release-control/public/release-control-upload.js","utf8");
const page=fs.readFileSync("ReleaseControl.php","utf8");

assert(page.includes('<form method="post" action="/ReleaseControl.php" enctype="multipart/form-data" class="actions" id="packageUploadForm">'));
assert(page.includes('<input type="hidden" name="action" value="upload-release-package">'),
  "Real form contains an action-named field, shadowing form.action");
assert(page.includes('release-control-upload.js?v=2.15.11-upload-target-1'),
  "After recovery-plane activation, Release Control must load the fixed JS without stale browser cache");
assert(!script.includes("xhr.open('POST',form.action,true)"),
  "Direct form.action is shadowed by form.elements named action");
assert(script.includes("form.getAttribute('action')"),
  "Always obtain the actual URL from the form's HTML action attribute");

const recorded=[];
const nodes=new Map();
const submitListeners=[];
const form={
  action:{tagName:"INPUT",name:"action",value:"upload-release-package",
    toString(){return"[object HTMLInputElement]"}},
  getAttribute(name){return name==="action"?"/ReleaseControl.php":null},
  reportValidity(){return true},
  addEventListener(type,callback){
    if(type==="submit")submitListeners.push(callback);
  }
};
nodes.set("packageUploadForm",form);
nodes.set("packageUploadProgress",{hidden:true});
nodes.set("packageUploadStatus",{textContent:""});
nodes.set("packageUploadPercent",{textContent:""});
nodes.set("packageUploadProgressBar",{setAttribute(name,value){this[name]=value}});
nodes.set("packageUploadProgressFill",{style:{width:""}});
nodes.set("packageUploadSubmit",{disabled:false,textContent:"Upload and install candidate ZIP"});

class FakeXHR{
  constructor(){this.upload={addEventListener:(type,cb)=>{this.upload[type]=cb}};
    this.events={};
    this.status=0;
    this.responseURL="";
  }
  open(method,url,async){
    recorded.push({method,url,async});
    this.target=url;
  }
  addEventListener(type,callback){this.events[type]=callback;}
  send(formData){this.sent=formData;recorded.push({sent:true});}
}

class FakeFormData{constructor(node){assert.equal(node,form);this.form=node;}}
const href="https://www.promotetoking.org/ReleaseControl.php";
const window={XMLHttpRequest:FakeXHR,location:{href}};
const document={baseURI:href,getElementById(id){return nodes.get(id)||null}};
const ctx={document,window,URL,FormData:FakeFormData,XMLHttpRequest:FakeXHR,Math,String,Number};
vm.runInNewContext(script,ctx,{filename:"release-control-upload.js",timeout:2000});
assert.equal(submitListeners.length,1,"XHR hook must be attached to the actual form");
assert.equal(String(form.action),"[object HTMLInputElement]","Form action must simulate real Chromium shadow collision");
let prevented=false;
submitListeners[0]({preventDefault(){prevented=true}});
assert(prevented,"Uploader should override normal form submission only to show progress");
assert.deepEqual(recorded[0],{method:"POST",url:href,async:true},
  "XHR POST must target the actual /ReleaseControl.php, never [object HTMLInputElement]");
assert(!recorded[0].url.includes("[object"),"Bad path from action input collision");
assert(recorded[1].sent,"XHR must send original multipart form data");
assert.equal(nodes.get("packageUploadSubmit").disabled,true,"Prevent duplicate upload");
assert.equal(nodes.get("packageUploadProgress").hidden,false,"Existing progress UI retained");
assert.equal(nodes.get("packageUploadProgressBar")["aria-valuenow"],"0","Progress starts at 0");
// The server's existing 303 POST->GET redirect ends in a readable final response.
assert(script.includes("xhr.responseURL"),"Keep existing redirect-to-result behavior");
console.log("PASS browser action-name shadow regression, exact /ReleaseControl.php XHR target, original multipart/CSRF, progress and redirect behavior.");
