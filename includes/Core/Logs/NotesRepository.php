<?php
/**
 * NotesRepository — zone de saisie libre stockee dans une option WP.
 *
 * Independante du journal d'evenements (LogRepository). Permet a l'admin
 * de prendre des notes contextuelles persistantes (rappels, todo, etc.)
 * sans polluer les entrees du journal.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Logs;

defined( 'ABSPATH' ) || exit;

/**
 * Repository de la note libre (1 seule chaine).
 */
final class NotesRepository {

	public const OPT_NAME = 'son100_htmln_logs_notes';

	/**
	 * Recupere la note actuelle.
	 *
	 * @return string Chaine vide si absente.
	 */
	public function get(): string {
		$value = get_option( self::OPT_NAME, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Enregistre une note (remplace l'existante).
	 *
	 * @param string $notes Texte (sera trimme).
	 * @return void
	 */
	public function set( string $notes ): void {
		update_option( self::OPT_NAME, trim( $notes ), false );
	}

	/**
	 * Vide la note (option mise a chaine vide).
	 *
	 * @return void
	 */
	public function clear(): void {
		update_option( self::OPT_NAME, '', false );
	}
}
