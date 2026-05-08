<?php
/**
 * Plugin Name:       100son HTML Normalizer
 * Plugin URI:        https://100son.net/plugins/100son-html-normalizer
 * Description:       Moteur de normalisation HTML configurable pour WordPress — 8 présets + règles custom + API publique htmln/normalize.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            Cyrille / 100son.net
 * Author URI:        https://100son.net
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       100son-html-normalizer
 * Domain Path:       /languages
 * Update URI:        false
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Constantes du plugin (cf. cahier §9 — toutes obligatoires).
 * ---------------------------------------------------------------------- */

define( 'SON100_HTMLN_VERSION', '0.1.0' );
define( 'SON100_HTMLN_FILE',    __FILE__ );
define( 'SON100_HTMLN_PATH',    plugin_dir_path( __FILE__ ) );
define( 'SON100_HTMLN_URL',     plugin_dir_url( __FILE__ ) );
define( 'SON100_HTMLN_MIN_PHP', '8.3' );
define( 'SON100_HTMLN_MIN_WP',  '6.8' );

/* -------------------------------------------------------------------------
 * Garde-fous environnement (PHP / WP) avant tout chargement.
 * ---------------------------------------------------------------------- */

if ( version_compare( PHP_VERSION, SON100_HTMLN_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( '100son HTML Normalizer requiert PHP %1$s ou plus récent. Version actuelle : %2$s.', '100son-html-normalizer' ),
						SON100_HTMLN_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, SON100_HTMLN_MIN_WP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () use ( $wp_version ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required WP version, 2: current WP version */
						__( '100son HTML Normalizer requiert WordPress %1$s ou plus récent. Version actuelle : %2$s.', '100son-html-normalizer' ),
						SON100_HTMLN_MIN_WP,
						$wp_version
					)
				)
			);
		}
	);
	return;
}

/* -------------------------------------------------------------------------
 * Autoloader Composer.
 * ---------------------------------------------------------------------- */

$son100_htmln_autoload = SON100_HTMLN_PATH . 'vendor/autoload.php';
if ( ! is_readable( $son100_htmln_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__(
					'100son HTML Normalizer : autoload Composer manquant. Exécuter `composer install` dans le dossier du plugin.',
					'100son-html-normalizer'
				)
			);
		}
	);
	return;
}
require_once $son100_htmln_autoload;

/* -------------------------------------------------------------------------
 * Activation / désactivation / désinstallation.
 * ---------------------------------------------------------------------- */

register_activation_hook( SON100_HTMLN_FILE, [ \Cent_Son\Html_Normalizer\Activator::class, 'activate' ] );
register_deactivation_hook( SON100_HTMLN_FILE, [ \Cent_Son\Html_Normalizer\Deactivator::class, 'deactivate' ] );
// Désinstallation : voir uninstall.php (point d'entrée Core WP).

/* -------------------------------------------------------------------------
 * Bootstrap orchestrateur.
 * ---------------------------------------------------------------------- */

add_action(
	'plugins_loaded',
	static function (): void {
		\Cent_Son\Html_Normalizer\Plugin::instance()->boot();
	},
	10
);

/* -------------------------------------------------------------------------
 * Text domain (chargé sur init, requis WP 6.7+).
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain(
			'100son-html-normalizer',
			false,
			dirname( plugin_basename( SON100_HTMLN_FILE ) ) . '/languages'
		);
	}
);
