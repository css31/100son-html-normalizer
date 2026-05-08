<?php
/**
 * P5 — ExcessiveBrRule.
 *
 * Collapse les <br> consecutifs (>= seuil, defaut 2) en </p><p>.
 * Le <p> vide eventuellement produit sera ramasse par P1 en fin de pipeline.
 *
 * Cf. cahier section 3.1 F2.P5 et section 8 F2.P5.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Preset P5 : collapse des <br> excessifs.
 */
final class ExcessiveBrRule implements RuleInterface {

	/**
	 * Seuil minimum de <br> consecutifs declenchant le collapse.
	 *
	 * @var int
	 */
	private int $threshold;

	/**
	 * Constructor.
	 *
	 * @param int $threshold Seuil (>= 2).
	 */
	public function __construct( int $threshold = 2 ) {
		$this->threshold = max( 2, $threshold );
	}

	/**
	 * Identifiant stable.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'P5';
	}

	/**
	 * Libelle humain.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( '<br> excessifs', '100son-html-normalizer' );
	}

	/**
	 * Applique la regle.
	 *
	 * @param string               $html    HTML d'entree.
	 * @param array<string, mixed> $context Contexte d'appel.
	 * @return string
	 */
	public function apply( string $html, array $context = [] ): string {
		if ( '' === $html ) {
			return $html;
		}

		$pattern = '#(?:<br\b[^>]*>\s*)' . '{' . $this->threshold . ',}#i';
		$result  = preg_replace( $pattern, '</p><p>', $html );
		return $result ?? $html;
	}

	/**
	 * Expose le seuil courant (utile pour debug / introspection).
	 *
	 * @return int
	 */
	public function get_threshold(): int {
		return $this->threshold;
	}
}
