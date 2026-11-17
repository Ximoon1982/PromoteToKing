/* Shared organizer-based MCA preselector for TGP and Team France (0.3.4).
 * Discovery never changes the widget until the existing arena form is saved.
 */
(() => {
  "use strict";
  const byId = id => document.getElementById(id);
  const state = {revision:0,organizers:[],scan:null,busy:false,cancel:false,ready:false,ctx:null,notice:null};
  const host = byId("arenaDiscoveryPanel");
  if (!host) return;

  async function request(action,body=null) {
    const ctx=state.ctx;
    if (!ctx) throw new Error("Club administrator session is unavailable.");
    const headers={Accept:"application/json"};
    if (body!==null) {
      headers["Content-Type"]="application/json";
      headers["X-"+(ctx.clubSlug==="team-france"?"TF":"TGP")+"-CSRF"]=ctx.getCsrf();
    }
    const response=await fetch(ctx.api+"?action=arena-discovery-"+action,{
      method:body===null?"GET":"POST", credentials:"same-origin",cache:"no-store",
      headers, ...(body!==null?{body:JSON.stringify(body)}:{})
    });
    const data=await response.json().catch(()=>({}));
    if (!response.ok || data.ok===false) {
      const err=new Error(data?.error?.message || "Arena discovery failed (HTTP "+response.status+").");
      err.status=response.status;
      throw err;
    }
    return data;
  }
  function message(text,isError=false,persist=true) {
    if(persist)state.notice={text:String(text||""),isError:!!isError};
    const active=state.notice||{text:String(text||""),isError:!!isError};
    const node=byId("arenaDiscoveryStatus");
    node.textContent=active.text;
    node.classList.toggle("is-error",active.isError);
  }
  function apply(data) {
    state.revision=Number(data.revision)||0;
    state.organizers=Array.isArray(data.organizers)?data.organizers:[];
    state.scan=data.scan||null;
    render();
  }
  function knownIds() {
    const ids=new Set();
    for (const a of state.ctx?.getArenas()||[]) {
      const path=String(a.link||"").split(/[?#]/,1)[0];
      const m=path.match(/\/(?:play\/arena|tournament\/live\/arena)\/(?:[^/]*?-)?([0-9]{4,15})(?:\/|$)/i);
      if(m) ids.add(m[1]);
    }
    return ids;
  }
  function element(tag,cls,text) {
    const node=document.createElement(tag);
    if(cls) node.className=cls;
    if(text!==undefined) node.textContent=String(text);
    return node;
  }
  function render() {
    if(!state.ready) return;
    const watch=byId("arenaDiscoveryOrganizers");
    watch.replaceChildren();
    if(!state.organizers.length) watch.append(element("p","arena-discovery-empty",
      "No organizers monitored yet. Add a numeric Chess.com club ID and name."));
    state.organizers.forEach((org,index)=>{
      const row=element("div","arena-discovery-organizer");
      const name=element("strong","",org.name+" · #"+org.clubId);
      const check=element("input");
      check.type="checkbox";
      check.checked=org.enabled===true;
      check.disabled=state.busy;
      check.setAttribute("aria-label","Monitor "+org.name);
      check.addEventListener("change",()=>void editOrganizers(rows=>{
        rows[index].enabled=check.checked;
      }));
      const label=element("label","arena-discovery-checkbox");
      label.append(check,document.createTextNode(" Monitor"));
      const remove=element("button","secondary","Remove");
      remove.type="button";
      remove.disabled=state.busy;
      remove.addEventListener("click",()=>void editOrganizers(rows=>rows.splice(index,1)));
      row.append(name,label,remove);
      watch.append(row);
    });
    byId("arenaDiscoveryAddOrganizer").disabled=state.busy;
    const scan=state.scan;
    const scanButton=byId("arenaDiscoveryScan");
    scanButton.disabled=state.busy;
    scanButton.textContent=scan?.status==="running"?"Resume scan":"Scan upcoming arenas";
    const cancel=byId("arenaDiscoveryCancel");
    cancel.hidden=!state.busy&&scan?.status!=="running";
    cancel.disabled=state.cancel;
    const known=knownIds();
    const now=Date.now();
    const seen=new Set();
    const candidates=(Array.isArray(scan?.results)?scan.results:[]).filter(a=>{
      const id=String(a.arenaId||"");
      if(known.has(id)||seen.has(id)||!Number.isFinite(Number(a.startEpoch))||
         Number(a.startEpoch)*1000<=now) return false;
      seen.add(id);return true;
    }).sort((a,b)=>Number(a.startEpoch)-Number(b.startEpoch));
    const list=byId("arenaDiscoveryResults");
    list.replaceChildren();
    for(const a of candidates) {
      const row=element("div","arena-discovery-result");
      const info=element("div","arena-discovery-result-copy");
      const link=element("a","",a.name||"Arena #"+a.arenaId);
      link.href="https://www.chess.com/play/arena/"+encodeURIComponent(String(a.arenaId));
      link.target="_blank";link.rel="noopener noreferrer";
      const when=new Date(Number(a.startEpoch)*1000);
      const tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"browser local";
      const local=Number.isFinite(when.getTime()) ?
        new Intl.DateTimeFormat(undefined,{
          year:"numeric",month:"short",day:"numeric",hour:"2-digit",
          minute:"2-digit",timeZoneName:"short"
        }).format(when) : "Time unavailable";
      const description=element("small","",
        String(a.organizer||"Organizer")+" · #"+String(a.arenaId)+
        " · "+local+" ("+tz+")");
      info.append(link,description);
      const add=element("button","secondary","Add");
      add.type="button";
      add.disabled=state.busy||state.ctx?.isSaving?.()===true;
      add.addEventListener("click",()=>{
        if(knownIds().has(String(a.arenaId))) {
          message("This arena is already configured.",true);render();return;
        }
        state.ctx?.preselect?.(a);
        message("Arena preselected. Review duration and time control, then save manually.");
      });
      row.append(info,add);
      list.append(row);
    }
    if(!candidates.length&&scan?.status==="complete")list.append(element(
      "p","arena-discovery-empty","No new upcoming arenas found for the monitored clubs."));
    if(scan) message(
      (scan.status||"Unknown")+" · "+Number(scan.pages||0)+" pages · "+
      Number(scan.seen||0)+" arenas examined · "+candidates.length+
      " new candidates"+(scan.message?" · "+scan.message:""),false,false);
    else message("No scan yet. Add organizers and click Scan upcoming arenas.",false,false);
  }
  async function editOrganizers(change) {
    if(state.busy)return;
    state.notice=null;
    const next=state.organizers.map(row=>({...row}));
    change(next);
    try {
      const data=await request("save",{revision:state.revision,organizers:next});
      apply(data);
    } catch(e) {
      message(e.message,true);
      try{apply(await request("state"));}catch{}
    }
  }
  function connect() {
    byId("arenaDiscoveryAddOrganizer").addEventListener("click",()=>void (async()=>{
      const id=byId("arenaDiscoveryClubId").value.trim();
      const name=byId("arenaDiscoveryClubName").value.trim();
      if(!/^[1-9][0-9]{0,14}$/.test(id)||!name||name.length>80){
        message("Provide a numeric club ID and an organizer name (1–80 characters).",true);return;
      }
      if(state.organizers.some(o=>String(o.clubId)===id)){
        message("This organizing club is already monitored.",true);return;
      }
      await editOrganizers(rows=>rows.push({clubId:id,name,enabled:true}));
      if(state.organizers.some(o=>String(o.clubId)===id)){
        byId("arenaDiscoveryClubId").value="";
        byId("arenaDiscoveryClubName").value="";
      }
    })());
    byId("arenaDiscoveryScan").addEventListener("click",()=>void scan());
    byId("arenaDiscoveryCancel").addEventListener("click",()=>{
      state.cancel=true;
      render();
      if(!state.busy&&state.scan?.status==="running")
        void request("cancel",{scanId:state.scan.id}).then(apply).catch(e=>message(e.message,true));
    });
  }
  async function scan() {
    if(state.busy||!state.ctx)return;
    state.notice=null;state.busy=true;state.cancel=false;render();
    try {
      if(state.scan?.status!=="running")apply(await request("begin",{}));
      while(!state.cancel&&state.scan?.status==="running") {
        const result=await request("page",{
          scanId:state.scan.id,page:Number(state.scan.pages||0)+1
        });
        state.scan=result.scan;
        render();
      }
      if(state.cancel&&state.scan?.status==="running")
        apply(await request("cancel",{scanId:state.scan.id}));
      message("Arena scan "+String(state.scan?.status||"finished")+".");
    } catch(e) {
      message(e.message,true);
      try{apply(await request("state"));}catch{}
    } finally {
      state.busy=false;state.cancel=false;render();
    }
  }

  window.P2K_STANDALONE_ARENA_DISCOVERY={
    async start(ctx) {
      state.ctx=ctx;
      state.notice=null;
      if(!state.ready){connect();state.ready=true;}
      try{apply(await request("state"));}
      catch(e){message("Arena discovery unavailable: "+e.message,true);}
    },
    render() {if(state.ready)render();},
    stop() {state.ctx=null;state.cancel=true;}
  };
})();
