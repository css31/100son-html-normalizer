<?php
/**
 * StepsRepository — accès lecture/écriture à la table custom V1.0.
 *
 * Cf. cahier v2.0 §4.2 (schéma) et §3.1 F14/F16.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Steps;

defined( 'ABSPATH' ) || exit;

/**
 * Repository CRUD sur `{$wpdb->prefix}son100_htmln_steps`.
 *
 * Couvre la création initiale d'un pas (status `running`), l'append des
 * résultats par article, la finalisation, et les requêtes de lecture
 * pour la vue Historique (F16) et le bandeau de reprise (cf. §3.1 F14).
 */
class StepsRepository {

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
		$this->table = $wpdb->prefix . 'son100_htmln_steps';
	}

	// =========================================================================
	//  Lecture
	// =========================================================================

	/**
	 * Cherche un pas par UUID.
	 *
	 * @param string $uuid UUID v4.
	 * @return StepRecord|null
	 */
	public function find_by_uuid( string $uuid ): ?StepRecord {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` WHERE step_uuid = %s LIMIT 1",
			$uuid
		);
		$row = $this->wpdb->get_row( $sql, 'ARRAY_A' );
		if ( ! is_array( $row ) ) {
			return null;
		}
		return StepRecord::from_db_row( $row );
	}

	/**
	 * Pas inachevés (finished_at IS NULL), du plus récent au plus ancien.
	 * Utilisé par `StepResumeBanner` (F14, hyp. 31).
	 *
	 * @return list<StepRecord>
	 */
	public function find_unfinished(): array {
		$sql = "SELECT * FROM `{$this->table}` WHERE finished_at IS NULL ORDER BY started_at DESC";
		return $this->fetch_records( $sql );
	}

	/**
	 * Liste paginée pour la vue Historique (F16).
	 *
	 * @param int $limit  Nombre max.
	 * @param int $offset Décalage.
	 * @return list<StepRecord>
	 */
	public function list_recent( int $limit = 50, int $offset = 0 ): array {
		$sql = $this->wpdb->prepare(
			"SELECT * FROM `{$this->table}` ORDER BY started_at DESC LIMIT %d OFFSET %d",
			max( 1, $limit ),
			max( 0, $offset )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Liste paginée filtrée par fenêtre temporelle (F16). Sert
	 * `GET /steps?from&to` (Phase 5.2 — StepsController).
	 *
	 * Les bornes `$from` et `$to` s'appliquent à `started_at` (datetime
	 * MySQL UTC `Y-m-d H:i:s`). Bornes optionnelles, inclusives. Le
	 * caller (BaseController::sanitize_*) est censé avoir validé les
	 * formats — ici on délègue à `wpdb->prepare()`.
	 *
	 * @param string|null $from   Datetime MySQL inclusif, ou null.
	 * @param string|null $to     Datetime MySQL inclusif, ou null.
	 * @param int         $limit  Nombre max.
	 * @param int         $offset Décalage.
	 * @return list<StepRecord>
	 */
	public function list_filtered(
		?string $from,
		?string $to,
		int $limit = 50,
		int $offset = 0
	): array {
		$sql = $this->build_filtered_query(
			"SELECT * FROM `{$this->table}`",
			$from,
			$to,
			" ORDER BY started_at DESC LIMIT %d OFFSET %d",
			array( max( 1, $limit ), max( 0, $offset ) )
		);
		return $this->fetch_records( $sql );
	}

	/**
	 * Nombre total de pas enregistrés.
	 *
	 * @return int
	 */
	public function count_total(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" );
	}

	/**
	 * Liste **tous** les pas terminés (`finished_at IS NOT NULL`), sans
	 * pagination. Utilisé par `RuleCoverageService` pour agréger la
	 * couverture historique de chaque règle (post_ids effectivement
	 * traités avec succès depuis le démarrage de l'extension).
	 *
	 * Volume attendu sur MMM-2 : quelques dizaines de pas — la requête
	 * full-scan reste acceptable. Si le volume gonfle, ce service
	 * devra basculer sur un agrégat persistant ou un cache transient.
	 *
	 * @return list<StepRecord>
	 */
	public function list_all_finished(): array {
		$sql = "SELECT * FROM `{$this->table}` WHERE finished_at IS NOT NULL ORDER BY started_at DESC";
		return $this->fetch_records( $sql );
	}

	/**
	 * Date (`finished_at`) la plus récente d'un pas FINI ayant appliqué
	 * la règle `$rule_id`. Retourne `null` si la règle n'a jamais été
	 * appliquée (ou si aucun pas la concernant n'est terminé).
	 *
	 * Implémenté via `JSON_SEARCH` sur la colonne `applied_rules` (JSON
	 * list de `rule_id`). MySQL 5.7+ / MariaDB 10.2+ requis (déjà exigé
	 * par WP 6.8). Sémantique stricte : `'one'` retourne le chemin
	 * trouvé ou NULL — pas de faux positif (`R1` ne matche pas `R10`).
	 *
	 * @param string $rule_id Identifiant interne (ex. `R5`).
	 * @return string|null Datetime MySQL (`Y-m-d H:i:s`) ou null.
	 */
	public function last_applied_for_rule( string $rule_id ): ?string {
		$sql = $this->wpdb->prepare(
			"SELECT MAX(finished_at) FROM `{$this->table}`
			 WHERE finished_at IS NOT NULL
			   AND JSON_SEARCH(applied_rules, 'one', %s) IS NOT NULL",
			$rule_id
		);
		$value = $this->wpdb->get_var( $sql );
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Steps postérieurs à `$after_datetime` ayant touché l'article `$post_id`.
	 * Sert à la modale de confirmation Rollback : avertir l'admin qu'un
	 * rollback du step N sur 1234 va aussi perdre les écritures des steps
	 * N+1, N+2... qui ont remodifié 1234. Filtre via `JSON_CONTAINS` sur
	 * la colonne `affected_post_ids` (JSON list d'int).
	 *
	 * Sémantique stricte : on retourne les steps **finalisés** dont
	 * `finished_at > $after_datetime` ET qui contiennent `$post_id` dans
	 * `affected_post_ids`. Le step source du rollback (passé via
	 * `$exclude_uuid`) est exclu pour ne pas s'auto-référencer.
	 *
	 * NB : `affected_post_ids` est le snapshot au démarrage du pas — un
	 * article qui y figure peut avoir terminé en `error` ou `regression_pending`
	 * (donc sans écriture). Le caller doit donc reconfirmer via
	 * `per_article_results[post_id].status === 'success'` pour parler de
	 * cascade *réelle*.
	 *
	 * @param int         $post_id        Article concerné.
	 * @param string      $after_datetime Datetime MySQL exclusif (typiquement
	 *                                    `finished_at` du step source).
	 * @param string|null $exclude_uuid   UUID à exclure (le step source), ou null.
	 * @return list<StepRecord>
	 */
	public function find_subsequent_steps_for_post(
		int $post_id,
		string $after_datetime,
		?string $exclude_uuid = null
	): array {
		// JSON_CONTAINS sur JSON list d'int : on encode le post_id en JSON
		// (int → string sans guillemets) puis on délègue à wpdb->prepare()
		// pour quoter les arguments.
		$post_id_json = (string) $post_id;
		if ( null !== $exclude_uuid && '' !== $exclude_uuid ) {
			$sql = $this->wpdb->prepare(
				"SELECT * FROM `{$this->table}`
				 WHERE finished_at IS NOT NULL
				   AND finished_at > %s
				   AND step_uuid <> %s
				   AND JSON_CONTAINS(affected_post_ids, %s)
				 ORDER BY finished_at ASC",
				$after_datetime,
				$exclude_uuid,
				$post_id_json
			);
		} else {
			$sql = $this->wpdb->prepare(
				"SELECT * FROM `{$this->table}`
				 WHERE finished_at IS NOT NULL
				   AND finished_at > %s
				   AND JSON_CONTAINS(affected_post_ids, %s)
				 ORDER BY finished_at ASC",
				$after_datetime,
				$post_id_json
			);
		}
		return $this->fetch_records( $sql );
	}

	/**
	 * Nombre de pas dans une fenêtre temporelle. Sert au calcul de
	 * `total_pages` côté SPA (F16).
	 *
	 * @param string|null $from Datetime MySQL inclusif, ou null.
	 * @param string|null $to   Datetime MySQL inclusif, ou null.
	 * @return int
	 */
	public function count_filtered( ?string $from, ?string $to ): int {
		$sql = $this->build_filtered_query(
			"SELECT COUNT(*) FROM `{$this->table}`",
			$from,
			$to,
			'',
			array()
		);
		return (int) $this->wpdb->get_var( $sql );
	}

	// =========================================================================
	//  Écriture
	// =========================================================================

	/**
	 * Crée un pas en status `running` et retourne son ID en base.
	 *
	 * @param string       $uuid              UUID v4 (typiquement `wp_generate_uuid4()`).
	 * @param list<string> $applied_rules     IDs des règles appliquées.
	 * @param list<int>    $affected_post_ids IDs articles ciblés.
	 * @param int|null     $user_id           Auteur (null en CLI).
	 * @param string       $started_at        Datetime MySQL ; défaut = now().
	 * @return int|false ID inseré ou false si l'insert a échoué.
	 */
	public function insert_running(
		string $uuid,
		array $applied_rules,
		array $affected_post_ids,
		?int $user_id = null,
		string $started_at = ''
	): int|false {
		$started = '' !== $started_at ? $started_at : gmdate( 'Y-m-d H:i:s' );
		$record  = new StepRecord(
			id: null,
			step_uuid: $uuid,
			applied_rules: $applied_rules,
			affected_post_ids: $affected_post_ids,
			total_articles: count( $affected_post_ids ),
			successful_articles: 0,
			refused_articles: 0,
			errored_articles: 0,
			pending_articles: 0,
			per_article_results: array(),
			user_id: $user_id,
			started_at: $started,
			finished_at: null,
		);
		$row = $record->to_db_row();
		unset( $row['id'] );
		$result = $this->wpdb->insert( $this->table, $row );
		if ( false === $result || 0 === $result ) {
			return false;
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Append un résultat par article au champ `per_article_results`.
	 *
	 * Stratégie : on relit `per_article_results` complet, on merge l'entrée,
	 * on UPDATE en bloc. Coût acceptable (1 read + 1 write par article)
	 * compatible avec le rythme F14 (chunking de 10-20 articles côté SPA).
	 *
	 * @param string                                                              $uuid    UUID du pas.
	 * @param int                                                                 $post_id Article concerné.
	 * @param array{status: string, regression?: array<string, mixed>, error?: string} $result Résultat à enregistrer.
	 * @return bool Vrai si l'update a réussi.
	 */
	public function update_per_article_result( string $uuid, int $post_id, array $result ): bool {
		$existing = $this->find_by_uuid( $uuid );
		if ( null === $existing ) {
			return false;
		}
		$results             = $existing->per_article_results;
		$results[ $post_id ] = $result;

		$json = wp_json_encode( $results );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$updated = $this->wpdb->update(
			$this->table,
			array( 'per_article_results' => $json ),
			array( 'step_uuid' => $uuid )
		);
		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Marque le pas comme finalisé : pose les totaux et `finished_at`.
	 *
	 * @param string      $uuid                UUID du pas.
	 * @param int         $successful_articles Total succès.
	 * @param int         $refused_articles    Total refus admin.
	 * @param int         $errored_articles    Total erreurs.
	 * @param int         $pending_articles    Total articles en `regression_pending` non arbitrés.
	 * @param string|null $finished_at         Datetime MySQL ; défaut = now().
	 * @return bool Vrai si l'update a effectué un changement.
	 */
	public function finalize(
		string $uuid,
		int $successful_articles,
		int $refused_articles,
		int $errored_articles,
		int $pending_articles = 0,
		?string $finished_at = null
	): bool {
		$finished = $finished_at ?? gmdate( 'Y-m-d H:i:s' );
		$updated  = $this->wpdb->update(
			$this->table,
			array(
				'successful_articles' => $successful_articles,
				'refused_articles'    => $refused_articles,
				'errored_articles'    => $errored_articles,
				'pending_articles'    => $pending_articles,
				'finished_at'         => $finished,
			),
			array( 'step_uuid' => $uuid )
		);
		return is_int( $updated ) && $updated > 0;
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Construit dynamiquement une requête SELECT/COUNT avec clause WHERE
	 * temporelle optionnelle. Les bornes sont passées via `prepare()` —
	 * jamais de concaténation directe.
	 *
	 * @param string             $base_select Préfixe SQL (ex. `SELECT * FROM …`).
	 * @param string|null        $from        Borne basse `started_at >=`.
	 * @param string|null        $to          Borne haute `started_at <=`.
	 * @param string             $suffix      Suffixe SQL (ex. ORDER BY + LIMIT).
	 * @param list<int|string>   $suffix_args Arguments du suffixe.
	 * @return string
	 */
	private function build_filtered_query(
		string $base_select,
		?string $from,
		?string $to,
		string $suffix,
		array $suffix_args
	): string {
		$where  = array();
		$args   = array();
		if ( null !== $from && '' !== $from ) {
			$where[] = 'started_at >= %s';
			$args[]  = $from;
		}
		if ( null !== $to && '' !== $to ) {
			$where[] = 'started_at <= %s';
			$args[]  = $to;
		}
		$sql = $base_select;
		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= $suffix;
		$all_args = array_merge( $args, $suffix_args );
		if ( array() === $all_args ) {
			return $sql;
		}
		return $this->wpdb->prepare( $sql, $all_args );
	}

	/**
	 * @param string $sql SQL préparé.
	 * @return list<StepRecord>
	 */
	private function fetch_records( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, 'ARRAY_A' );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$result[] = StepRecord::from_db_row( $row );
			}
		}
		return $result;
	}
}
