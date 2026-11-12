/* Recruitment scan pagination + recoverable CSV staging (v2.15.9). */
(function(root){"use strict";
  const PAGE_SIZE=50, DATABASE="p2k-recruitment-archive-v2159", STORE="pending", KEY="last";
  function page(rows,number,size=PAGE_SIZE){
    const bounded=Math.max(1,Math.min(PAGE_SIZE,Math.floor(Number(size)||PAGE_SIZE)));
    const total=rows.length, pages=Math.max(1,Math.ceil(total/bounded));
    const current=Math.max(1,Math.min(pages,Math.floor(Number(number)||1)));
    const start=(current-1)*bounded, items=rows.slice(start,start+bounded);
    return {items,page:current,pages,total,from:total?start+1:0,to:start+items.length};
  }
  function filterSort(rows,query,field,direction){
    const needle=String(query||"").trim().toLowerCase(),sign=direction===1?1:-1;
    return rows.filter(r=>String(r.username||"").toLowerCase().includes(needle)).sort((a,b)=>{
      const value=r=>field==="timeout"?r.live?.timeout_percent:field==="load"?r.live?.current_match_load:r[field];
      const av=value(a),bv=value(b);
      // Unavailable match-load values always sort after actual numbers.
      if(av==null||bv==null)return (av==null?1:0)-(bv==null?1:0)||String(a.username).localeCompare(String(b.username));
      const x=typeof av==="string"?String(av).localeCompare(String(bv)):Number(av)-Number(bv);
      return (Number.isFinite(x)?x:0)*sign||String(a.username).localeCompare(String(b.username));
    });
  }
  function open(){
    return new Promise((resolve,reject)=>{
      if(!root.indexedDB){reject(new Error("Browser storage is unavailable"));return;}
      let request;
      try{request=root.indexedDB.open(DATABASE,1)}catch(error){reject(error);return;}
      request.onupgradeneeded=()=>{if(!request.result.objectStoreNames.contains(STORE))request.result.createObjectStore(STORE)};
      request.onsuccess=()=>resolve(request.result);
      request.onerror=()=>reject(request.error||new Error("Browser storage could not open"));
      request.onblocked=()=>reject(new Error("Browser storage is blocked by another tab"));
    });
  }
  async function transact(mode,operation){
    const db=await open();
    try{
      return await new Promise((resolve,reject)=>{
        let tx,req,result;
        try{tx=db.transaction(STORE,mode);req=operation(tx.objectStore(STORE));}catch(error){reject(error);return;}
        req.onsuccess=()=>{result=req.result};
        req.onerror=()=>reject(req.error||new Error("Browser storage request failed"));
        tx.oncomplete=()=>resolve(result);
        tx.onabort=()=>reject(tx.error||new Error("Browser storage transaction aborted"));
        tx.onerror=()=>reject(tx.error||new Error("Browser storage transaction failed"));
      });
    }finally{db.close()}
  }
  const pending={
    async load(){const record=await transact("readonly",store=>store.get(KEY));return record||null},
    async save(record){
      if(!record||!Number.isInteger(Number(record.match?.id))||Number(record.match.id)<=0||typeof record.csv!=="string"||!record.csv.length||record.csv.length>20000000)throw new Error("Invalid pending CSV");
      const value={id:String(record.id||Date.now()),match:{id:Number(record.match.id),name:String(record.match.name||"")},csv:record.csv,createdAt:Date.now()};
      await transact("readwrite",store=>store.put(value,KEY));
      return value;
    },
    async clear(id){
      const stored=await this.load();
      if(stored&&String(stored.id)===String(id))await transact("readwrite",store=>store.delete(KEY));
    }
  };
  root.P2K_RECRUIT_UI_HELPERS={PAGE_SIZE,page,filterSort,pending};
})(window);
