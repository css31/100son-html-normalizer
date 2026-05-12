/**
 * Helpers REST pour le contrôleur Notes (post-rc1).
 *
 * Documentés côté serveur dans `includes/Rest/NotesController.php`.
 *
 * Le contenu transporté est la *block grammar* Gutenberg sérialisée
 * (sortie de `@wordpress/blocks::serialize(blocks)`) — chaîne brute,
 * pas de désérialisation côté API (le caller passe la sortie de
 * `parse()` directement aux composants éditeur).
 */

import { get, del, raw } from './client';

const PATH = '/notes';

/**
 * `GET /notes` — récupère la note libre courante (block grammar Gutenberg).
 *
 * @return {Promise<{content: string}>} Contenu courant — chaîne vide si jamais saisi.
 */
export const getNotes = () => get( PATH );

/**
 * `PUT /notes` — écrit la note. Le serveur sanitize via `wp_kses_post()` et
 * renvoie la version persistée — le caller resynchronise son éditeur sur
 * ce retour pour éviter toute dérive client/serveur.
 *
 * @param {string} content Block grammar Gutenberg (sortie de `serialize`).
 * @return {Promise<{content: string}>} Contenu persisté après sanitization.
 */
export const saveNotes = ( content ) =>
	raw( {
		path: `/htmln/v1${ PATH }`,
		method: 'PUT',
		data: { content },
	} );

/**
 * `DELETE /notes` — vide la note. Retourne `{ content: '' }`.
 *
 * @return {Promise<{content: string}>} Contenu vidé (chaîne vide).
 */
export const clearNotes = () => del( PATH );
