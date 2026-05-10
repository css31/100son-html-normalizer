<?php
/**
 * ArticleResult — DTO du résultat de traitement d'un article par StepRunner.
 *
 * Cf. cahier v2.0 §4.4.2 (pipeline étendu d'un pas) et §3.1 F14.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Metrics\MetricsSnapshot;
use Cent_Son\Html_Normalizer\Regression\RegressionReport;

/**
 * Représentation immuable du résultat de `StepRunner::process_article()`
 * (et des opérations frères `confirm_article` / `refuse_article`).
 *
 * Les snapshots `metrics_before` / `metrics_after` sont systématiquement
 * calculés (même en cas d'erreur précoce, on retourne des snapshots zéro)
 * pour garder un contrat de retour homogène côté caller (REST/CLI/SPA).
 *
 * Statuts possibles :
 *  - `success`            : règles appliquées + écriture validée + diagnostic recalculé.
 *  - `dry_run`            : règles appliquées en simulation, aucune écriture.
 *  - `regression_pending` : régression détectée, **rien d'écrit**, en attente
 *                           d'un choix admin (`confirm_article` ou `refuse_article`).
 *  - `refused`            : admin a refusé une régression — post_meta de relance posée.
 *  - `error`              : échec technique non récupérable pour cet article.
 *
 * Persistance : la méthode `to_persistence_array()` produit l'entrée
 * `son100_htmln_steps.per_article_results[post_id]`. Elle ne retient que
 * les clés `{status, regression?, error?}` car `StepRecord::from_db_row()`
 * filtre strictement sur ce shape (cf. `decode_per_article_results()`).
 * Les snapshots métriques ne sont volontairement **pas** persistés ici :
 * ils sont déjà cumulés dans `son100_htmln_diagnostics.metrics` (frais
 * post-pas) et le `RegressionReport` lui-même contient les avant/après
 * des métriques fautives.
 */
final class ArticleResult {

	public const STATUS_SUCCESS            = 'success';
	public const STATUS_DRY_RUN            = 'dry_run';
	public const STATUS_REGRESSION_PENDING = 'regression_pending';
	public const STATUS_REFUSED            = 'refused';
	public const STATUS_ERROR              = 'error';

	/**
	 * @param string                $status            Un des `STATUS_*`.
	 * @param MetricsSnapshot       $metrics_before    Snapshot avant application des règles.
	 * @param MetricsSnapshot       $metrics_after     Snapshot après application (= before si erreur précoce).
	 * @param RegressionReport|null $regression_report Rapport si régression détectée, sinon null.
	 * @param string|null           $error             Message d'erreur si status = `error`, sinon null.
	 */
	private function __construct(
		public readonly string $status,
		public readonly MetricsSnapshot $metrics_before,
		public readonly MetricsSnapshot $metrics_after,
		public readonly ?RegressionReport $regression_report,
		public readonly ?string $error,
	) {}

	/**
	 * Article traité avec succès, post_content mis à jour.
	 *
	 * `regression_report` est null en cas de succès direct (pas de régression
	 * initiale) ; il est non-null lorsque l'admin vient de **confirmer** une
	 * régression (`StepRunner::confirm_article()`) — la trace est conservée
	 * pour l'historique F16 et la SPA.
	 *
	 * @param MetricsSnapshot       $before            Snapshot avant.
	 * @param MetricsSnapshot       $after             Snapshot après.
	 * @param RegressionReport|null $regression_report Report préservé en cas de confirm sur régression.
	 * @return self
	 */
	public static function success(
		MetricsSnapshot $before,
		MetricsSnapshot $after,
		?RegressionReport $regression_report = null
	): self {
		return new self( self::STATUS_SUCCESS, $before, $after, $regression_report, null );
	}

	/**
	 * Application simulée (mode dry-run) : aucune écriture, aucune révision,
	 * aucun recalcul de diagnostic. `metrics_after` reflète bien ce qu'on
	 * aurait obtenu, le caller peut donc afficher un diff prévisionnel.
	 *
	 * @param MetricsSnapshot $before Snapshot avant.
	 * @param MetricsSnapshot $after  Snapshot après simulation.
	 * @return self
	 */
	public static function dry_run( MetricsSnapshot $before, MetricsSnapshot $after ): self {
		return new self( self::STATUS_DRY_RUN, $before, $after, null, null );
	}

	/**
	 * Régression détectée — aucune écriture. Décision déléguée à l'admin
	 * via `StepRunner::confirm_article()` / `refuse_article()`.
	 *
	 * @param MetricsSnapshot  $before Snapshot avant.
	 * @param MetricsSnapshot  $after  Snapshot après application qui a déclenché la régression.
	 * @param RegressionReport $report Rapport non vide.
	 * @return self
	 */
	public static function regression_pending(
		MetricsSnapshot $before,
		MetricsSnapshot $after,
		RegressionReport $report
	): self {
		return new self( self::STATUS_REGRESSION_PENDING, $before, $after, $report, null );
	}

	/**
	 * Admin a refusé la régression : post_meta `_son100_htmln_manual_check_required`
	 * posée, aucune écriture sur post_content.
	 *
	 * `regression_report` est typiquement non-null (refus = il y avait régression)
	 * mais reste optionnel pour permettre à `StepRunner::refuse_article()` de
	 * dégrader gracieusement si la persistance ne contient plus le rapport.
	 *
	 * @param MetricsSnapshot       $before            Snapshot avant.
	 * @param MetricsSnapshot       $after             Snapshot post-application (jeté).
	 * @param RegressionReport|null $regression_report Rapport conservé pour traçabilité.
	 * @return self
	 */
	public static function refused(
		MetricsSnapshot $before,
		MetricsSnapshot $after,
		?RegressionReport $regression_report = null
	): self {
		return new self( self::STATUS_REFUSED, $before, $after, $regression_report, null );
	}

	/**
	 * Échec technique pour cet article (post introuvable, exception applySubset,
	 * `wp_update_post` qui retourne 0/WP_Error, etc.).
	 *
	 * @param string               $message Message d'erreur (anglais, technique).
	 * @param MetricsSnapshot|null $before  Snapshot avant si calculable, sinon zero.
	 * @param MetricsSnapshot|null $after   Snapshot après si calculable, sinon = before.
	 * @return self
	 */
	public static function error(
		string $message,
		?MetricsSnapshot $before = null,
		?MetricsSnapshot $after = null
	): self {
		$resolved_before = $before ?? MetricsSnapshot::zero();
		$resolved_after  = $after ?? $resolved_before;
		return new self( self::STATUS_ERROR, $resolved_before, $resolved_after, null, $message );
	}

	/**
	 * Sérialisation pour persistance dans
	 * `son100_htmln_steps.per_article_results[post_id]`.
	 *
	 * Shape strict imposé par `StepRecord::from_db_row()` :
	 *  - `status`     : toujours présent ;
	 *  - `regression` : présent ssi `regression_report` non null ;
	 *  - `error`      : présent ssi `error` non null.
	 *
	 * @return array{status: string, regression?: array<string, mixed>, error?: string}
	 */
	public function to_persistence_array(): array {
		$row = array( 'status' => $this->status );
		if ( null !== $this->regression_report ) {
			$row['regression'] = $this->regression_report->to_array();
		}
		if ( null !== $this->error ) {
			$row['error'] = $this->error;
		}
		return $row;
	}
}
