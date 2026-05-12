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
 *  - **Gauche** : dropdown « Par page » (20 / 50 / 100 / 200 max).
 *  - **Centre** : compte total des articles (`N article(s)`).
 *  - **Droite** : Précédent | « page X / Y » | Suivant.
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
 * Options du dropdown « Par page ». Plafonné à MAX_PER_PAGE = 200 côté
 * REST (cf. `DiagnosticsController::MAX_PER_PAGE`).
 *
 * @type {Array<{value: string, label: string}>}
 */
const PER_PAGE_OPTIONS = [
	{ value: '20', label: '20' },
	{ value: '50', label: '50' },
	{ value: '100', label: '100' },
	{ value: '200', label: '200' },
];

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

	return (
		<div
			className={ `tablenav ${ position } htmln-pagination htmln-pagination--${ position }` }
		>
			<div className="htmln-pagination__per-page">
				<SelectControl
					label={ __( 'Par page', '100son-html-normalizer' ) }
					value={ String( perPage ) }
					options={ PER_PAGE_OPTIONS }
					onChange={ ( raw ) => onChangePerPage( Number( raw ) ) }
					disabled={ isLoading }
					__nextHasNoMarginBottom
				/>
			</div>

			<div className="htmln-pagination__count">
				<span className="displaying-num">
					{ sprintf(
						// translators: %d = nombre total d'articles.
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
				</Button>{ ' ' }
				<span className="paging-input">
					{ sprintf(
						// translators: 1 = page courante, 2 = total de pages.
						__( 'page %1$d sur %2$d', '100son-html-normalizer' ),
						page,
						safeTotalPages
					) }
				</span>{ ' ' }
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
