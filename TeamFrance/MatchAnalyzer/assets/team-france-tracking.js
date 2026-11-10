/* Team France add-to-monitoring UI; no P2K administrator credentials required. */
(() => {
  "use strict";
  window.P2K_TEAM_FRANCE_ANALYZER = Object.freeze({clubSlug:"team-france",clubName:"Team France",shortName:"TF"});
  window.P2K_MATCH_HISTORY_HOME = window.P2K_TEAM_FRANCE_ANALYZER;
  const host=document.getElementById("teamFranceTrackingControls");
  const base="/TeamFrance/MatchAnalyzer/api/index.php";
  let serial=0;
  async function send(method,id,csrf="") {
    const url=new URL(base,location.origin);
    if(method==="GET")url.searchParams.set("match",id);
    const response=await fetch(url,{method,credentials:"same-origin",cache:"no-store",
      headers:method==="POST"?{"Content-Type":"application/json","X-Club-Tools-Request":"team-france-track-match","X-P2K-OAuth-CSRF":csrf}:{},
      body:method==="POST"?JSON.stringify({match:id}):undefined});
    const raw=await response.text();
    let json=null;
    try { json=raw ? JSON.parse(raw) : null; } catch (_) { /* invalid response */ }
    if(!json || typeof json!=="object")
      throw new Error("Tracking endpoint returned HTTP "+response.status+" without valid JSON.");
    if(!response.ok||!json.ok) {
      const detail=json?.error?.message||("Tracking service returned HTTP "+response.status+".");
      const ref=json?.diagnosticId ? " Reference: "+json.diagnosticId : "";
      throw new Error(detail+ref);
    }
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
      host.replaceChildren();
      const label=document.createElement("span");
      label.textContent="This match is not currently monitored. ";
      const button=document.createElement("button");
      button.type="button";button.className="p2k-main-button";button.textContent="Track this match";
      host.append(label,button);
      button.addEventListener("click",async()=>{
        button.disabled=true;label.textContent="Enabling monitoring… ";
        try {
          const csrf=document.querySelector('meta[name="team-france-oauth-csrf"]')?.content||"";
          if(!csrf)throw new Error("Authenticated token missing. Reload the analyzer.");
          await send("POST",id,csrf);
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
  window.P2K_TEAM_FRANCE_TRACKING=Object.freeze({refresh,clear(){serial++;if(host)host.textContent="";}});
})();
