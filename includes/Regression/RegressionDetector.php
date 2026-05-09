<?php
/**
 * RegressionDetector — compare 2 MetricsSnapshot vs RegressionThresholds.
 *
 * Cf. cahier v2.0 §3.1 F15, §11.21 et §13 garde-fou « toujours appelé ».
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Regression;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;

/**
 * Service stateless de détection de régression structurelle.
 *
 * Sémantique :
 *  - on compare les 7 métriques γ avant/après ;
 *  - une métrique est « fautive » si la PERTE (before - after) dépasse le
 *    seuil correspondant ;
 *  - `chars`/`words`/`paragraphs` ont un seuil en pourcentage (`*_loss_pct`) :
 *    on calcule `loss_pct = max(0, (before - after) / before * 100)`.
 *    Si `before === 0`, on considère qu'il n'y a pas de régression possible
 *    (tout gain est positif, toute "perte" est inexistante).
 *  - `headings` a un seuil par niveau (`headings_loss`) appliqué à h1..h6
 *    indépendamment ; chaque niveau fautif ajoute une `RegressionFailure`
 *    avec metric_key `headings.h{N}`.
 *  - `images`/`links`/`lists` : seuil absolu (`*_loss`) sur le compte total.
 *
 * Retour :
 *  - `null` si aucune métrique en dépassement ;
 *  - `RegressionReport` non vide sinon.
 */
final class RegressionDetector {

	/**
	 * Analyse 2 snapshots et produit un rapport de régression si applicable.
	 *
	 * @param MetricsSnapshot       $before     Snapshot avant normalisation.
	 * @param MetricsSnapshot       $after      Snapshot après normalisation.
	 * @param RegressionThresholds  $thresholds Seuils γ courants.
	 * @return RegressionReport|null `null` si pas de régression.
	 */
	public function analyze(
		MetricsSnapshot $before,
		MetricsSnapshot $after,
		RegressionThresholds $thresholds
	): ?RegressionReport {
		$failures = array();

		// 1. Métriques en pourcentage (chars/words/paragraphs).
		$pct_failure = self::check_pct( 'chars', $before->chars, $after->chars, $thresholds->text_loss_pct );
		if ( null !== $pct_failure ) {
			$failures[] = $pct_failure;
		}
		$pct_failure = self::check_pct( 'words', $before->words, $after->words, $thresholds->words_loss_pct );
		if ( null !== $pct_failure ) {
			$failures[] = $pct_failure;
		}
		$pct_failure = self::check_pct( 'paragraphs', $before->paragraphs, $after->paragraphs, $thresholds->paragraphs_loss_pct );
		if ( null !== $pct_failure ) {
			$failures[] = $pct_failure;
		}

		// 2. Métriques absolues (images/links/lists).
		$abs_failure = self::check_absolute( 'images', $before->images, $after->images, $thresholds->images_loss );
		if ( null !== $abs_failure ) {
			$failures[] = $abs_failure;
		}
		$abs_failure = self::check_absolute( 'links', $before->links, $after->links, $thresholds->links_loss );
		if ( null !== $abs_failure ) {
			$failures[] = $abs_failure;
		}
		$abs_failure = self::check_absolute( 'lists', $before->lists, $after->lists, $thresholds->lists_loss );
		if ( null !== $abs_failure ) {
			$failures[] = $abs_failure;
		}

		// 3. Headings — seuil par niveau h1..h6.
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $level ) {
			$abs_failure = self::check_absolute(
				'headings.' . $level,
				$before->headings[ $level ],
				$after->headings[ $level ],
				$thresholds->headings_loss
			);
			if ( null !== $abs_failure ) {
				$failures[] = $abs_failure;
			}
		}

		if ( array() === $failures ) {
			return null;
		}
		return new RegressionReport( $failures );
	}

	/**
	 * Vérifie une métrique à seuil de pourcentage. Retourne une `RegressionFailure`
	 * si la perte en pourcentage dépasse le seuil, sinon null.
	 *
	 * Cas particuliers :
	 *  - `before <= 0` : pas de régression possible (division impossible).
	 *  - `after >= before` : aucune perte, donc pas de régression.
	 *
	 * @param string $metric_key Identifiant de métrique.
	 * @param int    $before     Valeur avant.
	 * @param int    $after      Valeur après.
	 * @param int    $threshold  Pourcentage seuil (≥ 0).
	 * @return RegressionFailure|null
	 */
	private static function check_pct( string $metric_key, int $before, int $after, int $threshold ): ?RegressionFailure {
		if ( $before <= 0 || $after >= $before ) {
			return null;
		}
		$loss     = $before - $after;
		$loss_pct = ( $loss / $before ) * 100;
		// On compare en arrondissant à 2 décimales pour rester aligné avec l'UI.
		$loss_pct_rounded = round( $loss_pct, 2 );
		if ( $loss_pct_rounded <= $threshold ) {
			return null;
		}
		return new RegressionFailure(
			metric_key: $metric_key,
			before: $before,
			after: $after,
			threshold: $threshold,
			unit: RegressionFailure::UNIT_PCT,
			loss: $loss,
			loss_pct: $loss_pct_rounded,
		);
	}

	/**
	 * Vérifie une métrique à seuil absolu. Retourne une `RegressionFailure`
	 * si la perte (before - after) dépasse le seuil, sinon null.
	 *
	 * @param string $metric_key Identifiant de métrique.
	 * @param int    $before     Valeur avant.
	 * @param int    $after      Valeur après.
	 * @param int    $threshold  Seuil absolu (≥ 0).
	 * @return RegressionFailure|null
	 */
	private static function check_absolute( string $metric_key, int $before, int $after, int $threshold ): ?RegressionFailure {
		$loss = $before - $after;
		if ( $loss <= $threshold ) {
			return null;
		}
		return new RegressionFailure(
			metric_key: $metric_key,
			before: $before,
			after: $after,
			threshold: $threshold,
			unit: RegressionFailure::UNIT_ABSOLUTE,
			loss: $loss,
			loss_pct: null,
		);
	}
}
