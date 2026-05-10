<?php
/**
 * DiagnosticBatchRunner — orchestre le scan global des articles.
 *
 * Cf. cahier v2.0 §3.1 F12 et §11.18.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_Post;

/**
 * Scan global par chunks (20 articles par défaut).
 *
 * Workflow attendu (consommé par la couche REST F12 — Phase 5) :
 *  1. SPA appelle `start_batch()` → reçoit {batch_id, total, post_ids, chunk_size}.
 *  2. SPA boucle sur les post_ids par paquets de `chunk_size`, appelle
 *     `process_chunk($subset)` pour chaque paquet et progresse la barre.
 *  3. À la fin du dernier chunk, le scan est terminé.
 *
 * Le `batch_id` est un identifiant côté client (UUID) qui n'est pas
 * persisté en V1 — la table `son100_htmln_steps` est dédiée aux pas F14,
 * pas aux scans diagnostiques. La SPA s'en sert pour reconnaître son
 * propre batch côté UI.
 *
 * Scope du scan : `post_status='publish'` + post_types issus du setting
 * F8 (`f8_post_types_selection`). Cohérent avec §3.1 F12 et hyp. 20 du cahier.
 */
class DiagnosticBatchRunner {

	/**
	 * Taille de chunk par défaut (cf. §11.18 — 20 articles).
	 */
	public const DEFAULT_CHUNK_SIZE = 20;

	/**
	 * @param DiagnosticEngine       $engine     Calcul du diagnostic d'un article.
	 * @param DiagnosticsRepository  $repository Persistance du diagnostic.
	 * @param SettingsRepository     $settings   Source des post_types F8.
	 */
	public function __construct(
		private readonly DiagnosticEngine $engine,
		private readonly DiagnosticsRepository $repository,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Énumère les articles éligibles et retourne les méta-données du batch.
	 *
	 * `$post_types_override` permet à un caller (typiquement
	 * `DiagnosticsController::run_batch()`) de scanner un sous-ensemble
	 * différent du défaut F8 sans persister la sélection. Si `null`, on
	 * retombe sur la sélection F8 stockée en `Settings`.
	 *
	 * @param int|null            $chunk_size           Taille de chunk (≥ 1). Défaut : `DEFAULT_CHUNK_SIZE`.
	 * @param list<string>|null   $post_types_override  Override ad-hoc des post_types ; `null` = défauts F8.
	 * @return array{batch_id: string, total_articles: int, post_ids: list<int>, chunk_size: int}
	 */
	public function start_batch( ?int $chunk_size = null, ?array $post_types_override = null ): array {
		$resolved_chunk = max( 1, $chunk_size ?? self::DEFAULT_CHUNK_SIZE );
		$post_types     = $post_types_override ?? $this->settings->get_f8_post_types_selection();
		$raw            = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$post_ids       = array();
		foreach ( (array) $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$post_ids[] = (int) $id;
			}
		}

		return array(
			'batch_id'       => wp_generate_uuid4(),
			'total_articles' => count( $post_ids ),
			'post_ids'       => $post_ids,
			'chunk_size'     => $resolved_chunk,
		);
	}

	/**
	 * Traite un chunk d'articles : diagnostic + upsert pour chacun.
	 *
	 * Les articles dont `get_post()` ne retourne pas un `WP_Post` (suppression
	 * concurrente, ID invalide…) sont silencieusement skippés.
	 *
	 * @param list<int> $post_ids IDs à traiter.
	 * @return array<int, DiagnosticRecord> Map post_id → record diagnostiqué.
	 */
	public function process_chunk( array $post_ids ): array {
		$results = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$record = $this->engine->diagnose( $post );
			$this->repository->upsert( $record );
			$results[ $post->ID ] = $record;
		}
		return $results;
	}
}
