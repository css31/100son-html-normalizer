/**
 * BuilderBadge — pastille colorée indiquant le constructeur d'origine
 * d'un article diagnostiqué.
 *
 * 5 variantes reflétant `BuilderClassifier::TYPE_*` côté PHP, plus une
 * 6e variante visuelle « Gutenberg avec fossile SO » qui réutilise le
 * type `gutenberg` mais signale en orange la présence d'un ancien
 * `panels_data` en post-meta (vestige de migration) :
 *
 *   - `siteorigin`      → `SO`   pastille rouge (intervention prudente)
 *   - `siteorigin_flat` → `SO~`  pastille jaune (intervention TRÈS prudente)
 *   - `gutenberg`       → `Gut`  pastille verte
 *   - `gutenberg` + fossile → `Gut` pastille **orange** (rc4) — signale
 *                             un `panels_data` résiduel à nettoyer
 *                             éventuellement
 *   - `other`           → `?`    pastille neutre
 *   - `out`             → `Out`  pastille grise (hors périmètre)
 *
 * Les couleurs et libellés sont alignés sur la pastille V0.1
 * (`Admin\Pages\PostsPage::builder_badge`) pour cohérence visuelle
 * entre les deux UIs en cohabitation V1.0.
 */

import { __ } from '@wordpress/i18n';

/**
 * Mapping type → présentation. Source de vérité unique pour le rendu
 * de la pastille — toute modification ici doit être répliquée en V0.1
 * pour préserver la lecture croisée.
 *
 * @type {Object<string, {label: string, title: string, modifier: string}>}
 */
const PRESETS = {
	siteorigin: {
		label: 'SO',
		title: __(
			'SiteOrigin Page Builder (mode natif : panels_data ou bloc siteorigin-panels)',
			'100son-html-normalizer'
		),
		modifier: 'siteorigin',
	},
	siteorigin_flat: {
		label: 'SO~',
		title: __(
			'SiteOrigin (mode aplati) — rendu HTML figé sans panels_data ni bloc, normalisation à risque',
			'100son-html-normalizer'
		),
		modifier: 'siteorigin-flat',
	},
	gutenberg: {
		label: 'Gut',
		title: __( 'Gutenberg (blocs FSE)', '100son-html-normalizer' ),
		modifier: 'gutenberg',
	},
	other: {
		label: '?',
		title: __(
			'Constructeur inconnu (HTML libre / éditeur classique)',
			'100son-html-normalizer'
		),
		modifier: 'other',
	},
	out: {
		label: 'Out',
		title: __(
			'Hors périmètre (tag manuel) — actions de normalisation désactivées',
			'100son-html-normalizer'
		),
		modifier: 'out',
	},
};

/**
 * Tooltip enrichi pour le cas « Gutenberg + fossile `panels_data` » —
 * informe l'admin que l'article a un passé SO inerte sans dramatiser
 * (le rendu reste Gutenberg, le meta peut être supprimé sans impact).
 *
 * @return {string} Titre HTML-safe (rendu dans `title=`).
 */
function getFossilTitle() {
	return __(
		"Gutenberg avec fossile SiteOrigin : un ancien `panels_data` est resté en post-meta après migration. Inerte au front (c'est `post_content` qui est servi). Peut être nettoyé via WP-CLI : `wp post meta delete <ID> panels_data`.",
		'100son-html-normalizer'
	);
}

/**
 * @param {Object}  props
 * @param {?string} props.type                  Une des 5 valeurs
 *                                              `BuilderClassifier::TYPE_*`
 *                                              ou null/undefined (pour
 *                                              diagnostics pré-2.1.0 sans
 *                                              builder_type calculé).
 * @param {boolean} [props.hasFossilPanelsData] Vrai si l'article est
 *                                              Gutenberg mais conserve un
 *                                              `panels_data` en post-meta.
 *                                              Sans effet pour les autres
 *                                              types.
 * @return {JSX.Element} Pastille `<span>`.
 */
export default function BuilderBadge( { type, hasFossilPanelsData = false } ) {
	const preset = type && PRESETS[ type ] ? PRESETS[ type ] : null;

	if ( ! preset ) {
		// Pre-2.1.0 ou type inattendu — pastille discrète qui signale
		// « pas encore classifié ; rescanner pour catégoriser ».
		return (
			<span
				className="htmln-builder-badge htmln-builder-badge--unknown"
				title={ __(
					'Constructeur non calculé — rescanner pour le déterminer.',
					'100son-html-normalizer'
				) }
			>
				—
			</span>
		);
	}

	// Cas spécial Gutenberg + fossile : on bascule sur un modifier visuel
	// orange et on remplace le tooltip par une explication ciblée.
	if ( 'gutenberg' === type && hasFossilPanelsData ) {
		return (
			<span
				className="htmln-builder-badge htmln-builder-badge--gutenberg-fossil"
				title={ getFossilTitle() }
			>
				{ preset.label }
			</span>
		);
	}

	return (
		<span
			className={ `htmln-builder-badge htmln-builder-badge--${ preset.modifier }` }
			title={ preset.title }
		>
			{ preset.label }
		</span>
	);
}
