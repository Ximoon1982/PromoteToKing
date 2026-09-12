"use strict";
const assert = require("assert");
const C = require("../assets/js/pages/recruit-match-v2-core.js");

assert.equal(C.parseMatchReference("123456"), "123456");
assert.equal(C.parseMatchReference("https://www.chess.com/club/matches/p2k/123456"), "123456");
assert.equal(C.parseMatchReference("p2k-v-opponent-123456"), "123456");
assert.equal(C.ratingCategory("chess960"), "daily_chess960");
assert.equal(C.ratingCategory("chess"), "daily_standard");

const rows = [
  {username:"Alpha",username_key:"alpha",rating:1400},
  {username:"Beta",username_key:"beta",rating:1599},
  {username:"Comma, Quote \"",username_key:"csv",rating:1500},
  {username:"Registered",username_key:"registered",rating:1500},
  {username:"Opponent",username_key:"opponent",rating:1500},
  {username:"Low",username_key:"low",rating:999},
  {username:"Unrated",username_key:"unrated",rating:null}
];
const pre = C.preselect(rows,{min:1000,max:1600,registered:new Set(["registered"]),opponent:new Set(["opponent"])});
assert.deepEqual(pre.candidates.map(x=>x.username_key),["alpha","beta","csv"]);
assert.deepEqual(pre.counts,{total:7,unrated:1,outsideRating:1,registered:1,opponent:1});
assert.equal(C.preselect(rows,{min:0,max:Infinity,registered:new Set(),opponent:new Set()}).candidates.length,6);

const now = 2_000_000;
assert.equal(C.verify(rows[0],{last_online:now-3600,timeout_percent:5},{onlineDays:1,maxTimeout:5},now).decision,"eligible");
assert.equal(C.verify(rows[0],{last_online:now-90000,timeout_percent:0},{onlineDays:1,maxTimeout:5},now).decision,"excluded");
assert.equal(C.verify(rows[0],{last_online:now-3600,timeout_percent:5.1},{onlineDays:2,maxTimeout:5},now).decision,"excluded");
assert.equal(C.verify(rows[0],{error:"offline"},{onlineDays:1,maxTimeout:5},now).decision,"unverified");
// Five candidates completing concurrently at one second represent 5/s wall-clock
// throughput: five remaining candidates take ~1s, not 5s of summed request time.
assert.equal(C.eta([1000,1000,1000,1000,1000],5,1000),1);
assert.equal(C.eta([],3,1000),null);
assert.equal(C.eta([500],3,1000),null);
assert.equal(C.eta([1000],0,1000),0);
const csv=C.csv([C.verify(rows[2],{last_online:now-10,timeout_percent:1,current_match_load:7},{onlineDays:1,maxTimeout:5},now)],{id:"42",name:"A, \"B\"",ratingCategory:"daily_standard",min:0,max:Infinity});
assert(csv.startsWith("\uFEFF"));
assert(csv.includes('"Comma, Quote """'));
assert(csv.includes('"A, ""B"""'));
assert(csv.includes('"7"'));
console.log("Recruit Match v2 core tests passed.");
