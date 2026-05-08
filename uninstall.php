<?php
/**
 * Désinstallation du plugin 100son HTML Normalizer.
 *
 * Purge totale des options. La bibliothèque de règles custom est perdue —
 * cf. cahier §12 : le README explique qu'il faut exporter en .json avant.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$son100_htmln_options = [
	'son100_htmln_settings',
	'son100_htmln_presets',
	'son100_htmln_rules_user',
	'son100_htmln_logs',
	'son100_htmln_logs_notes',
	'son100_htmln_db_version',
];

foreach ( $son100_htmln_options as $son100_htmln_option ) {
	delete_option( $son100_htmln_option );
}

unset( $son100_htmln_option, $son100_htmln_options );
