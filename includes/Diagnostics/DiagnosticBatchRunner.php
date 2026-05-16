<?php
/**
 * DiagnosticBatchRunner — orchestre le scan global des articles.
 *
 * Cf. cahier v2.0 §3.1 F12 et §11.18.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Posts\BuilderClassifier;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticRecord;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_Post;

/**
 * Scan global par chunks (20 articles par défaut).
 *
 * Workflow attendu (consommé par la couche REST F12 — Phase 5) :
 *  1. SPA appelle `start_batch()` → reçoit {batch_id, total, post_ids, chunk_size}.
 *  2. SPA boucle sur les post_ids par paquets de `chunk_size`, appelle
 *     `process_chunk($subset)` pour chaque paquet et progresse la barre.
 *  3. À la fin du dernier chunk, le scan est terminé.
 *
 * Le `batch_id` est un identifiant côté client (UUID) qui n'est pas
 * persisté en V1 — la table `son100_htmln_steps` est dédiée aux pas F14,
 * pas aux scans diagnostiques. La SPA s'en sert pour reconnaître son
 * propre batch côté UI.
 *
 * Scope du scan : `post_status='publish'` + post_types issus du setting
 * F8 (`f8_post_types_selection`). Cohérent avec §3.1 F12 et hyp. 20 du cahier.
 */
class DiagnosticBatchRunner {

	/**
	 * Taille de chunk par défaut (cf. §11.18 — 20 articles).
	 */
	public const DEFAULT_CHUNK_SIZE = 20;

	/**
	 * @param DiagnosticEngine       $engine     Calcul du diagnostic d'un article.
	 * @param DiagnosticsRepository  $repository Persistance du diagnostic.
	 * @param SettingsRepository     $settings   Source des post_types F8.
	 * @param BuilderClassifier|null $classifier Optionnel : classifier réutilisé
	 *                                           pour les filtres `builder` /
	 *                                           `exclude_normalized` de
	 *                                           `start_batch()`. Null = instancié
	 *                                           paresseusement à la demande
	 *                                           (rétro-compat tests existants).
	 */
	public function __construct(
		private readonly DiagnosticEngine $engine,
		private readonly DiagnosticsRepository $repository,
		private readonly SettingsRepository $settings,
		private readonly ?BuilderClassifier $classifier = null,
	) {}

	/**
	 * Énumère les articles éligibles et retourne les méta-données du batch.
	 *
	 * `$post_types_override` permet à un caller (typiquement
	 * `DiagnosticsController::run_batch()`) de scanner un sous-ensemble
	 * différent du défaut F8 sans persister la sélection. Si `null`, on
	 * retombe sur la sélection F8 stockée en `Settings`.
	 *
	 * `$filters` permet de scoper le scan via les mêmes axes que la liste
	 * (cf. `DiagnosticsRepository::list_paginated()`). Les filtres SQL-friendly
	 * (`cat_id`, `year`, `month`, `search`) sont appliqués dans `WP_Query`
	 * pour réduire le volume en amont. Le filtre `builder` exige
	 * `BuilderClassifier` (post-meta + post_content) — appliqué côté PHP
	 * après le fetch. La clé `rule_ids` est volontairement ignorée : un
	 * scan applique TOUTES les règles à chaque article, scoper par règle
	 * n'a pas de sens.
	 *
	 * `$exclude_normalized = true` retire les articles dont le **dernier
	 * diagnostic** est `status = 'normal'` ET non périmé (`is_stale = 0`) —
	 * c'est-à-dire ceux qui ont déjà été identifiés comme « OK » par un scan
	 * antérieur. Les articles jamais diagnostiqués ou périmés restent inclus
	 * (à (re)scanner). Cumulable avec les autres filtres.
	 *
	 * Note sémantique : la divergence avec `list_paginated()` est minimale —
	 * `search` ici cherche dans titre + contenu (sémantique `WP_Query::s`),
	 * alors que la liste cherche uniquement dans le titre ou par post_id
	 * exact si numérique. Acceptable pour V1 : un scan brasse plus large
	 * que la liste, ce qui est cohérent avec son rôle.
	 *
	 * @param int|null            $chunk_size           Taille de chunk (≥ 1). Défaut : `DEFAULT_CHUNK_SIZE`.
	 * @param list<string>|null   $post_types_override  Override ad-hoc des post_types ; `null` = défauts F8.
	 * @param array<string,mixed> $filters              Filtres optionnels : `search` (string), `cat_id` (int), `year` (int), `month` (1-12), `builder` (string parmi `BuilderClassifier::ALL_TYPES`).
	 * @param bool                $exclude_normalized   `true` = retire les articles `TYPE_GUTENBERG` (migration finie).
	 * @return array{batch_id: string, total_articles: int, post_ids: list<int>, chunk_size: int}
	 */
	public function start_batch(
		?int $chunk_size = null,
		?array $post_types_override = null,
		array $filters = array(),
		bool $exclude_normalized = false
	): array {
		$resolved_chunk = max( 1, $chunk_size ?? self::DEFAULT_CHUNK_SIZE );
		$post_types     = $post_types_override ?? $this->settings->get_f8_post_types_selection();

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$cat_id = isset( $filters['cat_id'] ) ? (int) $filters['cat_id'] : 0;
		if ( $cat_id > 0 ) {
			$query_args['cat'] = $cat_id;
		}

		$year  = isset( $filters['year'] ) ? (int) $filters['year'] : 0;
		$month = isset( $filters['month'] ) ? (int) $filters['month'] : 0;
		$date_clause = array();
		if ( $year > 0 ) {
			$date_clause['year'] = $year;
		}
		if ( $month >= 1 && $month <= 12 ) {
			$date_clause['month'] = $month;
		}
		if ( array() !== $date_clause ) {
			$query_args['date_query'] = array( $date_clause );
		}

		$search = isset( $filters['search'] ) && is_string( $filters['search'] )
			? trim( $filters['search'] )
			: '';
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$raw      = get_posts( $query_args );
		$post_ids = array();
		foreach ( (array) $raw as $id ) {
			if ( is_numeric( $id ) ) {
				$post_ids[] = (int) $id;
			}
		}

		// Filtre `builder` (cumulable) : nécessite BuilderClassifier
		// (classification par post_meta + post_content). Boucle PHP coûteuse
		// → on skip si aucun filtre builder n'est posé. Le cas par défaut
		// (scan corpus complet sans filtre builder) reste sans coût.
		$builder              = isset( $filters['builder'] ) ? (string) $filters['builder'] : '';
		$builder_filter_valid = '' !== $builder
			&& in_array( $builder, BuilderClassifier::ALL_TYPES, true );
		if ( $builder_filter_valid && array() !== $post_ids ) {
			$classifier = $this->classifier ?? new BuilderClassifier();
			$filtered   = array();
			foreach ( $post_ids as $post_id ) {
				if ( $classifier->classify( $post_id ) === $builder ) {
					$filtered[] = $post_id;
				}
			}
			$post_ids = $filtered;
		}

		// Filtre `exclude_normalized` (cumulable) : retire les articles dont
		// le diagnostic persisté indique `status = 'normal'` ET `is_stale = 0`
		// (= déjà OK et le scan reflète la version courante de l'article).
		// Les articles jamais diagnostiqués ou périmés (`is_stale = 1`) ne
		// sont **pas** exclus — ils doivent être (re)scannés.
		// Coût : 1 requête SQL avec `IN (id, id, ...)` + `status = 'normal'`,
		// bien moins coûteux que la classification PHP par BuilderClassifier.
		if ( $exclude_normalized && array() !== $post_ids ) {
			$already_ok_ids = $this->repository->find_post_ids_with_status(
				$post_ids,
				DiagnosticRecord::STATUS_NORMAL,
				true
			);
			if ( array() !== $already_ok_ids ) {
				$exclude_lookup = array_flip( $already_ok_ids );
				$post_ids       = array_values(
					array_filter(
						$post_ids,
						static fn( int $id ): bool => ! isset( $exclude_lookup[ $id ] )
					)
				);
			}
		}

		return array(
			'batch_id'       => wp_generate_uuid4(),
			'total_articles' => count( $post_ids ),
			'post_ids'       => $post_ids,
			'chunk_size'     => $resolved_chunk,
		);
	}

	/**
	 * Traite un chunk d'articles : diagnostic + upsert pour chacun.
	 *
	 * Les articles dont `get_post()` ne retourne pas un `WP_Post` (suppression
	 * concurrente, ID invalide…) sont silencieusement skippés.
	 *
	 * @param list<int> $post_ids IDs à traiter.
	 * @return array<int, DiagnosticRecord> Map post_id → record diagnostiqué.
	 */
	public function process_chunk( array $post_ids ): array {
		$results = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$record = $this->engine->diagnose( $post );
			$this->repository->upsert( $record );
			$results[ $post->ID ] = $record;
		}
		return $results;
	}
}
