import {AbsoluteFill, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import type {CSSProperties, ReactNode} from 'react';
import product from './product.json';

export const colors = product.theme;
export const Reveal = ({children, delay = 0, style}: {children: ReactNode; delay?: number; style?: CSSProperties}) => {
  const frame = useCurrentFrame();
  const progress = interpolate(frame, [delay, delay + 18], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  return <div style={{opacity: progress, transform: `translateY(${(1 - progress) * 22}px)`, ...style}}>{children}</div>;
};
export const Eyebrow = ({children}: {children: ReactNode}) => <div style={{fontSize: 26, letterSpacing: 3, color: colors.muted, marginBottom: 28}}>{children}</div>;
export const Heading = ({children, size = 84}: {children: ReactNode; size?: number}) => <h1 style={{fontSize: size, fontWeight: 600, lineHeight: 1.08, letterSpacing: -3, whiteSpace: 'pre-line', margin: 0}}>{children}</h1>;
export const Note = ({children}: {children: ReactNode}) => <div style={{fontSize: 28, color: colors.muted, lineHeight: 1.4}}>{children}</div>;
export const Art = ({size = 450}: {size?: number}) => <Img src={staticFile(product.asset)} style={{width: size, height: size, objectFit: 'contain'}} />;
export const Card = ({children, style}: {children: ReactNode; style?: CSSProperties}) => <div style={{background: '#FFFDF8', border: '2px solid #D9E0D4', borderRadius: 24, padding: 36, ...style}}>{children}</div>;
export const Frame = ({children, index, duration}: {children: ReactNode; index: number; duration: number}) => {
  const frame = useCurrentFrame();
  const opacity = index === product.scenes.length - 1 ? 1 : interpolate(frame, [duration - 9, duration - 1], [1, 0], {extrapolateLeft:'clamp', extrapolateRight:'clamp'});
  return <AbsoluteFill style={{padding: '140px 120px 112px', opacity}}>
    <div style={{position:'absolute', top:54, left:120, right:120, display:'flex', justifyContent:'space-between', alignItems:'center', fontSize:25, letterSpacing:2}}>
      <span>{product.series}</span><span style={{fontSize:34, fontWeight:600, letterSpacing:-1}}>{product.name}</span>
    </div>
    {children}
    <div style={{position:'absolute', bottom:52, left:120, right:120, display:'flex', gap:12}}>{product.scenes.map((_, i) => <div key={i} style={{height:5, flex:1, background:i <= index ? colors.ink : '#D9E0D4'}} />)}</div>
  </AbsoluteFill>;
};
