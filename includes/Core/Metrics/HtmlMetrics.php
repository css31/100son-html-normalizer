<?php
/**
 * HtmlMetrics — calcul et comparaison de metriques sur un fragment HTML.
 *
 * 3 metriques :
 *  - word_count   : nombre de mots dans le texte effectif (apres strip_tags + entites decodees)
 *  - char_count   : nombre de caracteres du texte effectif
 *  - image_count  : nombre de balises <img> presentes
 *
 * Severite des changements (compare) :
 *  - 'ok'        : pertes negligeables (mots < 10% ET images >= 0)
 *  - 'warning'   : mots >= 10% perdus OU 1+ image perdue
 *  - 'critical'  : mots >= 30% perdus OU 2+ images perdues
 *
 * Le calcul est volontairement leger : strip_tags + str_word_count + substr_count
 * sont O(N) sur la longueur du fragment, sans parsing DOM.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Metrics;

defined( 'ABSPATH' ) || exit;

/**
 * Helper de metriques HTML.
 */
final class HtmlMetrics {

	public const SEVERITY_OK       = 'ok';
	public const SEVERITY_WARNING  = 'warning';
	public const SEVERITY_CRITICAL = 'critical';

	private const WORD_WARNING_PCT  = 10.0;
	private const WORD_CRITICAL_PCT = 30.0;

	/**
	 * Calcule les metriques d'un fragment.
	 *
	 * @param string $html HTML.
	 * @return array{word_count: int, char_count: int, image_count: int}
	 */
	public static function compute( string $html ): array {
		// Decode entities (e.g., &nbsp;) puis strip tags pour avoir le texte effectif.
		$text = (string) html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Normalise les NBSP en espaces ordinaires pour le compte de mots.
		$text = str_replace( "\xc2\xa0", ' ', $text );
		$text_trimmed = trim( $text );

		return [
			'word_count'  => '' === $text_trimmed ? 0 : self::count_words_utf8( $text_trimmed ),
			'char_count'  => self::strlen_utf8( $text_trimmed ),
			'image_count' => self::count_images( $html ),
		];
	}

	/**
	 * Compare deux jeux de metriques (avant -> apres).
	 *
	 * @param array{word_count: int, char_count: int, image_count: int} $before Avant.
	 * @param array{word_count: int, char_count: int, image_count: int} $after  Apres.
	 * @return array{
	 *     word_delta: int,
	 *     word_pct: float,
	 *     char_delta: int,
	 *     char_pct: float,
	 *     image_delta: int,
	 *     severity: string
	 * } word_pct et char_pct sont des pourcentages negatifs si perte (ex: -12.5).
	 */
	public static function compare( array $before, array $after ): array {
		$word_delta  = (int) $after['word_count'] - (int) $before['word_count'];
		$word_pct    = $before['word_count'] > 0
			? ( $word_delta / (int) $before['word_count'] ) * 100
			: 0.0;
		$char_delta  = (int) $after['char_count'] - (int) $before['char_count'];
		$char_pct    = $before['char_count'] > 0
			? ( $char_delta / (int) $before['char_count'] ) * 100
			: 0.0;
		$image_delta = (int) $after['image_count'] - (int) $before['image_count'];

		return [
			'word_delta'  => $word_delta,
			'word_pct'    => round( $word_pct, 2 ),
			'char_delta'  => $char_delta,
			'char_pct'    => round( $char_pct, 2 ),
			'image_delta' => $image_delta,
			'severity'    => self::compute_severity( $word_pct, $image_delta ),
		];
	}

	/**
	 * Determine le niveau de severite selon les seuils.
	 *
	 * @param float $word_pct    Pourcentage de variation des mots (negatif si perte).
	 * @param int   $image_delta Variation du nombre d'images (negatif si perte).
	 * @return string SEVERITY_*.
	 */
	private static function compute_severity( float $word_pct, int $image_delta ): string {
		// Critique : >= 30% de mots perdus OU 2+ images perdues.
		if ( $word_pct <= -self::WORD_CRITICAL_PCT || $image_delta <= -2 ) {
			return self::SEVERITY_CRITICAL;
		}
		// Warning : >= 10% de mots perdus OU 1 image perdue.
		if ( $word_pct <= -self::WORD_WARNING_PCT || $image_delta <= -1 ) {
			return self::SEVERITY_WARNING;
		}
		return self::SEVERITY_OK;
	}

	/**
	 * Compte les mots UTF-8 (str_word_count est limite ASCII).
	 *
	 * @param string $text Texte.
	 * @return int
	 */
	private static function count_words_utf8( string $text ): int {
		// Coupe sur les sequences de blancs/ponctuation, garde les sequences alphanumeriques unicode.
		$count = preg_match_all( '/[\p{L}\p{N}]+/u', $text );
		return false === $count ? 0 : (int) $count;
	}

	/**
	 * Longueur en caracteres (multibyte si possible, sinon strlen).
	 *
	 * @param string $text Texte.
	 * @return int
	 */
	private static function strlen_utf8( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Compte les balises `<img ...>` dans un fragment HTML.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	private static function count_images( string $html ): int {
		$count = preg_match_all( '/<img\b/i', $html );
		return false === $count ? 0 : (int) $count;
	}
}
