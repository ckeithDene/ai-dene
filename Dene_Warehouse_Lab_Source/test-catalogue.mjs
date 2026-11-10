import assert from 'node:assert/strict';import fs from 'node:fs';
import{classify,resolveRecord}from './dist/catalogue.mjs';import{placementLocations,openZones}from './dist/placements.mjs';import{upperLocations,H}from './dist/mezzanine.mjs';import{path,plan,home,aisleX}from './dist/engine.mjs';
const {records,audit}=JSON.parse(fs.readFileSync('dist/location-catalogue.json'));
assert.equal(records.length,3731);assert.equal(new Set(records.map(r=>r.code)).size,3731);assert.equal(audit.unmatched,0);
const bases=[...upperLocations,...placementLocations];
for(let a=4;a<=9;a++)for(const side of ['L','R']){const n=a===4?(side==='L'?0:13):a===9?(side==='L'?9:6):5;for(let i=0;i<n;i++)bases.push({code:`GROUND:${a}:${side}${String.fromCharCode(65+i)}`,x:aisleX[a],y:0,z:21-(i+.5)*18/n,area:'a'+a,rank:a,floor:0,level:1});}
for(let a=5;a<=8;a++)bases.push({code:`GROUND:${a}:B`,x:24+(a-5)*14,y:0,z:27.8,area:'b'+a,rank:10+a,floor:0});
for(let a=10;a<=13;a++)bases.push({code:`7L:${a}`,x:97,y:0,z:7+(a-10)*6,area:'drug',rank:9.5,floor:0});
for(const [i,code]of ['3L:01','4L:01'].entries())bases.push({code,x:3+4.5*i,y:0,z:7,area:'small',rank:3,floor:0});
const resolved=records.map(r=>resolveRecord(r,bases));
for(const r of resolved){assert.ok([r.x,r.y,r.z,r.level,r.rank].every(Number.isFinite),r.code);assert.equal(r.requiresLadder,r.floor===0&&r.level>=2,r.code);assert.equal(r.code,records.find(v=>v.code===r.code).source.split('/').at(-1));}
// Test every distinct access point on a complete trip, including stairs and returns.
const unique=[...new Map(resolved.map(r=>[r.modelCode,r])).values()];
for(const r of unique){const trip=plan([r],'input',1.2,12,30,.6);assert.ok(Number.isFinite(trip.total));assert.equal(trip.stairTrips,r.floor===1?2:0);const pick=trip.events.find(e=>e.type==='pick');assert.ok(Math.abs((pick.end-pick.start)-(r.requiresLadder?42:12))<1e-7);const last=trip.events.at(-1);assert.deepEqual(last.to,{x:5,y:0,z:25});}
for(const zone of openZones){const p=path(home,zone);assert.ok(p.some(p=>p.y===H));assert.ok(zone.x>43);}
assert.equal(classify('UP:00:PA:LL:01').modelCode,'UP:11:FRONT');
assert.equal(classify('6L:09:LI:00:00').modelCode,'GROUND:9:FRONT');
assert.equal(classify('UP:19:FA:01:01').modelCode,'UP:19:FRONT');
assert.equal(classify('UP:25:OF:FI:CE').modelCode,'PACKING:SPECIAL');
assert.equal(classify('UP:20:RA:04:01').requiresLadder,false);
assert.equal(classify('6L:09:RA:04:01').requiresLadder,true);
const drugs=path(home,resolved.find(r=>r.modelCode==='7L:13'));assert.ok(drugs.some(p=>p.x===79&&p.z===2));assert.ok(drugs.some(p=>p.x===97&&p.z===2));
console.log(`PASS: ${records.length} full codes; ${unique.length} access points; both floors, 00/FA/special placement, ladders and drugs access.`);
