<?php
/**
 * Singleton bootstrap du plugin.
 *
 * Orchestrateur central, instancié sur `plugins_loaded`. Charge les
 * sous-systèmes dans l'ordre attendu (REST, CLI, Admin, API publique).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
final class Plugin {

	/**
	 * Instance singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Indique si le plugin a déjà été démarré.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Empêche l'instanciation directe.
	 */
	private function __construct() {}

	/**
	 * Empêche la duplication via clone.
	 */
	private function __clone() {}

	/**
	 * Récupère (ou crée) l'instance singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Démarre les sous-systèmes du plugin (idempotent).
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Sous-systèmes — chargés conditionnellement selon le contexte.
		// (Phases ultérieures : REST, CLI, Admin, PublicApi.)
		// En l'état (étapes 1-4 du §11), seul le PublicApi est attendu mais
		// arrive dans une étape suivante. Bootstrap minimal pour l'instant.
	}
}
