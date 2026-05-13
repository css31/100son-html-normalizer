/**
 * highlightHtml — coloration syntaxique d'une chaîne HTML via Prism.js.
 *
 * Utilisé par le composant `HighlightedCode` (vue DiffModal, mode « Code
 * source ») pour mettre en valeur tags, attributs, valeurs d'attributs et
 * commentaires HTML dans les panneaux Avant/Après du diff. Sur le corpus
 * MMM-2 où chaque article mélange balises HTML standard, blocs Gutenberg
 * (`<!-- wp:* -->`) et résidus SiteOrigin, la coloration accélère
 * significativement la lecture.
 *
 * On charge uniquement le **core Prism** + le composant `markup` (langue
 * HTML/XML). Pas d'import CSS : les styles des tokens sont définis dans
 * `assets/src/admin-spa/styles/main.scss` (section `.htmln-diff-modal__code`)
 * pour rester en parité avec la palette WP-Admin et préserver les
 * backgrounds `#f6f7f7` / `#f0f6fc` qui distinguent visuellement les
 * colonnes Avant / Après (le thème Prism par défaut imposerait son propre
 * background, qui écraserait cette distinction).
 *
 * Sécurité : `Prism.highlight` échappe lui-même les caractères spéciaux
 * (`<`, `>`, `&`) avant d'insérer ses balises `<span class="token …">`.
 * Le retour est donc une chaîne HTML sûre à injecter via
 * `dangerouslySetInnerHTML` côté composant React, même si l'entrée
 * contient du HTML arbitraire issu de `post_content`.
 */

import Prism from 'prismjs';
import 'prismjs/components/prism-markup';

/**
 * Tokenise une chaîne HTML brute et renvoie le balisage tokénisé prêt à
 * être injecté.
 *
 * @param {string} raw Chaîne HTML brute (post_content), potentiellement vide.
 * @return {string} HTML avec spans `.token.*`, ou chaîne vide si rien à colorer.
 */
export function highlightHtml( raw ) {
	if ( 'string' !== typeof raw || '' === raw ) {
		return '';
	}
	return Prism.highlight( raw, Prism.languages.markup, 'markup' );
}
