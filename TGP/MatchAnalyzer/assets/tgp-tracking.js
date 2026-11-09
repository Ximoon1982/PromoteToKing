/* TGP add-to-monitoring UI; no P2K administrator credentials required. */
(() => {
  "use strict";
  const host=document.getElementById("tgpTrackingControls");
  const base="/TGP/MatchAnalyzer/api/index.php";
  let serial=0;
  async function send(method,id,csrf="") {
    const url=new URL(base,location.origin);
    if(method==="GET")url.searchParams.set("match",id);
    const response=await fetch(url,{method,credentials:"same-origin",cache:"no-store",
      headers:method==="POST"?{"Content-Type":"application/json","X-Club-Tools-Request":"tgp-track-match","X-P2K-OAuth-CSRF":csrf}:{},
      body:method==="POST"?JSON.stringify({match:id}):undefined});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json?.error?.message||"Tracking service unavailable.");
    return json;
  }
  async function refresh(id,match) {
    const job=++serial;
    if(!host)return;
    host.textContent="Checking tracking status…";
    try {
      const status=await send("GET",id);
      if(job!==serial)return;
      if(status.tracked){host.textContent="Tracked by Promote to King match monitoring.";return;}
      if(["finished","completed","complete"].includes(String(match?.status||"").toLowerCase().replace(/[ -]/g,"_"))){
        host.textContent="This finished match is not being monitored.";return;
      }
      host.replaceChildren();
      const label=document.createElement("span");
      label.textContent="This match is not currently monitored. ";
      const button=document.createElement("button");
      button.type="button";button.className="p2k-main-button";button.textContent="Track this match";
      host.append(label,button);
      button.addEventListener("click",async()=>{
        button.disabled=true;label.textContent="Enabling monitoring… ";
        try {
          const oauth=await fetch("/server/team-points/public/oauth.php?action=session",{cache:"no-store",credentials:"same-origin"});
          const session=await oauth.json();
          if(!oauth.ok||!session?.authenticated||!session?.csrf)throw new Error("Chess.com OAuth session expired.");
          await send("POST",id,session.csrf);
          if(job!==serial)return;
          host.textContent="Match tracked. Initial registration snapshot stored.";
          const graph=document.querySelector('[data-p2k-match-history="'+id+'"]');
          if(graph){delete graph.dataset.p2kHistoryLoaded;window.P2K_MATCH_HISTORY_UI?.hydrate?.(document.getElementById("p2kResults"));}
        } catch(error) {
          if(job!==serial)return;
          label.textContent="Tracking failed: "+String(error?.message||error)+" ";
          button.disabled=false;button.textContent="Retry";
        }
      });
    } catch(error) {
      if(job===serial)host.textContent="Unable to check tracking status: "+String(error?.message||error);
    }
  }
  window.P2K_TGP_TRACKING=Object.freeze({refresh,clear(){serial++;if(host)host.textContent="";}});
})();
