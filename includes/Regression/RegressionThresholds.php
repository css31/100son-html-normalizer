<?php
/**
 * RegressionThresholds — DTO immuable des 7 seuils γ.
 *
 * Wraps `SettingsRepository::getRegressionThresholds()` pour offrir un
 * objet typé manipulable par RegressionDetector. La source de vérité reste
 * la constante `SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS` et
 * l'option `son100_htmln_settings.regression_thresholds`.
 *
 * Cf. cahier v2.0 §3.1 F15 (sémantique) et §14 hyp. 24 (defaults).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Regression;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

/**
 * Seuils de régression structurelle, en deux unités selon la métrique :
 *  - `*_loss_pct` (chars, words, paragraphs) : pourcentage entier (≥ 0).
 *    Exemple : 5 → tolère 5 % de perte.
 *  - `*_loss` (images, headings, links, lists) : nombre absolu (≥ 0).
 *    Exemple : 0 → toute perte d'élément déclenche une alerte.
 *
 * `headings_loss` s'applique à chaque niveau h1..h6 indépendamment
 * (cf. cahier §14 hyp. 23 et §3.1 F15).
 */
final class RegressionThresholds {

	/**
	 * @param int $text_loss_pct       Pourcentage de perte tolérée sur `chars`.
	 * @param int $words_loss_pct      Pourcentage de perte tolérée sur `words`.
	 * @param int $paragraphs_loss_pct Pourcentage de perte tolérée sur `paragraphs`.
	 * @param int $headings_loss       Perte absolue tolérée par niveau h1..h6.
	 * @param int $images_loss         Perte absolue tolérée sur `images`.
	 * @param int $links_loss          Perte absolue tolérée sur `links`.
	 * @param int $lists_loss          Perte absolue tolérée sur `lists`.
	 */
	public function __construct(
		public readonly int $text_loss_pct,
		public readonly int $words_loss_pct,
		public readonly int $paragraphs_loss_pct,
		public readonly int $headings_loss,
		public readonly int $images_loss,
		public readonly int $links_loss,
		public readonly int $lists_loss,
	) {}

	/**
	 * Construit un DTO depuis un tableau brut (typé identique à
	 * `SettingsRepository::getRegressionThresholds()`).
	 *
	 * @param array{
	 *   text_loss_pct?: int,
	 *   words_loss_pct?: int,
	 *   paragraphs_loss_pct?: int,
	 *   headings_loss?: int,
	 *   images_loss?: int,
	 *   links_loss?: int,
	 *   lists_loss?: int,
	 * } $data Tableau brut.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$defaults = SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS;
		return new self(
			text_loss_pct: self::sanitize( $data['text_loss_pct'] ?? $defaults['text_loss_pct'] ),
			words_loss_pct: self::sanitize( $data['words_loss_pct'] ?? $defaults['words_loss_pct'] ),
			paragraphs_loss_pct: self::sanitize( $data['paragraphs_loss_pct'] ?? $defaults['paragraphs_loss_pct'] ),
			headings_loss: self::sanitize( $data['headings_loss'] ?? $defaults['headings_loss'] ),
			images_loss: self::sanitize( $data['images_loss'] ?? $defaults['images_loss'] ),
			links_loss: self::sanitize( $data['links_loss'] ?? $defaults['links_loss'] ),
			lists_loss: self::sanitize( $data['lists_loss'] ?? $defaults['lists_loss'] ),
		);
	}

	/**
	 * Construit un DTO en lisant directement les seuils depuis le repository
	 * de settings (raccourci pour le runtime).
	 *
	 * @param SettingsRepository $settings Source des seuils.
	 * @return self
	 */
	public static function from_settings( SettingsRepository $settings ): self {
		return self::from_array( $settings->getRegressionThresholds() );
	}

	/**
	 * DTO avec les valeurs par défaut du cahier (§14 hyp. 24).
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return self::from_array( SettingsRepository::REGRESSION_THRESHOLD_DEFAULTS );
	}

	/**
	 * Retourne une copie avec les seuils `text_loss_pct` et `words_loss_pct`
	 * portés à 100 — i.e. désactive de fait les vérifications de perte
	 * texte/mots. Les seuils structurels (paragraphes, headings, images,
	 * links, lists) sont conservés tels quels.
	 *
	 * Usage : appelé par `StepRunner::process_article` quand le sous-ensemble
	 * de règles à appliquer contient au moins une `LossyRule` (R3, R4) —
	 * ces règles sont délibérément destructives en texte (retraits de
	 * shortcodes / snippets), il ne faut pas qu'elles déclenchent une
	 * fausse régression. Les checks structurels restent en place pour
	 * détecter les erreurs réelles (image perdue, h2 disparu, etc.).
	 *
	 * @return self
	 */
	public function relax_text_checks_for_lossy(): self {
		return new self(
			text_loss_pct: 100,
			words_loss_pct: 100,
			paragraphs_loss_pct: $this->paragraphs_loss_pct,
			headings_loss: $this->headings_loss,
			images_loss: $this->images_loss,
			links_loss: $this->links_loss,
			lists_loss: $this->lists_loss,
		);
	}

	/**
	 * Représentation tableau (clé → entier).
	 *
	 * @return array{
	 *   text_loss_pct: int,
	 *   words_loss_pct: int,
	 *   paragraphs_loss_pct: int,
	 *   headings_loss: int,
	 *   images_loss: int,
	 *   links_loss: int,
	 *   lists_loss: int,
	 * }
	 */
	public function to_array(): array {
		return array(
			'text_loss_pct'       => $this->text_loss_pct,
			'words_loss_pct'      => $this->words_loss_pct,
			'paragraphs_loss_pct' => $this->paragraphs_loss_pct,
			'headings_loss'       => $this->headings_loss,
			'images_loss'         => $this->images_loss,
			'links_loss'          => $this->links_loss,
			'lists_loss'          => $this->lists_loss,
		);
	}

	/**
	 * Coerce une valeur potentiellement scalaire en entier ≥ 0.
	 *
	 * @param mixed $value Valeur brute.
	 * @return int
	 */
	private static function sanitize( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}
		$int = (int) $value;
		return $int < 0 ? 0 : $int;
	}
}
