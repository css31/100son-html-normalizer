/**
 * Hook React — pilote un scan diagnostic depuis la SPA.
 *
 * Deux modes d'invocation selon l'argument passé à `startScan` :
 *
 *  1. **Scan complet** (`startScan()` ou `startScan(null)`) :
 *     - POST /diagnostics/run → batch_id + total + post_ids + chunk_size
 *     - Pour chaque chunk de `post_ids`, POST /diagnostics/run/chunk
 *
 *  2. **Scan sélection** (`startScan([id1, id2, …])`) — post-rc4 :
 *     - Skip /run (pas besoin d'énumérer côté serveur, on a déjà les IDs)
 *     - Chunke directement la liste fournie via POST /diagnostics/run/chunk
 *     - `total` = `postIds.length`, `chunkSize` = constante client (20).
 *
 * Le serveur retourne `processed` = count du chunk courant (pas cumul).
 * Le cumul est maintenu côté client dans `progress.processed`.
 *
 * Si l'utilisateur ferme l'onglet pendant le scan, les chunks déjà
 * envoyés sont persistés en base — les suivants ne partiront pas. Le
 * scan peut être relancé : `DiagnosticBatchRunner::start_batch` réénumère
 * la liste, le diagnostic existant est écrasé via upsert.
 *
 * En fin de boucle, le hook appelle `POST /diagnostics/finalize-scan` qui
 * applique l'auto-désactivation des règles épuisées (état `complete`) si
 * le scan couvre 100 % du corpus. Le résultat est exposé dans
 * `lastFinalize` et peut être affiché par la SPA (notice succincte).
 *
 * Post-rc4 : `startScan` accepte aussi `filters` (objet : search/cat_id/year/
 * month/builder) + `excludeNormalized` (bool). Ces paramètres scopent le
 * scan complet (mode `/run`) — ils n'ont aucun effet en mode sélection
 * (`explicitPostIds` fourni) puisque le scope est déjà entièrement défini
 * par la liste d'IDs.
 *
 * @param {(finalize?: {auto_disabled_rules: string[], fully_scanned: boolean}) => void} [onComplete]
 *                                                                                                    Callback déclenché en fin de scan réussi.
 *                                                                                                    Reçoit le payload de finalize-scan.
 * @return {{
 *   isScanning: boolean,
 *   progress: ?{processed: number, total: number},
 *   error: ?string,
 *   lastFinalize: ?{auto_disabled_rules: string[], fully_scanned: boolean},
 *   startScan: (postIds?: ?number[], filters?: Object, excludeNormalized?: boolean) => Promise<void>,
 *   reset: () => void,
 * }}
 */

import { useCallback, useState } from '@wordpress/element';
import * as api from '../api';

/**
 * Taille de chunk côté client pour les scans de sélection. Aligné sur
 * `DiagnosticBatchRunner::DEFAULT_CHUNK_SIZE = 20` côté serveur — pas
 * de bénéfice à diverger (FPM DevKinsta a peu de workers).
 */
const SELECTION_CHUNK_SIZE = 20;

export function useScanBatch( onComplete ) {
	const [ isScanning, setIsScanning ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ lastFinalize, setLastFinalize ] = useState( null );

	/**
	 * Lance un scan diagnostic.
	 *
	 * @param {?number[]} [explicitPostIds]   Liste d'IDs à scanner (mode
	 *                                        sélection) ; si null/undefined/vide → scan complet via /run.
	 * @param {Object}    [filters]           Filtres optionnels (mode `/run` uniquement) : search, cat_id, year, month, builder.
	 * @param {boolean}   [excludeNormalized] Si true, exclut les articles classés Gutenberg (mode `/run` uniquement).
	 */
	const startScan = useCallback(
		async (
			explicitPostIds = null,
			filters = {},
			excludeNormalized = false
		) => {
			if ( isScanning ) {
				return;
			}
			setIsScanning( true );
			setError( null );
			setProgress( { processed: 0, total: 0 } );

			try {
				let postIds;
				let chunkSize;
				let total;
				let jobId = null;

				const useSelection =
					Array.isArray( explicitPostIds ) &&
					explicitPostIds.length > 0;

				if ( useSelection ) {
					// Mode sélection : pas de /run, on chunke directement
					// la liste fournie. Le serveur (run_chunk) tolère les
					// IDs hors périmètre (post-types non publish, etc.) —
					// `process_chunk` les ignore silencieusement.
					postIds = explicitPostIds
						.map( ( id ) => Number( id ) )
						.filter( ( id ) => Number.isFinite( id ) && id > 0 );
					chunkSize = SELECTION_CHUNK_SIZE;
					total = postIds.length;
				} else {
					// Mode complet : énumération via /run, scope optionnel
					// via `filters` + `exclude_normalized` (le serveur
					// applique les filtres SQL natifs + post-filtre PHP via
					// BuilderClassifier — cf. DiagnosticBatchRunner::start_batch).
					const runBody = {};
					if ( filters && 'object' === typeof filters ) {
						const cleaned = {};
						if ( filters.search ) {
							cleaned.search = filters.search;
						}
						if ( filters.cat_id ) {
							cleaned.cat_id = filters.cat_id;
						}
						if ( filters.year ) {
							cleaned.year = filters.year;
						}
						if ( filters.month ) {
							cleaned.month = filters.month;
						}
						if ( filters.builder ) {
							cleaned.builder = filters.builder;
						}
						if ( Object.keys( cleaned ).length > 0 ) {
							runBody.filters = cleaned;
						}
					}
					if ( excludeNormalized ) {
						runBody.exclude_normalized = true;
					}
					const batch = await api.diagnostics.runBatch( runBody );
					total = Number( batch.total_articles ?? 0 );
					chunkSize = Math.max( 1, Number( batch.chunk_size ?? 20 ) );
					postIds = Array.isArray( batch.post_ids )
						? batch.post_ids
						: [];
					jobId = batch.job_id ?? null;
				}

				setProgress( { processed: 0, total } );

				if ( 0 === total ) {
					// Rien à diagnostiquer (corpus vide ou sélection vide).
					onComplete?.();
					return;
				}

				// Boucle de chunks. Séquentiel pour ne pas saturer FPM
				// (DevKinsta a peu de workers) et garder un ordre
				// prévisible du `progress`.
				let processed = 0;
				for ( let i = 0; i < postIds.length; i += chunkSize ) {
					const chunk = postIds.slice( i, i + chunkSize );
					const result = await api.diagnostics.runChunk( {
						job_id: jobId,
						chunk_post_ids: chunk,
					} );
					processed += Number( result.processed ?? chunk.length );
					setProgress( { processed, total } );
				}

				// Finalize : auto-désactivation des règles épuisées si le
				// scan couvre 100 % du corpus. Failure non bloquante — on
				// invalide le résultat client mais on ne propage pas
				// l'erreur (le scan en lui-même a réussi).
				let finalize = null;
				try {
					finalize = await api.diagnostics.finalizeScan();
				} catch ( finalizeErr ) {
					finalize = {
						auto_disabled_rules: [],
						fully_scanned: false,
					};
				}
				setLastFinalize( finalize );

				onComplete?.( finalize );
			} catch ( err ) {
				setError(
					err && err.message ? String( err.message ) : 'unknown_error'
				);
			} finally {
				setIsScanning( false );
			}
		},
		[ isScanning, onComplete ]
	);

	const reset = useCallback( () => {
		setError( null );
		setProgress( null );
		setLastFinalize( null );
	}, [] );

	return { isScanning, progress, error, lastFinalize, startScan, reset };
}
