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
	 * Nombre total de pas enregistrés.
	 *
	 * @return int
	 */
	public function count_total(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->table}`" );
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
	 * @param string|null $finished_at         Datetime MySQL ; défaut = now().
	 * @return bool Vrai si l'update a effectué un changement.
	 */
	public function finalize(
		string $uuid,
		int $successful_articles,
		int $refused_articles,
		int $errored_articles,
		?string $finished_at = null
	): bool {
		$finished = $finished_at ?? gmdate( 'Y-m-d H:i:s' );
		$updated  = $this->wpdb->update(
			$this->table,
			array(
				'successful_articles' => $successful_articles,
				'refused_articles'    => $refused_articles,
				'errored_articles'    => $errored_articles,
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
