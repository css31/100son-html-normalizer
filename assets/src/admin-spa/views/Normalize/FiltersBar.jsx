/**
 * FiltersBar — barre de filtres au-dessus du tableau des diagnostics.
 *
 * Positionnée entre `ScanBar` (action ponctuelle) et `TabsHeader` (onglets
 * internes status). Permet de raffiner la liste affichée selon 6 critères :
 *
 *   - Recherche (input texte)   : titre ou ID exact si numérique
 *   - Catégorie WP              : dropdown des taxonomies `category`
 *   - Année / Mois              : 2 dropdowns sur `post_date`
 *   - Constructeur              : 4 valeurs (SO / Gut / Autres / Out)
 *   - Règles applicables (rc4)  : Dropdown multi-checkbox sur les 9 préréglages,
 *                                 sémantique OR (au moins une match)
 *
 * État local minimaliste : la barre est contrôlée par le parent via
 * `value` + `onChange( nextValue )`. À chaque modification, on remonte
 * un nouvel objet filters qui déclenche le refetch du hook.
 *
 * Search debouncé à 250 ms — sans debounce, taper « Hello » lancerait
 * 5 requêtes REST consécutives. Le debounce est local au composant
 * Search (pas en remontée), donc le parent reçoit l'événement dès
 * que l'utilisateur a fini de taper.
 */

import { __, sprintf, _n } from '@wordpress/i18n';
import {
	useEffect,
	useState,
	useCallback,
	useRef,
	useMemo,
} from '@wordpress/element';
import {
	Button,
	TextControl,
	SelectControl,
	CheckboxControl,
	Dropdown,
	Spinner,
} from '@wordpress/components';
import {
	RULE_DISPLAY_ORDER,
	getRuleLabel,
	getRuleTooltip,
	compareRuleIdsByDisplayOrder,
} from '../../utils/ruleLabels';

/**
 * @typedef {Object} Filters
 * @property {string}   [search]   Recherche par titre, ou par ID si numérique.
 * @property {number}   [cat]      ID de catégorie WP (>0).
 * @property {number}   [year]     Année (>0).
 * @property {number}   [month]    Mois 1-12 (combiné avec year).
 * @property {string}   [builder]  siteorigin / gutenberg / other / out.
 * @property {string[]} [rule_ids] IDs internes (`P1`..`P9`) filtre OR sur règles applicables.
 */

/**
 * Liste triée des IDs internes des 9 préréglages, dans l'ordre d'affichage
 * UI (P1, P2.1, P2.2, P3, P4, P5, P6, P7, P8). Pré-calculé hors composant
 * pour éviter de retrier à chaque render.
 *
 * @type {string[]}
 */
const SORTED_RULE_IDS = Object.keys( RULE_DISPLAY_ORDER ).sort(
	compareRuleIdsByDisplayOrder
);

/**
 * Mois (numérique → label localisé) — clé chaîne car SelectControl
 * convertit toutes les valeurs en string en interne.
 *
 * @type {Array<{value: string, label: string}>}
 */
const MONTH_OPTIONS = [
	{ value: '', label: '—' },
	{ value: '1', label: __( 'Janvier', '100son-html-normalizer' ) },
	{ value: '2', label: __( 'Février', '100son-html-normalizer' ) },
	{ value: '3', label: __( 'Mars', '100son-html-normalizer' ) },
	{ value: '4', label: __( 'Avril', '100son-html-normalizer' ) },
	{ value: '5', label: __( 'Mai', '100son-html-normalizer' ) },
	{ value: '6', label: __( 'Juin', '100son-html-normalizer' ) },
	{ value: '7', label: __( 'Juillet', '100son-html-normalizer' ) },
	{ value: '8', label: __( 'Août', '100son-html-normalizer' ) },
	{ value: '9', label: __( 'Septembre', '100son-html-normalizer' ) },
	{ value: '10', label: __( 'Octobre', '100son-html-normalizer' ) },
	{ value: '11', label: __( 'Novembre', '100son-html-normalizer' ) },
	{ value: '12', label: __( 'Décembre', '100son-html-normalizer' ) },
];

/**
 * Délai du debounce sur la recherche, en ms.
 */
const SEARCH_DEBOUNCE_MS = 250;

/**
 * @param {Object}                                                        props
 * @param {Filters}                                                       props.value     Filtres courants.
 * @param {(next: Filters) => void}                                       props.onChange  Callback de remontée.
 * @param {{
 *   years: number[],
 *   categories: Array<{id: number, name: string, count: number}>,
 *   builders: Object<string, number>,
 *   applicable_rules: Object<string, number>
 * }}                                  props.facets    Données des dropdowns.
 * @param {boolean}                                                       props.isLoading Spinner pendant fetch facets.
 * @return {JSX.Element} Barre.
 */
export default function FiltersBar( { value, onChange, facets, isLoading } ) {
	// Search local debouncé : on stocke la valeur typée immédiatement
	// pour la réactivité du input, et on déclenche `onChange` après
	// SEARCH_DEBOUNCE_MS d'inactivité.
	const [ searchLocal, setSearchLocal ] = useState( value.search ?? '' );
	const debounceTimerRef = useRef( null );

	// Synchronise le local quand le parent reset les filters
	// (ex. bouton « Effacer tous »).
	useEffect( () => {
		setSearchLocal( value.search ?? '' );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value.search ] );

	const handleSearchChange = useCallback(
		( next ) => {
			setSearchLocal( next );
			if ( debounceTimerRef.current ) {
				clearTimeout( debounceTimerRef.current );
			}
			debounceTimerRef.current = setTimeout( () => {
				onChange( {
					...value,
					search: next.trim(),
				} );
			}, SEARCH_DEBOUNCE_MS );
		},
		[ value, onChange ]
	);

	// Cleanup du timer au démontage pour éviter les warnings React.
	useEffect( () => {
		return () => {
			if ( debounceTimerRef.current ) {
				clearTimeout( debounceTimerRef.current );
			}
		};
	}, [] );

	const handleSelectChange = useCallback(
		( key, raw ) => {
			const next = { ...value };
			const parsed = '' === raw ? undefined : Number( raw );
			if (
				undefined === parsed ||
				! Number.isFinite( parsed ) ||
				0 === parsed
			) {
				delete next[ key ];
			} else {
				next[ key ] = parsed;
			}
			onChange( next );
		},
		[ value, onChange ]
	);

	const handleBuilderChange = useCallback(
		( raw ) => {
			const next = { ...value };
			if ( '' === raw ) {
				delete next.builder;
			} else {
				next.builder = raw;
			}
			onChange( next );
		},
		[ value, onChange ]
	);

	const handleRuleToggle = useCallback(
		( ruleId, isChecked ) => {
			const current = Array.isArray( value.rule_ids )
				? value.rule_ids
				: [];
			const next = { ...value };
			if ( isChecked ) {
				if ( ! current.includes( ruleId ) ) {
					next.rule_ids = [ ...current, ruleId ];
				} else {
					next.rule_ids = current;
				}
			} else {
				const filtered = current.filter( ( r ) => r !== ruleId );
				if ( 0 === filtered.length ) {
					// Omet la clé quand vide pour rester aligné sur la
					// convention des autres filtres (absent = inactif).
					delete next.rule_ids;
				} else {
					next.rule_ids = filtered;
				}
			}
			onChange( next );
		},
		[ value, onChange ]
	);

	const handleRulesClear = useCallback( () => {
		const next = { ...value };
		delete next.rule_ids;
		onChange( next );
	}, [ value, onChange ] );

	const handleReset = useCallback( () => {
		setSearchLocal( '' );
		if ( debounceTimerRef.current ) {
			clearTimeout( debounceTimerRef.current );
		}
		onChange( {} );
	}, [ onChange ] );

	// Options des dropdowns construites à partir des facets.
	const yearOptions = useMemo( () => {
		const opts = [
			{ value: '', label: __( 'Toutes', '100son-html-normalizer' ) },
		];
		( facets.years ?? [] ).forEach( ( y ) => {
			opts.push( { value: String( y ), label: String( y ) } );
		} );
		return opts;
	}, [ facets.years ] );

	const categoryOptions = useMemo( () => {
		const opts = [
			{ value: '', label: __( 'Toutes', '100son-html-normalizer' ) },
		];
		( facets.categories ?? [] ).forEach( ( c ) => {
			opts.push( {
				value: String( c.id ),
				/* translators: 1 = nom de la catégorie, 2 = nombre d'articles. */
				label: sprintf( '%1$s (%2$d)', c.name, c.count ),
			} );
		} );
		return opts;
	}, [ facets.categories ] );

	const builderOptions = useMemo( () => {
		const b = facets.builders ?? {};
		const fmt = ( label, count ) =>
			Number.isFinite( count ) && count > 0
				? /* translators: 1 = libellé du constructeur, 2 = nombre d'articles. */
				  sprintf( '%1$s (%2$d)', label, count )
				: label;
		return [
			{ value: '', label: __( 'Tous', '100son-html-normalizer' ) },
			{
				value: 'siteorigin',
				label: fmt(
					__( 'SiteOrigin', '100son-html-normalizer' ),
					Number( b.siteorigin ?? 0 )
				),
			},
			{
				value: 'gutenberg',
				label: fmt(
					__( 'Gutenberg', '100son-html-normalizer' ),
					Number( b.gutenberg ?? 0 )
				),
			},
			{
				value: 'other',
				label: fmt(
					__( 'Autres', '100son-html-normalizer' ),
					Number( b.other ?? 0 )
				),
			},
			{
				value: 'out',
				label: fmt(
					__( 'Hors périmètre', '100son-html-normalizer' ),
					Number( b.out ?? 0 )
				),
			},
		];
	}, [ facets.builders ] );

	const selectedRuleIds = Array.isArray( value.rule_ids )
		? value.rule_ids
		: [];

	const hasActiveFilters =
		( value.search && '' !== value.search ) ||
		value.cat ||
		value.year ||
		value.month ||
		value.builder ||
		selectedRuleIds.length > 0;

	return (
		<div className="htmln-filters-bar">
			<div className="htmln-filters-bar__row">
				<div className="htmln-filters-bar__field htmln-filters-bar__field--search">
					<TextControl
						label={ __( 'Recherche', '100son-html-normalizer' ) }
						value={ searchLocal }
						onChange={ handleSearchChange }
						placeholder={ __(
							'Titre ou ID…',
							'100son-html-normalizer'
						) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="htmln-filters-bar__field">
					<SelectControl
						label={ __( 'Catégorie', '100son-html-normalizer' ) }
						value={ value.cat ? String( value.cat ) : '' }
						options={ categoryOptions }
						onChange={ ( raw ) => handleSelectChange( 'cat', raw ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="htmln-filters-bar__field">
					<SelectControl
						label={ __( 'Année', '100son-html-normalizer' ) }
						value={ value.year ? String( value.year ) : '' }
						options={ yearOptions }
						onChange={ ( raw ) =>
							handleSelectChange( 'year', raw )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="htmln-filters-bar__field">
					<SelectControl
						label={ __( 'Mois', '100son-html-normalizer' ) }
						value={ value.month ? String( value.month ) : '' }
						options={ MONTH_OPTIONS }
						onChange={ ( raw ) =>
							handleSelectChange( 'month', raw )
						}
						disabled={ ! value.year }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="htmln-filters-bar__field">
					<SelectControl
						label={ __( 'Constructeur', '100son-html-normalizer' ) }
						value={ value.builder ?? '' }
						options={ builderOptions }
						onChange={ handleBuilderChange }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>

				<div className="htmln-filters-bar__field htmln-filters-bar__field--rules">
					<RulesFilterDropdown
						selectedRuleIds={ selectedRuleIds }
						ruleCounts={ facets.applicable_rules ?? {} }
						onToggle={ handleRuleToggle }
						onClear={ handleRulesClear }
					/>
				</div>

				<div className="htmln-filters-bar__actions">
					<Button
						variant="tertiary"
						onClick={ handleReset }
						disabled={ ! hasActiveFilters }
					>
						{ __( 'Effacer', '100son-html-normalizer' ) }
					</Button>
					{ isLoading && <Spinner /> }
				</div>
			</div>
		</div>
	);
}

/**
 * RulesFilterDropdown — dropdown multi-sélection des règles applicables.
 *
 * Affiche un bouton « Règles : … ▾ » qui ouvre un popover de 9 cases à
 * cocher (P1..P8, libellés UI via `getRuleLabel`). Chaque case porte un
 * compteur `(N)` issu de la facette `applicable_rules`. Filtre OR côté
 * serveur (au moins une règle cochée match l'article).
 *
 * Le label du bouton s'adapte :
 *  - 0 sélectionnée : « Règles : Toutes »
 *  - 1 sélectionnée : « Règles : P2.1 »
 *  - N≥2 sélectionnées : « Règles : N sélectionnées »
 *
 * @param {Object}                                 props
 * @param {string[]}                               props.selectedRuleIds IDs internes cochés.
 * @param {Object<string, number>}                 props.ruleCounts      Map rule_id → count (facette).
 * @param {(id: string, checked: boolean) => void} props.onToggle        Callback toggle d'une case.
 * @param {() => void}                             props.onClear         Callback bouton « Tout désélectionner ».
 * @return {JSX.Element} Dropdown.
 */
function RulesFilterDropdown( {
	selectedRuleIds,
	ruleCounts,
	onToggle,
	onClear,
} ) {
	const selectedCount = selectedRuleIds.length;

	// Libellé du bouton selon le nombre de règles cochées.
	const toggleLabel = useMemo( () => {
		if ( 0 === selectedCount ) {
			return __( 'Toutes', '100son-html-normalizer' );
		}
		if ( 1 === selectedCount ) {
			return getRuleLabel( selectedRuleIds[ 0 ] );
		}
		return sprintf(
			// translators: %d = nombre de règles sélectionnées dans le filtre.
			_n(
				'%d sélectionnée',
				'%d sélectionnées',
				selectedCount,
				'100son-html-normalizer'
			),
			selectedCount
		);
	}, [ selectedCount, selectedRuleIds ] );

	return (
		<div className="htmln-filters-bar__rules-wrapper">
			<span className="htmln-filters-bar__rules-label">
				{ __( 'Règles', '100son-html-normalizer' ) }
			</span>
			<Dropdown
				className="htmln-rules-filter"
				contentClassName="htmln-rules-filter__popover"
				popoverProps={ { placement: 'bottom-start' } }
				renderToggle={ ( { isOpen, onToggle: onPopoverToggle } ) => (
					<Button
						variant="secondary"
						onClick={ onPopoverToggle }
						aria-expanded={ isOpen }
						aria-haspopup="true"
						className="htmln-rules-filter__toggle"
						__next40pxDefaultSize
					>
						{ toggleLabel }
						<span
							className="htmln-rules-filter__chevron"
							aria-hidden="true"
						>
							▾
						</span>
					</Button>
				) }
				renderContent={ () => (
					<div className="htmln-rules-filter__menu">
						<ul className="htmln-rules-filter__list">
							{ SORTED_RULE_IDS.map( ( ruleId ) => {
								const isChecked =
									selectedRuleIds.includes( ruleId );
								const count = Number(
									ruleCounts[ ruleId ] ?? 0
								);
								const label = sprintf(
									/* translators: 1 = label affiché (ex. P2.1), 2 = nombre d'articles concernés. */
									'%1$s (%2$d)',
									getRuleLabel( ruleId ),
									count
								);
								return (
									<li
										key={ ruleId }
										className="htmln-rules-filter__item"
										title={ getRuleTooltip( ruleId ) }
									>
										<CheckboxControl
											label={ label }
											checked={ isChecked }
											onChange={ ( next ) =>
												onToggle( ruleId, next )
											}
											__nextHasNoMarginBottom
										/>
									</li>
								);
							} ) }
						</ul>
						{ selectedCount > 0 && (
							<div className="htmln-rules-filter__footer">
								<Button variant="link" onClick={ onClear }>
									{ __(
										'Tout désélectionner',
										'100son-html-normalizer'
									) }
								</Button>
							</div>
						) }
					</div>
				) }
			/>
		</div>
	);
}
