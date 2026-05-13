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
import { Fragment } from '@wordpress/element';
import { Spinner, Button } from '@wordpress/components';
import BuilderBadge from './BuilderBadge';
import PaginationBar from './PaginationBar';
import {
	getRuleLabel,
	getRuleTooltip,
	compareRuleIdsByDisplayOrder,
} from '../../utils/ruleLabels';

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
 * Rend la liste des rule_ids comme une suite de `<span title="…">`
 * séparés par virgules. Chaque span porte le titre humain de la règle
 * (cf. `RULE_TOOLTIPS` dans `utils/ruleLabels`) — le tooltip natif du
 * navigateur s'affiche au survol.
 *
 * Tri par ordre d'affichage UI (P1, P2.1, P2.2, P3…), pas par ordre
 * d'exécution du pipeline.
 *
 * @param {Array<{rule_id?: string}>} matchingRules Règles applicables.
 * @return {JSX.Element|string} JSX (suite de spans) ou tiret si vide.
 */
function renderRuleIds( matchingRules ) {
	if ( ! Array.isArray( matchingRules ) || 0 === matchingRules.length ) {
		return '—';
	}
	const ids = matchingRules
		.map( ( rule ) => String( rule?.rule_id ?? '' ) )
		.filter( ( id ) => '' !== id )
		.sort( compareRuleIdsByDisplayOrder );
	if ( 0 === ids.length ) {
		return '—';
	}
	return ids.map( ( id, index ) => (
		<Fragment key={ id }>
			{ index > 0 && ', ' }
			<span className="htmln-rule-chip" title={ getRuleTooltip( id ) }>
				{ getRuleLabel( id ) }
			</span>
		</Fragment>
	) );
}

/**
 * @param {{status: string, is_stale: boolean}} item Diagnostic.
 * @return {JSX.Element} Pastille statut + drapeau stale.
 */
function StatusBadge( { item } ) {
	const status = String( item.status );
	const isStale = Boolean( item.is_stale );
	const labels = {
		normal: __( 'OK', '100son-html-normalizer' ),
		to_improve: __( 'NOK', '100son-html-normalizer' ),
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
 * Compose une URL d'ouverture vers un site externe en gardant le path du
 * permalien courant (slug, structure de permaliens) et en remplaçant juste
 * le scheme + host.
 *
 * Pourquoi pas un simple concat `domain + slug` : la structure de permaliens
 * peut être `/%category%/%postname%/`, `/blog/%postname%/`, etc. On préserve
 * exactement le path du permalien local — il est censé être identique sur
 * Old et Prod (corpus migré, même config).
 *
 * Retourne `null` si l'un des deux ingrédients manque ou est invalide.
 *
 * @param {string} permalink Permalien local (peut être absolu ou vide).
 * @param {string} baseUrl   URL absolue configurée (sans slash final).
 * @return {?string} URL absolue cible, ou null si impossible à composer.
 */
function buildExternalUrl( permalink, baseUrl ) {
	if ( ! permalink || ! baseUrl ) {
		return null;
	}
	try {
		const src = new URL( permalink );
		const dest = new URL( baseUrl );
		return `${ dest.origin }${ src.pathname }${ src.search }${ src.hash }`;
	} catch ( _err ) {
		return null;
	}
}

/**
 * @param {Object}                                                                                                                   props
 * @param {Array}                                                                                                                    props.items             Diagnostics paginés.
 * @param {number}                                                                                                                   props.total             Total non paginé.
 * @param {number}                                                                                                                   props.page              Page courante (≥ 1).
 * @param {number}                                                                                                                   props.perPage           Articles par page.
 * @param {number}                                                                                                                   props.totalPages        Nombre de pages.
 * @param {boolean}                                                                                                                  props.isLoading         Vrai durant le fetch.
 * @param {?string}                                                                                                                  props.error             Message d'erreur ou null.
 * @param {(p: number) => void}                                                                                                      props.onChangePage      Callback changement de page.
 * @param {(n: number) => void}                                                                                                      props.onChangePerPage   Callback changement de per-page (dropdown PaginationBar).
 * @param {Set<number>}                                                                                                              props.selectedIds       IDs sélectionnés (F14.1).
 * @param {(id: number, checked: boolean) => void}                                                                                   props.onToggleArticle   Toggle d'un article.
 * @param {(checked: boolean) => void}                                                                                               props.onToggleAllOnPage Toggle de tous les articles de la page.
 * @param {boolean}                                                                                                                  props.disabled          Désactive les checkboxes (pas en cours).
 * @param {(id: number) => void}                                                                                                     props.onViewDiff        Callback bouton « Voir le diff » par ligne.
 * @param {?{old_url: string, old_label: string, old_enabled: boolean, prod_url: string, prod_label: string, prod_enabled: boolean}} props.externalSites     Config des sites externes (Old / Prod) — null tant que pas chargé. Chaque site a son URL, son libellé de bouton (max 5 chars) et son toggle d'affichage.
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
	onChangePerPage,
	selectedIds,
	onToggleArticle,
	onToggleAllOnPage,
	disabled,
	onViewDiff,
	externalSites,
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

	const paginationProps = {
		page,
		totalPages,
		total,
		perPage,
		isLoading,
		onChangePage,
		onChangePerPage,
	};

	return (
		<div className="htmln-table-wrap">
			<PaginationBar { ...paginationProps } position="top" />
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
								<div className="htmln-articles-table__title-cell">
									{ item.edit_url && (
										<a
											href={ item.edit_url }
											target="_blank"
											rel="noopener noreferrer"
											className="button button-small htmln-articles-table__edit-btn"
											title={ __(
												'Ouvrir l’éditeur (nouvel onglet)',
												'100son-html-normalizer'
											) }
										>
											{ __(
												'Éditer',
												'100son-html-normalizer'
											) }
										</a>
									) }
									{ item.permalink ? (
										<a
											href={ item.permalink }
											target="_blank"
											rel="noopener noreferrer"
											className="htmln-articles-table__title-link"
											title={ __(
												'Ouvrir l’article (lecture, nouvel onglet)',
												'100son-html-normalizer'
											) }
										>
											{ item.post_title
												? String( item.post_title )
												: __(
														'(sans titre)',
														'100son-html-normalizer'
												  ) }
										</a>
									) : (
										<span className="htmln-articles-table__title-link">
											{ item.post_title
												? String( item.post_title )
												: __(
														'(sans titre)',
														'100son-html-normalizer'
												  ) }
										</span>
									) }
									{ /* Boutons Old / Prod — ouvrent l'article
									     sur les domaines configurés en Réglages.
									     Pour chaque site : on n'affiche le bouton
									     que si (a) le toggle `<site>_enabled`
									     est `true` en config, (b) le permalien
									     local est valide → URL composable. Le
									     label est lui aussi configurable
									     (`<site>_label`, 5 chars max), avec
									     fallback `Old` / `Prod` si l'option n'est
									     pas encore chargée. */ }
									{ false !==
										( externalSites?.old_enabled ??
											true ) &&
										( () => {
											const oldHref = buildExternalUrl(
												item.permalink,
												externalSites?.old_url ?? ''
											);
											return (
												oldHref && (
													<a
														href={ oldHref }
														target="_blank"
														rel="noopener noreferrer"
														className="button button-small htmln-articles-table__site-btn"
														title={ __(
															'Ouvrir sur l’ancien site (nouvel onglet)',
															'100son-html-normalizer'
														) }
													>
														{ String(
															externalSites?.old_label ??
																''
														).trim() ||
															__(
																'Old',
																'100son-html-normalizer'
															) }
													</a>
												)
											);
										} )() }
									{ false !==
										( externalSites?.prod_enabled ??
											true ) &&
										( () => {
											const prodHref = buildExternalUrl(
												item.permalink,
												externalSites?.prod_url ?? ''
											);
											return (
												prodHref && (
													<a
														href={ prodHref }
														target="_blank"
														rel="noopener noreferrer"
														className="button button-small htmln-articles-table__site-btn"
														title={ __(
															'Ouvrir sur le site de prod (nouvel onglet)',
															'100son-html-normalizer'
														) }
													>
														{ String(
															externalSites?.prod_label ??
																''
														).trim() ||
															__(
																'Prod',
																'100son-html-normalizer'
															) }
													</a>
												)
											);
										} )() }
								</div>
							</td>
							<td>
								<StatusBadge item={ item } />
							</td>
							<td className="htmln-articles-table__col-builder">
								<BuilderBadge
									type={ item.builder_type }
									hasFossilPanelsData={ Boolean(
										item.has_fossil_panels_data
									) }
								/>
							</td>
							<td>{ renderRuleIds( item.matching_rules ) }</td>
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

			<PaginationBar { ...paginationProps } position="bottom" />
		</div>
	);
}
