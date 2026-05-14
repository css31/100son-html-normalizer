/**
 * escapeHtml — échappement HTML minimal pour injection sûre via
 * `dangerouslySetInnerHTML`.
 *
 * Couvre les quatre caractères qui peuvent ouvrir une vulnérabilité
 * d'injection (`&`, `<`, `>`, `"`) — pas plus. L'apostrophe n'est pas
 * échappée parce qu'on n'utilise jamais cette sortie à l'intérieur d'un
 * attribut HTML quoté avec `'`.
 *
 * Mutualisé entre `highlightHtml` (fallback Prism désactivé) et
 * `highlightHtmlWithDiff` (fallback surlignage désactivé) pour garantir
 * un comportement identique sur les deux chemins.
 *
 * @param {string} text Texte brut, potentiellement non-string.
 * @return {string} Texte HTML-safe.
 */
export function escapeHtml( text ) {
	return String( text )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}
