<?php
/**
 * Désactivation du plugin.
 *
 * Cf. cahier §12 : la désactivation est un no-op (pas de transients,
 * pas de WP-Cron). Les données restent intactes.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Hook de désactivation.
 */
final class Deactivator {

	/**
	 * Exécuté à la désactivation du plugin.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// No-op intentionnel (cf. cahier §12).
	}
}
