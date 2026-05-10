/**
 * App — composant racine de la SPA V1.0.
 *
 * V1.0 ne sert qu'une seule vue (Normalize F13/F14) — pas de router pour
 * l'instant. Si la SPA grandit en V1.1 (Dashboard, Presets SPA, Settings
 * onglet à part, Journal SPA), un router minimaliste type `useState` +
 * switch pourra être introduit ici sans toucher aux vues elles-mêmes.
 */

import Normalize from './views/Normalize';

/**
 * @return {JSX.Element} Vue active.
 */
export default function App() {
	return <Normalize />;
}
