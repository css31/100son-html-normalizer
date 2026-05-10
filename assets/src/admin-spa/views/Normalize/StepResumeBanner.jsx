/**
 * StepResumeBanner — bandeau au mount si un pas non-finalisé est détecté
 * en BDD (cas typique : l'utilisateur a fermé l'onglet pendant un pas).
 *
 * Cf. cahier §3.1 F14.5. V1.0 6.4 : on propose seulement « Abandonner »
 * (= finalize côté serveur). Le « Reprendre » qui rejouerait les
 * articles `pending` arrive avec la modale RegressionModal (6.5) qui
 * permettra de gérer les régressions intermédiaires.
 *
 * Détection : la route `GET /steps?per_page=10` retourne les pas récents,
 * on filtre côté client ceux dont `finished_at` est null. Plus simple
 * qu'une route serveur dédiée pour V1.0.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState, useCallback } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import * as api from '../../api';

/**
 * @typedef {Object} UnfinishedStep
 * @property {string} uuid                UUID v4 du pas.
 * @property {number} total_articles      Articles ciblés au démarrage.
 * @property {number} successful_articles Articles validés.
 * @property {number} refused_articles    Articles refusés.
 * @property {number} errored_articles    Articles en erreur.
 * @property {string} started_at          Datetime MySQL au lancement.
 */

/**
 * Filtre les pas non-finalisés et retourne le plus récent.
 *
 * @param {Array<Object>} steps Pas issus de `GET /steps`.
 * @return {?UnfinishedStep} Pas non-finalisé le plus récent ou null.
 */
function findMostRecentUnfinished( steps ) {
	if ( ! Array.isArray( steps ) ) {
		return null;
	}
	const unfinished = steps.filter( ( s ) => ! s.is_finished );
	if ( 0 === unfinished.length ) {
		return null;
	}
	// `GET /steps` est trié `started_at DESC` côté serveur ; on prend le premier.
	return unfinished[ 0 ];
}

/**
 * @param {Object}     props
 * @param {?string}    props.activeUuid   UUID du pas actuellement en cours dans la SPA (à exclure).
 * @param {() => void} [props.onResolved] Callback après abandon (refresh stats).
 * @return {?JSX.Element} Bandeau ou null.
 */
export default function StepResumeBanner( { activeUuid, onResolved } ) {
	const [ unfinishedStep, setUnfinishedStep ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isAbandoning, setIsAbandoning ] = useState( false );

	const fetchUnfinished = useCallback( async () => {
		setIsLoading( true );
		try {
			const result = await api.steps.list( { per_page: 10 } );
			const candidate = findMostRecentUnfinished( result.items ?? [] );
			// Ne pas afficher si c'est le pas qu'on est en train de jouer.
			if ( candidate && candidate.uuid === activeUuid ) {
				setUnfinishedStep( null );
			} else {
				setUnfinishedStep( candidate );
			}
		} catch ( _err ) {
			// Échec silencieux du detect — pas critique, l'admin peut toujours
			// abandonner via la commande WP-CLI `wp htmln steps show`.
			setUnfinishedStep( null );
		} finally {
			setIsLoading( false );
		}
	}, [ activeUuid ] );

	useEffect( () => {
		fetchUnfinished();
	}, [ fetchUnfinished ] );

	const handleAbandon = useCallback( async () => {
		if ( ! unfinishedStep ) {
			return;
		}
		setIsAbandoning( true );
		try {
			await api.steps.finalize( unfinishedStep.uuid );
			setUnfinishedStep( null );
			if ( 'function' === typeof onResolved ) {
				onResolved();
			}
		} catch ( _err ) {
			// Idem : on laisse le bandeau, l'admin retentera.
		} finally {
			setIsAbandoning( false );
		}
	}, [ unfinishedStep, onResolved ] );

	if ( isLoading ) {
		return null;
	}
	if ( ! unfinishedStep ) {
		return null;
	}

	const successful = Number( unfinishedStep.successful_articles ) || 0;
	const refused = Number( unfinishedStep.refused_articles ) || 0;
	const errored = Number( unfinishedStep.errored_articles ) || 0;
	const total = Number( unfinishedStep.total_articles ) || 0;
	const treated = successful + refused + errored;
	const remaining = Math.max( 0, total - treated );

	return (
		<div className="htmln-step-resume notice notice-warning" role="alert">
			<p>
				<strong>
					{ __(
						'Pas précédent interrompu',
						'100son-html-normalizer'
					) }
				</strong>
				{ ' — ' }
				{ sprintf(
					// translators: 1 = traités, 2 = restants, 3 = total.
					__(
						'%1$d articles traités, %2$d restants sur %3$d.',
						'100son-html-normalizer'
					),
					treated,
					remaining,
					total
				) }
			</p>
			<p className="htmln-step-resume__hint">
				{ __(
					"La reprise depuis l'article en pause sera disponible en Phase 6.5 (avec la modale de décision sur régression). Pour l'instant, vous pouvez abandonner le pas pour relâcher le verrou.",
					'100son-html-normalizer'
				) }
			</p>
			<p>
				<Button
					variant="secondary"
					onClick={ handleAbandon }
					disabled={ isAbandoning }
				>
					{ isAbandoning && <Spinner /> }{ ' ' }
					{ __( 'Abandonner ce pas', '100son-html-normalizer' ) }
				</Button>
			</p>
		</div>
	);
}
