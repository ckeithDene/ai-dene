import{upperLocations}from './mezzanine.mjs';
export const normalise=value=>String(value).trim().split('/').at(-1).split(':').map(p=>p.trim().toUpperCase()).join(':');
const upperSections=new Set(upperLocations.map(l=>l.code));
function classifyOriginal(raw){
 const code=normalise(raw),parts=code.split(':'),out={code,source:raw,building:parts[0]||'',aisle:parts[1]||'',section:parts[2]||'',levelText:parts[3]||'',subdivision:parts[4]||'',status:'unmatched',reason:'',precision:'',modelCode:null};
 if(parts.length!==5||parts.some(p=>!p)){out.reason='Nonstandard location code';return out;}
 out.floor=parts[0]==='UP'?1:0;
 if(!/^\d{2}$/.test(out.aisle)){out.reason='Special location / nonnumeric aisle';return out;}
 if(!/^\d{2}$/.test(out.levelText)){out.reason='Nonnumeric shelf level';return out;}
 out.level=Number(out.levelText);out.requiresLadder=out.floor===0&&out.level>=2;
 if(out.level===0){out.reason='Shelf level 00 needs clarification';return out;}
 const a=Number(out.aisle),s=out.section;
 if(out.floor===1){if(upperSections.has(`UP:${out.aisle}:${s}`)){out.modelCode=`UP:${out.aisle}:${s}`;out.precision='section';}else if([12,13,14].includes(a)&&/^B[A-Z]$/.test(s)){out.modelCode=`UP:${out.aisle}:B`;out.precision='zone';}else out.reason=a<11||a>19?'Upstairs aisle not in model':'Upstairs section not in model';}
 else if(a>=4&&a<=9&&/^[LR][A-Z]$/.test(s)){const max=a===4?(s[0]==='R'?'M':''):a===9?(s[0]==='L'?'I':'F'):'E';if(max&&s[1]<=max){out.modelCode=`GROUND:${a}:${s}`;out.precision='section';}else out.reason='Downstairs section not in model';}
 else if(a>=5&&a<=8&&/^B[A-Z]$/.test(s)){out.modelCode=`GROUND:${a}:B`;out.precision='zone';}
 else if(parts[0]==='7L'&&a>=10&&a<=13){out.modelCode=`7L:${a}`;out.precision='room';}
 else if(parts[0]==='3L'&&a===1&&s==='SA'){out.modelCode='3L:01';out.precision='zone';}
 else if(parts[0]==='4L'&&a===1&&/^L[A-E]$/.test(s)){out.modelCode='4L:01';out.precision='zone';}
 else out.reason='Downstairs section / area not in model';
 if(out.modelCode){out.status='mapped';out.reason=out.precision==='section'?'Matched aisle and section':out.precision==='room'?'Room-level route; internal shelf positions not drawn':'Zone-level route; internal section positions not drawn';}
 return out;
}
export function classify(raw){
 const out=classifyOriginal(raw),a=Number(out.aisle),numericA=/^\d{2}$/.test(out.aisle),numericLevel=/^\d{2}$/.test(out.levelText);
 const mapped=(modelCode,precision,reason,floor=out.floor)=>({...out,modelCode,precision,reason,floor,status:'mapped',level:numericLevel?Number(out.levelText):0,requiresLadder:floor===0&&numericLevel&&Number(out.levelText)>=2});
 // Nonstandard names (including office, middle, boardroom) sit beside packing.
 if(numericA&&a===0){const nearest=out.floor===1?11:out.building==='7L'?9:4;return {...mapped(`${out.floor===1?'UP':'GROUND'}:${nearest}:FRONT`,'approximate',`00 aisle placed at front of nearest relevant aisle ${nearest}`),level:0,requiresLadder:false};}
 if(!numericA||!numericLevel)return mapped('PACKING:SPECIAL','approximate','Special location placed beside packing',0);
 if(a===0||Number(out.levelText)===0){
  const nearest=out.floor===1?([20,21,22,25].includes(a)?a:Math.max(11,Math.min(19,a||11))):Math.max(4,Math.min(9,a||4));
  return mapped(`${out.floor===1?'UP':'GROUND'}:${nearest}:FRONT`,'approximate',`00 code placed at front of aisle / zone ${nearest}`);
 }
 if(out.section==='FA'&&((out.floor===1&&(a>=11&&a<=19||[20,21,22,25].includes(a)))||(out.floor===0&&a>=4&&a<=9)))return mapped(`${out.floor===1?'UP':'GROUND'}:${a}:FRONT`,'approximate','FA placed at front of corresponding aisle');
 if(out.floor===1&&[20,21,22,25].includes(a))return mapped(`UP:${a}:ZONE`,'approximate','Open mezzanine storage area; position estimated');
 if(out.status==='mapped')return out;
 return mapped('PACKING:SPECIAL','approximate','Other location placed beside packing',0);
}
export function baseKey(loc){if(loc.modelCode)return loc.modelCode;if(loc.floor===1)return loc.code;const p=loc.code.split(':');if(p.length===3&&Number(p[1])>=4&&Number(p[1])<=9)return `GROUND:${Number(p[1])}:${p[2]}`;return loc.code;}
export function resolveRecord(record,bases){if(record.status!=='mapped')return null;const candidates=bases.filter(b=>baseKey(b)===record.modelCode);if(!candidates.length)throw Error('Missing mapped geometry: '+record.modelCode);const base=candidates.find(b=>b.level===record.level)||candidates[0];const resolved={...base,...record,rank:base.rank,area:base.area,x:base.x,y:base.y||0,z:base.z,level:record.level};
 if(record.modelCode==='4L:01')resolved.x=(Math.min(...candidates.map(b=>b.x))+Math.max(...candidates.map(b=>b.x)))/2;
 return resolved;}
