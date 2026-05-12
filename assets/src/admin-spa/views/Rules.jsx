/**
 * Rules — vue racine de l'onglet « Règles » (post-rc1).
 *
 * Liste les 8 préréglages P1-P8 avec, pour chacun :
 *  - une **case « Sélectionnée pour le prochain pas »** (store mémoire,
 *    partagée avec la vue Normaliser) ;
 *  - un **toggle « Activée par défaut »** (BDD, persisté via REST) ;
 *  - la **description** rendue depuis PresetRegistry (HTML serveur de
 *    confiance, `dangerouslySetInnerHTML` autorisé) ;
 *  - les **paramètres modifiables** spécifiques à la règle (P5 seuil,
 *    P6 keep_text_align, P7 seuil + 5 marqueurs + custom_markers,
 *    P8 mappings bold/italic) ;
 *  - un **encart Avant / Après** sur un fragment HTML factice statique
 *    qui illustre l'effet de la règle.
 *
 * Persistance :
 *  - Sélection éphémère : store `htmln/spa.selectedRules` (perdue au reload).
 *  - `enabled` + paramètres : option WP `son100_htmln_presets` via
 *    POST /presets/<id>. Cohabite avec la page V0.1 PHP — les deux
 *    écrivent dans la même clé d'option.
 */

import { __, sprintf } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { STORE_NAME, ALL_RULE_IDS } from '../store';
import { usePresets } from '../hooks/usePresets';
import {
	getRuleLabel,
	compareRuleIdsByDisplayOrder,
} from '../utils/ruleLabels';

/**
 * Exemples statiques par règle pour l'encart « Avant / Après ».
 * Sources : `PLUGIN_CONTEXT.md` §6 + descriptions des règles.
 *
 * @type {Object<string, {before: string, after: string}>}
 */
const RULE_EXAMPLES = {
	P1: {
		before: '<p>Texte utile</p>\n<p>&nbsp;</p>\n<p>Suite</p>',
		after: '<p>Texte utile</p>\n<p>Suite</p>',
	},
	P2: {
		before: '<h2>Vraie section</h2>\n<h3>&nbsp;</h3>\n<p>Contenu</p>',
		after: '<h2>Vraie section</h2>\n<p>Contenu</p>',
	},
	P3: {
		before: '<p>Article</p>\n[shareaholic id="123-abc"]\n<p>Suite</p>',
		after: '<p>Article</p>\n<p>Suite</p>',
	},
	P4: {
		before: '<span data-pin-do="buttonBookmark" data-pin-config="above">Pin it</span>\n<p>Article</p>',
		after: '<p>Article</p>',
	},
	P5: {
		before: '<p>Ligne 1<br><br><br><br>Ligne 2</p>',
		after: '<p>Ligne 1</p>\n<p>Ligne 2</p>',
	},
	P6: {
		before: '<p style="color:red; text-align:center; font-size:16px;">Texte centré rouge</p>',
		after: '<p style="text-align:center;">Texte centré rouge</p>',
	},
	P7: {
		before: '<p>- Premier item<br>- Deuxième item<br>- Troisième item</p>',
		after: '<ul>\n  <li>Premier item</li>\n  <li>Deuxième item</li>\n  <li>Troisième item</li>\n</ul>',
	},
	P8: {
		before: '<span style="font-weight:bold; color:red;">Important</span> et <span style="font-style:italic;">accent</span>',
		after: '<strong style="color:red;">Important</strong> et <em>accent</em>',
	},
	P9: {
		before: '<h2><img src="/photo.jpg" alt="Photo de Une"></h2>\n<h2>Vrai titre</h2>',
		after: '<img src="/photo.jpg" alt="Photo de Une">\n<h2>Vrai titre</h2>',
	},
};

/**
 * @return {JSX.Element} Vue Règles complète.
 */
export default function Rules() {
	const { presets, isLoading, isSaving, error, save } = usePresets();
	const selectedRules = useSelect(
		( select ) => select( STORE_NAME ).getSelectedRules(),
		[]
	);
	const { toggleSelectedRule, selectAllRules, deselectAllRules } =
		useDispatch( STORE_NAME );

	const handleToggleEnabled = useCallback(
		( id, checked ) => save( id, { enabled: checked } ).catch( () => {} ),
		[ save ]
	);

	const handleSaveParams = useCallback(
		( id, partial ) => save( id, { params: partial } ).catch( () => {} ),
		[ save ]
	);

	if ( isLoading && null === presets ) {
		return (
			<div className="htmln-rules htmln-rules--loading">
				<Spinner />{ ' ' }
				{ __( 'Chargement des règles…', '100son-html-normalizer' ) }
			</div>
		);
	}

	if ( error && null === presets ) {
		return (
			<div className="htmln-rules">
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Impossible de charger les règles : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			</div>
		);
	}

	const selectedCount = selectedRules.length;
	const totalCount = ALL_RULE_IDS.length;

	return (
		<div className="htmln-rules">
			<header className="htmln-rules__header">
				<h2>{ __( 'Règles', '100son-html-normalizer' ) }</h2>
				<p className="description">
					{ __(
						'Les 9 préréglages du pipeline de normalisation. Pour chaque règle : la case « Sélectionnée » ne s’applique qu’au prochain pas et redevient cochée par défaut au rechargement de la page. Le toggle « Activée par défaut » et les paramètres sont persistés en base et partagés avec la page « Préréglages » (V0.1).',
						'100son-html-normalizer'
					) }
				</p>
			</header>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						// translators: %s = message d'erreur technique.
						__(
							'Échec de l’enregistrement : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			) }

			<div className="htmln-rules__toolbar">
				<span className="htmln-rules__count">
					{ sprintf(
						// translators: 1 = sélectionnées, 2 = total.
						__(
							'%1$d / %2$d règles sélectionnées pour le prochain pas',
							'100son-html-normalizer'
						),
						selectedCount,
						totalCount
					) }
				</span>
				<div className="htmln-rules__toolbar-actions">
					<Button
						variant="secondary"
						onClick={ selectAllRules }
						disabled={ selectedCount === totalCount }
					>
						{ __( 'Tout cocher', '100son-html-normalizer' ) }
					</Button>{ ' ' }
					<Button
						variant="secondary"
						onClick={ deselectAllRules }
						disabled={ selectedCount === 0 }
					>
						{ __( 'Tout décocher', '100son-html-normalizer' ) }
					</Button>
				</div>
			</div>

			<div className="htmln-rules__list">
				{ /* Tri d'affichage : pas l'ordre du pipeline (P3 → P4 → … → P2)
				 *   mais l'ordre lisible humain où les règles d'une même
				 *   famille sont contiguës (P1, P2.1, P2.2, P3, …). Cf.
				 *   `utils/ruleLabels.RULE_DISPLAY_ORDER`. */ }
				{ [ ...( presets ?? [] ) ]
					.sort( ( a, b ) =>
						compareRuleIdsByDisplayOrder( a.id, b.id )
					)
					.map( ( preset ) => (
						<RuleCard
							key={ preset.id }
							preset={ preset }
							isSelected={ selectedRules.includes( preset.id ) }
							isSaving={ isSaving }
							onToggleSelected={ () =>
								toggleSelectedRule( preset.id )
							}
							onToggleEnabled={ ( checked ) =>
								handleToggleEnabled( preset.id, checked )
							}
							onSaveParams={ ( partial ) =>
								handleSaveParams( preset.id, partial )
							}
						/>
					) ) }
			</div>
		</div>
	);
}

/**
 * Carte d'une règle individuelle.
 *
 * @param {Object}                     props
 * @param {Object}                     props.preset           Entrée PresetEntry (cf. usePresets).
 * @param {boolean}                    props.isSelected       Cochée pour le prochain pas ?
 * @param {boolean}                    props.isSaving         Vrai pendant un POST.
 * @param {() => void}                 props.onToggleSelected Bascule la sélection éphémère.
 * @param {(checked: boolean) => void} props.onToggleEnabled  Toggle enabled.
 * @param {(partial: Object) => void}  props.onSaveParams     Save params partiels.
 * @return {JSX.Element} Carte.
 */
function RuleCard( {
	preset,
	isSelected,
	isSaving,
	onToggleSelected,
	onToggleEnabled,
	onSaveParams,
} ) {
	const example = RULE_EXAMPLES[ preset.id ] ?? null;
	return (
		<article
			className={ `htmln-rule${
				preset.enabled ? '' : ' htmln-rule--disabled'
			}` }
		>
			<header className="htmln-rule__header">
				<div className="htmln-rule__title">
					<code className="htmln-rule__code" title={ preset.id }>
						{ getRuleLabel( preset.id ) }
					</code>
					<h3
						className="htmln-rule__label"
						/* eslint-disable-next-line react/no-danger -- HTML serveur de confiance depuis PresetRegistry. */
						dangerouslySetInnerHTML={ {
							__html: preset.label,
						} }
					/>
				</div>
				<div className="htmln-rule__actions">
					<CheckboxControl
						label={ __(
							'Sélectionnée pour le prochain pas',
							'100son-html-normalizer'
						) }
						checked={ isSelected }
						onChange={ onToggleSelected }
						__nextHasNoMarginBottom
					/>
					<ToggleControl
						label={ __(
							'Activée par défaut',
							'100son-html-normalizer'
						) }
						checked={ preset.enabled }
						onChange={ onToggleEnabled }
						disabled={ isSaving }
						__nextHasNoMarginBottom
					/>
				</div>
			</header>

			<div
				className="htmln-rule__description"
				/* eslint-disable-next-line react/no-danger -- HTML serveur de confiance depuis PresetRegistry. */
				dangerouslySetInnerHTML={ {
					__html: preset.description,
				} }
			/>

			{ preset.has_options && (
				<RuleParams
					preset={ preset }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			) }

			{ example && <RuleExample example={ example } /> }
		</article>
	);
}

/**
 * Champs de paramètres spécifiques à chaque règle. Switch sur
 * `preset.id` — chaque cas connaît son schéma local.
 *
 * @param {Object}                    props
 * @param {Object}                    props.preset       PresetEntry complet.
 * @param {boolean}                   props.isSaving     Désactive les champs durant le save.
 * @param {(partial: Object) => void} props.onSaveParams Save partiel.
 * @return {JSX.Element|null} Bloc de champs ou null si pas de paramètre.
 */
function RuleParams( { preset, isSaving, onSaveParams } ) {
	switch ( preset.id ) {
		case 'P5':
			return (
				<P5Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'P6':
			return (
				<P6Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'P7':
			return (
				<P7Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'P8':
			return (
				<P8Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		default:
			return null;
	}
}

/**
 * P5 — un seul paramètre : `threshold` (int 2..20).
 *
 * @param {Object}              props
 * @param {{threshold: number}} props.params
 * @param {boolean}             props.isSaving
 * @param {(p: Object) => void} props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function P5Params( { params, isSaving, onSaveParams } ) {
	const [ value, setValue ] = useState( String( params.threshold ?? 2 ) );
	const isValid = /^\d+$/.test( value.trim() );
	const parsed = isValid ? parseInt( value, 10 ) : null;
	const inRange = null !== parsed && parsed >= 2 && parsed <= 20;
	return (
		<fieldset className="htmln-rule__params">
			<legend>{ __( 'Paramètres', '100son-html-normalizer' ) }</legend>
			<TextControl
				label={ __( 'Seuil (≥ 2)', '100son-html-normalizer' ) }
				value={ value }
				type="number"
				min={ 2 }
				max={ 20 }
				step={ 1 }
				onChange={ setValue }
				disabled={ isSaving }
				help={ __(
					'Nombre minimal de <br> consécutifs pour déclencher la coupure en <p>. Défaut : 2.',
					'100son-html-normalizer'
				) }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ () => onSaveParams( { threshold: parsed } ) }
				disabled={
					isSaving || ! inRange || parsed === params.threshold
				}
			>
				{ __( 'Enregistrer le seuil', '100son-html-normalizer' ) }
			</Button>
		</fieldset>
	);
}

/**
 * P6 — un seul paramètre booléen : `keep_text_align`.
 *
 * @param {Object}                     props
 * @param {{keep_text_align: boolean}} props.params
 * @param {boolean}                    props.isSaving
 * @param {(p: Object) => void}        props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function P6Params( { params, isSaving, onSaveParams } ) {
	return (
		<fieldset className="htmln-rule__params">
			<legend>{ __( 'Paramètres', '100son-html-normalizer' ) }</legend>
			<ToggleControl
				label={ __(
					'Conserver text-align (sinon strip total)',
					'100son-html-normalizer'
				) }
				checked={ Boolean( params.keep_text_align ) }
				onChange={ ( checked ) =>
					onSaveParams( { keep_text_align: checked } )
				}
				disabled={ isSaving }
				__nextHasNoMarginBottom
			/>
		</fieldset>
	);
}

/**
 * P7 — seuil + 5 marqueurs + custom_markers (textarea).
 *
 * @param {Object}                                                         props
 * @param {{threshold: number, markers: Object, custom_markers: string[]}} props.params
 * @param {boolean}                                                        props.isSaving
 * @param {(p: Object) => void}                                            props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function P7Params( { params, isSaving, onSaveParams } ) {
	const [ threshold, setThreshold ] = useState(
		String( params.threshold ?? 2 )
	);
	const [ customRaw, setCustomRaw ] = useState(
		( params.custom_markers ?? [] ).join( '\n' )
	);
	const isThresholdValid =
		/^\d+$/.test( threshold.trim() ) &&
		parseInt( threshold, 10 ) >= 2 &&
		parseInt( threshold, 10 ) <= 20;

	const MARKER_LABELS = {
		dash: __( '- (tiret ASCII)', '100son-html-normalizer' ),
		emdash: __( '– (cadratin)', '100son-html-normalizer' ),
		asterix: __( '* (astérisque)', '100son-html-normalizer' ),
		bullet: __( '• (puce)', '100son-html-normalizer' ),
		numeric: __( '1. 2. 3. (numéros → <ol>)', '100son-html-normalizer' ),
	};

	return (
		<fieldset className="htmln-rule__params">
			<legend>{ __( 'Paramètres', '100son-html-normalizer' ) }</legend>

			<TextControl
				label={ __( 'Seuil (≥ 2)', '100son-html-normalizer' ) }
				value={ threshold }
				type="number"
				min={ 2 }
				max={ 20 }
				step={ 1 }
				onChange={ setThreshold }
				disabled={ isSaving }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ () =>
					onSaveParams( {
						threshold: parseInt( threshold, 10 ),
					} )
				}
				disabled={
					isSaving ||
					! isThresholdValid ||
					parseInt( threshold, 10 ) === params.threshold
				}
			>
				{ __( 'Enregistrer le seuil', '100son-html-normalizer' ) }
			</Button>

			<p className="htmln-rule__params-label">
				{ __( 'Marqueurs activés :', '100son-html-normalizer' ) }
			</p>
			{ Object.entries( MARKER_LABELS ).map( ( [ key, label ] ) => (
				<CheckboxControl
					key={ key }
					label={ label }
					checked={ Boolean( params.markers?.[ key ] ) }
					onChange={ ( checked ) =>
						onSaveParams( {
							markers: {
								...params.markers,
								[ key ]: checked,
							},
						} )
					}
					disabled={ isSaving }
					__nextHasNoMarginBottom
				/>
			) ) }

			<TextareaControl
				label={ __(
					'Marqueurs custom (1 par ligne)',
					'100son-html-normalizer'
				) }
				value={ customRaw }
				onChange={ setCustomRaw }
				disabled={ isSaving }
				help={ __(
					'Ex. ▸ ou ► — un par ligne. Toujours produit <ul>.',
					'100son-html-normalizer'
				) }
				rows={ 3 }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="secondary"
				onClick={ () =>
					onSaveParams( {
						custom_markers: customRaw
							.split( '\n' )
							.map( ( s ) => s.trim() )
							.filter( ( s ) => '' !== s ),
					} )
				}
				disabled={ isSaving }
			>
				{ __(
					'Enregistrer les marqueurs custom',
					'100son-html-normalizer'
				) }
			</Button>
		</fieldset>
	);
}

/**
 * P8 — 2 toggles : `mappings.bold`, `mappings.italic`.
 *
 * @param {Object}                                       props
 * @param {{mappings: {bold: boolean, italic: boolean}}} props.params
 * @param {boolean}                                      props.isSaving
 * @param {(p: Object) => void}                          props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function P8Params( { params, isSaving, onSaveParams } ) {
	const mappings = params.mappings ?? { bold: true, italic: true };
	return (
		<fieldset className="htmln-rule__params">
			<legend>
				{ __(
					'Mappings sémantiques activés',
					'100son-html-normalizer'
				) }
			</legend>
			<ToggleControl
				label={ __(
					'font-weight: bold (ou ≥ 700) → <strong>',
					'100son-html-normalizer'
				) }
				checked={ Boolean( mappings.bold ) }
				onChange={ ( checked ) =>
					onSaveParams( {
						mappings: { ...mappings, bold: checked },
					} )
				}
				disabled={ isSaving }
				__nextHasNoMarginBottom
			/>
			<ToggleControl
				label={ __(
					'font-style: italic → <em>',
					'100son-html-normalizer'
				) }
				checked={ Boolean( mappings.italic ) }
				onChange={ ( checked ) =>
					onSaveParams( {
						mappings: { ...mappings, italic: checked },
					} )
				}
				disabled={ isSaving }
				__nextHasNoMarginBottom
			/>
		</fieldset>
	);
}

/**
 * Encart d'exemple « Avant / Après ».
 *
 * @param {Object}                          props
 * @param {{before: string, after: string}} props.example
 * @return {JSX.Element} Bloc.
 */
function RuleExample( { example } ) {
	return (
		<div className="htmln-rule__example">
			<div className="htmln-rule__example-col">
				<h4>{ __( 'Avant', '100son-html-normalizer' ) }</h4>
				<pre>
					<code>{ example.before }</code>
				</pre>
			</div>
			<div className="htmln-rule__example-col">
				<h4>{ __( 'Après', '100son-html-normalizer' ) }</h4>
				<pre>
					<code>{ example.after }</code>
				</pre>
			</div>
		</div>
	);
}
