/**
 * SiteOriginWarning — bandeau d'avertissement quand SiteOrigin Page Builder
 * est détecté sur l'installation.
 *
 * Deux niveaux de sévérité :
 *  - **active** (rouge) : SO est chargé → ses hooks `the_content` régénèrent
 *    le rendu front depuis `panels_data`. Toute normalisation du
 *    `post_content` sur un article SO est invisible côté visiteur tant que
 *    SO est actif, et sera écrasée à la prochaine sauvegarde dans l'éditeur
 *    SO. Le risque est immédiat.
 *  - **installed-only** (orange) : SO est désactivé mais présent sur disque.
 *    `panels_data` est inerte, mais un admin peut réactiver l'extension par
 *    inadvertance pendant la migration et invalider tout le travail
 *    accumulé. Le risque est latent.
 *
 * Les données viennent de `window.htmlnEnv` (cf. `Admin\Assets::on_enqueue`).
 * Si la variable n'est pas définie (build local, dev hors WP-Admin, etc.),
 * le composant ne rend rien — pas de faux positif au boot.
 */

import { __ } from '@wordpress/i18n';

/**
 * @typedef {Object} HtmlnEnv
 * @property {boolean} siteoriginActive    Plugin actif (chargé par WP).
 * @property {boolean} siteoriginInstalled Plugin présent sur disque (actif ou non).
 */

/**
 * Lit `window.htmlnEnv` de façon défensive. Retourne `null` si la donnée
 * n'est pas exposée — la SPA continue de fonctionner.
 *
 * @return {?HtmlnEnv} Snapshot env normalisé, ou null si indisponible.
 */
function readEnv() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const env = window.htmlnEnv;
	if ( ! env || typeof env !== 'object' ) {
		return null;
	}
	return {
		siteoriginActive: Boolean( env.siteoriginActive ),
		siteoriginInstalled: Boolean( env.siteoriginInstalled ),
	};
}

/**
 * @return {?JSX.Element} Bandeau d'avertissement, ou null si SO absent.
 */
export default function SiteOriginWarning() {
	const env = readEnv();
	if ( null === env ) {
		return null;
	}
	if ( env.siteoriginActive ) {
		return (
			<div
				className="htmln-so-warning htmln-so-warning--active"
				role="alert"
			>
				<strong className="htmln-so-warning__title">
					{ __(
						'SiteOrigin Page Builder est actif',
						'100son-html-normalizer'
					) }
				</strong>
				<p className="htmln-so-warning__body">
					{ __(
						"Tant que cette extension est chargée, son filtre `the_content` régénère le rendu front à partir du post-meta `panels_data`. Toute normalisation appliquée au `post_content` d'un article avec un `panels_data` non vide reste invisible côté visiteur, et sera écrasée silencieusement à la prochaine sauvegarde dans l'éditeur SiteOrigin. Désactivez SiteOrigin Page Builder avant de poursuivre la normalisation.",
						'100son-html-normalizer'
					) }
				</p>
			</div>
		);
	}
	if ( env.siteoriginInstalled ) {
		return (
			<div
				className="htmln-so-warning htmln-so-warning--installed"
				role="status"
			>
				<strong className="htmln-so-warning__title">
					{ __(
						'SiteOrigin Page Builder est installé mais désactivé',
						'100son-html-normalizer'
					) }
				</strong>
				<p className="htmln-so-warning__body">
					{ __(
						"L'extension reste présente sur disque. Sa réactivation pendant la migration réintroduirait le rendu front depuis `panels_data` et masquerait les normalisations déjà appliquées. Désinstallez SiteOrigin Page Builder une fois la migration validée pour écarter définitivement ce risque.",
						'100son-html-normalizer'
					) }
				</p>
			</div>
		);
	}
	return null;
}
