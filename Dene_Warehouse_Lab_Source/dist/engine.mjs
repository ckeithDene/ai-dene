import{H,upperSegments,upperLanding,lowerLanding}from './mezzanine.mjs';
export const aisleX={4:14,5:27,6:40,7:53,8:66,9:79};
function groundPath(a,b){const pts=[];const push=(x,z)=>{const p=pts.at(-1);if(!p||p.x!==x||p.z!==z)pts.push({x,z})};push(a.x,a.z);if(a.area===b.area){push(b.x,b.z);return pts;}if(a.area==='a9'&&b.area==='drug'){push(79,2);push(97,2);push(97,b.z);return pts;}if(a.area==='drug'&&b.area==='a9'){push(97,2);push(79,2);push(b.x,b.z);return pts;}if(a.area==='drug'){push(97,2);push(79,2);push(79,25);}else if(a.area==='small'){push(14,a.z);push(14,25);}else{push(a.x,25);}if(b.area==='drug'){push(79,25);push(79,2);push(97,2);push(97,b.z);push(b.x,b.z);}else if(b.area==='small'){push(14,25);push(14,b.z);push(b.x,b.z);}else{push(b.x,25);push(b.x,b.z);}return pts;}
const point=p=>({x:p.x,y:p.y||0,z:p.z});
const equal=(a,b)=>Math.hypot(a.x-b.x,(a.y||0)-(b.y||0),a.z-b.z)<1e-7;
const length=(a,b)=>Math.hypot(a.x-b.x,(a.y||0)-(b.y||0),a.z-b.z);
function onSegment(p,a,b){const e=1e-6;return Math.abs(p.y-H)<e&&Math.abs(length(a,p)+length(p,b)-length(a,b))<e;}
export function upperPath(a,b){
 const all=[...upperSegments.flat(),point(a),point(b)],nodes=[];
 for(const p of all)if(!nodes.some(n=>equal(n,p)))nodes.push(p);
 const adjacency=nodes.map(()=>[]);
 for(const [a,b]of upperSegments){const ids=nodes.map((p,i)=>({p,i})).filter(n=>onSegment(n.p,a,b)).sort((u,v)=>length(a,u.p)-length(a,v.p));for(let k=1;k<ids.length;k++){const u=ids[k-1].i,v=ids[k].i,d=length(nodes[u],nodes[v]);adjacency[u].push([v,d]);adjacency[v].push([u,d]);}}
 const start=nodes.findIndex(p=>equal(p,a)),end=nodes.findIndex(p=>equal(p,b)),dist=nodes.map(()=>Infinity),prev=[],todo=new Set(nodes.map((_,i)=>i));dist[start]=0;
 while(todo.size){let u=-1;for(const i of todo)if(u<0||dist[i]<dist[u])u=i;if(!Number.isFinite(dist[u]))break;todo.delete(u);if(u===end)break;for(const[v,w]of adjacency[u])if(dist[u]+w<dist[v]){dist[v]=dist[u]+w;prev[v]=u;}}
 if(!Number.isFinite(dist[end]))throw Error('Upstairs location is disconnected');const out=[];for(let i=end;i!==undefined;i=prev[i])out.unshift(nodes[i]);return out;
}
function toStairs(a){return [...groundPath(a,{x:14,z:lowerLanding.z,area:'a4'}).map(point),point(lowerLanding)];}
export function path(a,b){
 const upA=a.floor===1,upB=b.floor===1;
 let pts;if(upA&&upB)pts=upperPath(a,b);else if(!upA&&!upB)pts=groundPath(a,b).map(point);
 else if(upB)pts=[...toStairs(a),point(upperLanding),...upperPath(upperLanding,b)];
 else pts=[...upperPath(a,upperLanding),point(lowerLanding),...toStairs(b).reverse()];
 return pts.filter((p,i)=>!i||!equal(p,pts[i-1]));
}
export const home={x:5,y:0,z:25,area:'home',floor:0};
export function plan(items,mode,walk,pick,ladder,stairSpeed=.6){
 if(!(walk>0&&pick>=0&&ladder>=0&&stairSpeed>0))throw Error('Invalid timing inputs');
 const list=[...items];if(mode==='group')list.sort((a,b)=>a.rank-b.rank||(a.floor===1?a.z-b.z:b.z-a.z));
 const events=[];let time=0,dist=0,stairTrips=0,stairTime=0,prev=home;
 for(const dest of [...list,home]){const pts=path(prev,dest);for(let i=1;i<pts.length;i++){const d=length(pts[i-1],pts[i]);if(d){const stairs=Math.abs(pts[i].y-pts[i-1].y)>.001,duration=d/(stairs?stairSpeed:walk);events.push({start:time,end:time+duration,from:pts[i-1],to:pts[i],type:stairs?'stairs':'walk',label:stairs?(pts[i].y>pts[i-1].y?'Going upstairs':'Going downstairs'):(dest===home?'Returning to packing':'Walking to '+dest.code)});time+=duration;dist+=d;if(stairs){stairTrips++;stairTime+=duration;}}}
 if(dest!==home){const elevated=dest.floor!==1&&dest.level>1,duration=pick+(elevated?ladder:0);events.push({start:time,end:time+duration,from:point(dest),to:point(dest),type:'pick',label:(elevated?'Ladder + pick · ':'Picking · ')+dest.code+(dest.floor===1?' · Mezzanine':' / level '+dest.level),location:dest});time+=duration;}prev=dest;}
 return{events,total:time,distance:dist,list,stairTrips,stairTime};
}
