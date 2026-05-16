/**
 * Rules — vue racine de l'onglet « Règles » (post-rc1).
 *
 * Liste les 16 règles R1-R16 avec, pour chacune :
 *  - un **toggle « Activée »** (BDD, persisté via REST). Depuis 2026-05-16,
 *    ce toggle est le **seul** contrôle : il pilote à la fois l'évaluation
 *    par le scan ET l'application au prochain « Appliquer ce lot ». La
 *    case « Dans le lot » + son localStorage ont été supprimés.
 *  - la **description** rendue depuis PresetRegistry (HTML serveur de
 *    confiance, `dangerouslySetInnerHTML` autorisé) ;
 *  - les **paramètres modifiables** spécifiques à la règle (R5 seuil,
 *    R6 keep_text_align, R7 seuil + 5 marqueurs + custom_markers,
 *    R8 mappings bold/italic) ;
 *  - un **encart Avant / Après** sur un fragment HTML factice statique
 *    qui illustre l'effet de la règle.
 *
 * Bulk « Tout activer / Tout désactiver » : ces deux boutons pilotent
 * désormais le toggle « Activée » de toutes les règles en parallèle
 * (`Promise.all(presets.map(p => save(p.id, { enabled })))`).
 *
 * Persistance unique : `enabled` + paramètres → option WP
 * `son100_htmln_presets` via `POST /presets/<id>`.
 */

import { __, sprintf } from '@wordpress/i18n';
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
import { ALL_RULE_IDS } from '../store';
import { usePresets } from '../hooks/usePresets';
import {
	getRuleLabel,
	compareRuleIdsByDisplayOrder,
} from '../utils/ruleLabels';
import { formatLocalDateTime } from '../utils/datetime';

/**
 * Exemples statiques par règle pour l'encart « Avant / Après ».
 * Sources : `PLUGIN_CONTEXT.md` §6 + descriptions des règles.
 *
 * @type {Object<string, {before: string, after: string}>}
 */
const RULE_EXAMPLES = {
	R1: {
		before: '<p>Texte utile</p>\n<p>&nbsp;</p>\n<p>Suite</p>',
		after: '<p>Texte utile</p>\n<p>Suite</p>',
	},
	R2: {
		before: '<h2>Vraie section</h2>\n<h3>&nbsp;</h3>\n<p>Contenu</p>',
		after: '<h2>Vraie section</h2>\n<p>Contenu</p>',
	},
	R3: {
		before: '<p>Article</p>\n[shareaholic id="123-abc"]\n<p>Suite</p>',
		after: '<p>Article</p>\n<p>Suite</p>',
	},
	R4: {
		before: '<span data-pin-do="buttonBookmark" data-pin-config="above">Pin it</span>\n<p>Article</p>',
		after: '<p>Article</p>',
	},
	R5: {
		before: '<p>Ligne 1<br><br><br><br>Ligne 2</p>',
		after: '<p>Ligne 1</p>\n<p>Ligne 2</p>',
	},
	R6: {
		before: '<p style="color:red; text-align:center; font-size:16px;">Texte centré rouge</p>',
		after: '<p style="text-align:center;">Texte centré rouge</p>',
	},
	R7: {
		before: '<p>- Premier item<br>- Deuxième item<br>- Troisième item</p>',
		after: '<ul>\n  <li>Premier item</li>\n  <li>Deuxième item</li>\n  <li>Troisième item</li>\n</ul>',
	},
	R8: {
		before: '<span style="font-weight:bold; color:red;">Important</span> et <span style="font-style:italic;">accent</span>',
		after: '<strong style="color:red;">Important</strong> et <em>accent</em>',
	},
	R9: {
		before: '<h2><img src="/photo.jpg" alt="Photo de Une"></h2>\n<h2>Vrai titre</h2>',
		after: '<img src="/photo.jpg" alt="Photo de Une">\n<h2>Vrai titre</h2>',
	},
	R10: {
		before: '<p><img src="/photo.jpg" alt="Photo"></p>\n<p>Texte normal.</p>',
		after: '<img src="/photo.jpg" alt="Photo">\n<p>Texte normal.</p>',
	},
	R11: {
		before: '<!-- Cas 1 : h4 après image-p → figure -->\n<p><a href="big.jpg"><img src="thumb.jpg" alt=""></a></p>\n<h4>Légende.</h4>\n\n<!-- Cas 2 : h4 orphelin juste après chapô → crédit -->\n<p class="chapo">Chapô seul.</p>\n<h4>Cyrille Martin</h4>\n\n<!-- Cas 3 : h4 orphelin ailleurs → p gras -->\n<p>Corps.</p>\n<h4>Sous-titre détourné</h4>',
		after: '<!-- Cas 1 -->\n<figure>\n  <a href="big.jpg"><img src="thumb.jpg" alt=""></a>\n  <figcaption>Légende.</figcaption>\n</figure>\n\n<!-- Cas 2 -->\n<p class="chapo">Chapô seul.</p>\n<p class="chapo">Cyrille Martin</p>\n\n<!-- Cas 3 -->\n<p>Corps.</p>\n<p><strong>Sous-titre détourné</strong></p>',
	},
	R12: {
		before: '<h4><a href="big.jpg"><img src="thumb.jpg" alt=""></a> Texte de légende.</h4>',
		after: '<figure>\n  <a href="big.jpg"><img src="thumb.jpg" alt=""></a>\n  <figcaption>Texte de légende.</figcaption>\n</figure>',
	},
	R13: {
		before: "<h2>Première phrase du chapô. Deuxième phrase qui complète.</h2>\n<p>Texte de l'article.</p>",
		after: '<p class="chapo">Première phrase du chapô. Deuxième phrase qui complète.</p>\n<p>Texte de l\'article.</p>',
	},
	R14: {
		before: "<p>Une famille s'est lancée dans la rénovation écologique de sa maison.</p>\n<p>LA RÉDACTION</p>\n<p>PHOTOS Cyrille Martin</p>\n<p>Premier paragraphe du corps.</p>",
		after: '<p class="chapo">Une famille s\'est lancée dans la rénovation écologique de sa maison.</p>\n<p class="chapo">LA RÉDACTION</p>\n<p class="chapo">PHOTOS Cyrille Martin</p>\n<p>Premier paragraphe du corps.</p>',
	},
	R15: {
		before: '<p><em>Première moitié</em> <em>deuxième moitié</em> du texte.</p>\n<p><span style="font-size:14pt">A</span><span style="font-size:14pt">B</span></p>',
		after: '<p><em>Première moitié deuxième moitié</em> du texte.</p>\n<p><span style="font-size:14pt">AB</span></p>',
	},
	R16: {
		before: '<h2>1. Pourquoi bioclimatique ?</h2>\n<h2>• Spécialiste de la terrasse</h2>\n<h3>— Sous-titre</h3>',
		after: '<h2>Pourquoi bioclimatique ?</h2>\n<h2>Spécialiste de la terrasse</h2>\n<h3>Sous-titre</h3>',
	},
};

/**
 * @return {JSX.Element} Vue Règles complète.
 */
export default function Rules() {
	const { presets, isLoading, isSaving, error, save } = usePresets();

	const handleToggleEnabled = useCallback(
		( id, checked ) => save( id, { enabled: checked } ).catch( () => {} ),
		[ save ]
	);

	const handleSaveParams = useCallback(
		( id, partial ) => save( id, { params: partial } ).catch( () => {} ),
		[ save ]
	);

	// Bulk activer/désactiver — post-2026-05-16 : ces boutons pilotent
	// désormais le toggle « Activée » (et plus la case « Dans le lot »
	// supprimée). N appels REST `POST /presets/<id>` en parallèle via
	// `Promise.all`, un par règle. Acceptable pour 16 règles ; si la
	// latence devient gênante, un endpoint bulk `POST /presets/bulk-toggle`
	// pourrait être ajouté côté backend (cf. discussion design).
	//
	// Cas particulier « Tout activer » : on saute les règles auto-désactivées
	// par le backend après scan complet (corpus épuisé). Pour les ressusciter,
	// l'utilisateur doit passer par « Réactiver pour cette session » dans la
	// carte de la règle — un bulk activate ne doit pas court-circuiter ce
	// garde-fou et relancer du travail déjà accompli.
	const handleBulkSetEnabled = useCallback(
		( targetEnabled ) => {
			if ( ! Array.isArray( presets ) ) {
				return;
			}
			Promise.all(
				presets
					.filter( ( p ) => Boolean( p.enabled ) !== targetEnabled )
					.filter(
						( p ) =>
							! (
								targetEnabled &&
								'complete' === p.completion_state &&
								p.auto_disabled_at
							)
					)
					.map( ( p ) =>
						save( p.id, { enabled: targetEnabled } ).catch(
							() => {}
						)
					)
			);
		},
		[ presets, save ]
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

	const presetsList = Array.isArray( presets ) ? presets : [];
	const enabledCount = presetsList.filter( ( p ) =>
		Boolean( p.enabled )
	).length;
	const totalCount = presetsList.length || ALL_RULE_IDS.length;
	// Règles que « Tout activer » ne ressuscite plus : auto-désactivées
	// par le backend après scan complet (corpus épuisé). Compteur utilisé
	// uniquement pour désactiver le bouton « Tout activer » dès qu'il n'a
	// plus rien à faire — réactivation explicite uniquement.
	const autoDisabledCount = presetsList.filter(
		( p ) =>
			! p.enabled &&
			'complete' === p.completion_state &&
			p.auto_disabled_at
	).length;

	return (
		<div className="htmln-rules">
			<header className="htmln-rules__header">
				<h2>{ __( 'Règles', '100son-html-normalizer' ) }</h2>
				<p className="description">
					{ __(
						"Les 16 règles du pipeline de normalisation. Le toggle « Activée » détermine si la règle est évaluée par le scan ET appliquée au prochain « Appliquer ce lot ». Les paramètres et l'état activé sont persistés en base.",
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
						// translators: 1 = règles activées, 2 = total.
						__(
							'%1$d / %2$d règles activées',
							'100son-html-normalizer'
						),
						enabledCount,
						totalCount
					) }
				</span>
				<div className="htmln-rules__toolbar-actions">
					<Button
						variant="secondary"
						onClick={ () => handleBulkSetEnabled( true ) }
						disabled={
							isSaving ||
							enabledCount + autoDisabledCount === totalCount
						}
					>
						{ __( 'Tout activer', '100son-html-normalizer' ) }
					</Button>{ ' ' }
					<Button
						variant="secondary"
						onClick={ () => handleBulkSetEnabled( false ) }
						disabled={ isSaving || enabledCount === 0 }
					>
						{ __( 'Tout désactiver', '100son-html-normalizer' ) }
					</Button>
				</div>
			</div>

			<div className="htmln-rules__list">
				{ /* Tri d'affichage : pas l'ordre du pipeline
				 *   (`R3 → R4 → R8 → R13 → R14 → R6 → R7 → R5 → R9 → R12 → R11 → R10 → R1 → R2`)
				 *   mais l'ordre naturel R1..R14. Cf.
				 *   `utils/ruleLabels.RULE_DISPLAY_ORDER`. */ }
				{ [ ...( presets ?? [] ) ]
					.sort( ( a, b ) =>
						compareRuleIdsByDisplayOrder( a.id, b.id )
					)
					.map( ( preset ) => (
						<RuleCard
							key={ preset.id }
							preset={ preset }
							isSaving={ isSaving }
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
 * @param {Object}                     props.preset          Entrée PresetEntry (cf. usePresets).
 * @param {boolean}                    props.isSaving        Vrai pendant un POST.
 * @param {(checked: boolean) => void} props.onToggleEnabled Toggle enabled (pilote scan + apply lot).
 * @param {(partial: Object) => void}  props.onSaveParams    Save params partiels.
 * @return {JSX.Element} Carte.
 */
function RuleCard( { preset, isSaving, onToggleEnabled, onSaveParams } ) {
	const example = RULE_EXAMPLES[ preset.id ] ?? null;

	// État de complétion (cf. PresetsController::preset_to_array) :
	//  - 'pending'  : il reste des articles à traiter pour cette règle
	//  - 'complete' : règle appliquée à tout le corpus (verrouillée)
	//  - 'unused'   : aucun article ne nécessite cette règle, et elle
	//                 n'a jamais été appliquée
	const completionState = preset.completion_state ?? 'pending';

	// Override per-session : permet de re-tourner une règle « complete »
	// pour retester après modification de la règle. État UNIQUEMENT
	// frontend, perdu au reload.
	const [ overrideLock, setOverrideLock ] = useState( false );

	// Quand la règle a été auto-désactivée à la fin d'un scan, on
	// considère que le verrou « complete » est déjà résolu côté
	// serveur (la règle est `enabled=false`). Le toggle reste directement
	// modifiable pour permettre une réactivation manuelle sans passer
	// par l'override de session.
	const isAutoDisabled =
		'complete' === completionState &&
		preset.auto_disabled_at &&
		! preset.enabled;
	const isLocked =
		'complete' === completionState && ! overrideLock && ! isAutoDisabled;
	const lockClass = isLocked ? ' htmln-rule--locked' : '';
	const unusedClass =
		'unused' === completionState ? ' htmln-rule--unused' : '';

	return (
		<article
			className={ `htmln-rule${
				preset.enabled ? '' : ' htmln-rule--disabled'
			}${ lockClass }${ unusedClass }` }
			data-rule-id={ preset.id }
			data-completion-state={ completionState }
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
					<ToggleControl
						label={ __( 'Activée', '100son-html-normalizer' ) }
						checked={ preset.enabled }
						onChange={ onToggleEnabled }
						disabled={ isSaving || isLocked }
						__nextHasNoMarginBottom
					/>
				</div>
			</header>

			<RuleCompletionBanner
				state={ completionState }
				lastAppliedAt={ preset.last_applied_at }
				autoDisabledAt={ preset.auto_disabled_at }
				overrideActive={ overrideLock }
				onToggleOverride={ () => setOverrideLock( ( v ) => ! v ) }
			/>

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
 * Bandeau d'état d'application affiché entre le header et la description
 * de chaque card. Trois variantes selon `completion_state` :
 *
 *  - **complete** : règle appliquée à tout le corpus. Badge vert ✓ +
 *    date de dernière application + bouton « Réactiver pour ce corpus »
 *    qui débloque les contrôles uniquement pour la session courante
 *    (état frontend, perdu au reload).
 *  - **unused** : règle jamais utile sur ce corpus. Badge gris discret,
 *    pas de verrou.
 *  - **pending** : règle a encore du travail. N affiche pas de bandeau
 *    (card normale).
 *
 * @param {Object}     props
 * @param {string}     props.state            État (`pending`, `complete`, `unused`).
 * @param {?string}    props.lastAppliedAt    Datetime MySQL ou null.
 * @param {?string}    props.autoDisabledAt   Datetime MySQL si la règle a été auto-désactivée à la fin d'un scan complet — modifie le libellé du bandeau `complete`.
 * @param {boolean}    props.overrideActive   L'override est-il actif (vrai = verrou levé).
 * @param {() => void} props.onToggleOverride Bascule l'override.
 * @return {JSX.Element|null} Bandeau ou null si rien à afficher.
 */
function RuleCompletionBanner( {
	state,
	lastAppliedAt,
	autoDisabledAt = null,
	overrideActive,
	onToggleOverride,
} ) {
	if ( 'pending' === state ) {
		return null;
	}

	if ( 'unused' === state ) {
		return (
			<p className="htmln-rule__completion htmln-rule__completion--unused">
				<span
					className="htmln-rule__completion-icon"
					aria-hidden="true"
				>
					{ '○' }
				</span>{ ' ' }
				{ __(
					'Aucun article ne nécessite cette règle.',
					'100son-html-normalizer'
				) }
			</p>
		);
	}

	// 'complete'
	const formattedDate = lastAppliedAt
		? formatLocalDateTime( lastAppliedAt, { fallback: '' } )
		: '';

	const completionMessage = autoDisabledAt
		? sprintf(
				// translators: %s = date de la dernière application.
				__(
					'Appliquée à tout le corpus le %s. Désactivée automatiquement.',
					'100son-html-normalizer'
				),
				formattedDate
		  )
		: sprintf(
				// translators: %s = date de la dernière application.
				__(
					'Appliquée à tout le corpus le %s.',
					'100son-html-normalizer'
				),
				formattedDate
		  );

	return (
		<p className="htmln-rule__completion htmln-rule__completion--complete">
			<span className="htmln-rule__completion-icon" aria-hidden="true">
				{ '✓' }
			</span>{ ' ' }
			{ completionMessage }{ ' ' }
			<Button
				variant="link"
				onClick={ onToggleOverride }
				className="htmln-rule__completion-override"
			>
				{ overrideActive
					? __( 'Re-verrouiller', '100son-html-normalizer' )
					: __(
							'Réactiver pour cette session',
							'100son-html-normalizer'
					  ) }
			</Button>
		</p>
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
		case 'R5':
			return (
				<R5Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'R6':
			return (
				<R6Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'R7':
			return (
				<R7Params
					params={ preset.params }
					isSaving={ isSaving }
					onSaveParams={ onSaveParams }
				/>
			);
		case 'R8':
			return (
				<R8Params
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
 * R5 — un seul paramètre : `threshold` (int 2..20).
 *
 * @param {Object}              props
 * @param {{threshold: number}} props.params
 * @param {boolean}             props.isSaving
 * @param {(p: Object) => void} props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function R5Params( { params, isSaving, onSaveParams } ) {
	const [ value, setValue ] = useState( String( params.threshold ?? 2 ) );
	const isValid = /^\d+$/.test( value.trim() );
	const parsed = isValid ? parseInt( value, 10 ) : null;
	const inRange = null !== parsed && parsed >= 2 && parsed <= 20;
	return (
		<fieldset className="htmln-rule__params">
			<legend>{ __( 'Paramètres', '100son-html-normalizer' ) }</legend>
			<div className="htmln-rule__threshold-row">
				<TextControl
					label={ __( 'Seuil (≥ 2)', '100son-html-normalizer' ) }
					value={ value }
					type="number"
					min={ 2 }
					max={ 20 }
					step={ 1 }
					onChange={ setValue }
					disabled={ isSaving }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				<p className="htmln-rule__threshold-help">
					{ __(
						'Nombre minimal de <br> consécutifs pour déclencher la coupure en <p>. Défaut : 2.',
						'100son-html-normalizer'
					) }
				</p>
			</div>
			<Button
				variant="secondary"
				onClick={ () => onSaveParams( { threshold: parsed } ) }
				disabled={
					isSaving || ! inRange || parsed === params.threshold
				}
			>
				{ __( 'Enregistrer', '100son-html-normalizer' ) }
			</Button>
		</fieldset>
	);
}

/**
 * R6 — un seul paramètre booléen : `keep_text_align`.
 *
 * @param {Object}                     props
 * @param {{keep_text_align: boolean}} props.params
 * @param {boolean}                    props.isSaving
 * @param {(p: Object) => void}        props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function R6Params( { params, isSaving, onSaveParams } ) {
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
 * R7 — seuil + 5 marqueurs + custom_markers (textarea).
 *
 * @param {Object}                                                         props
 * @param {{threshold: number, markers: Object, custom_markers: string[]}} props.params
 * @param {boolean}                                                        props.isSaving
 * @param {(p: Object) => void}                                            props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function R7Params( { params, isSaving, onSaveParams } ) {
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

			<div className="htmln-rule__threshold-row">
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
				<p className="htmln-rule__threshold-help">
					{ __(
						'Nombre minimal de marqueurs consécutifs pour déclencher la conversion en liste. Défaut : 2.',
						'100son-html-normalizer'
					) }
				</p>
			</div>
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
				{ __( 'Enregistrer', '100son-html-normalizer' ) }
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
 * R8 — 2 toggles : `mappings.bold`, `mappings.italic`.
 *
 * @param {Object}                                       props
 * @param {{mappings: {bold: boolean, italic: boolean}}} props.params
 * @param {boolean}                                      props.isSaving
 * @param {(p: Object) => void}                          props.onSaveParams
 * @return {JSX.Element} Bloc.
 */
function R8Params( { params, isSaving, onSaveParams } ) {
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
