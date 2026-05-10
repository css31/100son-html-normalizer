<?php
/**
 * RegressionReport — DTO synthèse des métriques en régression.
 *
 * Cf. cahier v2.0 §3.1 F15 et §11.21.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Regression;

defined( 'ABSPATH' ) || exit;

/**
 * Liste les métriques fautives détectées par `RegressionDetector::analyze()`.
 *
 * Convention : `RegressionDetector` retourne `null` pour signaler "pas de
 * régression" (court-circuit propre côté StepRunner). On ne retourne JAMAIS
 * un `RegressionReport` vide — un report existe ssi au moins une métrique
 * est en dépassement.
 *
 * Le report sérialisé est ensuite stocké dans
 * `son100_htmln_steps.per_article_results[post_id].regression`.
 */
final class RegressionReport {

	/**
	 * @param non-empty-list<RegressionFailure> $failures Métriques fautives (≥ 1).
	 */
	public function __construct(
		public readonly array $failures,
	) {}

	/**
	 * Toujours `false` par construction (un report vide ne devrait pas exister).
	 * Méthode défensive pour les chemins de code qui voudraient s'assurer.
	 *
	 * @return bool
	 */
	public function is_clean(): bool {
		return array() === $this->failures;
	}

	/**
	 * Nombre de métriques fautives.
	 *
	 * @return int
	 */
	public function failure_count(): int {
		return count( $this->failures );
	}

	/**
	 * Vrai si une métrique précise est dans la liste des fautives.
	 *
	 * @param string $metric_key Identifiant de métrique (ex. `images`, `headings.h2`).
	 * @return bool
	 */
	public function has_failure( string $metric_key ): bool {
		foreach ( $this->failures as $failure ) {
			if ( $failure->metric_key === $metric_key ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Récupère la failure pour une métrique donnée, ou null.
	 *
	 * @param string $metric_key Identifiant de métrique.
	 * @return RegressionFailure|null
	 */
	public function failure_for( string $metric_key ): ?RegressionFailure {
		foreach ( $this->failures as $failure ) {
			if ( $failure->metric_key === $metric_key ) {
				return $failure;
			}
		}
		return null;
	}

	/**
	 * Représentation tableau sérialisable JSON (pour persistance dans
	 * `son100_htmln_steps.per_article_results`).
	 *
	 * @return array{failures: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		$serialized = array();
		foreach ( $this->failures as $failure ) {
			$serialized[] = $failure->to_array();
		}
		return array(
			'failures' => $serialized,
		);
	}

	/**
	 * Reconstruit un RegressionReport depuis sa représentation `to_array()`.
	 *
	 * Convention : un report sans failures est invalide par construction
	 * (cf. invariant `non-empty-list`). Cette méthode retourne donc `null`
	 * si la liste reconstruite est vide — cohérent avec `RegressionDetector`
	 * qui retourne `null` pour signaler "pas de régression".
	 *
	 * Sert à `StepRunner::confirm_article()` et `refuse_article()` pour
	 * rejouer un rapport persisté en base et le propager dans le DTO de
	 * retour `ArticleResult` (traçabilité côté SPA / Historique F16).
	 *
	 * @param array<string, mixed> $data Données issues de `to_array()` ou JSON décodé.
	 * @return self|null `null` si aucune failure exploitable.
	 */
	public static function from_array( array $data ): ?self {
		if ( ! isset( $data['failures'] ) || ! is_array( $data['failures'] ) ) {
			return null;
		}
		$failures = array();
		foreach ( $data['failures'] as $entry ) {
			if ( is_array( $entry ) ) {
				$failures[] = RegressionFailure::from_array( $entry );
			}
		}
		if ( array() === $failures ) {
			return null;
		}
		return new self( $failures );
	}
}
