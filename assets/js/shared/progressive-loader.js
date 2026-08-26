(function(global){'use strict';
  const pending=[]; let active=0; const MAX=2;
  function pump(){while(active<MAX&&pending.length){const job=pending.shift();if(job.signal?.aborted){job.reject(new DOMException('Aborted','AbortError'));continue;}active++;Promise.resolve().then(job.run).then(job.resolve,job.reject).finally(()=>{active--;pump();});}}
  function lowPriority(run,{signal}={}){return new Promise((resolve,reject)=>{pending.push({run,signal,resolve,reject});pump();});}
  function afterFirstPaint(run){requestAnimationFrame(()=>requestAnimationFrame(()=>setTimeout(run,0)));}
  function afterIdle(run,{timeout=1800}={}){if('requestIdleCallback'in global)global.requestIdleCallback(run,{timeout});else setTimeout(run,250);}
  function observe(target,loader,{rootMargin='600px 0px',once=true}={}){const el=typeof target==='string'?document.querySelector(target):target;if(!el)return()=>{};if(!('IntersectionObserver'in global)){afterFirstPaint(loader);return()=>{};}const observer=new IntersectionObserver(entries=>{for(const entry of entries){if(!entry.isIntersecting)continue;loader(entry);if(once)observer.disconnect();}},{rootMargin});observer.observe(el);return()=>observer.disconnect();}
  function snapshotGet(key,maxAge=86400000){try{const raw=sessionStorage.getItem('p2k:'+key);if(!raw)return null;const row=JSON.parse(raw);if(!row||!row.savedAt||Date.now()-row.savedAt>maxAge)return null;return row;}catch(_){return null;}}
  function snapshotSet(key,payload){try{sessionStorage.setItem('p2k:'+key,JSON.stringify({savedAt:Date.now(),payload}));}catch(_){} }
  function canPrefetch(){const c=navigator.connection||navigator.mozConnection||navigator.webkitConnection;return !(c?.saveData||['slow-2g','2g'].includes(String(c?.effectiveType||'')));}
  global.P2K_PROGRESSIVE={afterFirstPaint,afterIdle,observe,lowPriority,snapshotGet,snapshotSet,canPrefetch};
})(window);
