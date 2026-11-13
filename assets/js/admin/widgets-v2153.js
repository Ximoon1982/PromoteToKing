/* P2K v2.15.3 — Team / Widgets native administration. */
(() => {
  "use strict";
  if(window.P2K_WIDGETS_ADMIN)return;
  const ids=['club-pulse','recruitment-radar','trophy-spotlight','rivalry-spotlight','club-progress','around-the-world','player-spotlight','tournament-winners','hall-of-fame','competition'];
  const defaults={
    'club-pulse':{period:['7d','30d'],showMembers:[true,false]},
    'recruitment-radar':{window:['24h','48h','72h','7d'],limit:[3,4,6,8]},
    'trophy-spotlight':{mode:['latest','random']},
    'rivalry-spotlight':{},
    'club-progress':{lowerReferencePoints:'number',lowerReferenceLabel:'reference-label',upperReferencePoints:'number',upperReferenceLabel:'reference-label'},
    'around-the-world':{metric:['matches','clubs'],limit:[5,8,10,15]},
    'player-spotlight':{limit:[3,5],showArena:[true,false],showMedals:[true,false]},
    'tournament-winners':{medalPageSize:[3,6,9]},
    'hall-of-fame':{medalPageSize:[3,6,9]},
    'competition':{}
  };
  const labels={period:'Time period',showMembers:'Show member count',window:'Recruitment window',limit:'Items to display',mode:'Trophy order',opponent:'Opponent slug (blank = most frequent)',metric:'Primary metric',showArena:'Include MCA arena scoring',showMedals:'Show tournament medals',medalPageSize:'Medalists per page',lowerReferencePoints:'Lower reference · Club Points',lowerReferenceLabel:'Lower reference label',upperReferencePoints:'Upper reference · Club Points',upperReferenceLabel:'Upper reference label'};
  const names={'club-pulse':'Club Points · Pulse','recruitment-radar':'Recruitment Radar','trophy-spotlight':'Trophy Spotlight','rivalry-spotlight':'Rivalry Spotlight','club-progress':'Club Progress','around-the-world':'P2K Around the World','player-spotlight':'Player Spotlight','tournament-winners':'Tournament Winners','hall-of-fame':'Hall of Fame','competition':'Competition'};
  const root=new URL('../../../',document.currentScript?.src||location.href);
  const api=new URL('server/widgets/public/api.php',root).href;
  const embed=new URL('server/widgets/public/embed.php',root).href;
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  let state=null,promise=null,active='',drafts={},dirty=new Set(),previewFrame=null;
  function injectStyles(){
    if(document.getElementById('p2kWidgetManagementStyle'))return;
    const style=document.createElement('style');style.id='p2kWidgetManagementStyle';style.textContent=`
    .p2kw-admin{color:#eee;padding:12px;max-width:980px;margin:0 auto;font-size:13px}.p2kw-admin *{box-sizing:border-box}.p2kw-admin>header{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:18px}.p2kw-admin h3{margin:0;color:#f6b73c;font-size:21px}.p2kw-admin h4{margin:0 0 9px;color:#eee;font-size:15px}.p2kw-admin p{margin:5px 0;color:#aaa198;font-size:12px}.p2kw-admin-panel{background:linear-gradient(145deg,#27231f,#191715);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:16px;margin:0 0 14px}.p2kw-admin-grid{display:grid;grid-template-columns:minmax(210px,1fr) minmax(0,1fr);gap:12px}.p2kw-admin label{display:block;margin-bottom:10px;font-size:11px;color:#bcb5aa;font-weight:800}.p2kw-admin input:not([type=checkbox]),.p2kw-admin select,.p2kw-admin textarea{display:block;margin-top:5px;width:100%;min-height:37px;padding:8px 9px;border:1px solid rgba(255,255,255,.17);border-radius:7px;background:#121110;color:#eee;font:inherit}.p2kw-admin textarea{min-height:105px;resize:vertical;white-space:pre-wrap;overflow-wrap:anywhere}.p2kw-admin input[type=checkbox]{accent-color:#d98d18;margin-right:7px}.p2kw-admin button{min-height:32px;padding:7px 11px;border-radius:7px;border:1px solid rgba(246,183,60,.38);background:rgba(246,183,60,.09);color:#f6b73c;font:800 12px Arial;cursor:pointer}.p2kw-admin button.primary{background:linear-gradient(135deg,#d98d18,#f6b73c);color:#17110a;border:0}.p2kw-admin button:disabled{opacity:.45;cursor:default}.p2kw-admin button:focus-visible,.p2kw-admin input:focus-visible,.p2kw-admin select:focus-visible{outline:2px solid #82c8ff;outline-offset:1px}.p2kw-admin-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:13px}.p2kw-admin-note{font-size:11px;color:#aaa198;line-height:1.5}.p2kw-admin-status{margin:0 0 10px;min-height:17px;font-size:11px;color:#bcb5aa}.p2kw-admin-status.good{color:#91e09a}.p2kw-admin-status.bad{color:#f2abab}.p2kw-admin-preview-wrap{display:flex;justify-content:center;align-items:start;margin-top:11px;padding:12px;border:1px solid rgba(255,255,255,.08);background:#141311;border-radius:9px;overflow:auto}.p2kw-admin-preview{display:block;border:0;width:260px;min-width:260px;max-width:100%;height:430px;overflow:auto;background:transparent}.p2kw-admin-live{font-size:10px;color:#91e09a}.p2kw-admin-code{position:relative}.p2kw-admin-code textarea{height:95px;font-size:10px}.p2kw-admin-kpi{font-size:11px;color:#d0c5b2}.p2kw-admin-kpi strong{color:#f6b73c}.p2kw-admin-empty{padding:20px;color:#aaa198;text-align:center}.p2kw-admin-preview-wrap[hidden]{display:none!important}@media(max-width:680px){.p2kw-admin{padding:8px}.p2kw-admin-grid{grid-template-columns:1fr}.p2kw-admin-panel{padding:11px}}
    `;document.head.appendChild(style);
  }
  async function json(url){const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||data.ok===false)throw new Error(data?.error?.message||`HTTP ${response.status}`);return data;}
  async function load(){if(state)return state;if(promise)return promise;promise=json(`${api}?action=admin-state`).then(x=>{state=x;return x;}).finally(()=>{promise=null;});return promise;}
  function host(){return document.querySelector('#adminShellNativeDetailHost[data-native-detail^="p2k-widget-"]');}
  function message(value,ok=''){const n=host()?.querySelector('[data-widgets-message]');if(n){n.textContent=value||'';n.className='p2kw-admin-status'+(ok?' '+ok:'');}}
  function opts(name,value){const alternatives=defaults[active]?.[name];if(alternatives==='text')return`<input type="text" maxlength="160" data-setting="${name}" value="${esc(value)}" placeholder="Leave empty for the most frequent rival">`;
    if(alternatives==='number')return`<input type="number" inputmode="decimal" min="0.01" max="999999999" step="0.01" data-setting="${name}" value="${esc(value??'')}" placeholder="Optional points threshold">`;
    if(alternatives==='reference-label')return`<input type="text" maxlength="32" data-setting="${name}" value="${esc(value??'')}" placeholder="Optional label (e.g. #9)">`;
    if(Array.isArray(alternatives)){
      if(typeof alternatives[0]==='boolean')return`<input type="checkbox" data-setting="${name}" ${value?'checked':''}>`;
      return`<select data-setting="${name}">${alternatives.map(v=>`<option value="${esc(v)}" ${String(value)===String(v)?'selected':''}>${esc(String(v))}</option>`).join('')}</select>`;
    }
    return'';
  }
  function iframeUrl(id,theme,preview=false){const u=new URL(embed);u.searchParams.set('widget',id);u.searchParams.set('theme',theme||'auto');if(preview)u.searchParams.set('preview','1');return u.href;}
  // All embed snippets are standalone iframe-only HTML. The host must not need
  // inline scripts or postMessage resizing; previews use the same fixed heights.
  const heights={'club-pulse':350,'recruitment-radar':650,'trophy-spotlight':490,'rivalry-spotlight':540,'club-progress':430,'around-the-world':780,'player-spotlight':760,'tournament-winners':1050,'hall-of-fame':680,'competition':565};
  function code(){
    const settings=drafts[active]||state?.widgets?.[active]||{};
    const url=iframeUrl(active,settings.theme,false),height=heights[active]||600;
    return `<iframe src="${url}" width="260" height="${height}" style="display:block;width:260px;max-width:100%;height:${height}px;border:0;overflow:auto" loading="lazy" scrolling="auto" title="${esc(settings.title||names[active])}"></iframe>`;
  }

  function fields(){const settings=drafts[active]||state?.widgets?.[active]||{};
    let rows=`<label><input type="checkbox" data-setting="enabled" ${settings.enabled?'checked':''}> Publish this widget</label><label>Widget title<input type="text" maxlength="64" data-setting="title" value="${esc(settings.title||'')}"></label><label>Theme<select data-setting="theme">${['dark','light','auto'].map(v=>`<option value="${v}" ${settings.theme===v?'selected':''}>${v}</option>`).join('')}</select></label>`;
    for(const k of Object.keys(defaults[active]||{}))rows+=`<label>${esc(labels[k]||k)}${opts(k,settings[k])}</label>`;
    return rows;
  }
  function layout(){const el=host();if(!el)return;injectStyles();
    if(!state){el.innerHTML='<div class="p2kw-admin-empty">Loading widget configuration…</div>';return;}
    if(!drafts[active])drafts[active]={...(state.widgets?.[active]||{})};
    const changed=dirty.has(active);
    el.innerHTML=`<section class="p2kw-admin" data-widgets-admin="${esc(active)}"><header><div><h3>${esc(names[active]||active)}</h3><p>Read-only embedded widget · 260 px · configurable independently from the Match Showcase</p></div><span class="p2kw-admin-live">Native widgets</span></header><div data-widgets-message class="p2kw-admin-status">${changed?'Unsaved changes':'Configuration loaded'}</div><div class="p2kw-admin-grid"><div><section class="p2kw-admin-panel"><h4>Appearance & content</h4><form data-widgets-form>${fields()}<div class="p2kw-admin-actions"><button class="primary" type="submit" ${!changed?'disabled':''}>Save settings</button><button type="button" data-revert ${!changed?'disabled':''}>Revert</button></div></form></section><section class="p2kw-admin-panel p2kw-admin-code"><h4>Embed HTML</h4><p>Paste the iframe snippet into a page that permits iframes. The embed uses one fixed-height iframe only. No script or host JavaScript is required. Longer content scrolls within the iframe.</p><textarea data-widgets-code readonly aria-label="Iframe HTML">${esc(code())}</textarea><div class="p2kw-admin-actions"><button type="button" data-copy-embed>Copy iframe HTML</button></div></section></div><div><section class="p2kw-admin-panel"><div style="display:flex;align-items:center;justify-content:space-between;gap:8px"><h4>Public preview</h4><button type="button" data-preview>Load preview</button></div><p>Preview is loaded only on demand; it uses live public data and the current saved configuration.</p><div class="p2kw-admin-preview-wrap" data-preview-wrap hidden><iframe class="p2kw-admin-preview" title="${esc(active)} preview" loading="lazy"></iframe></div></section></div></div></section>`;
    const form=el.querySelector('[data-widgets-form]');
    form.oninput=onChange;form.onchange=onChange;form.onsubmit=save;
    el.querySelector('[data-revert]').onclick=()=>{delete drafts[active];dirty.delete(active);layout();};
    el.querySelector('[data-preview]').onclick=preview;
    el.querySelector('[data-copy-embed]').onclick=copy;
  }
  function onChange(event){const target=event.target;const key=target.dataset.setting;if(!key)return;let value=target.type==='checkbox'?target.checked:target.value;
    if(key==='limit'||key==='medalPageSize')value=Number(value);
    drafts[active]={...drafts[active],[key]:value};dirty.add(active);
    const saveButton=host()?.querySelector('[data-widgets-form] button[type=submit]');if(saveButton)saveButton.disabled=false;
    const revert=host()?.querySelector('[data-revert]');if(revert)revert.disabled=false;
    const codeArea=host()?.querySelector('[data-widgets-code]');if(codeArea)codeArea.value=code();
    message('Unsaved changes');
  }
  async function save(event){event.preventDefault();const id=active;const form=event.currentTarget;
    const button=form.querySelector('[type=submit]');button.disabled=true;message('Saving…');
    try{
      const client=window.P2K_TEAM_POINTS_CLIENT;
      if(!client?.endpointRequest)throw new Error('P2K administrator request client unavailable.');
      if(client.connect)await client.connect();
      const result=await client.endpointRequest(`${api}?action=save`,{method:'PUT',body:{widget:id,revision:state.revision,settings:drafts[id]},requestKind:'widgets',timeoutMs:30000});
      state={...result};delete drafts[id];dirty.delete(id);
      if(active===id)layout();message('Saved. Preview can be refreshed to see the published settings.','good');
    }catch(error){
      if(error.status===409){
        try{state=await json(`${api}?action=admin-state`);delete drafts[id];dirty.delete(id);if(active===id)layout();message('Another administrator changed widget settings. Current server settings have been reloaded.','bad');}catch(e){message(e.message,'bad');}
      }else{button.disabled=false;message(error.message||'Unable to save widget settings','bad');}
    }
  }
  function preview(){const h=host(),frame=h?.querySelector('.p2kw-admin-preview'),wrap=h?.querySelector('[data-preview-wrap]');if(!frame||!wrap)return;
    frame.src=iframeUrl(active,drafts[active]?.theme||'auto',true);
    frame.style.height=String(heights[active]||440)+'px';wrap.hidden=false;
    h.querySelector('[data-preview]').textContent='Refresh preview';
  }
  async function copy(){const str=code();try{await navigator.clipboard.writeText(str);message('Iframe HTML copied.','good');}catch{const textarea=host()?.querySelector('[data-widgets-code]');if(textarea){textarea.focus();textarea.select();message('Select and copy the highlighted iframe HTML.');}}}
  async function mount(id){if(!ids.includes(id))return;active=id;
    if(!host())return;
    try{layout();await load();if(active===id)layout();}catch(e){const h=host();if(h)h.innerHTML='<div class="p2kw-admin-empty">Widget configuration unavailable: '+esc(e.message)+'</div>';}
  }
  function resume(){const h=host();if(!h)return;const k=h.dataset.nativeDetail||'';if(k.startsWith('p2k-widget-'))void mount(k.slice('p2k-widget-'.length));}
  window.addEventListener('p2k-admin-shell-route',resume);
  window.P2K_WIDGETS_ADMIN=Object.freeze({mount});
  queueMicrotask(resume);
})();
