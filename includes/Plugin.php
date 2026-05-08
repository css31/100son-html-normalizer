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

use Cent_Son\Html_Normalizer\Admin\Menu;
use Cent_Son\Html_Normalizer\Admin\Pages\PresetsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\TesterPage;
use Cent_Son\Html_Normalizer\Api\PublicApi;
use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;

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

		// Composition root : assemblage des dépendances Core.
		$settings        = new SettingsRepository();
		$preset_registry = new PresetRegistry( $settings );
		$pipeline        = new Pipeline();
		$normalizer      = new HtmlNormalizer( $preset_registry, $pipeline );

		// API publique : branche le filtre `htmln/normalize`.
		$public_api = new PublicApi( $normalizer );
		$public_api->register();

		// UI admin minimale V0.1 (PHP classique, pas SPA — phase 15 §11 ultérieure).
		if ( is_admin() ) {
			$presets_page = new PresetsPage( $settings, $preset_registry );
			$tester_page  = new TesterPage( $normalizer );
			$menu         = new Menu( $presets_page, $tester_page );
			$menu->register();
		}

		// (Phases ultérieures cf. cahier §11 :
		//  - étapes 8+ : REST, CLI, F4 (UserRules + Validator + Preview),
		//                F5 (HeadingStrategist), F7 (RulesIo), F8 (PostsController),
		//                15 (SPA React).)
	}
}
