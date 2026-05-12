/**
 * BuilderBadge — pastille colorée indiquant le constructeur d'origine
 * d'un article diagnostiqué.
 *
 * 5 variantes reflétant `BuilderClassifier::TYPE_*` côté PHP :
 *
 *   - `siteorigin`      → `SO`   pastille rouge (intervention prudente)
 *   - `siteorigin_flat` → `SO~`  pastille jaune (intervention TRÈS prudente)
 *   - `gutenberg`       → `Gut`  pastille verte
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
 * @param {Object}  props
 * @param {?string} props.type Une des 5 valeurs `BuilderClassifier::TYPE_*`
 *                             ou null/undefined (pour diagnostics pré-2.1.0
 *                             sans builder_type calculé).
 * @return {JSX.Element} Pastille `<span>`.
 */
export default function BuilderBadge( { type } ) {
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

	return (
		<span
			className={ `htmln-builder-badge htmln-builder-badge--${ preset.modifier }` }
			title={ preset.title }
		>
			{ preset.label }
		</span>
	);
}
