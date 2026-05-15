/**
 * Hook React — récupère la liste paginée des diagnostics (`GET /diagnostics`)
 * filtrée par status (onglet F13 actif) et page courante.
 *
 * État local : pas de store global (la liste est éphémère et propre à la
 * vue Normalize). Re-fetch automatique sur changement de `status` ou `page`.
 *
 * Le hook annule les fetch en cours via un drapeau `cancelled` capturé en
 * cleanup d'effet, pour éviter les writes incohérents si l'utilisateur
 * change rapidement d'onglet ou de page.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import * as api from '../api';

/**
 * @typedef {Object} DiagnosticsListResult
 * @property {Array}      items      Articles diagnostiqués pour la page courante.
 * @property {number}     total      Total non paginé (utile pour total_pages).
 * @property {number}     totalPages Nombre de pages.
 * @property {boolean}    isLoading  Vrai durant chaque fetch.
 * @property {?string}    error      Message d'erreur ou null.
 * @property {() => void} refetch    Relance manuelle (utile après une décision F14).
 */

/**
 * @typedef {Object} DiagnosticsFilters
 * @property {string}   [search]   Recherche titre ou ID exact si numérique.
 * @property {number}   [cat]      ID catégorie WP (>0).
 * @property {number}   [year]     Année (>0).
 * @property {number}   [month]    Mois (1-12).
 * @property {string}   [builder]  siteorigin / gutenberg / other / out.
 * @property {string[]} [rule_ids] IDs internes (R1..R12), filtre OR sur règles applicables.
 */

/**
 * Sérialise les filtres en query string (clés vides/zéro omises).
 *
 * @param {?DiagnosticsFilters} filters Filtres bruts.
 * @return {Object<string, unknown>} Params propres à passer à `list()`.
 */
function serializeFilters( filters ) {
	if ( ! filters || typeof filters !== 'object' ) {
		return {};
	}
	const out = {};
	if ( filters.search && '' !== String( filters.search ).trim() ) {
		out.search = String( filters.search ).trim();
	}
	if ( filters.cat && Number( filters.cat ) > 0 ) {
		out.cat = Number( filters.cat );
	}
	if ( filters.year && Number( filters.year ) > 0 ) {
		out.year = Number( filters.year );
	}
	if (
		filters.month &&
		Number( filters.month ) >= 1 &&
		Number( filters.month ) <= 12
	) {
		out.month = Number( filters.month );
	}
	if ( filters.builder ) {
		out.builder = String( filters.builder );
	}
	if ( Array.isArray( filters.rule_ids ) && filters.rule_ids.length > 0 ) {
		// On laisse `apiFetch` sérialiser en `?rule_ids[]=R1&rule_ids[]=R5`
		// via la convention array native — WP-REST parse en array PHP.
		out.rule_ids = filters.rule_ids.map( String );
	}
	return out;
}

/**
 * @param {?'normal'|'to_improve'|'stale'} status  Filtre status (null = tous).
 * @param {number}                         page    Page (≥ 1).
 * @param {number}                         perPage Articles par page (1..200).
 * @param {?DiagnosticsFilters}            filters Filtres additionnels (post-rc3).
 * @return {DiagnosticsListResult} État + actions.
 */
export function useDiagnosticsList(
	status,
	page = 1,
	perPage = 50,
	filters = null
) {
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	// Stabilise la sérialisation des filtres pour la dépendance du callback —
	// sinon un nouvel objet `{}` à chaque render relance fetchPage en boucle.
	const filtersKey = JSON.stringify( serializeFilters( filters ) );

	const fetchPage = useCallback(
		async ( signal ) => {
			setIsLoading( true );
			setError( null );
			try {
				const params = {
					page,
					per_page: perPage,
					...serializeFilters( filters ),
				};
				if ( null !== status ) {
					params.status = status;
				}
				const result = await api.diagnostics.list( params );
				if ( signal && signal.cancelled ) {
					return;
				}
				setItems( Array.isArray( result.items ) ? result.items : [] );
				setTotal( Number( result.total ) || 0 );
				setTotalPages( Number( result.total_pages ) || 0 );
			} catch ( err ) {
				if ( signal && signal.cancelled ) {
					return;
				}
				const msg =
					err && err.message
						? String( err.message )
						: 'unknown_error';
				setError( msg );
				setItems( [] );
				setTotal( 0 );
				setTotalPages( 0 );
			} finally {
				if ( ! signal || ! signal.cancelled ) {
					setIsLoading( false );
				}
			}
		},
		// `filtersKey` (sérialisation stable) en dépendance plutôt que
		// `filters` direct — évite la boucle infinie si le parent
		// recrée l'objet à chaque render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ status, page, perPage, filtersKey ]
	);

	useEffect( () => {
		const signal = { cancelled: false };
		fetchPage( signal );
		return () => {
			signal.cancelled = true;
		};
	}, [ fetchPage ] );

	const refetch = useCallback(
		() => fetchPage( { cancelled: false } ),
		[ fetchPage ]
	);

	return { items, total, totalPages, isLoading, error, refetch };
}
