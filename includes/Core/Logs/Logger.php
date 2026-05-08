<?php
/**
 * Logger — facade haut niveau pour journaliser les actions du plugin.
 *
 * Trois evenements traces :
 *  - normalize : appel a PostNormalizer::normalize_post() (toujours, quel que soit le statut)
 *  - preview   : appel a PostNormalizer::preview()
 *  - settings  : sauvegarde de la configuration des prereglages
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Logs;

defined( 'ABSPATH' ) || exit;

/**
 * Facade de logging.
 */
final class Logger {

	private LogRepository $repo;

	public function __construct( LogRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Logue une normalisation d'article.
	 *
	 * @param int    $post_id     ID de l'article.
	 * @param string $post_title  Titre snapshotte au moment de l'evenement.
	 * @param string $status      Statut renvoye par PostNormalizer.
	 * @param string $message     Message complementaire (optionnel).
	 * @param int    $revision_id ID de la revision creee (0 si aucune).
	 * @return void
	 */
	public function log_normalize( int $post_id, string $post_title, string $status, string $message = '', int $revision_id = 0 ): void {
		$this->repo->add( $this->build_entry( 'normalize', $status, $post_id, $post_title, $message, $revision_id ) );
	}

	/**
	 * Logue un apercu (sans ecriture).
	 *
	 * @param int    $post_id    ID.
	 * @param string $post_title Titre.
	 * @param string $status     Statut (modified/unchanged/error_*).
	 * @return void
	 */
	public function log_preview( int $post_id, string $post_title, string $status ): void {
		$this->repo->add( $this->build_entry( 'preview', $status, $post_id, $post_title, '', 0 ) );
	}

	/**
	 * Logue une sauvegarde de configuration des prereglages.
	 *
	 * @param string $message Description courte du changement (ex: "P3 desactive, P5 seuil 2->3").
	 * @return void
	 */
	public function log_settings_change( string $message = '' ): void {
		$this->repo->add( $this->build_entry( 'settings', 'updated', null, null, $message, 0 ) );
	}

	/**
	 * Construit une entree normalisee.
	 *
	 * @param string      $event       'normalize'|'preview'|'settings'.
	 * @param string      $status      Statut.
	 * @param int|null    $post_id     ID ou null.
	 * @param string|null $post_title  Titre ou null.
	 * @param string      $message     Message.
	 * @param int         $revision_id 0 si aucune.
	 * @return array<string, mixed>
	 */
	private function build_entry( string $event, string $status, ?int $post_id, ?string $post_title, string $message, int $revision_id ): array {
		[ $user_id, $user_login ] = $this->current_user();
		return [
			'timestamp'   => time(),
			'event'       => $event,
			'status'      => $status,
			'post_id'     => $post_id,
			'post_title'  => $post_title,
			'user_id'     => $user_id,
			'user_login'  => $user_login,
			'message'     => $message,
			'revision_id' => $revision_id,
		];
	}

	/**
	 * Recupere l'utilisateur courant (id + login).
	 *
	 * @return array{0: int, 1: string}
	 */
	private function current_user(): array {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return [ 0, '' ];
		}
		$user = wp_get_current_user();
		if ( ! $user || ! isset( $user->ID ) ) {
			return [ 0, '' ];
		}
		return [ (int) $user->ID, (string) ( $user->user_login ?? '' ) ];
	}
}
