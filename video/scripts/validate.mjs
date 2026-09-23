import {existsSync} from 'node:fs';
import {resolve} from 'node:path';
export function validateProduct(product, publicDir) {
  if (product.width !== 1920 || product.height !== 1080 || product.fps !== 30) throw new Error('Pilot requires 1080p at 30fps');
  if (!Array.isArray(product.scenes) || product.scenes.length === 0) throw new Error('Scenes required');
  let duration = 0;
  const supported = new Set(['intro','flow','conversation','finding','benefit','outro']);
  for (const scene of product.scenes) {
    if (!supported.has(scene.kind)) throw new Error('Unsupported scene');
    if (!Number.isFinite(scene.seconds) || scene.seconds < 6 || !Number.isInteger(scene.seconds * product.fps)) throw new Error('Scene needs readable hold and whole frames');
    if (!scene.title || !scene.eyebrow) throw new Error('Missing scene identity');
    if (['conversation','finding'].includes(scene.kind) && !/synthetic/i.test(scene.footer ?? '')) throw new Error('Example scenes must disclose synthetic data');
    if (scene.kind === 'flow' && (scene.nodes?.length !== 3 || scene.details?.length !== 3)) throw new Error('Flow requires three labelled nodes');
    duration += scene.seconds;
  }
  if (duration < 45 || duration > 60) throw new Error('Pilot duration must be 45–60 seconds');
  for (const asset of [product.asset,'instrument-sans.woff2','instrument-sans-semibold.woff2']) {
    if (!asset || !existsSync(resolve(publicDir, asset))) throw new Error('Missing local asset');
  }
  return {duration, frames:duration * product.fps};
}
