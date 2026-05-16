/**
 * PaginationBar — barre de pagination réutilisable haut/bas du tableau.
 *
 * Rendue **2 fois** par `<ArticlesTable>` (au-dessus et en dessous du
 * `<table>`) pour faciliter la navigation sur de longues listes — pas
 * besoin de scroller pour changer de page. Pattern WP admin classique
 * (`tablenav top` / `tablenav bottom`).
 *
 * Trois zones, alignées sur la convention WP-list-table :
 *
 *  - **Gauche** : dropdown « Par page » (20 / 50 / 100 / 200 / Tous (N))
 *                — l'option « Tous » résout vers `total`, plafonné à 1000
 *                côté REST (`DiagnosticsController::MAX_PER_PAGE`), pour
 *                permettre la sélection bulk sur tout le corpus filtré.
 *  - **Centre** : compte total des articles (`N article(s)`).
 *  - **Droite** : Précédent | 1 2 3 … 14 15 16 | Suivant
 *                (rc4 : pagination numérotée cliquable, cf. `buildPageRange`).
 *
 * Boutons désactivés intelligemment :
 *  - Précédent désactivé sur la page 1.
 *  - Suivant désactivé sur la dernière page.
 *  - Tout désactivé pendant un fetch (`isLoading`).
 *
 * Le composant est **stateless** — il reçoit `page`, `perPage`, `total`
 * en props et remonte les changements via `onChangePage` / `onChangePerPage`.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, SelectControl, Spinner } from '@wordpress/components';

/**
 * Options numériques du dropdown « Par page ». Plafonné à MAX_PER_PAGE
 * = 1000 côté REST (cf. `DiagnosticsController::MAX_PER_PAGE`).
 * L'option « Tous » est ajoutée dynamiquement dans `buildPerPageOptions`
 * car son libellé dépend du `total` courant.
 *
 * @type {Array<{value: string, label: string}>}
 */
const PER_PAGE_NUMERIC_OPTIONS = [
	{ value: '20', label: '20' },
	{ value: '50', label: '50' },
	{ value: '100', label: '100' },
	{ value: '200', label: '200' },
];

/**
 * Valeur sentinelle pour l'option « Tous » dans le SelectControl. Reçue
 * dans `onChange`, on la résout vers `total` pour appeler
 * `onChangePerPage`.
 *
 * @type {string}
 */
const ALL_VALUE = 'all';

/**
 * Construit la liste finale d'options selon `total` et `perPage` :
 *  - on filtre les options numériques > total (montrer « 200 » alors qu'il
 *    n'y a que 17 articles n'a pas de sens) ;
 *  - on ajoute en queue l'option « Tous (N) » dès que `total > 0`.
 *
 * @param {number} total   Nombre total d'articles non paginé.
 * @param {number} perPage Per-page courant — utilisé pour conserver
 *                         l'option numérique sélectionnée même si elle
 *                         serait normalement filtrée (cas rare : on
 *                         change de filtre et le total baisse).
 * @return {Array<{value: string, label: string}>} Options.
 */
function buildPerPageOptions( total, perPage ) {
	const numeric = PER_PAGE_NUMERIC_OPTIONS.filter(
		( opt ) =>
			Number( opt.value ) <= total || Number( opt.value ) === perPage
	);
	if ( total > 0 ) {
		numeric.push( {
			value: ALL_VALUE,
			label: sprintf(
				// translators: %d = nombre total d'articles.
				__( 'Tous (%d)', '100son-html-normalizer' ),
				total
			),
		} );
	}
	return numeric;
}

/**
 * Sentinelle d'ellipsis dans la liste de pages — `Number.NaN` permettrait
 * mais il complique l'égalité ; on prend une string explicite.
 *
 * @type {string}
 */
const ELLIPSIS = '…';

/**
 * Calcule la liste des items à afficher dans la pagination numérotée.
 *
 * Algorithme :
 *  - `totalPages ≤ 9` : on affiche toutes les pages, pas d'ellipsis.
 *  - sinon : on inclut **toujours** les pages 1, 2, 3 et n−2, n−1, n
 *    + la page courante ± 1. Les écarts > 1 entre deux pages
 *    consécutives produisent une ellipsis « … ».
 *
 * Exemples (total = 16) :
 *  - current = 1  → [1, 2, 3, …, 14, 15, 16]
 *  - current = 8  → [1, 2, 3, …, 7, 8, 9, …, 14, 15, 16]
 *  - current = 14 → [1, 2, 3, …, 13, 14, 15, 16]
 *  - current = 16 → [1, 2, 3, …, 14, 15, 16]
 *
 * Le rendu garde une largeur quasi-stable (7 à 11 items) — l'œil ne
 * « danse » pas à mesure qu'on navigue.
 *
 * @param {number} currentPage Page courante (≥ 1).
 * @param {number} totalPages  Nombre total de pages (≥ 1).
 * @return {Array<number|string>} Items à rendre — entier = page cliquable, `ELLIPSIS` = `…` inerte.
 */
function buildPageRange( currentPage, totalPages ) {
	if ( totalPages <= 1 ) {
		return [];
	}
	if ( totalPages <= 9 ) {
		return Array.from( { length: totalPages }, ( _, i ) => i + 1 );
	}

	const pages = new Set( [
		1,
		2,
		3,
		totalPages - 2,
		totalPages - 1,
		totalPages,
	] );
	for ( let i = currentPage - 1; i <= currentPage + 1; i++ ) {
		if ( i >= 1 && i <= totalPages ) {
			pages.add( i );
		}
	}

	const sorted = [ ...pages ].sort( ( a, b ) => a - b );
	const out = [];
	for ( let i = 0; i < sorted.length; i++ ) {
		out.push( sorted[ i ] );
		if ( i < sorted.length - 1 && sorted[ i + 1 ] - sorted[ i ] > 1 ) {
			out.push( ELLIPSIS );
		}
	}
	return out;
}

/**
 * @param {Object}              props
 * @param {number}              props.page            Page courante (≥ 1).
 * @param {number}              props.totalPages      Nombre total de pages.
 * @param {number}              props.total           Nombre total d'articles (non paginé).
 * @param {number}              props.perPage         Articles par page courant.
 * @param {boolean}             props.isLoading       Fetch en cours.
 * @param {string}              [props.position]      'top' ou 'bottom' (cosmétique CSS).
 * @param {(n: number) => void} props.onChangePage    Callback changement de page.
 * @param {(n: number) => void} props.onChangePerPage Callback changement de per-page.
 * @return {JSX.Element} Barre.
 */
export default function PaginationBar( {
	page,
	totalPages,
	total,
	perPage,
	isLoading,
	position = 'bottom',
	onChangePage,
	onChangePerPage,
} ) {
	const safeTotalPages = Math.max( 1, totalPages );
	const pageItems = buildPageRange( page, safeTotalPages );
	const perPageOptions = buildPerPageOptions( total, perPage );
	// Si perPage ≥ total (>0), l'utilisateur voit déjà tout : le select
	// affiche « Tous (N) » en lieu et place de la valeur numérique.
	const perPageSelectedValue =
		total > 0 && perPage >= total ? ALL_VALUE : String( perPage );

	return (
		<div className={ `htmln-pagination htmln-pagination--${ position }` }>
			<div className="htmln-pagination__per-page">
				{ /* Label rendu hors du SelectControl pour éviter la
				 *   troncature interne du « floating label » de
				 *   `__next40pxDefaultSize` (max-width fixe qui coupe
				 *   « Par page » en « Par pa… »). On passe
				 *   `hideLabelFromVision` pour garder l'accessibilité
				 *   (label-for via le `id` que le SelectControl génère
				 *   automatiquement). Le label est rendu **après** le
				 *   SelectControl (flex row) pour suivre la convention
				 *   « 50 articles par page » plus lisible que la
				 *   variante « par page : 50 ». */ }
				<SelectControl
					label={ __( 'Par page', '100son-html-normalizer' ) }
					hideLabelFromVision
					value={ perPageSelectedValue }
					options={ perPageOptions }
					onChange={ ( raw ) => {
						if ( ALL_VALUE === raw ) {
							onChangePerPage( Math.max( 1, total ) );
							return;
						}
						onChangePerPage( Number( raw ) );
					} }
					disabled={ isLoading }
					__nextHasNoMarginBottom
				/>
				<span
					className="htmln-pagination__per-page-label"
					aria-hidden="true"
				>
					{ __( 'Par page', '100son-html-normalizer' ) }
				</span>
				{ /* Tiret cadratin (—, U+2014) entre « Par page » et le
				 *   compteur de l'onglet actif. Aria-hidden : pour les
				 *   lecteurs d'écran, le compteur suit immédiatement
				 *   « Par page » sans annoncer le séparateur graphique. */ }
				<span
					className="htmln-pagination__separator"
					aria-hidden="true"
				>
					—
				</span>
				<span className="htmln-pagination__count displaying-num">
					{ sprintf(
						// translators: %d = nombre total d'articles dans l'onglet actif.
						__( '%d article(s)', '100son-html-normalizer' ),
						total
					) }
				</span>
				{ isLoading && (
					<span className="htmln-pagination__spinner">
						<Spinner />
					</span>
				) }
			</div>

			<div className="htmln-pagination__nav">
				<Button
					variant="secondary"
					disabled={ page <= 1 || isLoading }
					onClick={ () => onChangePage( page - 1 ) }
					aria-label={ __(
						'Page précédente',
						'100son-html-normalizer'
					) }
				>
					{ __( '« Précédent', '100son-html-normalizer' ) }
				</Button>
				<span
					className="htmln-pagination__pages"
					role="navigation"
					aria-label={ __(
						'Navigation entre les pages',
						'100son-html-normalizer'
					) }
				>
					{ pageItems.map( ( item, idx ) => {
						if ( ELLIPSIS === item ) {
							// Clé indexée OK : la liste est dérivée d'un calcul
							// déterministe sur (page, totalPages) — pas de
							// réordonnancement de keys entre renders qui pose
							// problème à React.
							return (
								<span
									// eslint-disable-next-line react/no-array-index-key
									key={ `gap-${ idx }` }
									className="htmln-pagination__ellipsis"
									aria-hidden="true"
								>
									{ ELLIPSIS }
								</span>
							);
						}
						const isCurrent = item === page;
						return (
							<Button
								key={ item }
								variant={ isCurrent ? 'primary' : 'tertiary' }
								className={
									isCurrent
										? 'htmln-pagination__page htmln-pagination__page--current'
										: 'htmln-pagination__page'
								}
								onClick={ () => onChangePage( item ) }
								disabled={ isCurrent || isLoading }
								aria-current={ isCurrent ? 'page' : undefined }
								aria-label={ sprintf(
									// translators: %d = numéro de page.
									__( 'Page %d', '100son-html-normalizer' ),
									item
								) }
							>
								{ item }
							</Button>
						);
					} ) }
				</span>
				<Button
					variant="secondary"
					disabled={ page >= totalPages || isLoading }
					onClick={ () => onChangePage( page + 1 ) }
					aria-label={ __(
						'Page suivante',
						'100son-html-normalizer'
					) }
				>
					{ __( 'Suivant »', '100son-html-normalizer' ) }
				</Button>
			</div>
		</div>
	);
}
