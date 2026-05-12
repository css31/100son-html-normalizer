/**
 * ArticlesTable — tableau des articles diagnostiqués pour l'onglet F13 actif.
 *
 * Colonnes (cf. cahier §3.1 F13) :
 *   - ID
 *   - Statut (badge coloré + drapeau stale)
 *   - Règles applicables (liste compacte des `rule_id`)
 *   - Violations (somme des occurrences des règles applicables)
 *   - Mots (issu de `metrics.words`)
 *   - Dernier diagnostic (date relative)
 *
 * Pagination simple : précédent / suivant + indicateur « page X / Y ».
 * Le composant est purement présentation — la vue parente fournit les
 * items et les callbacks de pagination via le hook `useDiagnosticsList`.
 *
 * Pas de styles SCSS V1.0 — on s'appuie sur les classes WP natives
 * (`wp-list-table widefat striped`) qui se fondent dans WP-Admin.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Spinner, Button } from '@wordpress/components';
import BuilderBadge from './BuilderBadge';

/**
 * Calcule le total des occurrences à partir des règles applicables.
 *
 * @param {Array<{occurrences?: number}>} matchingRules Règles applicables au diagnostic.
 * @return {number} Somme des occurrences.
 */
function sumViolations( matchingRules ) {
	if ( ! Array.isArray( matchingRules ) ) {
		return 0;
	}
	return matchingRules.reduce(
		( acc, rule ) => acc + ( Number( rule?.occurrences ) || 0 ),
		0
	);
}

/**
 * Concatène les rule_id en chaîne lisible (ex. `P1, P3, P7`).
 *
 * @param {Array<{rule_id?: string}>} matchingRules Règles applicables.
 * @return {string} Liste lisible ou tiret si vide.
 */
function formatRuleIds( matchingRules ) {
	if ( ! Array.isArray( matchingRules ) || 0 === matchingRules.length ) {
		return '—';
	}
	return matchingRules
		.map( ( rule ) => String( rule?.rule_id ?? '?' ) )
		.join( ', ' );
}

/**
 * @param {{status: string, is_stale: boolean}} item Diagnostic.
 * @return {JSX.Element} Pastille statut + drapeau stale.
 */
function StatusBadge( { item } ) {
	const status = String( item.status );
	const isStale = Boolean( item.is_stale );
	const labels = {
		normal: __( 'Normal', '100son-html-normalizer' ),
		to_improve: __( 'À améliorer', '100son-html-normalizer' ),
	};
	const label = labels[ status ] || status;
	return (
		<span className={ `htmln-badge htmln-badge--${ status }` }>
			{ label }
			{ isStale && (
				<em className="htmln-badge__stale">
					{ ' ' }
					{ __( '(obsolète)', '100son-html-normalizer' ) }
				</em>
			) }
		</span>
	);
}

/**
 * @param {Object}                                 props
 * @param {Array}                                  props.items             Diagnostics paginés.
 * @param {number}                                 props.total             Total non paginé.
 * @param {number}                                 props.page              Page courante (≥ 1).
 * @param {number}                                 props.perPage           Articles par page.
 * @param {number}                                 props.totalPages        Nombre de pages.
 * @param {boolean}                                props.isLoading         Vrai durant le fetch.
 * @param {?string}                                props.error             Message d'erreur ou null.
 * @param {(p: number) => void}                    props.onChangePage      Callback changement de page.
 * @param {Set<number>}                            props.selectedIds       IDs sélectionnés (F14.1).
 * @param {(id: number, checked: boolean) => void} props.onToggleArticle   Toggle d'un article.
 * @param {(checked: boolean) => void}             props.onToggleAllOnPage Toggle de tous les articles de la page.
 * @param {boolean}                                props.disabled          Désactive les checkboxes (pas en cours).
 * @param {(id: number) => void}                   props.onViewDiff        Callback bouton « Voir le diff » par ligne.
 * @return {JSX.Element} Tableau + pagination.
 */
export default function ArticlesTable( {
	items,
	total,
	page,
	perPage,
	totalPages,
	isLoading,
	error,
	onChangePage,
	selectedIds,
	onToggleArticle,
	onToggleAllOnPage,
	disabled,
	onViewDiff,
} ) {
	const allOnPageChecked =
		items.length > 0 &&
		items.every( ( item ) => selectedIds.has( item.post_id ) );
	const someOnPageChecked =
		! allOnPageChecked &&
		items.some( ( item ) => selectedIds.has( item.post_id ) );
	if ( error ) {
		return (
			<div className="htmln-error notice notice-error">
				<p>
					{ sprintf(
						// translators: %s = message d'erreur technique.
						__(
							'Impossible de charger les articles : %s',
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
				{ __( 'Chargement des articles…', '100son-html-normalizer' ) }
			</div>
		);
	}

	if ( 0 === items.length ) {
		return (
			<p className="htmln-empty">
				{ __(
					'Aucun article dans cet onglet.',
					'100son-html-normalizer'
				) }
			</p>
		);
	}

	return (
		<div className="htmln-table-wrap">
			<table className="wp-list-table widefat striped htmln-articles-table">
				<thead>
					<tr>
						<th scope="col" className="manage-column check-column">
							<input
								type="checkbox"
								aria-label={ __(
									'Tout sélectionner sur la page',
									'100son-html-normalizer'
								) }
								checked={ allOnPageChecked }
								ref={ ( node ) => {
									if ( node ) {
										node.indeterminate = someOnPageChecked;
									}
								} }
								disabled={ disabled }
								onChange={ ( e ) =>
									onToggleAllOnPage( e.target.checked )
								}
							/>
						</th>
						<th scope="col" className="manage-column">
							{ __( 'ID', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Titre', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Statut', '100son-html-normalizer' ) }
						</th>
						<th
							scope="col"
							className="manage-column htmln-articles-table__col-builder"
							title={ __(
								'Constructeur',
								'100son-html-normalizer'
							) }
						>
							{ __( 'Constr.', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __(
								'Règles applicables',
								'100son-html-normalizer'
							) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Violations', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __( 'Mots', '100son-html-normalizer' ) }
						</th>
						<th scope="col" className="manage-column">
							{ __(
								'Dernier diagnostic',
								'100son-html-normalizer'
							) }
						</th>
						<th scope="col" className="manage-column">
							<span className="screen-reader-text">
								{ __( 'Actions', '100son-html-normalizer' ) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item ) => (
						<tr key={ item.post_id }>
							<th scope="row" className="check-column">
								<input
									type="checkbox"
									aria-label={ sprintf(
										// translators: %d = post_id.
										__(
											"Sélectionner l'article %d",
											'100son-html-normalizer'
										),
										item.post_id
									) }
									checked={ selectedIds.has( item.post_id ) }
									disabled={ disabled }
									onChange={ ( e ) =>
										onToggleArticle(
											item.post_id,
											e.target.checked
										)
									}
								/>
							</th>
							<td>{ item.post_id }</td>
							<td className="htmln-articles-table__title">
								{ item.post_title
									? String( item.post_title )
									: __(
											'(sans titre)',
											'100son-html-normalizer'
									  ) }
							</td>
							<td>
								<StatusBadge item={ item } />
							</td>
							<td className="htmln-articles-table__col-builder">
								<BuilderBadge type={ item.builder_type } />
							</td>
							<td>{ formatRuleIds( item.matching_rules ) }</td>
							<td>{ sumViolations( item.matching_rules ) }</td>
							<td>
								{ item.metrics &&
								Number.isFinite( item.metrics.words )
									? item.metrics.words
									: '—' }
							</td>
							<td>
								<time
									dateTime={ String(
										item.diagnosed_at ?? ''
									) }
								>
									{ String( item.diagnosed_at ?? '—' ) }
								</time>
							</td>
							<td>
								<Button
									variant="link"
									onClick={ () => onViewDiff( item.post_id ) }
									disabled={ disabled }
								>
									{ __(
										'Voir le diff',
										'100son-html-normalizer'
									) }
								</Button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<div className="tablenav bottom htmln-pagination">
				<div className="tablenav-pages">
					<span className="displaying-num">
						{ sprintf(
							// translators: %d = nombre total d'articles.
							__(
								'%d article(s) au total',
								'100son-html-normalizer'
							),
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
			{ /* perPage est passé pour cohérence d'API mais non utilisé en V1.0 */ }
			<input type="hidden" value={ perPage } />
		</div>
	);
}
