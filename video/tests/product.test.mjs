import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, writeFileSync, rmSync, readFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {validateProduct} from '../scripts/validate.mjs';
const fixture = () => ({width:1920,height:1080,fps:30,asset:'test.png',scenes:Array.from({length:6}, (_,i)=>({kind:i===2?'finding':'intro',seconds:8,title:`Fixture ${i}`,eyebrow:'Fixture',footer:'Synthetic fixture data'}))});
const assets = mkdtempSync(join(tmpdir(),'hone-video-test-'));
for (const name of ['test.png','instrument-sans.woff2','instrument-sans-semibold.woff2']) writeFileSync(join(assets,name),'fixture');
test('computes a complete timeline from product input', () => {assert.deepEqual(validateProduct(fixture(), assets),{duration:48,frames:1440});});
test('rejects unreadable holds, invalid timing and unsupported scenes', () => {
  for (const change of [{seconds:0},{seconds:NaN},{seconds:6.01},{kind:'dashboard'}]) {const p=fixture();Object.assign(p.scenes[0],change);assert.throws(()=>validateProduct(p,assets));}
  const p=fixture();p.scenes.forEach(s=>s.seconds=11);assert.throws(()=>validateProduct(p,assets),/45–60/);
});
test('rejects synthetic results without disclosure', () => {const p=fixture();p.scenes[2].footer='';assert.throws(()=>validateProduct(p,assets),/disclose/);});
test('rejects missing assets before rendering', () => {const p=fixture();p.asset='absent.png';assert.throws(()=>validateProduct(p,assets),/asset/);});
test('actual product input meets render prerequisites',()=>{const p=JSON.parse(readFileSync(new URL('../src/product.json',import.meta.url)));assert.ok(validateProduct(p,new URL('../public',import.meta.url).pathname).frames>0);});
test.after(()=>rmSync(assets,{recursive:true}));
