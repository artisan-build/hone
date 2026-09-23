import {spawnSync} from 'node:child_process';
import {mkdirSync, readFileSync, writeFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {validateProduct} from './validate.mjs';
const root = fileURLToPath(new URL('../', import.meta.url));
const out = root + 'out/';
const product = JSON.parse(readFileSync(root+'src/product.json'));
const expected = validateProduct(product, root+'public');
const run = (command, args) => {
  const r=spawnSync(command,args,{encoding:'utf8'});
  assert.equal(r.status,0,`${command}: ${r.error?.message ?? r.stderr}`);
  return r.stdout;
};
const probe=run('ffprobe',['-v','error','-show_streams','-show_format','-of','json',out+'hone-explainer.mp4']);
writeFileSync(out+'ffprobe.json',probe);
const {streams} = JSON.parse(probe);
assert.equal(streams.length,1,'Silent video must have one video stream');
const stream=streams[0];
assert.equal(stream.codec_name,'h264');
assert.equal(stream.width,product.width);assert.equal(stream.height,product.height);
assert.equal(stream.r_frame_rate,`${product.fps}/1`);
assert.equal(Number(stream.nb_frames),expected.frames);assert.equal(Number(stream.duration),expected.duration);
run('ffmpeg',['-hide_banner','-v','error','-xerror','-i',out+'hone-explainer.mp4','-f','null','-']);
writeFileSync(out+'decode.txt','exit_code=0\nFull decode to null completed without errors.\n');
mkdirSync(out+'frames',{recursive:true});
const timestamps=new Set([0, expected.duration-1/product.fps]);
let start=0;
for(const scene of product.scenes) {
  timestamps.add(start);timestamps.add(start+scene.seconds/2);
  if(start>0) {timestamps.add(start-0.2);timestamps.add(start-1/product.fps);timestamps.add(start+0.2);}
  start+=scene.seconds;
}
for(const t of [...timestamps].sort((a,b)=>a-b)) {
  run('ffmpeg',['-hide_banner','-loglevel','error','-ss',String(t),'-i',out+'hone-explainer.mp4','-frames:v','1','-y',out+`frames/${t.toFixed(2).padStart(6,'0')}.png`]);
}
run('ffmpeg',['-hide_banner','-loglevel','error','-ss','32','-i',out+'hone-explainer.mp4','-frames:v','1','-vf','scale=960:540','-y',out+'embedded-960.png']);
writeFileSync(out+'verification.json',JSON.stringify({verifiedAt:new Date().toISOString(),expected,codec:stream.codec_name,pixelFormat:stream.pix_fmt,decodeExitCode:0,framesExtracted:timestamps.size,visualReview:'Manual review required; extraction alone is not visual verification.'},null,2)+'\n');
console.log(`PASS: ${expected.frames} frames, ${expected.duration}s, 1080p H.264; full decode; ${timestamps.size} review frames extracted.`);
