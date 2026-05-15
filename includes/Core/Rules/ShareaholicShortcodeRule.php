<?php
/**
 * R3 — ShareaholicShortcodeRule.
 *
 * Supprime tout shortcode `[shareaholic ...]` du HTML, en préservant les
 * autres shortcodes (notamment WP natifs ou tiers).
 *
 * Cf. cahier §3.1 F2.R3 et §8 F2.R3.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Règle R3 : suppression du shortcode Shareaholic.
 *
 * Marquée `LossyRule` : retire physiquement le shortcode du HTML, ce qui
 * réduit `chars` et `words` mesurés par `MetricsCalculator`. Sans le
 * marker, le seuil `text_loss_pct = 0` (default) ferait passer tout
 * article touché par R3 en `regression_pending` — voir CHANGELOG
 * « Auto-désactivation des règles épuisées ».
 */
final class ShareaholicShortcodeRule implements RuleInterface, LossyRule {

	/**
	 * Pattern de shortcode `[shareaholic ...]`.
	 *
	 * Forme self-closed uniquement (la forme bloc avec `[/shareaholic]` n'a
	 * pas été observée sur le corpus MMM ; couvrir cette forme avec une regex
	 * lazy serait catastrophique car il n'y a souvent qu'un seul `[/shareaholic]`
	 * dans tout le document, ce qui ferait engloutir le reste du contenu).
	 *
	 * - Couvre attributs avec ou sans guillemets via `[^\]]*`.
	 * - Insensible à la casse.
	 * - Pas de quantificateur imbriqué.
	 */
	private const PATTERN_SELF_CLOSED = '/\[shareaholic\b[^\]]*\]/i';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'R3';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return __( 'Shortcodes Shareaholic', '100son-html-normalizer' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function apply( string $html, array $context = array() ): string {
		if ( '' === $html ) {
			return $html;
		}
		$result = preg_replace( self::PATTERN_SELF_CLOSED, '', $html );
		return $result ?? $html;
	}

	/**
	 * {@inheritDoc}
	 */
	public function countMatches( string $html, array $context = array() ): int {
		if ( '' === $html ) {
			return 0;
		}
		$count = preg_match_all( self::PATTERN_SELF_CLOSED, $html );
		return false === $count ? 0 : $count;
	}
}
