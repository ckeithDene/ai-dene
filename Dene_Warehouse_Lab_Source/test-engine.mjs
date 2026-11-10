import assert from 'node:assert/strict';
import{path,plan,home,upperPath}from './dist/engine.mjs';
import{H,upperLocations,upperSegments,upperLanding,lowerLanding,upperX,upperMid,upperTail}from './dist/mezzanine.mjs';
const a={x:27,z:4,area:'a5',rank:5,code:'A',level:2},b={x:40,z:4,area:'a6',rank:6,code:'B',level:1},d={x:97,z:19,area:'drug',rank:9.5,code:'D',level:1};
assert(path(a,b).some(p=>p.z===25));assert(path(home,d).some(p=>p.x===79&&p.z===2));assert(path(home,d).some(p=>p.x===97&&p.z===2));assert.equal(plan([a],'input',1,12,30).total,128);assert.equal(plan([],'group',1,12,30).total,0);
assert.deepEqual(path(d,{x:79,z:7,area:'a9'}),[{x:97,y:0,z:19},{x:97,y:0,z:2},{x:79,y:0,z:2},{x:79,y:0,z:7}]);
const len=(a,b)=>Math.hypot(a.x-b.x,a.y-b.y,a.z-b.z),on=(p,a,b)=>Math.abs(len(a,p)+len(p,b)-len(a,b))<1e-6;
for(const loc of upperLocations){
 const r=plan([loc],'input',1.2,12,30,.6);
 assert.equal(r.stairTrips,2,loc.code);assert(Math.abs(r.events.filter(e=>e.type==='pick')[0].end-r.events.filter(e=>e.type==='pick')[0].start-12)<1e-8,loc.code);
 const p=upperPath(upperLanding,loc);assert.deepEqual(p.at(-1),{x:loc.x,y:H,z:loc.z});
 for(let i=1;i<p.length;i++)assert(upperSegments.some(([a,b])=>on(p[i-1],a,b)&&on(p[i],a,b)),loc.code+' crossed a rack or floor opening');
 const stairs=r.events.filter(e=>e.type==='stairs');assert.deepEqual(stairs[0].from,{x:lowerLanding.x,y:0,z:lowerLanding.z});assert.deepEqual(stairs[0].to,{x:upperLanding.x,y:H,z:upperLanding.z});assert.deepEqual(r.events.at(-1).to,{x:5,y:0,z:25});
}
const get=c=>upperLocations.find(l=>l.code===c);assert(get('UP:11:LI'));assert(get('UP:18:RS'));assert(get('UP:19:LR'));assert(!get('UP:19:RA'));
const short=upperPath(get('UP:15:LC'),get('UP:17:RC'));assert(short.some(p=>Math.abs(p.z-upperMid)<1e-6));
const last=upperPath(get('UP:18:RS'),get('UP:19:LR'));assert(last.some(p=>Math.abs(p.z-upperTail)<1e-6));
const mixed=[get('UP:11:LA'),a,get('UP:19:LR'),d];assert.equal(plan(mixed,'input',1.2,12,30).stairTrips,4);assert.equal(plan(mixed,'group',1.2,12,30).stairTrips,2);
const u=plan([get('UP:15:LC')],'input',1.2,12,0);assert.equal(u.total,plan([get('UP:15:LC')],'input',1.2,12,180).total);assert(plan([get('UP:15:LC')],'input',1.2,12,0,.3).total>u.total);
console.log('Passed: downstairs regression, 120 upstairs destinations, permitted walkway segments, stair-only transitions, cross-walkways, no upstairs ladder penalty and timing.');
