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
use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\LossyRule;
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
 * Surface publique V1.0 :
 *  - `start_step()`            — création initiale du pas (UUID v4 serveur).
 *  - `process_article()`       — pipeline §4.4.2 par article (happy / dry_run
 *                                / regression_pending / erreur).
 *  - `confirm_article()`       — admin confirme une régression : écriture forcée.
 *  - `refuse_article()`        — admin refuse : post_meta de relance + status refused.
 *  - `resume_progress()`       — énumération des articles par catégorie de
 *                                progression (alimente `StepResumeBanner` côté SPA).
 *  - `finalize_step()`         — comptage final + écriture `finished_at`.
 *                                Idempotent : ré-appel sur pas finalisé renvoie
 *                                le record tel quel.
 */
class StepRunner {

	/**
	 * @param StepsRepository        $steps       Persistance des pas.
	 * @param DiagnosticsRepository  $diagnostics Persistance des diagnostics (recalcul post-pas).
	 * @param PresetRegistry         $registry    Source des règles activées.
	 * @param Pipeline               $pipeline    Moteur d'application (run + applySubset).
	 * @param MetricsCalculator      $metrics     Calculateur des 7 métriques γ.
	 * @param RegressionDetector     $regression  Comparateur avant/après vs seuils.
	 * @param DiagnosticEngine       $engine      Recalcul diagnostic post-écriture.
	 * @param SettingsRepository     $settings    Source des seuils γ (relus à chaud).
	 * @param BuilderClassifier|null $classifier  Classification constructeur — injectée dans
	 *                                            `$context['builder_type']` pour permettre au
	 *                                            Pipeline de skipper les règles `BuilderScopedRule`
	 *                                            (R6/R14 sur les articles Gutenberg). Nullable
	 *                                            pour rétro-compat avec les tests existants qui
	 *                                            construisent StepRunner avec 8 arguments.
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
		private readonly ?BuilderClassifier $classifier = null,
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
		$context = array(
			'post_id'   => $post_id,
			'step_uuid' => $uuid,
		);
		if ( null !== $this->classifier ) {
			$context['builder_type'] = $this->classifier->classify( $post_id );
		}
		try {
			$warnings   = array();
			$html_after = $this->pipeline->applySubset(
				$this->registry->get_enabled_rules(),
				$step->applied_rules,
				$html_before,
				$context,
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
		// SYSTÉMATIQUE, jamais shortcircuité. Si le subset contient au
		// moins une règle marquée `LossyRule` (R3, R4 — retraits volontaires
		// de shortcodes/snippets), on relâche les seuils `text_loss_pct` et
		// `words_loss_pct` : sinon chaque application d'une lossy rule sur
		// un article tomberait en `regression_pending` faute de tolérance,
		// rendant la cleanup inopérante. Les checks structurels (images,
		// headings, links, lists, paragraphes) restent appliqués.
		$thresholds = RegressionThresholds::from_settings( $this->settings );
		if ( $this->subset_contains_lossy_rule( $step->applied_rules ) ) {
			$thresholds = $thresholds->relax_text_checks_for_lossy();
		}
		$report = $this->regression->analyze( $before, $after, $thresholds );

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
		$write = $this->write_post_content( $post_id, $html_after );
		if ( null !== $write['error'] ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( $write['error'], $before, $after )
			);
		}

		// 8. Recalcul du diagnostic post-écriture.
		$this->refresh_diagnostic_for( $post_id );

		// 9. Persister le résultat success — revision_id capturé pour rollback.
		return $this->persist_and_return(
			$uuid,
			$post_id,
			ArticleResult::success( $before, $after, null, $write['revision_id'] )
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

		$context = array(
			'post_id'   => $post_id,
			'step_uuid' => $uuid,
			'mode'      => 'confirm',
		);
		if ( null !== $this->classifier ) {
			$context['builder_type'] = $this->classifier->classify( $post_id );
		}
		try {
			$warnings   = array();
			$html_after = $this->pipeline->applySubset(
				$this->registry->get_enabled_rules(),
				$step->applied_rules,
				$html_before,
				$context,
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

		$write = $this->write_post_content( $post_id, $html_after );
		if ( null !== $write['error'] ) {
			return $this->persist_and_return(
				$uuid,
				$post_id,
				ArticleResult::error( $write['error'], $before, $after ),
				$entry
			);
		}

		$this->refresh_diagnostic_for( $post_id );

		// Résultat success avec trace de la régression confirmée + revision_id rollback.
		return $this->persist_and_return(
			$uuid,
			$post_id,
			ArticleResult::success( $before, $after, $preserved_report, $write['revision_id'] ),
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

	/**
	 * Énumère les articles d'un pas par catégorie de progression. Alimente
	 * le `StepResumeBanner` côté SPA (cf. cahier §3.1 F14, hyp. 31) et la
	 * vue Historique (F16).
	 *
	 * Catégorisation à partir de `per_article_results` :
	 *  - `processed`          : status `success`, `refused`, `error` ou `dry_run`
	 *                           (état terminal du point de vue du pas — l'article
	 *                           ne sera plus retraité).
	 *  - `regression_pending` : article en attente d'une décision admin via
	 *                           `confirm_article()` ou `refuse_article()`.
	 *  - `pending`            : article jamais traité (pas dans `per_article_results`).
	 *
	 * L'ordre des listes respecte celui de `affected_post_ids`, snapshot au
	 * démarrage du pas, ce qui permet à la SPA de reprendre exactement où elle
	 * s'était arrêtée.
	 *
	 * @param string $uuid UUID du pas.
	 * @return array{
	 *   uuid: string,
	 *   total_articles: int,
	 *   processed: list<int>,
	 *   regression_pending: list<int>,
	 *   pending: list<int>,
	 * }|null `null` si le pas est inconnu.
	 */
	public function resume_progress( string $uuid ): ?array {
		$step = $this->steps->find_by_uuid( $uuid );
		if ( null === $step ) {
			return null;
		}

		$processed          = array();
		$regression_pending = array();
		$pending            = array();

		foreach ( $step->affected_post_ids as $post_id ) {
			$entry = $step->per_article_results[ $post_id ] ?? null;
			if ( null === $entry ) {
				$pending[] = $post_id;
				continue;
			}
			$status = (string) ( $entry['status'] ?? '' );
			if ( ArticleResult::STATUS_REGRESSION_PENDING === $status ) {
				$regression_pending[] = $post_id;
				continue;
			}
			$processed[] = $post_id;
		}

		return array(
			'uuid'               => $uuid,
			'total_articles'     => $step->total_articles,
			'processed'          => $processed,
			'regression_pending' => $regression_pending,
			'pending'            => $pending,
		);
	}

	/**
	 * Finalise un pas : compte les statuts depuis `per_article_results` et
	 * délègue à `StepsRepository::finalize()` pour poser `finished_at`.
	 *
	 * Règle de comptage :
	 *  - `successful_articles` = entrées `success`.
	 *  - `refused_articles`    = entrées `refused`.
	 *  - `errored_articles`    = entrées `error`, `regression_pending`, `dry_run`,
	 *                            statut inconnu, **et** articles affectés non
	 *                            présents dans `per_article_results` (la SPA
	 *                            n'est pas allée jusqu'au bout — équivalent
	 *                            sémantique d'un abandon).
	 *
	 * Idempotent : si le pas est déjà finalisé (`finished_at` non null), retourne
	 * le record tel quel sans recompter — tolère les double-clics SPA.
	 *
	 * @param string $uuid UUID du pas.
	 * @return StepRecord|null Record finalisé, ou `null` si le pas est inconnu.
	 */
	public function finalize_step( string $uuid ): ?StepRecord {
		$step = $this->steps->find_by_uuid( $uuid );
		if ( null === $step ) {
			return null;
		}
		if ( $step->is_finished() ) {
			return $step;
		}

		$counts = $this->count_terminal_statuses( $step );

		$this->steps->finalize(
			$uuid,
			$counts['success'],
			$counts['refused'],
			$counts['errored'],
			$counts['pending'],
		);

		return $this->steps->find_by_uuid( $uuid );
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
	 * Retourne un tuple `{error, revision_id}` — la révision capturée alimente
	 * `ArticleResult::success()` puis `per_article_results[post_id].revision_id`
	 * en BDD, pivot du rollback (cf. RollbackService). `revision_id` peut être
	 * `null` même en succès si WP n'a pas créé de révision (post_type sans
	 * support `revisions`, dédoublonnage, etc.) — l'article ne sera alors pas
	 * rollback-able pour ce step.
	 *
	 * @param int    $post_id    Article concerné.
	 * @param string $html_after HTML normalisé à persister.
	 * @return array{error: string|null, revision_id: int|null} `error` null en succès, message sinon ;
	 *                                                          `revision_id` non-null ssi WP a créé une révision.
	 */
	private function write_post_content( int $post_id, string $html_after ): array {
		// `wp_save_post_revision()` déduplique par défaut : si le `post_content`
		// courant est identique à la dernière révision existante (cas typique
		// après l'auto-révision créée par n'importe quel `wp_update_post`
		// précédent — édition admin ou step plugin antérieur), il retourne
		// `null` au lieu de créer une révision. Notre pivot de rollback
		// disparaît alors silencieusement et le bouton « Restaurer tout le
		// lot » reste désactivé même quand le step a réellement modifié les
		// articles. On force donc la création via le filtre prévu pour ça,
		// scope-restreint à l'appel (add → call → remove).
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		$rev_raw = wp_save_post_revision( $post_id );
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );

		$revision_id = is_int( $rev_raw ) && $rev_raw > 0 ? $rev_raw : null;

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $html_after,
			),
			true
		);
		if ( $updated instanceof WP_Error ) {
			return array(
				'error'       => 'wp_update_post: ' . $updated->get_error_message(),
				'revision_id' => $revision_id,
			);
		}
		if ( 0 === $updated ) {
			return array(
				'error'       => 'wp_update_post returned 0 for post ' . $post_id,
				'revision_id' => $revision_id,
			);
		}
		return array(
			'error'       => null,
			'revision_id' => $revision_id,
		);
	}

	/**
	 * Vrai ssi au moins une règle du sous-ensemble actif implémente
	 * `LossyRule` (R3, R4 — retraits volontaires de contenu textuel).
	 *
	 * Le check passe par `PresetRegistry::get_enabled_rules()` puis filtre
	 * sur les `rule_ids` du subset — on évite ainsi d'instancier les règles
	 * deux fois et on respecte la même source de vérité que `applySubset`.
	 *
	 * @param list<string> $rule_ids IDs du subset appliqué (`StepRecord::applied_rules`).
	 * @return bool
	 */
	private function subset_contains_lossy_rule( array $rule_ids ): bool {
		if ( array() === $rule_ids ) {
			return false;
		}
		$index = array_flip( $rule_ids );
		foreach ( $this->registry->get_enabled_rules() as $rule ) {
			if ( ! isset( $index[ $rule->id() ] ) ) {
				continue;
			}
			if ( $rule instanceof LossyRule ) {
				return true;
			}
		}
		return false;
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

	/**
	 * Compte les statuts terminaux d'un pas pour `finalize_step()`.
	 *
	 * Quatre buckets distincts (post-rc4 — séparation de `pending` qui était
	 * fusionné dans `errored`) :
	 *  - `success` : `success`
	 *  - `refused` : `refused`
	 *  - `pending` : `regression_pending` (admin n'a pas arbitré)
	 *  - `errored` : `error`, `dry_run`, statut inconnu, et articles affectés
	 *                mais absents de `per_article_results` (jamais traités).
	 *
	 * @param StepRecord $step Record à analyser.
	 * @return array{success: int, refused: int, errored: int, pending: int}
	 */
	private function count_terminal_statuses( StepRecord $step ): array {
		$success = 0;
		$refused = 0;
		$errored = 0;
		$pending = 0;
		$seen    = array();

		foreach ( $step->per_article_results as $post_id => $entry ) {
			$seen[ (int) $post_id ] = true;
			$status                 = (string) ( $entry['status'] ?? '' );
			switch ( $status ) {
				case ArticleResult::STATUS_SUCCESS:
					++$success;
					break;
				case ArticleResult::STATUS_REFUSED:
					++$refused;
					break;
				case ArticleResult::STATUS_REGRESSION_PENDING:
					++$pending;
					break;
				default:
					// error, dry_run, statut inconnu.
					++$errored;
					break;
			}
		}

		// Articles affectés non présents dans per_article_results : non traités.
		foreach ( $step->affected_post_ids as $post_id ) {
			if ( ! isset( $seen[ $post_id ] ) ) {
				++$errored;
			}
		}

		return array(
			'success' => $success,
			'refused' => $refused,
			'errored' => $errored,
			'pending' => $pending,
		);
	}
}
