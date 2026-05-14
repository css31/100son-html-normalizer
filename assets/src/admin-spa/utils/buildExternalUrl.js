/**
 * buildExternalUrl — compose une URL pointant vers la version d'un
 * article sur un domaine externe (par ex. version « old » ou « prod »).
 *
 * Garde l'intégralité du `pathname + search + hash` du permalien local et
 * remplace uniquement l'`origin` par celui du domaine cible. Suppose donc
 * une homologie d'URL entre les deux sites (slugs identiques) — vérifié
 * sur le corpus MMM-2 entre Old et Prod (sites issus de la même base).
 *
 * Retourne `null` si l'un des deux ingrédients manque ou est invalide
 * (caller décide de ne pas afficher le bouton dans ce cas).
 *
 * Mutualisé entre `ArticlesTable` (colonne « Ouvrir sur… ») et `DiffModal`
 * (boutons sous le résumé des pertes). À l'origine factorisé après l'ajout
 * des mêmes boutons dans la modale Diff — éviter la duplication du parsing
 * d'URL et de la gestion d'erreur.
 *
 * @param {string} permalink Permalien local de l'article (URL absolue).
 * @param {string} baseUrl   URL absolue configurée du site externe (sans slash final).
 * @return {?string} URL absolue cible, ou `null` si impossible à composer.
 */
export function buildExternalUrl( permalink, baseUrl ) {
	if ( ! permalink || ! baseUrl ) {
		return null;
	}
	try {
		const src = new URL( permalink );
		const dest = new URL( baseUrl );
		return `${ dest.origin }${ src.pathname }${ src.search }${ src.hash }`;
	} catch ( _err ) {
		return null;
	}
}
