<?php
/**
 * StepRunner — orchestre l'application d'un pas (F14).
 *
 * Cf. cahier v2.0 §4.4.2 (pipeline étendu d'un pas) et §11 étape StepRunner.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Pipeline;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticEngine;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Metrics\MetricsCalculator;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Regression\RegressionThresholds;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_Error;
use WP_Post;

/**
 * Orchestrateur central de l'application par pas (F14).
 *
 * Répartition des responsabilités :
 *  - **Boucle d'articles** : assurée par la SPA (chunking REST séquentiel).
 *    StepRunner expose des opérations atomiques par article — il ne boucle pas.
 *  - **Boucle de règles** : déléguée à `Pipeline::applySubset()` (Phase 1).
 *    StepRunner ne connaît que les `rule_ids` snapshot dans le `StepRecord`.
 *  - **Décision sur régression** : déléguée à l'admin via SPA. StepRunner
 *    se contente de retourner `regression_pending` ; l'admin choisit ensuite
 *    `confirm_article()` (forcer l'écriture) ou `refuse_article()` (poser
 *    la post_meta de relance et passer à l'article suivant).
 *
 * Pipeline d'un article (cf. §4.4.2 du cahier) :
 *
 *   1.  Vérifier que le pas existe et est encore `running`.
 *   2.  Récupérer `WP_Post`. Si absent → `error`, on persiste et retourne.
 *   3.  Calculer `metrics_before` sur `post_content`.
 *   4.  Appliquer le sous-ensemble de règles via `Pipeline::applySubset()`.
 *   5.  Calculer `metrics_after`.
 *   6.  Lancer `RegressionDetector::analyze()` (seuils relus à chaud
 *       depuis Settings — un changement de seuil entre 2 articles d'un
 *       même pas est pris en compte).
 *   7a. Si régression → `regression_pending`, **pas d'écriture**, persister
 *       le rapport dans `per_article_results`.
 *   7b. Si dry_run → `dry_run`, pas d'écriture, persister le résultat.
 *   7c. Sinon → `wp_save_post_revision()` SYSTÉMATIQUEMENT (cf. §13)
 *       puis `wp_update_post()` puis recalcul `DiagnosticEngine` puis
 *       `DiagnosticsRepository::upsert()` puis persister `success`.
 *
 * Note V0 (Phase 4.1) : `confirm_article()` / `refuse_article()` /
 * `resume_progress()` / `finalize_step()` arriveront en Phases 4.2 et 4.3.
 * Cette première version couvre `start_step()` + `process_article()`
 * (happy path, dry_run, et la branche `regression_pending` pour ne pas
 * risquer d'écrire en cas de régression dès Phase 4.1).
 */
final class StepRunner {

	/**
	 * @param StepsRepository       $steps       Persistance des pas.
	 * @param DiagnosticsRepository $diagnostics Persistance des diagnostics (recalcul post-pas).
	 * @param PresetRegistry        $registry    Source des règles activées.
	 * @param Pipeline              $pipeline    Moteur d'application (run + applySubset).
	 * @param MetricsCalculator     $metrics     Calculateur des 7 métriques γ.
	 * @param RegressionDetector    $regression  Comparateur avant/après vs seuils.
	 * @param DiagnosticEngine      $engine      Recalcul diagnostic post-écriture.
	 * @param SettingsRepository    $settings    Source des seuils γ (relus à chaud).
	 */
	public function __construct(
		private readonly StepsRepository $steps,
		private readonly DiagnosticsRepository $diagnostics,
		private readonly PresetRegistry $registry,
		private readonly Pipeline $pipeline,
		private readonly MetricsCalculator $metrics,
		private readonly RegressionDetector $regression,
		private readonly DiagnosticEngine $engine,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * Initialise un pas : génère un UUID v4 côté serveur (cf. §13 garde-fou),
	 * insère une ligne `running` dans `son100_htmln_steps`, retourne l'UUID.
	 *
	 * Le `step_uuid` retourné est l'identifiant à ré-utiliser par tous les
	 * appels ultérieurs (process_article / confirm_article / refuse_article /
	 * finalize_step) sur ce pas.
	 *
	 * @param list<int>    $post_ids IDs articles ciblés (l'ordre est conservé).
	 * @param list<string> $rule_ids IDs des règles activées pour ce pas.
	 * @param int|null     $user_id  Auteur (null en CLI).
	 * @return string UUID v4 du pas créé.
	 *
	 * @throws \RuntimeException Si l'insertion en base a échoué — le pas
	 *                           n'existe pas et le caller ne peut rien faire.
	 */
	public function start_step( array $post_ids, array $rule_ids, ?int $user_id = null ): string {
		$uuid     = wp_generate_uuid4();
		$inserted = $this->steps->insert_running( $uuid, $rule_ids, $post_ids, $user_id );
		if ( false === $inserted ) {
			throw new \RuntimeException( 'StepRunner: insert_running failed for uuid ' . $uuid );
		}
		return $uuid;
	}

	/**
	 * Traite un article dans le contexte d'un pas en cours.
	 *
	 * @param string $uuid    UUID du pas (issu de `start_step()`).
	 * @param int    $post_id Article à traiter.
	 * @param bool   $dry_run Si true, calcule métriques + régression sans écrire.
	 * @return ArticleResult Résultat : success / dry_run / regression_pending / error.
	 */
	public function process_article( string $uuid, int $post_id, bool $dry_run = false ): ArticleResult {
		// 1. Vérifier que le pas existe et n'est pas déjà finalisé.
		$step = $this->steps->find_by_uuid( $uuid );
		if ( null === $step || $step->is_finished() ) {
			// Cas d'erreur global — pas tenté de persister (le pas n'existe peut-être pas).
			return ArticleResult::error( 'Step ' . $uuid . ' not found or already finalized' );
		}

		// 2. Récupérer le post.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			$result = ArticleResult::error( 'Post ' . $post_id . ' not found' );
			$this->steps->update_per_article_result( $uuid, $post_id, $result->to_persistence_array() );
			return $result;
		}

		// 3. Métriques avant + application des règles.
		$html_before = (string) $post->post_content;
		$before      = $this->metrics->compute( $html_before );

		$rules    = $this->registry->get_enabled_rules();
		$warnings = array();
		$html_after = $this->pipeline->applySubset(
			$rules,
			$step->applied_rules,
			$html_before,
			array(
				'post_id'  => $post_id,
				'step_uuid' => $uuid,
			),
			$warnings
		);

		// 4. Métriques après.
		$after = $this->metrics->compute( $html_after );

		// 5. Régression — seuils relus à chaud (un changement entre 2 articles
		// d'un même pas est pris en compte volontairement).
		$thresholds = RegressionThresholds::from_settings( $this->settings );
		$report     = $this->regression->analyze( $before, $after, $thresholds );

		// 6a. Régression détectée → pas d'écriture, status pending.
		if ( null !== $report ) {
			$result = ArticleResult::regression_pending( $before, $after, $report );
			$this->steps->update_per_article_result( $uuid, $post_id, $result->to_persistence_array() );
			return $result;
		}

		// 6b. Dry-run → pas d'écriture, status dry_run.
		if ( $dry_run ) {
			$result = ArticleResult::dry_run( $before, $after );
			$this->steps->update_per_article_result( $uuid, $post_id, $result->to_persistence_array() );
			return $result;
		}

		// 6c. Écriture validée — révision SYSTÉMATIQUE avant update (§13).
		wp_save_post_revision( $post_id );
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $html_after,
			),
			true
		);
		if ( $updated instanceof WP_Error || 0 === $updated ) {
			$message = $updated instanceof WP_Error
				? 'wp_update_post: ' . $updated->get_error_message()
				: 'wp_update_post returned 0 for post ' . $post_id;
			$result  = ArticleResult::error( $message, $before, $after );
			$this->steps->update_per_article_result( $uuid, $post_id, $result->to_persistence_array() );
			return $result;
		}

		// 7. Recalcul du diagnostic post-écriture (sur le post fraîchement modifié).
		$fresh_post = get_post( $post_id );
		if ( $fresh_post instanceof WP_Post ) {
			$diagnostic = $this->engine->diagnose( $fresh_post );
			$this->diagnostics->upsert( $diagnostic );
		}

		// 8. Persister le résultat success dans per_article_results.
		$result = ArticleResult::success( $before, $after );
		$this->steps->update_per_article_result( $uuid, $post_id, $result->to_persistence_array() );
		return $result;
	}
}
