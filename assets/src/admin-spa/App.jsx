/**
 * App — composant racine de la SPA V1.0.
 *
 * Phase 6.1 : version d'amorçage minimale (« Bonjour » localisé). Les
 * vues métier (Normalize F13/F14, modales F14.3/F15, StepsHistory F16,
 * Settings γ) arrivent en Phases 6.3 à 6.7.
 */

import { __ } from '@wordpress/i18n';

export default function App() {
	return (
		<div className="htmln-spa-root">
			<h2>{ __( 'Interface V1.0', '100son-html-normalizer' ) }</h2>
			<p>
				{ __(
					"L'interface de normalisation par pas est en cours de construction. Les vues métier seront livrées dans les sous-phases suivantes.",
					'100son-html-normalizer'
				) }
			</p>
		</div>
	);
}
