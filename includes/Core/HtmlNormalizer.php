<?php
/**
 * HtmlNormalizer — orchestrateur principal du moteur de normalisation.
 *
 * Compose les presets actives + la strategie hn (F5, V1+) + les regles custom
 * utilisateur (F4, V1+) en une seule pipeline. Expose le filtre WP
 * `htmln/normalize` (cf. PublicApi).
 *
 * Garde-fous :
 *  - Le retour est TOUJOURS une string (jamais null, false, throw).
 *  - En cas d'erreur interne d'une regle, le HTML d'entree est preserve.
 *  - Les actions `htmln/before_normalize` et `htmln/after_normalize` sont
 *    declenchees autour de la pipeline (cf. cahier 4.4 et 4.1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;

/**
 * Orchestrateur du moteur de normalisation HTML.
 */
final class HtmlNormalizer {

	/**
	 * Registry des presets.
	 *
	 * @var PresetRegistry
	 */
	private PresetRegistry $preset_registry;

	/**
	 * Pipeline d'application des regles.
	 *
	 * @var Pipeline
	 */
	private Pipeline $pipeline;

	/**
	 * Constructor.
	 *
	 * @param PresetRegistry $preset_registry Registry.
	 * @param Pipeline       $pipeline        Pipeline.
	 */
	public function __construct( PresetRegistry $preset_registry, Pipeline $pipeline ) {
		$this->preset_registry = $preset_registry;
		$this->pipeline        = $pipeline;
	}

	/**
	 * Normalise un fragment HTML selon la config du site.
	 *
	 * Ordre :
	 *  1. action `htmln/before_normalize`
	 *  2. presets actives (ordre PresetRegistry::PRESETS)
	 *  3. strategie hn (F5, V1+)
	 *  4. regles custom user (F4, V1+ — alphabetique label)
	 *  5. action `htmln/after_normalize`
	 *
	 * Garantit string en sortie quoi qu'il arrive.
	 *
	 * @param string               $html    HTML d'entree.
	 * @param array<string, mixed> $context Contexte d'appel (cf. hyp. 20).
	 * @return string HTML normalise.
	 */
	public function normalize( string $html, array $context = array() ): string {
		// Garde-fou contre les types non-string (defensif au-dela du type-hint).
		if ( '' === $html ) {
			return '';
		}

		try {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'htmln/before_normalize', $html, $context );
			}

			$rules    = $this->preset_registry->get_enabled_rules();
			$warnings = array();
			$out      = $this->pipeline->run( $rules, $html, $context, $warnings );

			// V1+ : ici viendront F5 (HeadingStrategist) puis F4 (UserRulesRepository).

			if ( function_exists( 'do_action' ) ) {
				do_action( 'htmln/after_normalize', $html, $out, $context, $warnings );
			}

			return $out;
		} catch ( \Throwable $e ) {
			// Defensive : en cas de defaillance non interceptee, retourne l'input.
			return $html;
		}
	}
}
