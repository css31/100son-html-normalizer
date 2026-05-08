<?php
/**
 * Bootstrap PHPUnit du plugin 100son-html-normalizer.
 *
 * Charge l'autoloader Composer et stub les fonctions WordPress strictement
 * nécessaires aux tests unitaires des règles (qui sont indépendantes de WP).
 *
 * Pour les tests d'intégration nécessitant un environnement WordPress réel
 * (PublicApiTest), utiliser un bootstrap dédié à la phase 7+.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SON100_HTMLN_VERSION' ) ) {
	define( 'SON100_HTMLN_VERSION', '0.1.0-test' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub de la fonction `__()` de WordPress.
	 *
	 * @param string $text   Chaîne source.
	 * @param string $domain Text domain (ignoré en test).
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub de `esc_html()` — pour les rares chemins de fallback.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
