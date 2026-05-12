<?php
/**
 * DiagnosticEngine — qualifie un article en `normal` / `to_improve`.
 *
 * Cf. cahier v2.0 §3.1 F12 et §11.17.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use WP_Post;

/**
 * Service stateless de calcul de diagnostic article.
 *
 * Stratégie :
 *  - itère les règles activées via `PresetRegistry::get_enabled_rules()` ;
 *  - pour chacune, appelle `countMatches()` sur le `post_content` (Phase 1) ;
 *  - cumule les occurrences ; statut `to_improve` si total > 0, `normal` sinon ;
 *  - calcule en parallèle un `MetricsSnapshot` (Phase 2.3) qui sera stocké
 *    avec le diagnostic pour comparaisons futures (F15 — RegressionDetector).
 *
 * Le DiagnosticRecord retourné est **frais** (`is_stale = false`,
 * `diagnosed_at` = now UTC) et son `post_modified_at_diagnosis` est snapshot
 * du `post_modified` à l'instant t — utile pour la détection fine de stale.
 *
 * Aucune persistance : le caller (DiagnosticBatchRunner ou la couche REST)
 * passe le record à `DiagnosticsRepository::upsert()`.
 */
class DiagnosticEngine {

	/**
	 * @param PresetRegistry    $registry   Source des règles activées.
	 * @param MetricsCalculator $metrics    Calculateur de snapshot.
	 * @param BuilderClassifier $classifier Classification constructeur (post-rc3,
	 *                                       nullable pour rétro-compat avec
	 *                                       l'ancien wiring 2-arg : si null, le
	 *                                       record reçoit `builder_type=null`).
	 */
	public function __construct(
		private readonly PresetRegistry $registry,
		private readonly MetricsCalculator $metrics,
		private readonly ?BuilderClassifier $classifier = null,
	) {}

	/**
	 * Qualifie un article et produit un DiagnosticRecord prêt à persister.
	 *
	 * @param WP_Post $post Article à diagnostiquer.
	 * @return DiagnosticRecord
	 */
	public function diagnose( WP_Post $post ): DiagnosticRecord {
		$html  = (string) $post->post_content;
		$rules = $this->registry->get_enabled_rules();

		$matching_rules = array();
		$total_matches  = 0;

		foreach ( $rules as $rule ) {
			$count = $rule->countMatches( $html );
			if ( $count > 0 ) {
				$matching_rules[] = array(
					'rule_id'     => $rule->id(),
					'occurrences' => $count,
				);
				$total_matches += $count;
			}
		}

		$status   = $total_matches > 0
			? DiagnosticRecord::STATUS_TO_IMPROVE
			: DiagnosticRecord::STATUS_NORMAL;
		$snapshot = $this->metrics->compute( $html );

		$post_modified = trim( (string) ( $post->post_modified ?? '' ) );
		$builder_type  = null !== $this->classifier
			? $this->classifier->classify( $post->ID )
			: null;

		return new DiagnosticRecord(
			id: null,
			post_id: $post->ID,
			status: $status,
			matching_rules: $matching_rules,
			metrics: $snapshot->toArray(),
			is_stale: false,
			diagnosed_at: gmdate( 'Y-m-d H:i:s' ),
			post_modified_at_diagnosis: '' === $post_modified ? null : $post_modified,
			builder_type: $builder_type,
		);
	}
}
