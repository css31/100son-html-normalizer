<?php
/**
 * Bootstrap PHPUnit du plugin 100son-html-normalizer.
 *
 * Charge l'autoloader Composer et stub les fonctions WordPress strictement
 * nécessaires aux tests unitaires des règles (qui sont indépendantes de WP).
 *
 * Pour les tests d'intégration nécessitant un environnement WordPress réel
 * (PublicApiTest), utiliser un bootstrap dédié à la phase 7+.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SON100_HTMLN_VERSION' ) ) {
	define( 'SON100_HTMLN_VERSION', '0.1.0-test' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub de la fonction `__()` de WordPress.
	 *
	 * @param string $text   Chaîne source.
	 * @param string $domain Text domain (ignoré en test).
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub de `esc_html()` — pour les rares chemins de fallback.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// ===================================================================
// Stubs pour PostsTest : registre interne pour mocker get_post_meta + WP_Post.
// Permet de tester SiteOriginDetector et PostNormalizer sans WordPress.
// ===================================================================

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID                = 0;
		public string $post_content    = '';
		public string $post_title      = '';
		public string $post_status     = 'publish';
		public string $post_type       = 'post';
		public string $post_modified   = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

/**
 * Test harness : registre en mémoire des posts et meta pour stubber WP.
 */
final class Son100_Htmln_Test_Posts_Registry {
	/** @var array<int, \WP_Post> */
	public static array $posts = [];
	/** @var array<int, array<string, mixed>> */
	public static array $meta = [];
	/** @var array<int, int> */
	public static array $revisions_created = [];
	/** @var array<int, string> */
	public static array $updates = [];

	public static function reset(): void {
		self::$posts             = [];
		self::$meta              = [];
		self::$revisions_created = [];
		self::$updates           = [];
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): ?\WP_Post {
		return \Son100_Htmln_Test_Posts_Registry::$posts[ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		$value = \Son100_Htmln_Test_Posts_Registry::$meta[ $post_id ][ $key ] ?? '';
		return $single ? $value : [ $value ];
	}
}

if ( ! function_exists( 'wp_save_post_revision' ) ) {
	function wp_save_post_revision( int $post_id ): int {
		$rev_id                                                            = $post_id * 1000 + count( \Son100_Htmln_Test_Posts_Registry::$revisions_created ) + 1;
		\Son100_Htmln_Test_Posts_Registry::$revisions_created[ $post_id ] = $rev_id;
		return $rev_id;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $data, bool $wp_error = false ): int|\WP_Error {
		$post_id = (int) ( $data['ID'] ?? 0 );
		if ( ! isset( \Son100_Htmln_Test_Posts_Registry::$posts[ $post_id ] ) ) {
			return $wp_error ? new \WP_Error( 'not_found', 'Post not found.' ) : 0;
		}
		\Son100_Htmln_Test_Posts_Registry::$posts[ $post_id ]->post_content = (string) ( $data['post_content'] ?? '' );
		\Son100_Htmln_Test_Posts_Registry::$updates[ $post_id ]            = (string) ( $data['post_content'] ?? '' );
		return $post_id;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object {
		return (object) [ 'ID' => 0, 'user_login' => '' ];
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = [], string $output = 'names' ): array {
		// Stub minimal : retourne par défaut post + page comme types publics.
		$types = array( 'post' => 'post', 'page' => 'page' );
		return $types;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args = [] ): array {
		// Stub : prefiltre `Son100_Htmln_Test_Posts_Registry::$posts` selon
		// post_type et post_status, retourne soit ids soit WP_Post selon `fields`.
		$wanted_types  = (array) ( $args['post_type'] ?? [ 'post' ] );
		$wanted_status = (array) ( $args['post_status'] ?? [ 'publish' ] );
		$fields        = (string) ( $args['fields'] ?? '' );
		$matching      = array();
		foreach ( \Son100_Htmln_Test_Posts_Registry::$posts as $post ) {
			if ( ! in_array( $post->post_type, $wanted_types, true ) ) {
				continue;
			}
			if ( ! in_array( $post->post_status, $wanted_status, true ) ) {
				continue;
			}
			$matching[] = $post;
		}
		if ( 'ids' === $fields ) {
			return array_map( static fn( $p ) => $p->ID, $matching );
		}
		return $matching;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, int $timestamp ): string {
		return date( $format, $timestamp );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['son100_htmln_options'] = $GLOBALS['son100_htmln_options'] ?? [];
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['son100_htmln_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	$GLOBALS['son100_htmln_options'] = $GLOBALS['son100_htmln_options'] ?? [];
	function update_option( string $name, mixed $value, string|bool $autoload = false ): bool {
		$GLOBALS['son100_htmln_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, mixed $value, string $deprecated = '', string|bool $autoload = false ): bool {
		if ( ! isset( $GLOBALS['son100_htmln_options'][ $name ] ) ) {
			$GLOBALS['son100_htmln_options'][ $name ] = $value;
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ), random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		if ( isset( $GLOBALS['son100_htmln_options'][ $name ] ) ) {
			unset( $GLOBALS['son100_htmln_options'][ $name ] );
			return true;
		}
		return false;
	}
}

// ===================================================================
// Stub minimal de $wpdb + dbDelta pour ActivatorTest et repos V1.0.
// Le stub enregistre les SQL passes a dbDelta() et les writes ($wpdb->insert,
// $wpdb->update) pour assertion en test, mais n'execute pas de vraie requete.
// ===================================================================

if ( ! class_exists( 'Son100_Htmln_Test_Wpdb' ) ) {
	/**
	 * Stub minimal de `\wpdb` pour tests unitaires.
	 *
	 * Strategie : journalise les appels (insert_log, update_log, query_log),
	 * et expose 3 files (`get_row_queue`, `get_results_queue`,
	 * `get_var_queue`) que les tests preremplissent pour simuler les
	 * resultats des SELECT. C'est explicite et trace l'intention de chaque
	 * test sans avoir a parser du SQL.
	 *
	 * `prepare()` est volontairement simpliste : suffit pour assertion sur
	 * les SQL envoyes a `query()`/`get_row()`/`get_results()`/`get_var()`,
	 * pas pour reproduire fidelement WordPress.
	 */
	final class Son100_Htmln_Test_Wpdb {
		public string $prefix = 'wptests_';
		public string $postmeta = 'wptests_postmeta';
		public int $insert_id = 0;
		/** @var int|false Valeur retournee par insert/update/query (par defaut 1 = success ; false = echec). */
		public int|false $insert_return = 1;
		public int|false $update_return = 1;
		public int|false $query_return  = 1;

		/** @var list<array{table: string, data: array<string, mixed>, formats: array|null}> */
		public array $insert_log = [];
		/** @var list<array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
		public array $update_log = [];
		/** @var list<string> */
		public array $query_log = [];

		// Queues stub pour les SELECT. Le test prepare la queue, le code consomme.
		/** @var list<mixed> */
		public array $get_row_queue = [];
		/** @var list<mixed> */
		public array $get_results_queue = [];
		/** @var list<mixed> */
		public array $get_var_queue = [];

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
		}

		public function prepare( string $query, mixed ...$args ): string {
			// Aplatissement defensif si appele avec un tableau unique (cas array_unpack).
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			return vsprintf( str_replace( [ '%s', '%d', '%f' ], [ "'%s'", '%d', '%F' ], $query ), $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function query( string $sql ): int {
			$this->query_log[] = $sql;
			return $this->query_return;
		}

		public function insert( string $table, array $data, ?array $formats = null ): int|false {
			$this->insert_log[] = [ 'table' => $table, 'data' => $data, 'formats' => $formats ];
			if ( false === $this->insert_return || 0 === $this->insert_return ) {
				return $this->insert_return;
			}
			++$this->insert_id;
			return $this->insert_return;
		}

		public function update( string $table, array $data, array $where, ?array $formats = null, ?array $where_formats = null ): int|false {
			$this->update_log[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
			return $this->update_return;
		}

		public function get_row( string $sql, string $output = 'OBJECT' ): mixed {
			$this->query_log[] = $sql;
			return [] === $this->get_row_queue ? null : array_shift( $this->get_row_queue );
		}

		public function get_results( string $sql, string $output = 'OBJECT' ): array {
			$this->query_log[] = $sql;
			$next = [] === $this->get_results_queue ? [] : array_shift( $this->get_results_queue );
			return is_array( $next ) ? $next : [];
		}

		public function get_var( string $sql ): mixed {
			$this->query_log[] = $sql;
			return [] === $this->get_var_queue ? null : array_shift( $this->get_var_queue );
		}
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	$GLOBALS['son100_htmln_dbdelta_log'] = $GLOBALS['son100_htmln_dbdelta_log'] ?? [];
	function dbDelta( string|array $sql ): array {
		$queries                                   = is_array( $sql ) ? $sql : [ $sql ];
		$GLOBALS['son100_htmln_dbdelta_log']       = array_merge(
			$GLOBALS['son100_htmln_dbdelta_log'] ?? [],
			$queries
		);
		return [];
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	// Deja defini plus haut — garde-fou si on charge ce bloc plusieurs fois.
}
