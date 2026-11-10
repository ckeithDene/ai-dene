// User-approved approximate areas; these are access points, not surveyed bays.
import {H,upperX,upperTop,upperSegments} from './mezzanine.mjs';
const gx={4:14,5:27,6:40,7:53,8:66,9:79};
export const openZones=[20,21,22,25].map((a,i)=>({code:`UP:${a}:ZONE`,floor:1,y:H,x:54+i*9,z:14,area:`zone${a}`,rank:40+a,level:1,requiresLadder:false,kind:'open',aisle:a}));
const segment=(x1,z1,x2,z2)=>upperSegments.push([{x:x1,y:H,z:z1},{x:x2,y:H,z:z2}]);
segment(upperX[19],upperTop,81,upperTop);
for(const loc of openZones)segment(loc.x,upperTop,loc.x,loc.z);
export const placementLocations=[
 ...Object.entries(gx).map(([a,x])=>({code:`GROUND:${a}:FRONT`,floor:0,y:0,x,z:25,area:'a'+a,rank:Number(a),level:0,kind:'front'})),
 ...Object.entries(upperX).map(([a,x])=>({code:`UP:${a}:FRONT`,floor:1,y:H,x,z:upperTop,area:'u'+a,rank:20+Number(a),level:0,kind:'front'})),
 ...openZones,
 ...openZones.map(l=>({...l,code:`UP:${l.aisle}:FRONT`,z:upperTop,kind:'front',level:0})),
 {code:'PACKING:SPECIAL',floor:0,y:0,x:10,z:25,area:'home',rank:0,level:0,kind:'special'}
];
