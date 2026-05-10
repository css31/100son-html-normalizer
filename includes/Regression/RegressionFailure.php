<?php
/**
 * RegressionFailure — DTO d'une métrique en dépassement de seuil.
 *
 * Cf. cahier v2.0 §3.1 F15 (modale "Régression détectée").
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Regression;

defined( 'ABSPATH' ) || exit;

/**
 * Représente une métrique précise dont la perte avant→après dépasse le
 * seuil γ correspondant.
 *
 * `metric_key` est un identifiant stable utilisé par la SPA pour traduire
 * un message localisé. Cas `headings` : la clé porte le niveau, par
 * exemple `headings.h2`, pour respecter le « seuil par niveau » de §14
 * hyp. 23.
 *
 * `unit` distingue les deux types de seuils :
 *  - `'pct'`      : seuil exprimé en pourcentage de perte (chars/words/paragraphs).
 *  - `'absolute'` : seuil exprimé en nombre d'éléments perdus (images/links/lists/headings).
 */
final class RegressionFailure {

	public const UNIT_PCT      = 'pct';
	public const UNIT_ABSOLUTE = 'absolute';

	/**
	 * @param string     $metric_key Identifiant stable (ex. `images`, `headings.h2`, `chars`).
	 * @param int        $before     Valeur de la métrique avant.
	 * @param int        $after      Valeur de la métrique après.
	 * @param int        $threshold  Seuil utilisé (entier ≥ 0).
	 * @param string     $unit       `'pct'` ou `'absolute'`.
	 * @param int        $loss       Perte absolue (max(before - after, 0)).
	 * @param float|null $loss_pct   Perte en pourcentage (null si `unit === 'absolute'`).
	 */
	public function __construct(
		public readonly string $metric_key,
		public readonly int $before,
		public readonly int $after,
		public readonly int $threshold,
		public readonly string $unit,
		public readonly int $loss,
		public readonly ?float $loss_pct,
	) {}

	/**
	 * Représentation tableau (sérialisable JSON pour `son100_htmln_steps.per_article_results`).
	 *
	 * @return array{
	 *   metric_key: string,
	 *   before: int,
	 *   after: int,
	 *   threshold: int,
	 *   unit: string,
	 *   loss: int,
	 *   loss_pct: float|null,
	 *   exceeded: true,
	 * }
	 */
	public function to_array(): array {
		return array(
			'metric_key' => $this->metric_key,
			'before'     => $this->before,
			'after'      => $this->after,
			'threshold'  => $this->threshold,
			'unit'       => $this->unit,
			'loss'       => $this->loss,
			'loss_pct'   => $this->loss_pct,
			'exceeded'   => true,
		);
	}

	/**
	 * Reconstruit une RegressionFailure depuis sa représentation `to_array()`.
	 *
	 * Tolérant : les champs manquants prennent des valeurs par défaut sûres
	 * (`unit` invalide → `absolute`, `loss_pct` non numérique → null). Sert
	 * notamment à `StepRunner::confirm_article()` et `refuse_article()` pour
	 * rejouer un rapport persisté en base.
	 *
	 * @param array<string, mixed> $data Données issues de `to_array()` ou JSON décodé.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$unit_raw = isset( $data['unit'] ) ? (string) $data['unit'] : self::UNIT_ABSOLUTE;
		$unit     = self::UNIT_PCT === $unit_raw ? self::UNIT_PCT : self::UNIT_ABSOLUTE;

		$loss_pct = null;
		if ( isset( $data['loss_pct'] ) && is_numeric( $data['loss_pct'] ) ) {
			$loss_pct = (float) $data['loss_pct'];
		}

		return new self(
			metric_key: isset( $data['metric_key'] ) ? (string) $data['metric_key'] : '',
			before: isset( $data['before'] ) ? (int) $data['before'] : 0,
			after: isset( $data['after'] ) ? (int) $data['after'] : 0,
			threshold: isset( $data['threshold'] ) ? (int) $data['threshold'] : 0,
			unit: $unit,
			loss: isset( $data['loss'] ) ? (int) $data['loss'] : 0,
			loss_pct: $loss_pct,
		);
	}
}
