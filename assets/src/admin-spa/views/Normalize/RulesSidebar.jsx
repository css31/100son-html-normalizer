/**
 * RulesSidebar — panneau latéral de sélection des règles à appliquer (F14.2).
 *
 * V1.0 : 8 préréglages P1-P8 hardcodés (pas de règles utilisateur en V1.0,
 * cf. §11.10 différé V1.1). Toutes cochées par défaut — l'admin décoche
 * celles qu'il ne veut pas appliquer pour le pas en cours.
 *
 * Le bouton « Appliquer ce pas » est désactivé si :
 *  - aucune règle cochée (rien à appliquer) ;
 *  - aucun article sélectionné dans le tableau ;
 *  - un pas est déjà en cours (`disabled`).
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button, CheckboxControl } from '@wordpress/components';

/**
 * Liste des 8 préréglages V1.0. Source de vérité : `PresetRegistry::PRESETS`
 * côté PHP. À synchroniser si l'ordre canonique change (cf. cahier §4.4).
 *
 * @return {Array<{id: string, label: string, description: string}>} Préréglages.
 */
function getPresets() {
	return [
		{
			id: 'P1',
			label: __( 'P1 — Paragraphes vides', '100son-html-normalizer' ),
			description: __(
				'Supprime les <p> vides ou contenant uniquement des espaces.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P2',
			label: __( 'P2 — Titres vides', '100son-html-normalizer' ),
			description: __(
				'Supprime les <h1>…<h6> vides.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P3',
			label: __( 'P3 — Shortcode Shareaholic', '100son-html-normalizer' ),
			description: __(
				'Supprime les shortcodes [shareaholic …] orphelins.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P4',
			label: __( 'P4 — Artefacts Pinterest', '100son-html-normalizer' ),
			description: __(
				'Nettoie les artefacts Pinterest résiduels.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P5',
			label: __( 'P5 — Cascade de <br>', '100son-html-normalizer' ),
			description: __(
				'Réduit les rafales <br><br><br> à un seul <br>.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P6',
			label: __( 'P6 — Styles en ligne', '100son-html-normalizer' ),
			description: __(
				'Supprime tout style="…" inline (option pour préserver les alignements).',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P7',
			label: __( 'P7 — Listes ASCII', '100son-html-normalizer' ),
			description: __(
				'Convertit "* item" / "- item" en <ul><li>.',
				'100son-html-normalizer'
			),
		},
		{
			id: 'P8',
			label: __(
				'P8 — Récupération sémantique',
				'100son-html-normalizer'
			),
			description: __(
				'<span style="font-weight:bold"> → <strong>, etc.',
				'100son-html-normalizer'
			),
		},
	];
}

/**
 * @param {Object}                  props
 * @param {string[]}                props.selectedRules   Règles cochées (rule_id).
 * @param {(ids: string[]) => void} props.onChangeRules   Callback de mise à jour.
 * @param {number[]}                props.selectedPostIds Articles cochés dans le tableau.
 * @param {boolean}                 props.disabled        Désactive entièrement la sidebar (pas en cours).
 * @param {() => void}              props.onApplyStep     Callback bouton « Appliquer ce pas ».
 * @return {JSX.Element} Sidebar.
 */
export default function RulesSidebar( {
	selectedRules,
	onChangeRules,
	selectedPostIds,
	disabled,
	onApplyStep,
} ) {
	const presets = getPresets();
	const selectedSet = new Set( selectedRules );

	const toggleRule = ( id, checked ) => {
		if ( checked ) {
			onChangeRules( [ ...selectedRules, id ] );
		} else {
			onChangeRules( selectedRules.filter( ( r ) => r !== id ) );
		}
	};

	const allChecked = selectedSet.size === presets.length;
	const noneChecked = 0 === selectedSet.size;

	const toggleAll = () => {
		onChangeRules( allChecked ? [] : presets.map( ( p ) => p.id ) );
	};

	const canApply = ! disabled && ! noneChecked && selectedPostIds.length > 0;

	return (
		<aside
			className="htmln-rules-sidebar"
			aria-label={ __( 'Règles à appliquer', '100son-html-normalizer' ) }
		>
			<h2 className="htmln-rules-sidebar__title">
				{ __( 'Règles à appliquer', '100son-html-normalizer' ) }
			</h2>

			<p className="htmln-rules-sidebar__hint">
				{ __(
					'Cochez les règles à inclure dans le pas. Toutes activées par défaut.',
					'100son-html-normalizer'
				) }
			</p>

			<div className="htmln-rules-sidebar__toggle-all">
				<Button
					variant="link"
					onClick={ toggleAll }
					disabled={ disabled }
				>
					{ allChecked
						? __( 'Tout décocher', '100son-html-normalizer' )
						: __( 'Tout cocher', '100son-html-normalizer' ) }
				</Button>
			</div>

			<ul className="htmln-rules-sidebar__list">
				{ presets.map( ( preset ) => (
					<li key={ preset.id }>
						<CheckboxControl
							__nextHasNoMarginBottom
							label={ preset.label }
							help={ preset.description }
							checked={ selectedSet.has( preset.id ) }
							disabled={ disabled }
							onChange={ ( checked ) =>
								toggleRule( preset.id, checked )
							}
						/>
					</li>
				) ) }
			</ul>

			<div className="htmln-rules-sidebar__apply">
				<p className="htmln-rules-sidebar__summary">
					{ sprintf(
						// translators: 1 = nombre de règles, 2 = nombre d'articles.
						__(
							'%1$d règle(s) × %2$d article(s) sélectionné(s)',
							'100son-html-normalizer'
						),
						selectedSet.size,
						selectedPostIds.length
					) }
				</p>

				<Button
					variant="primary"
					disabled={ ! canApply }
					onClick={ onApplyStep }
				>
					{ __( 'Appliquer ce pas', '100son-html-normalizer' ) }
				</Button>

				<p className="htmln-rules-sidebar__warning">
					{ __(
						"Important : gardez cet onglet ouvert pendant l'opération.",
						'100son-html-normalizer'
					) }
				</p>
			</div>
		</aside>
	);
}
