// Shared image registration: both supplied drawings use the same pixel canvas.
// Ground-floor footprint reference: x=154..1918, z=154..1086 -> 100 x 30 metres.
export const H=6; // Estimated floor elevation, not a surveyed measurement.
export const X=p=>(p-154)*100/1764;
export const Z=p=>(p-154)*30/932;
export const upperX=Object.fromEntries([218,304,392,469,566,653,741,828,924].map((p,i)=>[i+11,X(p)]));
export const upperTop=Z(242), upperMid=Z(775), upperBottom=Z(1023), upperTail=Z(945);
export const lowerLanding={x:5,y:0,z:Z(174),area:'stairG',floor:0};
export const upperLanding={x:X(582),y:H,z:Z(174),area:'stairU',floor:1};
export const upperSegments=[];
const seg=(x1,z1,x2,z2)=>upperSegments.push([{x:x1,y:H,z:z1},{x:x2,y:H,z:z2}]);
seg(upperX[11],upperTop,upperX[19],upperTop);
for(let a=11;a<=19;a++)seg(upperX[a],upperTop,upperX[a],a<=14?upperBottom:a<=17?upperMid:upperTail);
seg(upperX[11],upperBottom,upperX[14],upperBottom);
seg(upperX[14],upperMid,upperX[18],upperMid);
seg(upperX[18],upperTail,upperX[19],upperTail);
seg(upperLanding.x,upperLanding.z,X(430),upperLanding.z);
seg(X(430),upperLanding.z,X(430),upperTop);
// Front and bottom storage access from the blue walkway network.
seg(upperX[11],upperTop,upperX[11],Z(210));
for(const p of [220,310,400])seg(X(p),upperBottom,X(p),Z(1040));
export const upperRacks=[
 {a:11,s:'L',n:9,x1:154,x2:194,end:1000},
 {a:11,s:'R',n:7,x1:240,x2:260,end:1000},
 {a:12,s:'L',n:7,x1:260,x2:280,end:1000},
 {a:12,s:'R',n:8,x1:328,x2:348,end:1000},
 {a:13,s:'L',n:8,x1:348,x2:368,end:1000},
 {a:13,s:'R',n:8,x1:415,x2:435,end:1000},
 {a:14,s:'L',n:8,x1:435,x2:455,end:1000},
 {a:14,s:'R',n:3,x1:502,x2:522,end:750},
 {a:15,s:'L',n:3,x1:522,x2:542,end:750},
 {a:15,s:'R',n:3,x1:590,x2:610,end:750},
 {a:16,s:'L',n:3,x1:610,x2:630,end:750},
 {a:16,s:'R',n:3,x1:677,x2:697,end:750},
 {a:17,s:'L',n:3,x1:697,x2:717,end:750},
 {a:17,s:'R',n:3,x1:764,x2:784,end:750},
 {a:18,s:'L',n:3,x1:784,x2:804,end:750},
 {a:18,s:'R',n:19,x1:851,x2:871,end:920},
 {a:19,s:'L',n:18,x1:871,x2:891,end:920}
];
export const upperLocations=upperRacks.flatMap(r=>Array.from({length:r.n},(_,i)=>({code:`UP:${r.a}:${r.s}${String.fromCharCode(65+i)}`,floor:1,y:H,x:upperX[r.a],z:Z(270+(i+.5)*(r.end-270)/r.n),area:'u'+r.a,rank:20+r.a,level:1,requiresLadder:false,rack:r,index:i}))).concat([
 {code:'UP:11:FA',floor:1,y:H,x:upperX[11],z:Z(210),area:'frontU',rank:30,level:1,requiresLadder:false},
 ...[12,13,14].map((a,i)=>({code:`UP:${a}:B`,floor:1,y:H,x:X([220,310,400][i]),z:Z(1040),area:'bottomU',rank:40+a,level:1,requiresLadder:false}))
]);
