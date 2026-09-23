import {Composition, registerRoot} from 'remotion';
import {Explainer} from './Explainer';
import product from './product.json';
const Root = () => <Composition id={product.id} component={Explainer} durationInFrames={product.scenes.reduce((sum, scene) => sum + scene.seconds * product.fps, 0)} fps={product.fps} width={product.width} height={product.height}/>;
registerRoot(Root);
