<?php
/**
 * Activation du plugin.
 *
 * Seed des options par défaut (cf. cahier §11 étape 1).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Hook d'activation.
 */
final class Activator {

	/**
	 * Exécuté à l'activation du plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::seed_settings();
		self::seed_presets();
		self::seed_user_rules();

		update_option( 'son100_htmln_db_version', SON100_HTMLN_VERSION, false );
	}

	/**
	 * Initialise les réglages globaux si l'option est absente.
	 *
	 * Cf. §4.2 du cahier (modèle de données).
	 *
	 * @return void
	 */
	private static function seed_settings(): void {
		if ( false !== get_option( 'son100_htmln_settings', false ) ) {
			return;
		}

		$defaults = array(
			'f8_post_types_selection' => array( 'post' ),
		);

		add_option( 'son100_htmln_settings', $defaults, '', 'no' );
	}

	/**
	 * Initialise la configuration des préréglages si l'option est absente.
	 *
	 * Tous les préréglages sont activés par défaut, avec leurs paramètres au
	 * défaut documenté en §3.1 et §14.
	 *
	 * @return void
	 */
	private static function seed_presets(): void {
		if ( false !== get_option( 'son100_htmln_presets', false ) ) {
			return;
		}

		$defaults = array(
			'P1' => array( 'enabled' => true ),
			'P2' => array( 'enabled' => true ),
			'P3' => array( 'enabled' => true ),
			'P4' => array( 'enabled' => true ),
			'P5' => array(
				'enabled'   => true,
				'threshold' => 2,
			),
			'P6' => array(
				'enabled'         => true,
				'keep_text_align' => true,
			),
			'P7' => array(
				'enabled'        => true,
				'threshold'      => 2,
				'markers'        => array(
					'dash'    => true,
					'emdash'  => true,
					'asterix' => true,
					'bullet'  => true,
					'numeric' => true,
				),
				'custom_markers' => array(),
			),
			'P8' => array(
				'enabled'  => true,
				'mappings' => array(
					'bold'   => true,
					'italic' => true,
				),
			),
		);

		add_option( 'son100_htmln_presets', $defaults, '', 'no' );
	}

	/**
	 * Initialise une bibliothèque de règles custom vide.
	 *
	 * @return void
	 */
	private static function seed_user_rules(): void {
		if ( false !== get_option( 'son100_htmln_rules_user', false ) ) {
			return;
		}
		add_option( 'son100_htmln_rules_user', array(), '', 'no' );
	}
}
