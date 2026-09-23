import {AbsoluteFill, Sequence, staticFile, delayRender, continueRender, cancelRender} from 'remotion';
import {useEffect, useState} from 'react';
import product from './product.json';
import {Art, Card, colors, Eyebrow, Frame, Heading, Note, Reveal} from './components';

type Scene = (typeof product.scenes)[number];
const SceneContent = ({scene}: {scene: Scene}) => {
  if (scene.kind === 'intro' || scene.kind === 'outro') {
    return <div style={{display:'flex', alignItems:'center', height:'100%', gap:48}}>
      <div style={{flex:1}}><Eyebrow>{scene.eyebrow}</Eyebrow><Heading>{scene.title}</Heading>
        <Reveal delay={30} style={{marginTop:44, fontSize:38}}>{scene.body}</Reveal>
        <Reveal delay={45} style={{marginTop:28}}>{scene.cta ? <div style={{fontSize:40, padding:'22px 28px', background:colors.accent, borderRadius:14, display:'inline-block'}}>{scene.cta}</div> : <Note>{scene.label}</Note>}</Reveal>
        {scene.footer && <div style={{marginTop:32}}><Note>{scene.footer}</Note></div>}
      </div><Reveal delay={8}><Art size={480}/></Reveal>
    </div>;
  }
  return <><Eyebrow>{scene.eyebrow}</Eyebrow><Heading size={76}>{scene.title}</Heading>
    {scene.kind === 'flow' && <>
      <Reveal delay={20} style={{fontSize:36, marginTop:28}}>{scene.body}</Reveal>
      <div style={{display:'flex', alignItems:'center', gap:28, marginTop:66}}>{scene.nodes?.map((node, i) => <div key={node} style={{display:'flex', flex:1, alignItems:'center', gap:28}}>
        <Reveal delay={35+i*24} style={{flex:1}}><Card style={{height:185, textAlign:'center', display:'flex', flexDirection:'column', justifyContent:'center', background:i === 1 ? colors.ink:'#FFFDF8', color:i===1 ? '#F5F2E9':colors.ink}}><div style={{fontSize:42, fontWeight:600}}>{node}</div><div style={{fontSize:27, marginTop:20}}>{scene.details?.[i]}</div></Card></Reveal>
        {i < 2 && <Reveal delay={55+i*24}><span style={{fontSize:56}}>→</span></Reveal>}
      </div>)}</div>
    </>}
    {scene.kind === 'conversation' && <div style={{display:'flex', gap:36, marginTop:48}}>
      <Reveal delay={15} style={{flex:1}}><Card style={{minHeight:245}}><Eyebrow>YOU</Eyebrow><div style={{fontSize:43, lineHeight:1.25}}>{scene.body}</div></Card></Reveal>
      <Reveal delay={70} style={{flex:1}}><Card style={{background:colors.ink, color:colors.paper, minHeight:245}}><div style={{fontSize:26, letterSpacing:2, color:colors.accent, marginBottom:24}}>AGENT → HONE MCP</div><div style={{fontSize:40}}>{scene.tool}</div><div style={{fontSize:30, lineHeight:1.5, marginTop:18}}>app: {scene.arguments?.app} · last {scene.arguments?.days} days<br/>rank by average · top {scene.arguments?.limit}</div></Card></Reveal>
    </div>}
    {scene.kind === 'finding' && <>
      <Reveal delay={12} style={{display:'flex', gap:32, marginTop:38}}><Card style={{flex:1.5, fontFamily:'monospace', fontSize:36, lineHeight:1.5, whiteSpace:'pre-line'}}>{scene.query}</Card>{scene.metrics?.map(metric => <Card key={metric.label} style={{flex:1}}><div style={{fontSize:60, letterSpacing:-2}}>{metric.value}</div><div style={{fontSize:28, marginTop:16}}>{metric.label}</div></Card>)}</Reveal>
      <Reveal delay={90} style={{marginTop:34, borderLeft:`8px solid ${colors.accent}`, paddingLeft:28, fontSize:35, lineHeight:1.35}}>{scene.body}</Reveal>
    </>}
    {scene.kind === 'benefit' && <>
      <Reveal delay={18} style={{marginTop:62, padding:'44px 48px', borderRadius:24, background:colors.ink, color:colors.paper, fontSize:48}}>{scene.body}</Reveal>
      <Reveal delay={45} style={{marginTop:40, display:'flex', gap:20}}>{['Evidence','Human judgment','Next experiment'].map(label => <div key={label} style={{fontSize:30, border:'2px solid #CED7C8', padding:'18px 28px', borderRadius:40}}>{label}</div>)}</Reveal>
    </>}
    <div style={{position:'absolute', bottom:115, left:120, right:120}}><Note>{scene.footer}</Note></div>
  </>;
};
export const Explainer = () => {
  const [handle] = useState(() => delayRender('Load local Instrument Sans'));
  useEffect(() => {
    const regular = new FontFace('Instrument Sans', `url(${staticFile('instrument-sans.woff2')})`);
    const semibold = new FontFace('Instrument Sans', `url(${staticFile('instrument-sans-semibold.woff2')})`, {weight:'600'});
    Promise.all([regular.load(), semibold.load()]).then(fonts => {fonts.forEach(font => document.fonts.add(font)); continueRender(handle);}).catch(cancelRender);
  }, [handle]);
  let start = 0;
  return <AbsoluteFill style={{background:colors.paper, color:colors.ink, fontFamily:'Instrument Sans', fontWeight:400}}>{product.scenes.map((scene, index) => {
    const from = start;
    const duration = scene.seconds * product.fps;
    start += duration;
    return <Sequence key={scene.kind} from={from} durationInFrames={duration}><Frame index={index} duration={duration}><SceneContent scene={scene}/></Frame></Sequence>;
  })}</AbsoluteFill>;
};
