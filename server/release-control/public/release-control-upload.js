(() => {
  const form=document.getElementById('packageUploadForm');
  if(!form || !window.XMLHttpRequest) return;
  const wrap=document.getElementById('packageUploadProgress');
  const status=document.getElementById('packageUploadStatus');
  const percent=document.getElementById('packageUploadPercent');
  const bar=document.getElementById('packageUploadProgressBar');
  const fill=document.getElementById('packageUploadProgressFill');
  const submit=document.getElementById('packageUploadSubmit');

  const setProgress=(value,label)=>{
    const pct=Math.max(0,Math.min(100,Math.round(value)));
    if(fill) fill.style.width=pct+'%';
    if(percent) percent.textContent=pct+'%';
    if(bar) bar.setAttribute('aria-valuenow',String(pct));
    if(status && label) status.textContent=label;
  };

  form.addEventListener('submit',(event)=>{
    if(!form.reportValidity()) return;
    event.preventDefault();
    if(wrap) wrap.hidden=false;
    if(submit){submit.disabled=true;submit.textContent='Uploading…';}
    setProgress(0,'Uploading release ZIP…');

    const xhr=new XMLHttpRequest();
    xhr.open('POST',form.action,true);
    xhr.upload.addEventListener('progress',(e)=>{
      if(e.lengthComputable){
        const pct=(e.loaded/e.total)*100;
        setProgress(pct,pct<100?'Uploading release ZIP…':'Upload complete. Verifying and installing candidate…');
      }
    });
    xhr.upload.addEventListener('load',()=>{
      setProgress(100,'Upload complete. Verifying and installing candidate…');
      if(percent) percent.textContent='100% · installing';
    });
    xhr.addEventListener('load',()=>{
      if(xhr.status>=200 && xhr.status<400){
        if(status) status.textContent='Installation response received. Loading result…';
        window.location.href=xhr.responseURL || '/ReleaseControl.php#package-upload';
        return;
      }
      let detail='';
      try {
        const text=(xhr.responseText||'').trim();
        const match=text.match(/Release ZIP installation failed\.\s*<\/strong>\s*([^<]+)/i);
        if(match&&match[1]) detail=match[1].trim();
      } catch (_) {}
      if(status) status.textContent=detail?('Installation failed: '+detail):('Installation failed with HTTP '+xhr.status+'.');
      if(submit){submit.disabled=false;submit.textContent='Upload and install candidate ZIP';}
    });
    xhr.addEventListener('error',()=>{
      if(status) status.textContent='Upload failed because the browser lost the connection.';
      if(submit){submit.disabled=false;submit.textContent='Upload and install candidate ZIP';}
    });
    xhr.addEventListener('abort',()=>{
      if(status) status.textContent='Upload cancelled.';
      if(submit){submit.disabled=false;submit.textContent='Upload and install candidate ZIP';}
    });
    xhr.send(new FormData(form));
  });
})();
