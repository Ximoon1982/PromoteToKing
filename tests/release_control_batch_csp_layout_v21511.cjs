"use strict";
const assert=require("node:assert/strict");
const fs=require("node:fs");
const vm=require("node:vm");
const php=fs.readFileSync("ReleaseControl.php","utf8");
const js=fs.readFileSync("server/release-control/public/release-control-batch.js","utf8");
const original=fs.readFileSync("server/release-control/public/release-control-upload.js","utf8");

assert(php.includes("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"),
  "Scripts and same-origin upload XHR must be allowed by CSP, other restrictions preserved.");
assert(!php.includes("script-src 'unsafe-inline'"),"Do not weaken CSP to permit inline scripts.");
for(const m of php.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/g)){
  assert(/src="\/server\/release-control\/public\/release-control-(batch|upload)\.js(?:\?[^"]*)?"/.test(m[1]),
    "Only audited same-origin release control scripts may execute.");
  assert.equal(m[2].trim(),"","Inline JavaScript is still blocked by strict CSP.");
}
assert(php.includes('src="/server/release-control/public/release-control-batch.js?v=2.15.11-batch-csp-1"'),
  "Batch script must load after HTML and have a versioned cache identity.");
assert(php.includes('src="/server/release-control/public/release-control-upload.js"'),
  "Release ZIP upload progress must remain available.");
assert(original.includes("new XMLHttpRequest()"),"Existing XHR package uploader must remain intact.");
const headings=[
  "Recovery health","Install release ZIP","Candidate installation","Deployment controls",
  "Protected runtime","Release-slot filesystem","Release/version management",
  "Filesystem &amp; inode audit","Filesystem cleanup"
];
const locations=headings.map(h=>php.indexOf("<h2>"+h+"</h2>"));
assert(locations.every(i=>i>=0),JSON.stringify(locations));
assert(locations.every((n,i)=>i===0||n>locations[i-1]),
  "ZIP upload, candidate verification and deployment controls must immediately follow Recovery health.");
assert.equal((php.match(/id="package-upload"/g)||[]).length,1);
assert.equal((php.match(/id="rcReleaseBatchForm"/g)||[]).length,1);
assert.equal((php.match(/id="rcFilesystemBatchForm"/g)||[]).length,1);
assert(php.includes("!empty($release['protected'])")&&php.includes("!empty($release['cleanup_ready'])"),
  "Existing release eligibility checks must remain.");
assert(php.includes("!empty($artifact['cleanup_ready'])"),
  "Existing maintenance eligibility checks must remain.");
assert(php.includes("$releaseManager->deleteReleases($ids,$username)")&&php.includes("$filesystemCleanup->deleteMany($items,$username)"),
  "Batch actions must still use server-side protected deletion managers.");

function makeCheckbox(){
  return {checked:false,indeterminate:false, listeners:{},
    addEventListener(type,fn){this.listeners[type]=fn;},
    change(){this.listeners.change?.();}};
}
function makeForm(kind,n){
  const items=Array.from({length:n},makeCheckbox),all=makeCheckbox(),counter={textContent:"0"},button={disabled:false};
  const form={listeners:{},querySelectorAll(selector){
    assert.equal(selector,'[data-batch-item="'+kind+'"]');return items;
  },querySelector(selector){
    if(selector==='[data-batch-all="'+kind+'"]')return all;
    if(selector==='[data-batch-count="'+kind+'"]')return counter;
    if(selector==='button[type="submit"]')return button;
    throw new Error("Unrecognized selector "+selector);
  },addEventListener(type,fn){this.listeners[type]=fn;}};
  return {items,all,counter,button,form};
}
const release=makeForm("release",3),filesystem=makeForm("filesystem",2),prompts=[];
const document={
  getElementById(id){
    if(id==="rcReleaseBatchForm")return release.form;
    if(id==="rcFilesystemBatchForm")return filesystem.form;
    return null;
  }
};
const window={confirm(text){prompts.push(text);return false;}};
vm.runInNewContext(js,{document,window},{filename:"release-control-batch.js",timeout:2000});
for(const group of [release,filesystem]){
  assert.equal(group.button.disabled,true,"Initially disabled with zero selected");
  assert.equal(group.counter.textContent,"0");
  group.items[0].checked=true;group.items[0].change();
  assert.equal(group.counter.textContent,"1");
  assert.equal(group.button.disabled,false);
  assert.equal(group.all.checked,false);
  assert.equal(group.all.indeterminate,true);
  group.all.checked=true;group.all.change();
  assert.equal(group.counter.textContent,String(group.items.length),"Select-all should update count");
  assert(group.items.every(item=>item.checked));
  assert.equal(group.all.indeterminate,false);
  group.items[0].checked=false;group.items[0].change();
  assert.equal(group.counter.textContent,String(group.items.length-1));
  assert.equal(group.all.indeterminate,true);
  group.all.checked=false;group.all.change();
  assert(group.items.every(item=>!item.checked));
  assert.equal(group.counter.textContent,"0");
  assert.equal(group.button.disabled,true);
  let prevented=false;
  group.form.listeners.submit({preventDefault(){prevented=true;}});
  assert.equal(prevented,true,"Submitting zero selected must be refused");
  group.items[0].checked=true;group.items[0].change();
  prevented=false;
  group.form.listeners.submit({preventDefault(){prevented=true;}});
  assert.equal(prevented,true,"User confirmation cancel must prevent deletion");
}
assert.equal(prompts.length,2,"Each batch form must explicitly confirm deletion");
assert(prompts.some(text=>text.includes("obsolete release(s)")));
assert(prompts.some(text=>text.includes("maintenance artifact(s)")));
console.log("PASS strict CSP external scripts, accessible same-origin ZIP XHR, page section order, both checkbox counts/select-all, zero-selection guard and deletion confirmation");
