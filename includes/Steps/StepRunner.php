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
use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use Cent_Son\Html_Normalizer\Regression\RegressionDetector;
use Cent_Son\Html_Normalizer\Regression\RegressionReport;
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
 * Note V0 : `resume_progress()` / `finalize_step()` arriveront en Phase 4.3.
 * Cette version couvre `start_step()` + `process_article()` (happy path,
 * dry_run, regression_pending, erreur) + `confirm_article()` + `refuse_article()`.
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
		$step = $this->load_active_step( $uuid );
		if ( null === $step ) {
			// Pas tenté de persister (le pas n'existe peut-être pas).
			return ArticleResult::error( 'Step ' . $uuid . ' not found or already finalized' );
		}

		// 2. Récupérer le post.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( 'Post ' . $post_id . ' not found' )
			);
		}

		// 3. Métriques avant.
		$html_before = (string) $post->post_content;
		$before      = $this->metrics->compute( $html_before );

		// 4. Application du sous-ensemble — try/catch global pour ne jamais propager
		// une exception au caller REST/CLI (cf. §13 : le filtre htmln/normalize doit
		// toujours retourner une string ; même esprit côté StepRunner).
		try {
			$warnings   = array();
			$html_after = $this->pipeline->applySubset(
				$this->registry->get_enabled_rules(),
				$step->applied_rules,
				$html_before,
				array(
					'post_id'   => $post_id,
					'step_uuid' => $uuid,
				),
				$warnings
			);
		} catch ( \Throwable $e ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( 'applySubset failed: ' . $e->getMessage(), $before, $before )
			);
		}

		// 5. Métriques après.
		$after = $this->metrics->compute( $html_after );

		// 6. Régression — seuils relus à chaud (un changement entre 2 articles
		// d'un même pas est pris en compte volontairement). Cf. §13 : appel
		// SYSTÉMATIQUE, jamais shortcircuité.
		$thresholds = RegressionThresholds::from_settings( $this->settings );
		$report     = $this->regression->analyze( $before, $after, $thresholds );

		// 7a. Régression détectée → pas d'écriture, status pending.
		if ( null !== $report ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::regression_pending( $before, $after, $report )
			);
		}

		// 7b. Dry-run → pas d'écriture, status dry_run.
		if ( $dry_run ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::dry_run( $before, $after )
			);
		}

		// 7c. Écriture validée — révision SYSTÉMATIQUE avant update (§13).
		$write_error = $this->write_post_content( $post_id, $html_after );
		if ( null !== $write_error ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( $write_error, $before, $after )
			);
		}

		// 8. Recalcul du diagnostic post-écriture.
		$this->refresh_diagnostic_for( $post_id );

		// 9. Persister le résultat success.
		return $this->persist_and_return(
			$uuid,
			$post_id,
			ArticleResult::success( $before, $after )
		);
	}

	/**
	 * Confirme une régression : force l'écriture du HTML normalisé après
	 * que l'admin a accepté de passer outre le rapport de régression
	 * remonté par `process_article()`.
	 *
	 * Le rapport est conservé dans la persistance et propagé dans le DTO
	 * retour pour traçabilité (F16). Aucune re-vérification de régression
	 * (l'admin a décidé en connaissance de cause).
	 *
	 * Note : on **réapplique** les règles sur le `post_content` actuel ;
	 * si l'article a été modifié entre `process_article` et `confirm_article`,
	 * on travaille sur la dernière version (la SPA n'envoie pas le HTML).
	 *
	 * @param string $uuid    UUID du pas.
	 * @param int    $post_id Article concerné.
	 * @return ArticleResult success / error.
	 */
	public function confirm_article( string $uuid, int $post_id ): ArticleResult {
		$step = $this->load_active_step( $uuid );
		if ( null === $step ) {
			return ArticleResult::error( 'Step ' . $uuid . ' not found or already finalized' );
		}

		$entry = $step->per_article_results[ $post_id ] ?? null;
		if ( ! $this->is_in_regression_pending( $entry ) ) {
			return ArticleResult::error(
				'Cannot confirm article ' . $post_id . ' : not in regression_pending state for step ' . $uuid
			);
		}

		$preserved_report = $this->rebuild_report_from_entry( $entry );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( 'Post ' . $post_id . ' not found' ),
				$entry
			);
		}

		$html_before = (string) $post->post_content;
		$before      = $this->metrics->compute( $html_before );

		try {
			$warnings   = array();
			$html_after = $this->pipeline->applySubset(
				$this->registry->get_enabled_rules(),
				$step->applied_rules,
				$html_before,
				array(
					'post_id'   => $post_id,
					'step_uuid' => $uuid,
					'mode'      => 'confirm',
				),
				$warnings
			);
		} catch ( \Throwable $e ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( 'applySubset failed: ' . $e->getMessage(), $before, $before ),
				$entry
			);
		}
		$after = $this->metrics->compute( $html_after );

		$write_error = $this->write_post_content( $post_id, $html_after );
		if ( null !== $write_error ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( $write_error, $before, $after ),
				$entry
			);
		}

		$this->refresh_diagnostic_for( $post_id );

		// Résultat success avec trace de la régression confirmée.
		return $this->persist_and_return(
			$uuid,
			$post_id,
			ArticleResult::success( $before, $after, $preserved_report ),
			$entry
		);
	}

	/**
	 * Refuse une régression : pose la post_meta de relance manuelle
	 * (`_son100_htmln_manual_check_required = 1`), aucune écriture sur
	 * `post_content`. Le rapport reste persisté pour la trace F16.
	 *
	 * @param string $uuid    UUID du pas.
	 * @param int    $post_id Article concerné.
	 * @return ArticleResult refused / error.
	 */
	public function refuse_article( string $uuid, int $post_id ): ArticleResult {
		$step = $this->load_active_step( $uuid );
		if ( null === $step ) {
			return ArticleResult::error( 'Step ' . $uuid . ' not found or already finalized' );
		}

		$entry = $step->per_article_results[ $post_id ] ?? null;
		if ( ! $this->is_in_regression_pending( $entry ) ) {
			return ArticleResult::error(
				'Cannot refuse article ' . $post_id . ' : not in regression_pending state for step ' . $uuid
			);
		}

		$preserved_report = $this->rebuild_report_from_entry( $entry );

		// Pose la post_meta de relance manuelle (cf. cahier §3.1 F14).
		update_post_meta( $post_id, '_son100_htmln_manual_check_required', 1 );

		// Métriques sur le post_content actuel (intact, aucune écriture).
		$post = get_post( $post_id );
		$snapshot = $post instanceof WP_Post
			? $this->metrics->compute( (string) $post->post_content )
			: MetricsSnapshot::zero();

		return $this->persist_and_return(
			$uuid,
			$post_id,
			ArticleResult::refused( $snapshot, $snapshot, $preserved_report ),
			$entry
		);
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Charge un pas et vérifie qu'il est encore en cours (`finished_at` null).
	 *
	 * @param string $uuid UUID du pas.
	 * @return StepRecord|null `null` si introuvable ou déjà finalisé.
	 */
	private function load_active_step( string $uuid ): ?StepRecord {
		$step = $this->steps->find_by_uuid( $uuid );
		if ( null === $step || $step->is_finished() ) {
			return null;
		}
		return $step;
	}

	/**
	 * Écrit le HTML normalisé : révision systématique (§13) puis `wp_update_post`.
	 *
	 * @param int    $post_id    Article concerné.
	 * @param string $html_after HTML normalisé à persister.
	 * @return string|null `null` en cas de succès, message d'erreur sinon.
	 */
	private function write_post_content( int $post_id, string $html_after ): ?string {
		wp_save_post_revision( $post_id );
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $html_after,
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			return 'wp_update_post: ' . $updated->get_error_message();
		}
		if ( 0 === $updated ) {
			return 'wp_update_post returned 0 for post ' . $post_id;
		}
		return null;
	}

	/**
	 * Recalcule et upsert le diagnostic d'un article fraîchement modifié.
	 *
	 * @param int $post_id Article concerné.
	 */
	private function refresh_diagnostic_for( int $post_id ): void {
		$fresh_post = get_post( $post_id );
		if ( $fresh_post instanceof WP_Post ) {
			$diagnostic = $this->engine->diagnose( $fresh_post );
			$this->diagnostics->upsert( $diagnostic );
		}
	}

	/**
	 * Vrai ssi l'entrée per_article_results indique `regression_pending`.
	 *
	 * @param array<string, mixed>|null $entry Entrée potentielle.
	 * @return bool
	 */
	private function is_in_regression_pending( ?array $entry ): bool {
		if ( null === $entry ) {
			return false;
		}
		return ArticleResult::STATUS_REGRESSION_PENDING === ( $entry['status'] ?? '' );
	}

	/**
	 * Reconstruit un `RegressionReport` depuis l'entrée per_article_results
	 * (issue d'un précédent `process_article` ayant déclenché la régression).
	 *
	 * @param array<string, mixed> $entry Entrée per_article_results.
	 * @return RegressionReport|null
	 */
	private function rebuild_report_from_entry( array $entry ): ?RegressionReport {
		if ( ! isset( $entry['regression'] ) || ! is_array( $entry['regression'] ) ) {
			return null;
		}
		return RegressionReport::from_array( $entry['regression'] );
	}

	/**
	 * Persiste le résultat dans `per_article_results` et le retourne tel quel.
	 *
	 * Lorsque `$previous_entry` est fourni, son champ `regression` est
	 * réinjecté en fallback dans la persistance si le DTO n'en porte pas
	 * (filet de sécurité pour ne jamais perdre la trace côté F16, même si
	 * `from_array()` a échoué silencieusement).
	 *
	 * @param string                    $uuid           UUID du pas.
	 * @param int                       $post_id        Article concerné.
	 * @param ArticleResult             $result         Résultat à persister + retourner.
	 * @param array<string, mixed>|null $previous_entry Entrée précédente, si rejouée.
	 * @return ArticleResult
	 */
	private function persist_and_return(
		string $uuid,
		int $post_id,
		ArticleResult $result,
		?array $previous_entry = null
	): ArticleResult {
		$persistence = $result->to_persistence_array();
		if (
			null !== $previous_entry
			&& ! isset( $persistence['regression'] )
			&& isset( $previous_entry['regression'] )
			&& is_array( $previous_entry['regression'] )
		) {
			$persistence['regression'] = $previous_entry['regression'];
		}
		$this->steps->update_per_article_result( $uuid, $post_id, $persistence );
		return $result;
	}
}
