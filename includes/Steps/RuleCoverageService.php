<?php
/**
 * RuleCoverageService — calcule la **couverture historique** d'une
 * règle sur le corpus diagnostiqué.
 *
 * Pour chaque règle R parmi `PresetRegistry::PRESETS`, qualifie en :
 *
 *  - `'full'`    — au moins un article éligible a été traité avec
 *                  `status = 'success'` dans un step contenant R, ET
 *                  tous les autres articles éligibles n'ont rien à
 *                  faire (R absente de leur `matching_rules`). Autrement
 *                  dit : la règle a tourné ET il ne reste plus aucun
 *                  candidat à transformer ;
 *  - `'partial'` — au moins un article éligible touché, mais ≥ 1
 *                  autre article éligible a encore R dans son
 *                  `matching_rules` (reste à appliquer) ;
 *  - `'none'`    — aucun article éligible n'a jamais reçu R en succès
 *                  (jamais appliquée OU jamais traversée par un step
 *                  qui aurait écrit en succès).
 *
 * Cf. `assets/src/admin-spa/views/Rules.jsx` (composant `PipelineSchema`)
 * qui consomme la map et coloriste les pastilles : vert / orange / gris.
 *
 * **Périmètre éligible d'une règle R** :
 *  - On part de tous les articles présents dans `son100_htmln_diagnostics`
 *    (le « corpus connu du plugin »).
 *  - On exclut les articles dont `builder_type = 'out'` (override manuel,
 *    hors-périmètre d'action automatique — cf. `BuilderClassifier::is_out_of_scope`).
 *  - Si R implémente `BuilderScopedRule`, on exclut **aussi** les
 *    `builder_type` listés dans `R::excluded_builder_types()` (R6/R14
 *    ne s'appliquent jamais aux articles `gutenberg`, donc le `full`
 *    se calcule sur les SO + autres seulement).
 *  - Si le périmètre éligible est vide (corpus vide ou intégralement
 *    `out`), on retourne `'none'` — pas de notion de couverture
 *    significative.
 *
 * **Coût** : un SELECT plein de `son100_htmln_steps` (steps terminés)
 * + un SELECT `post_id, builder_type` sur `son100_htmln_diagnostics`.
 * Le calcul agrégat se fait en PHP (boucle O(steps × articles × rules)).
 * Sur MMM-2 (≤ 50 steps, ≤ 800 articles) le coût total est de l'ordre
 * de quelques ms. Si le volume grossit, basculer sur un cache transient
 * invalidé à la fin de chaque step.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Core\Rules\BuilderScopedRule;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;

/**
 * Service stateless de calcul de la couverture historique des règles.
 */
class RuleCoverageService {

	public const STATUS_FULL    = 'full';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_NONE    = 'none';

	/**
	 * @param StepsRepository       $steps       Source des steps finalisés.
	 * @param DiagnosticsRepository $diagnostics Source du corpus diagnostiqué.
	 * @param PresetRegistry        $registry    Source des règles (instanciation
	 *                                            pour détecter `BuilderScopedRule`).
	 */
	public function __construct(
		private readonly StepsRepository $steps,
		private readonly DiagnosticsRepository $diagnostics,
		private readonly PresetRegistry $registry,
	) {}

	/**
	 * Calcule la couverture historique de chaque règle de `PresetRegistry::PRESETS`.
	 *
	 * @return array<string, string> Map rule_id → 'full' | 'partial' | 'none'.
	 *                               Toutes les clés `PresetRegistry::PRESETS` présentes.
	 */
	public function compute(): array {
		$touched      = $this->aggregate_touched_post_ids_by_rule();
		$corpus       = $this->diagnostics->list_post_id_by_builder_type();
		$still_to_run = $this->diagnostics->list_post_ids_by_applicable_rule();

		$result = array();
		foreach ( PresetRegistry::PRESETS as $rule_id ) {
			$excluded_types = $this->excluded_builder_types_for( $rule_id );
			$eligible       = self::filter_eligible_post_ids( $corpus, $excluded_types );
			$touched_set    = $touched[ $rule_id ] ?? array();
			$pending_set    = $still_to_run[ $rule_id ] ?? array();

			$result[ $rule_id ] = self::qualify( $eligible, $touched_set, $pending_set );
		}
		return $result;
	}

	/**
	 * Agrège tous les `post_id` traités avec `status='success'` par chaque
	 * règle, à travers tous les steps finalisés.
	 *
	 * Un step peut appliquer N règles d'un coup ; un article `success`
	 * dans ce step est considéré comme touché par **toutes** les règles
	 * du subset (sémantique alignée sur `StepRunner::process_article`
	 * qui applique les N règles séquentiellement avant écriture).
	 *
	 * @return array<string, array<int, true>> Map rule_id → set<post_id>
	 *                                          (array keys pour dédoublonnage O(1)).
	 */
	private function aggregate_touched_post_ids_by_rule(): array {
		$touched = array();
		foreach ( $this->steps->list_all_finished() as $step ) {
			if ( array() === $step->applied_rules ) {
				continue;
			}
			foreach ( $step->per_article_results as $post_id => $entry ) {
				$status = isset( $entry['status'] ) ? (string) $entry['status'] : '';
				if ( ArticleResult::STATUS_SUCCESS !== $status ) {
					continue;
				}
				$pid = (int) $post_id;
				foreach ( $step->applied_rules as $rule_id ) {
					$touched[ $rule_id ][ $pid ] = true;
				}
			}
		}
		return $touched;
	}

	/**
	 * Énumère les `builder_type` exclus pour une règle donnée. Pour les
	 * règles non-`BuilderScopedRule`, retourne `[]` (aucun type exclu).
	 *
	 * @param string $rule_id Identifiant de la règle.
	 * @return list<string> Types exclus du périmètre éligible.
	 */
	private function excluded_builder_types_for( string $rule_id ): array {
		$rule = $this->registry->build_rule( $rule_id );
		if ( ! $rule instanceof BuilderScopedRule ) {
			return array();
		}
		return $rule->excluded_builder_types();
	}

	/**
	 * Filtre une map `post_id => builder_type` pour ne garder que les
	 * articles éligibles à une règle : on exclut systématiquement les
	 * `'out'` (override manuel hors-périmètre) et, en plus, les types
	 * passés en `$excluded_types`.
	 *
	 * @param array<int, string> $corpus         Map post_id → builder_type.
	 * @param list<string>       $excluded_types Types supplémentaires à exclure.
	 * @return array<int, true> Set des post_id éligibles (array keys).
	 */
	private static function filter_eligible_post_ids( array $corpus, array $excluded_types ): array {
		$excluded_index = array_flip( array_merge( array( BuilderClassifier::TYPE_OUT ), $excluded_types ) );
		$eligible       = array();
		foreach ( $corpus as $post_id => $builder_type ) {
			if ( isset( $excluded_index[ $builder_type ] ) ) {
				continue;
			}
			$eligible[ $post_id ] = true;
		}
		return $eligible;
	}

	/**
	 * Qualifie une règle selon le rapport `eligible` vs `touched` vs `pending`.
	 *
	 * Sémantique :
	 *  - `'none'`    : aucun éligible n'a été touché par un step success ;
	 *  - `'full'`    : ≥ 1 éligible touché ET aucun éligible non-touché
	 *                  n'a la règle dans son `matching_rules` (rien ne reste
	 *                  à transformer dans le périmètre) ;
	 *  - `'partial'` : ≥ 1 éligible touché ET ≥ 1 éligible non-touché a la
	 *                  règle dans son `matching_rules`.
	 *
	 * @param array<int, true> $eligible    Set des post_id du périmètre.
	 * @param array<int, true> $touched_set Set des post_id traités avec succès.
	 * @param array<int, true> $pending_set Set des post_id ayant la règle
	 *                                      dans leur `matching_rules` courant.
	 * @return string `STATUS_FULL`, `STATUS_PARTIAL` ou `STATUS_NONE`.
	 */
	private static function qualify( array $eligible, array $touched_set, array $pending_set ): string {
		if ( array() === $eligible ) {
			// Périmètre vide : aucune notion de couverture pertinente.
			return self::STATUS_NONE;
		}
		$has_touched_in_eligible = false;
		$has_pending_in_eligible = false;
		foreach ( $eligible as $post_id => $_ ) {
			if ( isset( $touched_set[ $post_id ] ) ) {
				$has_touched_in_eligible = true;
				continue;
			}
			// Article éligible non-touché : ne tire la couverture vers
			// `partial` que s'il a effectivement encore la règle dans son
			// `matching_rules` (= reste quelque chose à transformer).
			if ( isset( $pending_set[ $post_id ] ) ) {
				$has_pending_in_eligible = true;
			}
		}
		if ( ! $has_touched_in_eligible ) {
			return self::STATUS_NONE;
		}
		return $has_pending_in_eligible ? self::STATUS_PARTIAL : self::STATUS_FULL;
	}
}
