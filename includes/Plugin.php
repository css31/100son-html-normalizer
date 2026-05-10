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

use Cent_Son\Html_Normalizer\Admin\Assets;
use Cent_Son\Html_Normalizer\Admin\Menu;
use Cent_Son\Html_Normalizer\Admin\Pages\LogsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PostsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\PresetsPage;
use Cent_Son\Html_Normalizer\Admin\Pages\SpaPage;
use Cent_Son\Html_Normalizer\Admin\Pages\TesterPage;
use Cent_Son\Html_Normalizer\Api\PublicApi;
use Cent_Son\Html_Normalizer\Cli\CliServiceProvider;
use Cent_Son\Html_Normalizer\Cli\DiagnoseCommand;
use Cent_Son\Html_Normalizer\Cli\StepsCommand;
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
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticBatchRunner;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Rest\DiagnosticsController;
use Cent_Son\Html_Normalizer\Rest\DiffController;
use Cent_Son\Html_Normalizer\Rest\PostsController;
use Cent_Son\Html_Normalizer\Rest\RestServiceProvider;
use Cent_Son\Html_Normalizer\Rest\StepsController;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;

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

		// V1.0 — Couche REST (Phase 5+). La liste des contrôleurs s'étoffe
		// au fil des sous-commits Phase 5 (Steps, Diagnostics, Posts/Diff).
		// Le provider branche un seul hook `rest_api_init` qui itérera sur
		// la liste, donc ajouter un contrôleur en Phase 5.x = simple
		// extension du tableau ci-dessous, sans modification du provider.
		$rest_provider = new RestServiceProvider( $this->build_rest_controllers() );
		$rest_provider->register();

		// V1.0 — Couche WP-CLI (Phase 5.5). Le provider est no-op si la
		// classe `WP_CLI` n'existe pas (contexte non-CLI), donc on appelle
		// register() inconditionnellement plutôt que de tester
		// `defined('WP_CLI')` ici — délègue la responsabilité au provider.
		$cli_provider = new CliServiceProvider( $this->build_cli_commands() );
		$cli_provider->register();

		// UI admin V0.1 (pages PHP classiques) + SPA V1.0 (Phase 6.1) en
		// cohabitation. La SPA est ajoutée comme sous-page « Normaliser V1 »
		// — la migration complète des pages V0.1 vers la SPA est différée V1.1.
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
			$spa_page     = new SpaPage();

			$menu = new Menu( $presets_page, $tester_page, $posts_page, $logs_page, $spa_page );
			$menu->register();

			// Enqueue scope-restreint du bundle SPA — uniquement sur la page
			// `Normaliser V1` (cf. §13 « ne pas charger d'assets globalement
			// sur toutes les pages admin »).
			$assets = new Assets();
			$assets->register();
		}
	}

	/**
	 * Construit la liste des contrôleurs REST V1.0 actifs.
	 *
	 * Méthode séparée de `boot()` pour faciliter l'extension Phase 5.x :
	 * chaque sous-commit (5.2 Steps, 5.3 Diagnostics, 5.4 Posts/Diff)
	 * injecte ses contrôleurs ici sans toucher au cycle de boot.
	 *
	 * Phase 5.2 : StepsController.
	 * Phase 5.3 : DiagnosticsController.
	 * Phase 5.4 : PostsController + DiffController.
	 *
	 * @return list<\Cent_Son\Html_Normalizer\Rest\BaseController>
	 */
	private function build_rest_controllers(): array {
		$settings        = new SettingsRepository();
		$preset_registry = new PresetRegistry( $settings );
		$pipeline        = new Pipeline();
		$metrics         = new MetricsCalculator();
		$normalizer      = new HtmlNormalizer( $preset_registry, $pipeline );
		$so_detector     = new SiteOriginDetector();
		$post_normalizer = new PostNormalizer( $normalizer, $so_detector );
		$engine          = new DiagnosticEngine( $preset_registry, $metrics );
		$diag_repo       = new DiagnosticsRepository();
		$batch_runner    = new DiagnosticBatchRunner( $engine, $diag_repo, $settings );

		return array(
			new StepsController(
				self::make_step_runner(),
				new StepsRepository(),
			),
			new DiagnosticsController( $batch_runner, $diag_repo ),
			new PostsController( $settings, $post_normalizer, $so_detector ),
			new DiffController( $preset_registry, $pipeline, $metrics ),
		);
	}

	/**
	 * Construit la liste des commandes WP-CLI V1.0.
	 *
	 * Phase 5.5 : StepsCommand (`htmln steps {list, show, export}`) +
	 * DiagnoseCommand (`htmln scan`).
	 *
	 * Pour `steps list`, on ne peut pas utiliser une méthode `list()`
	 * directement car `list` est un mot réservé en PHP — on enregistre
	 * `list_steps` sous le nom WP-CLI `htmln steps list`.
	 *
	 * @return list<array{name: string, callable: callable}>
	 */
	private function build_cli_commands(): array {
		$settings        = new SettingsRepository();
		$preset_registry = new PresetRegistry( $settings );
		$metrics         = new MetricsCalculator();
		$engine          = new DiagnosticEngine( $preset_registry, $metrics );
		$diag_repo       = new DiagnosticsRepository();
		$batch_runner    = new DiagnosticBatchRunner( $engine, $diag_repo, $settings );

		$steps_cmd     = new StepsCommand( self::make_step_runner(), new StepsRepository() );
		$diagnose_cmd  = new DiagnoseCommand( $batch_runner, $engine, $diag_repo );

		return array(
			array( 'name' => 'htmln steps list',   'callable' => array( $steps_cmd, 'list_steps' ) ),
			array( 'name' => 'htmln steps show',   'callable' => array( $steps_cmd, 'show' ) ),
			array( 'name' => 'htmln steps export', 'callable' => array( $steps_cmd, 'export' ) ),
			array( 'name' => 'htmln scan',          'callable' => $diagnose_cmd ),
		);
	}

	/**
	 * Construit un `StepRunner` câblé avec ses 8 dépendances (composition root
	 * de F14 — application par pas).
	 *
	 * Volontairement statique et stateless : chaque appel instancie une nouvelle
	 * grappe complète. Les services injectés sont tous stateless (pas d'état
	 * partagé entre requêtes), donc ré-instancier à chaque request REST/CLI
	 * (Phase 5) ne pose aucun problème de cohérence et simplifie les tests.
	 *
	 * Utilisé par :
	 *  - les futurs `StepsController` (REST) et `StepsCommand` (CLI) en Phase 5 ;
	 *  - les tests d'intégration `StepRunnerIntegrationTest` qui valident
	 *    l'assemblage bout-en-bout sur un mini pas réel.
	 *
	 * @return StepRunner StepRunner prêt à recevoir start_step / process_article /
	 *                    confirm_article / refuse_article / resume_progress /
	 *                    finalize_step.
	 */
	public static function make_step_runner(): StepRunner {
		$settings        = new SettingsRepository();
		$preset_registry = new PresetRegistry( $settings );
		$pipeline        = new Pipeline();
		$metrics         = new MetricsCalculator();

		return new StepRunner(
			new StepsRepository(),
			new DiagnosticsRepository(),
			$preset_registry,
			$pipeline,
			$metrics,
			new RegressionDetector(),
			new DiagnosticEngine( $preset_registry, $metrics ),
			$settings,
		);
	}
}
