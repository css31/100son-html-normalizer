/**
 * StepsTable — tableau paginé de l'historique des pas (F16).
 *
 * Colonnes (cf. cahier §3.1 F16) :
 *   - UUID court (8 premiers caractères, valeur complète en title=…)
 *   - Démarré le (started_at — datetime MySQL)
 *   - Règles appliquées (`applied_rules` joined)
 *   - Articles : compteurs synthétiques (total / ✓ / ✗ / ⚠)
 *   - Statut (Terminé / En cours)
 *   - Durée (finished_at − started_at, ou « — » si non finalisé)
 *   - Action : bouton « Voir le détail »
 *
 * Composant purement présentation — la vue parente (History) fournit
 * items et callbacks via le hook `useStepsList`.
 *
 * Pas de styles SCSS V1.0 — on s'appuie sur les classes WP natives
 * (`wp-list-table widefat striped`) qui se fondent dans WP-Admin.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Spinner, Button } from '@wordpress/components';
import { formatRuleIdList } from '../../utils/ruleLabels';

/**
 * Tronque un UUID v4 à ses 8 premiers caractères pour affichage compact.
 *
 * @param {string} uuid UUID v4 (36 caractères).
 * @return {string} 8 premiers caractères ou chaîne vide.
 */
function shortUuid( uuid ) {
	const str = String( uuid ?? '' );
	return str.length >= 8 ? str.slice( 0, 8 ) : str;
}

/**
 * Calcule la durée en secondes entre deux datetimes MySQL et renvoie un
 * libellé lisible (« 12s », « 3m 47s », « 1h 02m »). Retourne « — » si
 * le pas n'est pas finalisé ou si le parse échoue.
 *
 * Note : `Date.parse('2026-05-11 14:32:00')` est non-standard mais
 * fonctionne sur tous les navigateurs cibles WP. On force le format ISO
 * en remplaçant l'espace par `T` et en ajoutant `Z` (UTC) pour la
 * cohérence — WP stocke en UTC dans les colonnes datetime.
 *
 * @param {?string} startedAt  Datetime MySQL `Y-m-d H:i:s`.
 * @param {?string} finishedAt Datetime MySQL `Y-m-d H:i:s` ou null.
 * @return {string} Durée formatée ou « — ».
 */
function formatDuration( startedAt, finishedAt ) {
	if ( ! startedAt || ! finishedAt ) {
		return '—';
	}
	const toMs = ( s ) => Date.parse( String( s ).replace( ' ', 'T' ) + 'Z' );
	const startMs = toMs( startedAt );
	const endMs = toMs( finishedAt );
	if ( Number.isNaN( startMs ) || Number.isNaN( endMs ) ) {
		return '—';
	}
	const totalSeconds = Math.max(
		0,
		Math.round( ( endMs - startMs ) / 1000 )
	);
	if ( totalSeconds < 60 ) {
		return sprintf(
			// translators: %d = secondes.
			__( '%ds', '100son-html-normalizer' ),
			totalSeconds
		);
	}
	if ( totalSeconds < 3600 ) {
		const m = Math.floor( totalSeconds / 60 );
		const s = totalSeconds % 60;
		return sprintf(
			// translators: 1 = minutes, 2 = secondes.
			__( '%1$dm %2$ds', '100son-html-normalizer' ),
			m,
			s
		);
	}
	const h = Math.floor( totalSeconds / 3600 );
	const m = Math.floor( ( totalSeconds % 3600 ) / 60 );
	return sprintf(
		// translators: 1 = heures, 2 = minutes.
		__( '%1$dh %2$02dm', '100son-html-normalizer' ),
		h,
		m
	);
}

/**
 * Compte les régressions confirmées dans `per_article_results` (status
 * = `success` ET `regression` présent). C'est la différence entre les
 * articles réussis « directement » et ceux qui ont été confirmés sur
 * régression — utile pour la synthèse F16.
 *
 * @param {Object<string, {status: string, regression?: Object}>} perArticle Map post_id → résultat.
 * @return {number} Nombre de régressions confirmées.
 */
function countConfirmedRegressions( perArticle ) {
	if ( ! perArticle || 'object' !== typeof perArticle ) {
		return 0;
	}
	return Object.values( perArticle ).filter(
		( entry ) =>
			entry &&
			'success' === entry.status &&
			entry.regression &&
			'object' === typeof entry.regression
	).length;
}

/**
 * @param {{ is_finished: boolean }} step Pas serialisé.
 * @return {JSX.Element} Pastille statut.
 */
function StatusBadge( { step } ) {
	const isFinished = Boolean( step.is_finished );
	const label = isFinished
		? __( 'Terminé', '100son-html-normalizer' )
		: __( 'En cours', '100son-html-normalizer' );
	const cls = isFinished
		? 'htmln-badge htmln-badge--normal'
		: 'htmln-badge htmln-badge--to_improve';
	return <span className={ cls }>{ label }</span>;
}

/**
 * @param {Object}                 props
 * @param {Array}                  props.items        Pas paginés.
 * @param {number}                 props.total        Total non paginé.
 * @param {number}                 props.page         Page courante (≥ 1).
 * @param {number}                 props.totalPages   Nombre de pages.
 * @param {boolean}                props.isLoading    Vrai durant le fetch.
 * @param {?string}                props.error        Message d'erreur ou null.
 * @param {(p: number) => void}    props.onChangePage Callback changement de page.
 * @param {(uuid: string) => void} props.onViewDetail Callback bouton « Voir le détail ».
 * @return {JSX.Element} Tableau + pagination.
 */
export default function StepsTable( {
	items,
	total,
	page,
	totalPages,
	isLoading,
	error,
	onChangePage,
	onViewDetail,
} ) {
	if ( error ) {
		return (
			<div className="htmln-error notice notice-error">
				<p>
					{ sprintf(
						// translators: %s = message d'erreur technique.
						__(
							'Impossible de charger l’historique : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</p>
			</div>
		);
	}

	if ( isLoading && 0 === items.length ) {
		return (
			<div className="htmln-table-loading">
				<Spinner />{ ' ' }
				{ __(
					'Chargement de l’historique…',
					'100son-html-normalizer'
				) }
			</div>
		);
	}

	if ( 0 === items.length ) {
		return (
			<p className="htmln-empty">
				{ __(
					'Aucun pas exécuté pour le moment.',
					'100son-html-normalizer'
				) }
			</p>
		);
	}

	return (
		<div className="htmln-table-wrap">
			<table className="wp-list-table widefat striped htmln-steps-table">
				<thead>
					<tr>
						<th scope="col" className="manage-column">
							{ __( 'UUID', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Démarré le', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Règles', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Articles', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Statut', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Durée', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							<span className="screen-reader-text">
								{ __( 'Actions', '100son-html-normalizer' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( step ) => {
						const rules = formatRuleIdList( step.applied_rules );
						const confirmedRegressions = countConfirmedRegressions(
							step.per_article_results
						);
						return (
							<tr key={ step.uuid }>
								<td>
									<code
										className="htmln-uuid"
										title={ String( step.uuid ) }
									>
										{ shortUuid( step.uuid ) }
									</code>
								</td>
								<td>
									<time
										dateTime={ String(
											step.started_at ?? ''
										) }
									>
										{ String( step.started_at ?? '—' ) }
									</time>
								</td>
								<td>{ rules || '—' }</td>
								<td className="htmln-steps-table__counters">
									<span
										title={ __(
											'Total',
											'100son-html-normalizer'
										) }
									>
										{ step.total_articles ?? 0 }
									</span>
									{ ' · ' }
									<span
										className="htmln-counter htmln-counter--success"
										title={ __(
											'Validés',
											'100son-html-normalizer'
										) }
									>
										✓ { step.successful_articles ?? 0 }
									</span>
									{ ' · ' }
									<span
										className="htmln-counter htmln-counter--refused"
										title={ __(
											'Refusés (régression rejetée)',
											'100son-html-normalizer'
										) }
									>
										✗ { step.refused_articles ?? 0 }
									</span>
									{ ' · ' }
									<span
										className="htmln-counter htmln-counter--error"
										title={ __(
											'Erreurs',
											'100son-html-normalizer'
										) }
									>
										⚠ { step.errored_articles ?? 0 }
									</span>
									{ confirmedRegressions > 0 && (
										<>
											{ ' · ' }
											<span
												className="htmln-counter htmln-counter--regression"
												title={ __(
													'Régressions confirmées (incluses dans ✓)',
													'100son-html-normalizer'
												) }
											>
												{ sprintf(
													// translators: %d = nombre de régressions confirmées.
													__(
														'⚑ %d régr.',
														'100son-html-normalizer'
													),
													confirmedRegressions
												) }
											</span>
										</>
									) }
								</td>
								<td>
									<StatusBadge step={ step } />
								</td>
								<td>
									{ formatDuration(
										step.started_at,
										step.finished_at
									) }
								</td>
								<td>
									<Button
										variant="link"
										onClick={ () =>
											onViewDetail( String( step.uuid ) )
										}
									>
										{ __(
											'Voir le détail',
											'100son-html-normalizer'
										) }
									</Button>
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			<div className="tablenav bottom htmln-pagination">
				<div className="tablenav-pages">
					<span className="displaying-num">
						{ sprintf(
							// translators: %d = nombre total de pas.
							__( '%d pas au total', '100son-html-normalizer' ),
							total
						) }
					</span>
					<span className="pagination-links">
						<Button
							variant="secondary"
							disabled={ page <= 1 || isLoading }
							onClick={ () => onChangePage( page - 1 ) }
						>
							{ __( '« Précédent', '100son-html-normalizer' ) }
						</Button>{ ' ' }
						<span className="paging-input">
							{ sprintf(
								// translators: 1 = page courante, 2 = total de pages.
								__(
									'page %1$d sur %2$d',
									'100son-html-normalizer'
								),
								page,
								Math.max( 1, totalPages )
							) }
						</span>{ ' ' }
						<Button
							variant="secondary"
							disabled={ page >= totalPages || isLoading }
							onClick={ () => onChangePage( page + 1 ) }
						>
							{ __( 'Suivant »', '100son-html-normalizer' ) }
						</Button>
					</span>
				</div>
				{ isLoading && (
					<span className="htmln-pagination__spinner">
						<Spinner />
					</span>
				) }
			</div>
		</div>
	);
}
