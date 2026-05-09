<?php
/**
 * PublicApi — facade publique exposee aux autres plugins.
 *
 * Branche le filtre WordPress `htmln/normalize` sur HtmlNormalizer::normalize().
 * Signature stable du filtre (cf. cahier 4.1, 8 F6, 13) :
 *
 *   apply_filters( 'htmln/normalize', string $html, array $context = [] ): string
 *
 * Garanties :
 *  - Toujours retourne une string (jamais null/false/throw — comportement defensif).
 *  - Si Normalizer est desactive : le filtre n'est pas branche, le consommateur
 *    recoit le HTML d'entree inchange.
 *  - 2e argument $context : passe-plat pour signaler la source d'appel
 *    (ex: ['source' => 'so-to-blocks', 'widget' => 'editor']).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Api;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;

/**
 * Facade publique du plugin.
 */
final class PublicApi {

	/**
	 * Orchestrateur central.
	 *
	 * @var HtmlNormalizer
	 */
	private HtmlNormalizer $normalizer;

	/**
	 * Constructor.
	 *
	 * @param HtmlNormalizer $normalizer Orchestrateur.
	 */
	public function __construct( HtmlNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Branche les filtres et actions WP.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}
		add_filter( 'htmln/normalize', array( $this, 'on_filter_normalize' ), 10, 2 );
	}

	/**
	 * Callback du filtre `htmln/normalize`.
	 *
	 * @param mixed $html    HTML d'entree (devrait etre une string).
	 * @param mixed $context Contexte (devrait etre un tableau).
	 * @return string HTML normalise (toujours une string, jamais null/false/throw).
	 */
	public function on_filter_normalize( mixed $html, mixed $context = array() ): string {
		// Garde-fou type : si l'appelant passe autre chose qu'une string, retourner ''.
		if ( ! is_string( $html ) ) {
			return '';
		}
		$ctx = is_array( $context ) ? $context : array();

		try {
			$result = $this->normalizer->normalize( $html, $ctx );
			return is_string( $result ) ? $result : $html;
		} catch ( \Throwable $e ) {
			// Defensive : ne propage jamais d'exception au consommateur.
			return $html;
		}
	}
}
