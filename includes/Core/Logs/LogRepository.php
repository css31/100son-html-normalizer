<?php
/**
 * LogRepository — stockage des entrees de journal dans une option WP.
 *
 * Capacite max : 500 entrees, FIFO (les plus anciennes sont evincees).
 * Pas de table custom (conforme cahier section 4.2).
 *
 * Schema d'une entree :
 *   [
 *     'timestamp'   => int (unix),
 *     'event'       => 'normalize'|'preview'|'settings',
 *     'status'      => string,
 *     'post_id'     => int|null,
 *     'post_title'  => string|null,
 *     'user_id'     => int,
 *     'user_login'  => string,
 *     'message'     => string,
 *     'revision_id' => int|null,
 *   ]
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Logs;

defined( 'ABSPATH' ) || exit;

/**
 * Repository des entrees de journal.
 */
final class LogRepository {

	public const OPT_NAME = 'son100_htmln_logs';
	public const MAX_ENTRIES = 500;

	/**
	 * Ajoute une entree (FIFO : evincion des plus anciennes au-dela du cap).
	 *
	 * @param array<string, mixed> $entry Entree.
	 * @return void
	 */
	public function add( array $entry ): void {
		$entries = $this->all();
		// Nouvelle entree en fin (plus recent en derniere position).
		$entries[] = $entry;
		// Cap : si on depasse, on evince les plus anciennes (debut du tableau).
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, count( $entries ) - self::MAX_ENTRIES );
		}
		update_option( self::OPT_NAME, array_values( $entries ), false );
	}

	/**
	 * Liste toutes les entrees, ordre chronologique (plus ancien en premier).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		$entries = get_option( self::OPT_NAME, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}
		/** @var list<array<string, mixed>> $entries */
		return array_values( $entries );
	}

	/**
	 * Liste les entrees triees par date decroissante (plus recent d'abord).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recent_first(): array {
		return array_reverse( $this->all() );
	}

	/**
	 * Page d'entrees (recent first).
	 *
	 * @param int $page     Page 1-indexee.
	 * @param int $per_page Entrees par page.
	 * @return array{entries: list<array<string, mixed>>, total: int, total_pages: int}
	 */
	public function paginate( int $page = 1, int $per_page = 50 ): array {
		$all      = $this->recent_first();
		$total    = count( $all );
		$per_page = max( 1, $per_page );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;
		return array(
			'entries'     => array_slice( $all, $offset, $per_page ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Compte total d'entrees.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * Purge complete du journal.
	 *
	 * @return void
	 */
	public function clear(): void {
		update_option( self::OPT_NAME, array(), false );
	}
}
