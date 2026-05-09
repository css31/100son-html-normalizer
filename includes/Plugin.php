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
use Cent_Son\Html_Normalizer\Admin\Pages\LogsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PostsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PresetsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\TesterPage;
use Cent_Son\Html_Normalizer\Api\PublicApi;
use Cent_Son\Html_Normalizer\Core\HtmlNormalizer;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticInvalidator;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Core\Logs\Logger;
use Cent_Son\Html_Normalizer\Core\Logs\LogRepository;
use Cent_Son\Html_Normalizer\Core\Logs\NotesRepository;
use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Posts\PostNormalizer;
use Cent_Son\Html_Normalizer\Core\Posts\SiteOriginDetector;
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

		// V1.0 — Invalidation diagnostic au save_post (Phase 3.4).
		$diagnostics_repo    = new DiagnosticsRepository();
		$diagnostic_invalida = new DiagnosticInvalidator( $diagnostics_repo, $settings );
		$diagnostic_invalida->register();

		// UI admin minimale V0.1 (PHP classique, pas SPA — phase 15 §11 ultérieure).
		if ( is_admin() ) {
			$log_repo        = new LogRepository();
			$notes_repo      = new NotesRepository();
			$logger          = new Logger( $log_repo );
			$so_detector     = new SiteOriginDetector();
			$post_normalizer = new PostNormalizer( $normalizer, $so_detector, $logger );

			$presets_page = new PresetsPage( $settings, $preset_registry, $logger );
			$tester_page  = new TesterPage( $normalizer );
			$posts_page   = new PostsPage( $settings, $so_detector, $post_normalizer );
			$logs_page    = new LogsPage( $log_repo, $notes_repo );

			$menu = new Menu( $presets_page, $tester_page, $posts_page, $logs_page );
			$menu->register();
		}

		// (Phases ultérieures cf. cahier §11 :
		// - étapes 8+ : REST, CLI, F4 (UserRules + Validator + Preview),
		// F5 (HeadingStrategist), F7 (RulesIo), F8 (PostsController),
		// 15 (SPA React).)
	}
}
