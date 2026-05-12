<?php
/**
 * Désinstallation du plugin 100son HTML Normalizer.
 *
 * Purge totale des options + tables custom V1.0 + post-meta plantées.
 * La bibliothèque de règles custom est perdue — cf. cahier v2.0 §12 :
 * le README explique qu'il faut exporter en .json avant.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// 1. Options du plugin.
$son100_htmln_options = array(
	'son100_htmln_settings',
	'son100_htmln_presets',
	'son100_htmln_rules_user',
	'son100_htmln_logs',
	'son100_htmln_logs_notes',
	'son100_htmln_notes_rich',
	'son100_htmln_db_version',
);

foreach ( $son100_htmln_options as $son100_htmln_option ) {
	delete_option( $son100_htmln_option );
}

unset( $son100_htmln_option, $son100_htmln_options );

// 2. Post-meta plantées par le plugin.
//    - tag Out manuel sur la page Normaliser (V0.1 + maintenu V1.0)
//    - vérification manuelle requise après refus de régression (V1.0 F15)
//    - éventuelles meta de diagnostic transitoires (V1.0 — purge défensive)
delete_post_meta_by_key( '_son100_htmln_builder_override' );
delete_post_meta_by_key( '_son100_htmln_manual_check_required' );

// Préfixe `_son100_htmln_diagnostic_*` : pas de wildcard côté API WP, on
// passe par une requête SQL préparée.
$son100_htmln_diag_meta_prefix = '_son100_htmln_diagnostic_';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( $son100_htmln_diag_meta_prefix ) . '%'
	)
);
unset( $son100_htmln_diag_meta_prefix );

// 3. Tables custom V1.0.
//    `dbDelta()` ne sait pas DROP — on émet le SQL manuellement.
$son100_htmln_diag_table  = $wpdb->prefix . 'son100_htmln_diagnostics';
$son100_htmln_steps_table = $wpdb->prefix . 'son100_htmln_steps';

$wpdb->query( "DROP TABLE IF EXISTS `$son100_htmln_diag_table`" );
$wpdb->query( "DROP TABLE IF EXISTS `$son100_htmln_steps_table`" );

unset( $son100_htmln_diag_table, $son100_htmln_steps_table );
