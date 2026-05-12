<?php
/**
 * DiagnosticsRepository — accès lecture/écriture à la table custom V1.0.
 *
 * Cf. cahier v2.0 §4.2 (schéma) et §3.1 F12 (DiagnosticEngine).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Diagnostics;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;

/**
 * Repository CRUD sur `{$wpdb->prefix}son100_htmln_diagnostics`.
 *
 * Convention :
 *  - Méthodes lecture renvoient `null` ou un DTO `DiagnosticRecord` ;
 *  - Méthodes écriture renvoient `int` (rows affectées) ou `bool` (succès).
 *  - Tous les accès passent par `wpdb::prepare()` quand des valeurs externes
 *    entrent dans le SQL — pas de concaténation directe.
 *
 * Volontairement non-final pour permettre l'extension/stub en tests
 * d'intégration (DiagnosticInvalidatorTest…). Même convention que
 * `SettingsRepository` et `PresetRegistry`.
 */
class DiagnosticsRepository {

	/**
	 * Adapter `$wpdb`.
	 *
	 * @var \wpdb
	 */
	private object $wpdb;

	/**
	 * Nom complet de la table avec préfixe.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * @param object|null $wpdb Adapter `\wpdb`. Si null, utilise `$GLOBALS['wpdb']`.
	 */
	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		/** @var \wpdb $wpdb */
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'son100_htmln_diagnostics';
	}

	// =========================================================================
	//  Lecture
	// =========================================================================

	/**
	 * Récupère le diagnostic d'un article (un seul existe par post_id).
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return DiagnosticRecord|null
	 */
	public function find_by_post_id( int $post_id ): ?DiagnosticRecord {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE post_id = %d LIMIT 1",
			$post_id
		);
		$row = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return DiagnosticRecord::from_db_row( $row );
	}

	/**
	 * Liste paginée des diagnostics filtrés par status.
	 *
	 * @param string $status `normal` ou `to_improve`.
	 * @param int    $limit  Nombre max de résultats.
	 * @param int    $offset Décalage.
	 * @return list<DiagnosticRecord>
	 */
	public function list_by_status( string $status, int $limit = 50, int $offset = 0 ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE status = %s ORDER BY diagnosed_at DESC LIMIT %d OFFSET %d",
			$status,
			max( 1, $limit ),
			max( 0, $offset )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Liste paginée des diagnostics is_stale = 1 (onglet F13 « Diagnostics
	 * obsolètes »).
	 *
	 * @param int $limit  Nombre max de résultats.
	 * @param int $offset Décalage.
	 * @return list<DiagnosticRecord>
	 */
	public function list_stale( int $limit = 50, int $offset = 0 ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE is_stale = 1 ORDER BY diagnosed_at DESC LIMIT %d OFFSET %d",
			max( 1, $limit ),
			max( 0, $offset )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Compte des diagnostics par status, utile pour les badges des onglets F13.
	 *
	 * `total` : nombre total de lignes dans la table, peu importe is_stale ou
	 * status. Utile pour `GET /diagnostics/stats` (F13) et la SPA pour calculer
	 * la part de stale dans l'ensemble.
	 *
	 * @return array{normal: int, to_improve: int, stale: int, total: int}
	 */
	public function count_by_status(): array {
		$sql_normal   = "SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'normal' AND is_stale = 0";
		$sql_improve  = "SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'to_improve' AND is_stale = 0";
		$sql_stale    = "SELECT COUNT(*) FROM `{$this->table}` WHERE is_stale = 1";
		$sql_total    = "SELECT COUNT(*) FROM `{$this->table}`";

		return array(
			'normal'     => (int) $this->wpdb->get_var( $sql_normal ),
			'to_improve' => (int) $this->wpdb->get_var( $sql_improve ),
			'stale'      => (int) $this->wpdb->get_var( $sql_stale ),
			'total'      => (int) $this->wpdb->get_var( $sql_total ),
		);
	}

	/**
	 * Liste paginée unifiée pour `GET /diagnostics` (F13). Filtre par status
	 * optionnel : `null` retourne tous les diagnostics. Filtres additionnels
	 * (post-rc3) : `search` / `cat_id` / `year` / `month` / `builder`.
	 *
	 * Sémantique du filtre `status` :
	 *  - `'normal'`     : `status = 'normal'` ET `is_stale = 0`.
	 *  - `'to_improve'` : `status = 'to_improve'` ET `is_stale = 0`.
	 *  - `'stale'`      : `is_stale = 1` (peu importe `status`).
	 *  - `null`         : pas de filtre, tous les diagnostics.
	 *  - autre valeur   : liste vide (status inconnu — défense en profondeur).
	 *
	 * Sémantique du tableau `$filters` (toutes clés optionnelles) :
	 *  - `search` (string)  : numérique → `post_id = N` exact ; sinon JOIN
	 *                         `wp_posts` + `post_title LIKE %X%` (titre seul,
	 *                         pas content/excerpt — alignement V0.1).
	 *  - `cat_id` (int > 0) : JOIN `wp_term_relationships` + `wp_term_taxonomy`
	 *                         sur `taxonomy = 'category'`.
	 *  - `year`   (int > 0) : `YEAR(p.post_date) = N` (JOIN wp_posts).
	 *  - `month`  (int 1-12): `MONTH(p.post_date) = N` (combiné avec year).
	 *  - `builder` (string) : `siteorigin` (couvre siteorigin + siteorigin_flat) |
	 *                         `gutenberg` | `other` | `out`. Autre → ignoré.
	 *
	 * @param string|null                                                                  $status  Filtre status, ou null pour tous.
	 * @param int                                                                          $limit   Nombre max.
	 * @param int                                                                          $offset  Décalage.
	 * @param array{search?: string, cat_id?: int, year?: int, month?: int, builder?: string, rule_ids?: list<string>} $filters Filtres additionnels (post-rc3).
	 * @return list<DiagnosticRecord>
	 */
	public function list_paginated( ?string $status, int $limit = 50, int $offset = 0, array $filters = array() ): array {
		$clauses = $this->build_filter_clauses( $status, $filters );
		if ( null === $clauses ) {
			return array();
		}
		$sql = "SELECT d.* FROM `{$this->table}` d" . $clauses['joins'];
		if ( '' !== $clauses['where'] ) {
			$sql .= ' WHERE ' . $clauses['where'];
		}
		$sql .= ' ORDER BY d.diagnosed_at DESC LIMIT %d OFFSET %d';
		$params = array_merge( $clauses['params'], array( max( 1, $limit ), max( 0, $offset ) ) );
		return $this->fetch_records( $this->wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Comptage paginé compagnon de `list_paginated()` — sert au calcul de
	 * `total_pages` côté contrôleur REST. Accepte les mêmes filtres.
	 *
	 * @param string|null                                                                  $status  Filtre status, ou null pour tous.
	 * @param array{search?: string, cat_id?: int, year?: int, month?: int, builder?: string, rule_ids?: list<string>} $filters Filtres additionnels.
	 * @return int
	 */
	public function count_paginated( ?string $status, array $filters = array() ): int {
		$clauses = $this->build_filter_clauses( $status, $filters );
		if ( null === $clauses ) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM `{$this->table}` d" . $clauses['joins'];
		if ( '' !== $clauses['where'] ) {
			$sql .= ' WHERE ' . $clauses['where'];
		}
		if ( array() === $clauses['params'] ) {
			return (int) $this->wpdb->get_var( $sql );
		}
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$clauses['params'] ) );
	}

	/**
	 * Liste des années (4 chiffres) ayant au moins un diagnostic, triées
	 * décroissant. Sert au populate du dropdown « Année » du filtre SPA.
	 *
	 * On dérive depuis `diagnosed_at` (DATETIME stocké côté custom table)
	 * plutôt que `wp_posts.post_date` pour éviter le JOIN coûteux — la
	 * date du diagnostic est toujours postérieure à la création du post,
	 * donc cohérente comme axe temporel.
	 *
	 * @return list<int>
	 */
	public function list_distinct_years(): array {
		$sql  = "SELECT DISTINCT YEAR(p.post_date) AS y
		         FROM `{$this->table}` d
		         INNER JOIN `{$this->wpdb->posts}` p ON p.ID = d.post_id
		         ORDER BY y DESC";
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$years = array();
		foreach ( $rows as $row ) {
			$y = isset( $row['y'] ) ? (int) $row['y'] : 0;
			if ( $y > 0 ) {
				$years[] = $y;
			}
		}
		return $years;
	}

	/**
	 * Compte les diagnostics dont `builder_type` est NULL (rows pré-2.1.0
	 * qui n'ont pas encore été classifiées). Sert au DiagnosticsController
	 * pour décider s'il faut déclencher un backfill avant un filtrage par
	 * builder.
	 *
	 * @return int
	 */
	public function count_null_builder_types(): int {
		$sql = "SELECT COUNT(*) FROM `{$this->table}` WHERE builder_type IS NULL";
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Backfill un batch de rows à `builder_type IS NULL` en classifiant
	 * via le classifier passé en argument. Retourne le nombre de rows
	 * effectivement mises à jour — 0 si plus rien à backfiller.
	 *
	 * Itéré par le caller jusqu'à retour 0 pour couvrir tout le corpus.
	 * Le batching évite une grosse transaction sur un corpus large ;
	 * 500 rows par batch ≈ 1 seconde sur DevKinsta.
	 *
	 * @param object $classifier Doit implémenter `classify(int): string`
	 *                           — typiquement `Core\Posts\BuilderClassifier`.
	 *                           Typé `object` pour permettre l'injection
	 *                           d'un stub en test.
	 * @param int    $batch_size Limit de la requête SELECT. Default 500.
	 * @return int Nombre de rows backfillées dans ce batch.
	 */
	public function backfill_builder_types_batch( object $classifier, int $batch_size = 500 ): int {
		$sql = $this->wpdb->prepare(
			"SELECT post_id FROM `{$this->table}` WHERE builder_type IS NULL LIMIT %d",
			max( 1, $batch_size )
		);
		$post_ids = $this->wpdb->get_col( $sql );
		if ( ! is_array( $post_ids ) || array() === $post_ids ) {
			return 0;
		}
		$count = 0;
		foreach ( $post_ids as $pid_raw ) {
			$pid  = (int) $pid_raw;
			$type = $classifier->classify( $pid );
			if ( ! is_string( $type ) || '' === $type ) {
				continue;
			}
			$result = $this->wpdb->update(
				$this->table,
				array( 'builder_type' => $type ),
				array( 'post_id' => $pid ),
			);
			if ( false !== $result ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Comptage de diagnostics par type de constructeur. Sert au populate
	 * du dropdown « Constructeur » du filtre SPA avec des compteurs.
	 *
	 * Regroupe les 2 variants SiteOrigin (natif + aplati) sous la clé
	 * `siteorigin` — alignement avec le filtre 4-valeurs côté UI.
	 *
	 * @return array<string, int> Map type → count (clés : siteorigin /
	 *                            gutenberg / other / out / unknown).
	 */
	public function count_by_builder(): array {
		$sql  = "SELECT builder_type, COUNT(*) AS c FROM `{$this->table}` GROUP BY builder_type";
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		$out  = array(
			'siteorigin' => 0,
			'gutenberg'  => 0,
			'other'      => 0,
			'out'        => 0,
			'unknown'    => 0,
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$type  = isset( $row['builder_type'] ) ? (string) $row['builder_type'] : '';
			$count = isset( $row['c'] ) ? (int) $row['c'] : 0;
			switch ( $type ) {
				case 'siteorigin':
				case 'siteorigin_flat':
					$out['siteorigin'] += $count;
					break;
				case 'gutenberg':
					$out['gutenberg'] += $count;
					break;
				case 'other':
					$out['other'] += $count;
					break;
				case 'out':
					$out['out'] += $count;
					break;
				default:
					$out['unknown'] += $count;
			}
		}
		return $out;
	}

	/**
	 * Comptage d'articles diagnostiqués par règle applicable (clé du JSON
	 * `matching_rules`). Sert au populate du dropdown « Règles » du filtre
	 * SPA avec compteur « (N) » par règle.
	 *
	 * Sémantique du compteur : nombre d'articles ayant **au moins une**
	 * occurrence de la règle (pas la somme des occurrences). C'est aligné
	 * avec le filtre lui-même qui retourne « les articles où cette règle
	 * s'applique ».
	 *
	 * Stratégie : un seul SELECT plein de la colonne `matching_rules`,
	 * agrégation PHP. Plus simple que 9 `JSON_SEARCH(... COUNT)` séparés,
	 * et le volume reste borné (≤ ~1000 rows typique).
	 *
	 * @return array<string, int> Map rule_id → count. Toutes les clés
	 *                            `PresetRegistry::PRESETS` présentes,
	 *                            même celles à 0 (UX stable pour la SPA).
	 */
	public function count_by_applicable_rule(): array {
		$out = array();
		foreach ( PresetRegistry::PRESETS as $rule_id ) {
			$out[ $rule_id ] = 0;
		}

		$sql  = "SELECT matching_rules FROM `{$this->table}` WHERE matching_rules IS NOT NULL AND matching_rules != ''";
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$json = isset( $row['matching_rules'] ) ? (string) $row['matching_rules'] : '';
			if ( '' === $json ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			// Dédoublonnage par row : si la même règle apparaît deux fois
			// (ne devrait pas, mais le format n'interdit pas), on compte
			// l'article une seule fois.
			$seen = array();
			foreach ( $decoded as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$rid = isset( $entry['rule_id'] ) ? (string) $entry['rule_id'] : '';
				if ( '' === $rid || isset( $seen[ $rid ] ) ) {
					continue;
				}
				$seen[ $rid ] = true;
				if ( isset( $out[ $rid ] ) ) {
					$out[ $rid ]++;
				}
			}
		}

		return $out;
	}

	// =========================================================================
	//  Écriture
	// =========================================================================

	/**
	 * Insère ou met à jour le diagnostic d'un article (un seul par post_id).
	 *
	 * Stratégie : on regarde si une ligne existe ; UPDATE si oui, INSERT sinon.
	 * Pas de `INSERT … ON DUPLICATE KEY` car portabilité douteuse selon les
	 * adapters $wpdb stubbés.
	 *
	 * @param DiagnosticRecord $record DTO à persister (id ignoré pour upsert).
	 * @return bool Vrai si l'opération a effectué une écriture.
	 */
	public function upsert( DiagnosticRecord $record ): bool {
		$existing = $this->find_by_post_id( $record->post_id );
		$row      = $record->to_db_row();
		// Pour l'upsert, on retire l'id du payload (la colonne reste pilotée par AUTO_INCREMENT).
		unset( $row['id'] );

		if ( null === $existing ) {
			$result = $this->wpdb->insert( $this->table, $row );
			return false !== $result && 0 !== $result;
		}

		$result = $this->wpdb->update(
			$this->table,
			$row,
			array( 'post_id' => $record->post_id )
		);
		return false !== $result;
	}

	/**
	 * Marque is_stale = 1 sur le diagnostic d'un article. No-op si aucun
	 * diagnostic n'existe pour ce post_id.
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return bool Vrai si une ligne a été modifiée.
	 */
	public function mark_stale_for_post( int $post_id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'is_stale' => 1 ),
			array( 'post_id' => $post_id )
		);
		return is_int( $result ) && $result > 0;
	}

	/**
	 * Supprime le diagnostic d'un article (utile lors d'un trash/delete WP).
	 *
	 * @param int $post_id Identifiant de l'article.
	 * @return bool Vrai si une ligne a été supprimée.
	 */
	public function delete_for_post( int $post_id ): bool {
		$sql = $this->wpdb->prepare(
			"DELETE FROM `{$this->table}` WHERE post_id = %d",
			$post_id
		);
		return $this->wpdb->query( $sql ) > 0;
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Construit la clause WHERE pour le filtrage par status. Hardcodé car
	 * limité à 4 valeurs whitelistées — pas de prepare() nécessaire.
	 *
	 * Préfixe avec `d.` (alias systématique de la table diagnostics dans
	 * les requêtes filtrées) pour éviter toute ambiguïté avec d'éventuelles
	 * colonnes `status` ou `is_stale` venant de tables JOINées.
	 *
	 * @param string|null $status Statut ou null.
	 * @return string|null Clause WHERE (ou chaîne vide pour pas de filtre),
	 *                     ou `null` pour signaler un status inconnu (le
	 *                     caller retourne array vide / 0 sans frapper la BDD).
	 */
	private function build_status_clause( ?string $status ): ?string {
		return match ( $status ) {
			null         => '',
			'normal'     => "d.status = 'normal' AND d.is_stale = 0",
			'to_improve' => "d.status = 'to_improve' AND d.is_stale = 0",
			'stale'      => 'd.is_stale = 1',
			default      => null,
		};
	}

	/**
	 * Compose les fragments JOIN + WHERE + params pour une requête filtrée.
	 *
	 * Pattern unique partagé par `list_paginated()` et `count_paginated()` :
	 * les deux requêtes ont exactement les mêmes JOIN/WHERE, seul le
	 * SELECT et le ORDER BY/LIMIT diffèrent. Centraliser ici garantit que
	 * `list` et `count` restent cohérents (jamais de drift où count
	 * retourne X mais list X+1 articles parce qu'un filtre a été oublié
	 * dans un seul des deux).
	 *
	 * @param string|null                                                                  $status  Filtre status.
	 * @param array{search?: string, cat_id?: int, year?: int, month?: int, builder?: string, rule_ids?: list<string>} $filters Filtres additionnels.
	 * @return array{joins: string, where: string, params: list<mixed>}|null
	 *   `null` = status invalide → caller retourne array vide / 0.
	 *   `joins` est préfixé par espace si non vide (concaténable directement).
	 *   `where` est sans `WHERE` (le caller ajoute si non vide).
	 *   `params` est ordonné pour `$wpdb->prepare(..., ...$params)`.
	 */
	private function build_filter_clauses( ?string $status, array $filters ): ?array {
		$status_clause = $this->build_status_clause( $status );
		if ( null === $status_clause ) {
			return null;
		}

		$where_parts = array();
		if ( '' !== $status_clause ) {
			$where_parts[] = $status_clause;
		}
		$joins  = array();
		$params = array();

		// search : numérique → post_id exact ; sinon → JOIN posts + LIKE titre.
		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$where_parts[] = 'd.post_id = %d';
				$params[]      = (int) $search;
			} else {
				$joins['posts'] = " INNER JOIN `{$this->wpdb->posts}` p ON p.ID = d.post_id";
				$where_parts[]  = 'p.post_title LIKE %s';
				$like_method    = method_exists( $this->wpdb, 'esc_like' ) ? array( $this->wpdb, 'esc_like' ) : null;
				$escaped        = null !== $like_method ? ( $like_method )( $search ) : $search;
				$params[]       = '%' . $escaped . '%';
			}
		}

		// cat_id : JOIN term_relationships + term_taxonomy.
		$cat_id = isset( $filters['cat_id'] ) ? (int) $filters['cat_id'] : 0;
		if ( $cat_id > 0 ) {
			$joins['terms'] = " INNER JOIN `{$this->wpdb->term_relationships}` tr ON tr.object_id = d.post_id"
				. " INNER JOIN `{$this->wpdb->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id";
			$where_parts[]  = "tt.taxonomy = 'category' AND tt.term_id = %d";
			$params[]       = $cat_id;
		}

		// year + month : JOIN posts si pas déjà fait.
		$year  = isset( $filters['year'] ) ? (int) $filters['year'] : 0;
		$month = isset( $filters['month'] ) ? (int) $filters['month'] : 0;
		if ( $year > 0 ) {
			if ( ! isset( $joins['posts'] ) ) {
				$joins['posts'] = " INNER JOIN `{$this->wpdb->posts}` p ON p.ID = d.post_id";
			}
			$where_parts[] = 'YEAR(p.post_date) = %d';
			$params[]      = $year;
			if ( $month >= 1 && $month <= 12 ) {
				$where_parts[] = 'MONTH(p.post_date) = %d';
				$params[]      = $month;
			}
		}

		// builder : `siteorigin` couvre les 2 variants (natif + flat).
		$builder = isset( $filters['builder'] ) ? (string) $filters['builder'] : '';
		switch ( $builder ) {
			case 'siteorigin':
				$where_parts[] = "d.builder_type IN ('siteorigin', 'siteorigin_flat')";
				break;
			case 'gutenberg':
			case 'other':
			case 'out':
				$where_parts[] = 'd.builder_type = %s';
				$params[]      = $builder;
				break;
		}

		// rule_ids : filtre OR sur le JSON `matching_rules`.
		// Format stocké : `[{"rule_id":"P1","occurrences":12}, …]`.
		// `JSON_SEARCH(... 'one', %s, NULL, '$[*].rule_id')` retourne le
		// chemin trouvé (ex. `"$[0].rule_id"`) ou NULL — précis et sans
		// faux positifs (`P1` ne matche pas `P10`). Disponible MySQL 5.7+
		// que WordPress 6.8 exige déjà.
		//
		// Sémantique : OR (au moins une des règles cochées s'applique à
		// l'article). Pas d'AND en V1.0 (cf. décision UX).
		$rule_ids = isset( $filters['rule_ids'] ) && is_array( $filters['rule_ids'] )
			? array_values( array_filter( $filters['rule_ids'], 'is_string' ) )
			: array();
		if ( array() !== $rule_ids ) {
			$rule_clauses = array();
			foreach ( $rule_ids as $rid ) {
				$rule_clauses[] = "JSON_SEARCH(d.matching_rules, 'one', %s, NULL, '$[*].rule_id') IS NOT NULL";
				$params[]       = $rid;
			}
			$where_parts[] = '(' . implode( ' OR ', $rule_clauses ) . ')';
		}

		return array(
			'joins'  => implode( '', $joins ),
			'where'  => implode( ' AND ', $where_parts ),
			'params' => $params,
		);
	}

	/**
	 * @param string $sql SQL préparé.
	 * @return list<DiagnosticRecord>
	 */
	private function fetch_records( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$result[] = DiagnosticRecord::from_db_row( $row );
			}
		}
		return $result;
	}
}
