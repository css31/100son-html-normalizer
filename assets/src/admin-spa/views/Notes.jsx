/**
 * Notes — vue racine de l'onglet « Notes » SPA (post-rc1).
 *
 * Note libre unique pour le plugin, éditée via l'éditeur Gutenberg en mode
 * restreint (whitelist de blocs ci-dessous). Persistance via `/notes` REST.
 *
 * Architecture en deux niveaux :
 *  - `Notes` (ce fichier) : enveloppe — état de chargement, notice d'erreur,
 *    header, montage conditionnel de l'éditeur quand le fetch initial a
 *    résolu (`content` non null) ;
 *  - `NotesEditor.jsx` : pilote l'éditeur Gutenberg réel et le dirty-state
 *    local. Séparé pour qu'on ne monte `BlockEditorProvider` qu'avec un
 *    `initialContent` stable — évite les remounts inutiles si le hook se
 *    réémet en cours de saisie.
 *
 * Cohabitation avec la zone de notes plain-text de la page Journal V0.1 :
 * stockage isolé (option dédiée `son100_htmln_notes_rich`). Cf. RichNotesRepository.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { useNotes } from '../hooks/useNotes';
import NotesEditor from './Notes/NotesEditor';

/**
 * @return {JSX.Element} Vue Notes complète.
 */
export default function Notes() {
	const {
		content,
		isLoading,
		isSaving,
		error,
		justSaved,
		save,
		clear,
		clearStatus,
	} = useNotes();

	// Fetch initial pas encore résolu — splash.
	if ( isLoading && null === content ) {
		return (
			<div className="htmln-notes htmln-notes--loading">
				<Spinner />{ ' ' }
				{ __( 'Chargement des notes…', '100son-html-normalizer' ) }
			</div>
		);
	}

	return (
		<div className="htmln-notes">
			<header className="htmln-notes__header">
				<h2>{ __( 'Notes', '100son-html-normalizer' ) }</h2>
				<p className="description">
					{ __(
						'Carnet de notes libre, édité avec Gutenberg. Persistance côté serveur sur l’option `son100_htmln_notes_rich` — exporte-toi le contenu avant désinstallation, il est supprimé à l’uninstall.',
						'100son-html-normalizer'
					) }
				</p>
			</header>

			{ justSaved && (
				<Notice status="success" onRemove={ clearStatus } isDismissible>
					{ __( 'Notes enregistrées.', '100son-html-normalizer' ) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" onRemove={ clearStatus } isDismissible>
					{ sprintf(
						// translators: %s = message d'erreur.
						__(
							'Échec de la dernière action : %s',
							'100son-html-normalizer'
						),
						error
					) }
				</Notice>
			) }

			<NotesEditor
				initialContent={ content ?? '' }
				isSaving={ isSaving }
				onSave={ save }
				onClear={ clear }
			/>
		</div>
	);
}
