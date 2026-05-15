/**
 * App — composant racine de la SPA V1.0.
 *
 * Router hash minimaliste. Ordre d'affichage des onglets dans la barre :
 *  - `#/rules`              → vue Rules (configuration des 11 règles).
 *  - `#/normalize` (défaut) → vue Normalize (F13/F14/F14.3/F15).
 *  - `#/history`            → vue History (F16).
 *  - `#/notes`              → vue Notes (post-rc1, éditeur Gutenberg restreint).
 *  - `#/settings`           → vue Settings (F15 — seuils γ).
 *
 * Note : l'ordre du `nav` est purement visuel. La route par défaut (au
 * mount sans hash) reste `normalize` — c'est le point d'entrée
 * fonctionnel de la SPA, l'onglet « Règles » à gauche fait office de
 * configuration en amont (relue à chaque pas).
 *
 * Pas de dépendance externe (pas de @wordpress/router qui n'existe pas en
 * V1.0) — un `useState` synchronisé sur `hashchange` suffit pour ces 5
 * routes. Si la SPA grandit (V1.1+ avec Dashboard, etc.) l'extraction
 * vers un router maison ou react-router restera locale à ce fichier
 * sans toucher aux vues.
 *
 * Le rendu utilise la barre `.nav-tab-wrapper` native WP-Admin, ce qui
 * garantit la cohérence visuelle avec les autres écrans de l'admin et
 * évite tout SCSS supplémentaire en V1.0.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Normalize from './views/Normalize';
import History from './views/History';
import Rules from './views/Rules';
import Settings from './views/Settings';
import Notes from './views/Notes';

/**
 * Identifiants de routes (utilisés dans le hash et dans le state local).
 *
 * @type {string}
 */
const ROUTE_NORMALIZE = 'normalize';
const ROUTE_HISTORY = 'history';
const ROUTE_RULES = 'rules';
const ROUTE_SETTINGS = 'settings';
const ROUTE_NOTES = 'notes';
const DEFAULT_ROUTE = ROUTE_NORMALIZE;

/**
 * Liste des routes valides. Toute autre valeur dans le hash retombe sur
 * `DEFAULT_ROUTE` — garde-fou contre un copier-coller hasardeux.
 *
 * @type {string[]}
 */
const VALID_ROUTES = [
	ROUTE_NORMALIZE,
	ROUTE_HISTORY,
	ROUTE_RULES,
	ROUTE_SETTINGS,
	ROUTE_NOTES,
];

/**
 * Parse le hash courant (`#/foo`, `#foo`, `#/foo?bar`) vers un identifiant
 * de route. Retombe sur `DEFAULT_ROUTE` si le hash est vide ou inconnu.
 *
 * @param {string} hash Valeur de `window.location.hash` (inclut le `#`).
 * @return {string} Route normalisée.
 */
function parseHash( hash ) {
	const clean = String( hash || '' )
		.replace( /^#\/?/, '' )
		.split( /[?&]/ )[ 0 ]
		.trim()
		.toLowerCase();
	return VALID_ROUTES.includes( clean ) ? clean : DEFAULT_ROUTE;
}

/**
 * @return {JSX.Element} Barre d'onglets primaire + vue active.
 */
export default function App() {
	const [ route, setRoute ] = useState( () =>
		parseHash( window.location.hash )
	);

	useEffect( () => {
		const handler = () => setRoute( parseHash( window.location.hash ) );
		window.addEventListener( 'hashchange', handler );
		return () => window.removeEventListener( 'hashchange', handler );
	}, [] );

	const navigate = useCallback( ( event, target ) => {
		// Laisse le navigateur mettre à jour le hash (déclenche hashchange) mais
		// évite le scroll-to-top que provoque un href naïf vers `#`. On force
		// donc l'assignation explicite et on annule l'événement par défaut.
		event.preventDefault();
		if ( window.location.hash !== `#/${ target }` ) {
			window.location.hash = `#/${ target }`;
		} else {
			// Hash déjà bon — pas d'événement hashchange, on resync à la main.
			setRoute( target );
		}
	}, [] );

	const tabClass = ( target ) =>
		`nav-tab ${ target === route ? 'nav-tab-active' : '' }`.trim();

	return (
		<div className="htmln-spa-root htmln-app">
			<nav
				className="nav-tab-wrapper htmln-app__tabs"
				aria-label={ __(
					'Sections HTML Normalizer',
					'100son-html-normalizer'
				) }
			>
				<a
					href="#/rules"
					aria-current={ ROUTE_RULES === route ? 'page' : undefined }
					className={ tabClass( ROUTE_RULES ) }
					onClick={ ( event ) => navigate( event, ROUTE_RULES ) }
				>
					{ __( 'Règles', '100son-html-normalizer' ) }
				</a>
				<a
					href="#/normalize"
					aria-current={
						ROUTE_NORMALIZE === route ? 'page' : undefined
					}
					className={ tabClass( ROUTE_NORMALIZE ) }
					onClick={ ( event ) => navigate( event, ROUTE_NORMALIZE ) }
				>
					{ __( 'Normaliser', '100son-html-normalizer' ) }
				</a>
				<a
					href="#/history"
					aria-current={
						ROUTE_HISTORY === route ? 'page' : undefined
					}
					className={ tabClass( ROUTE_HISTORY ) }
					onClick={ ( event ) => navigate( event, ROUTE_HISTORY ) }
				>
					{ __( 'Historique', '100son-html-normalizer' ) }
				</a>
				<a
					href="#/notes"
					aria-current={ ROUTE_NOTES === route ? 'page' : undefined }
					className={ tabClass( ROUTE_NOTES ) }
					onClick={ ( event ) => navigate( event, ROUTE_NOTES ) }
				>
					{ __( 'Notes', '100son-html-normalizer' ) }
				</a>
				<a
					href="#/settings"
					aria-current={
						ROUTE_SETTINGS === route ? 'page' : undefined
					}
					className={ tabClass( ROUTE_SETTINGS ) }
					onClick={ ( event ) => navigate( event, ROUTE_SETTINGS ) }
				>
					{ __( 'Réglages', '100son-html-normalizer' ) }
				</a>
			</nav>

			<div className="htmln-app__view">{ renderRoute( route ) }</div>
		</div>
	);
}

/**
 * Rend la vue correspondant à la route active. Switch séparé pour rester
 * lisible quand le nombre de routes grandit en V1.1.
 *
 * @param {string} route Route active (validée par `parseHash`).
 * @return {JSX.Element} Vue active.
 */
function renderRoute( route ) {
	switch ( route ) {
		case ROUTE_HISTORY:
			return <History />;
		case ROUTE_RULES:
			return <Rules />;
		case ROUTE_SETTINGS:
			return <Settings />;
		case ROUTE_NOTES:
			return <Notes />;
		case ROUTE_NORMALIZE:
		default:
			return <Normalize />;
	}
}
