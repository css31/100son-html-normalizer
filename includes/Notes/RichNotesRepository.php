<?php
/**
 * RichNotesRepository — note libre stockée en *block grammar* Gutenberg.
 *
 * Sœur isolée de `Core\Logs\NotesRepository` (V0.1, plain text) : la SPA V1.0
 * écrit ici, le panneau de notes V0.1 de la page Journal écrit ailleurs.
 * Cohabitation explicite jusqu'à V1.1 où la page Journal V0.1 disparaît.
 *
 * Le contenu stocké est de la **block grammar** sérialisée par
 * `@wordpress/blocks::serialize()` côté SPA — c'est-à-dire du HTML augmenté
 * de commentaires `<!-- wp:* -->` que `parse_blocks()` sait re-désérialiser.
 * On préserve donc les commentaires HTML au sanitize (`wp_kses_post()` les
 * laisse passer, contrairement à `sanitize_textarea_field` qui les détruit).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Notes;

defined( 'ABSPATH' ) || exit;

/**
 * Repository de la note libre riche (1 seule chaîne, block grammar Gutenberg).
 *
 * API alignée sur `Core\Logs\NotesRepository` (get / set / clear) pour rester
 * familière au lecteur — seul le sanitize d'écriture diffère.
 */
final class RichNotesRepository {

	/**
	 * Nom de l'option WP. Dédié — *ne pas* réutiliser `son100_htmln_logs_notes`
	 * (V0.1 plain text, encore édité par `Admin\Pages\LogsPage` qui appelle
	 * `sanitize_textarea_field` au save → un round-trip V0.1 corromprait les
	 * commentaires `<!-- wp:* -->`). Tant que la page V0.1 cohabite, on isole.
	 */
	public const OPT_NAME = 'son100_htmln_notes_rich';

	/**
	 * Récupère la note actuelle (block grammar). Chaîne vide si absente.
	 *
	 * Pas de désérialisation côté serveur : la SPA passe le contenu brut à
	 * `@wordpress/blocks::parse()` qui produit le tableau de blocs. Garder
	 * le brut sert aussi un futur `htmln/notes` consommé par une route REST
	 * frontale (V1.1) sans avoir à refaire `serialize_blocks` au retour.
	 *
	 * @return string Block grammar Gutenberg, ou chaîne vide.
	 */
	public function get(): string {
		$value = get_option( self::OPT_NAME, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Enregistre une note (remplace l'existante).
	 *
	 * Sanitization par `wp_kses_post()` — alignement strict sur ce que
	 * `wp_insert_post()` applique à `post_content`. Cela :
	 *  - préserve les commentaires `<!-- wp:* -->` (essentiels au parse) ;
	 *  - autorise tous les tags et attributs admis dans `post_content` ;
	 *  - filtre le code dangereux (script/iframe/event-handlers/javascript:).
	 *
	 * Le `trim()` final supprime les blancs en bord — pas le contenu interne,
	 * qui peut légitimement contenir des espaces dans des blocs `core/code`.
	 *
	 * @param string $notes Block grammar (sortie de `serialize` côté SPA).
	 * @return void
	 */
	public function set( string $notes ): void {
		$clean = trim( wp_kses_post( $notes ) );
		update_option( self::OPT_NAME, $clean, false );
	}

	/**
	 * Vide la note (option remise à chaîne vide).
	 *
	 * Pas de `delete_option` : la SPA distingue « jamais saisi » et « vidé »
	 * uniquement via le contenu. Garder la clé évite un autoload yo-yo si
	 * l'utilisateur sauvegarde une note vide puis la re-remplit.
	 *
	 * @return void
	 */
	public function clear(): void {
		update_option( self::OPT_NAME, '', false );
	}
}
