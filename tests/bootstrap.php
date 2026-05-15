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
		public string $post_date       = '';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $code;
		private string $message;
		/** @var array<string, mixed> */
		private array $data;

		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
		/** @return array<string, mixed> */
		public function get_error_data(): array {
			return $this->data;
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
	/** @var array<int, list<string>> Catégories par post_id — noms simples (utilisé par le stub `wp_get_post_categories`). */
	public static array $categories = [];

	public static function reset(): void {
		self::$posts             = [];
		self::$meta              = [];
		self::$revisions_created = [];
		self::$updates           = [];
		self::$categories        = [];
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

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ): mixed {
		$post = \Son100_Htmln_Test_Posts_Registry::$posts[ $post_id ] ?? null;
		if ( null === $post ) {
			return '';
		}
		return property_exists( $post, $field ) ? $post->{$field} : '';
	}
}

if ( ! function_exists( 'wp_get_post_categories' ) ) {
	/**
	 * Stub minimal : lit `Son100_Htmln_Test_Posts_Registry::$categories[$post_id]`,
	 * un simple `list<string>` de noms. Ne supporte que l'argument
	 * `fields => 'names'` (utilisé par `DiffController`) ; autres modes
	 * renvoient le même résultat — suffisant pour la couverture actuelle.
	 *
	 * @param int                  $post_id Identifiant de l'article.
	 * @param array<string, mixed> $args    Options (ignorées hormis 'fields').
	 * @return list<string>
	 */
	function wp_get_post_categories( int $post_id, array $args = array() ): array {
		unset( $args );
		return \Son100_Htmln_Test_Posts_Registry::$categories[ $post_id ] ?? array();
	}
}

if ( ! function_exists( 'has_blocks' ) ) {
	/**
	 * Stub `has_blocks` — détection minimale du marqueur `<!-- wp:` qui suffit
	 * pour les tests BuilderClassifier. La vraie WP fait un check plus
	 * sophistiqué (vérifie la fin `-->`) mais l'esprit est le même.
	 */
	function has_blocks( mixed $post = null ): bool {
		if ( is_string( $post ) ) {
			return str_contains( $post, '<!-- wp:' );
		}
		if ( $post instanceof \WP_Post ) {
			return str_contains( (string) $post->post_content, '<!-- wp:' );
		}
		return false;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Stub `get_permalink` — URL publique simulée pour les tests SPA.
	 * Format aligné sur la convention WP /?p=ID (la sandbox utilise
	 * pretty permalinks en réalité, mais ça suffit pour valider le
	 * round-trip dans le payload REST).
	 */
	function get_permalink( int|\WP_Post|null $post = null ): string|false {
		$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		if ( $id <= 0 ) {
			return false;
		}
		return 'http://example.test/?p=' . $id;
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	/**
	 * Stub `get_edit_post_link` — URL admin simulée. La vraie WP cherche
	 * le post type et applique des capability checks ; ici on retourne
	 * juste un format prévisible pour assertions de test.
	 */
	function get_edit_post_link( int $post_id = 0, string $context = 'display' ): ?string {
		unset( $context );
		if ( $post_id <= 0 ) {
			return null;
		}
		return 'http://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, mixed $value, mixed $prev_value = '' ): int|bool {
		\Son100_Htmln_Test_Posts_Registry::$meta[ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		if ( isset( \Son100_Htmln_Test_Posts_Registry::$meta[ $post_id ][ $key ] ) ) {
			unset( \Son100_Htmln_Test_Posts_Registry::$meta[ $post_id ][ $key ] );
			return true;
		}
		return false;
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

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	$GLOBALS['son100_htmln_test_is_revision'] = $GLOBALS['son100_htmln_test_is_revision'] ?? [];
	function wp_is_post_revision( int|\WP_Post $post ): false|int {
		$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return $GLOBALS['son100_htmln_test_is_revision'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	$GLOBALS['son100_htmln_test_is_autosave'] = $GLOBALS['son100_htmln_test_is_autosave'] ?? [];
	function wp_is_post_autosave( int|\WP_Post $post ): false|int {
		$id = $post instanceof \WP_Post ? $post->ID : (int) $post;
		return $GLOBALS['son100_htmln_test_is_autosave'][ $id ] ?? false;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	$GLOBALS['son100_htmln_test_actions'] = $GLOBALS['son100_htmln_test_actions'] ?? [];
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['son100_htmln_test_actions'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = [], string $output = 'names' ): array {
		// Stub minimal : retourne par défaut post + page comme types publics.
		$types = array( 'post' => 'post', 'page' => 'page' );
		if ( 'objects' === $output ) {
			$out = array();
			foreach ( $types as $slug => $name ) {
				$obj         = new \stdClass();
				$obj->name   = $slug;
				$obj->labels = (object) array(
					'singular_name' => ucfirst( $slug ),
				);
				$out[ $slug ] = $obj;
			}
			return $out;
		}
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

// ===================================================================
// Stubs REST (Phase 5).
// ===================================================================

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub minimal de `WP_REST_Request` — porte des params + métadonnées route.
	 */
	final class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params = array();
		private string $method;
		private string $route;

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string { return $this->method; }
		public function get_route(): string { return $this->route; }

		/** @return array<string, mixed> */
		public function get_params(): array { return $this->params; }

		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub minimal de `WP_REST_Response`.
	 */
	final class WP_REST_Response {
		private mixed $data;
		private int $status;
		/** @var array<string, string> */
		private array $headers;

		/**
		 * @param mixed                 $data    Payload.
		 * @param int                   $status  Code HTTP.
		 * @param array<string, string> $headers En-têtes.
		 */
		public function __construct( mixed $data = null, int $status = 200, array $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
		public function set_status( int $status ): void { $this->status = $status; }

		/** @return array<string, string> */
		public function get_headers(): array { return $this->headers; }

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	$GLOBALS['son100_htmln_test_rest_routes'] = $GLOBALS['son100_htmln_test_rest_routes'] ?? array();
	/**
	 * Stub `register_rest_route` — stocke chaque route enregistrée dans un
	 * registre global utilisable en assertion par les tests.
	 *
	 * @param string                                                  $namespace  Namespace REST (ex. `htmln/v1`).
	 * @param string                                                  $route      Pattern route (ex. `/steps/(?P<uuid>[a-f0-9-]+)`).
	 * @param array<string, mixed>|list<array<string, mixed>>         $args       Méthodes/args.
	 * @param bool                                                    $override   Réservé.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
		$GLOBALS['son100_htmln_test_rest_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
			'override'  => $override,
		);
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	$GLOBALS['son100_htmln_test_can_default'] = true;
	/** @var array<string, bool> Capabilities par-clé pour overrides ciblés. */
	$GLOBALS['son100_htmln_test_caps'] = array();
	function current_user_can( string $capability ): bool {
		return $GLOBALS['son100_htmln_test_caps'][ $capability ]
			?? $GLOBALS['son100_htmln_test_can_default']
			?? true;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	$GLOBALS['son100_htmln_test_nonce_valid'] = true;
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		return $GLOBALS['son100_htmln_test_nonce_valid'] ? 1 : false;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	$GLOBALS['son100_htmln_test_is_admin'] = false;
	function is_admin(): bool {
		return (bool) $GLOBALS['son100_htmln_test_is_admin'];
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'http://example.test/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Stub `wp_kses_post` — la vraie filtre via le tableau global
	 * `$allowedposttags` et préserve les commentaires HTML (donc les
	 * commentaires `<!-- wp:* -->` de la block grammar Gutenberg). Pour
	 * les tests unitaires on simule ce contrat minimal :
	 *  - les commentaires HTML restent intacts (essentiel pour les tests
	 *    `RichNotesRepository` qui valident le round-trip block grammar) ;
	 *  - les `<script>` complets (tag + contenu) sont strippés (anti-XSS
	 *    basique, suffisant pour valider la sanitization côté test) ;
	 *  - le reste passe.
	 *
	 * Suffisant pour les tests qui se concentrent sur la sémantique du
	 * repo et du controller, pas sur l'exhaustivité de kses.
	 *
	 * @param mixed $value Entrée brute.
	 * @return string
	 */
	function wp_kses_post( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$str = (string) $value;
		return preg_replace(
			'#<script\b[^>]*>.*?</script>#is',
			'',
			$str
		) ?? $str;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return is_scalar( $value )
			? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''
			: '';
	}
}

// ===================================================================
// Stubs WP-CLI (Phase 5.5).
// ===================================================================

if ( ! class_exists( 'Son100_Htmln_Test_Cli_Exit_Exception' ) ) {
	/**
	 * Exception levée par le stub `WP_CLI::error()` pour simuler l'exit
	 * non-zero qu'opèrerait la vraie WP-CLI. Les tests `try/catch` cette
	 * exception pour vérifier les chemins d'erreur.
	 */
	final class Son100_Htmln_Test_Cli_Exit_Exception extends \RuntimeException {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stub minimal de la façade `WP_CLI` (couvre les méthodes que nos
	 * commandes V1.0 utilisent : add_command + log/success/warning/error).
	 *
	 * Les sorties s'accumulent dans `$logs` et `$success`/`$warnings` ;
	 * `error()` lève `Son100_Htmln_Test_Cli_Exit_Exception` (vraie WP-CLI
	 * fait un exit). Les tests assertent sur ces buffers ou catchent
	 * l'exception.
	 */
	final class WP_CLI {
		/** @var list<array{name: string, callable: mixed, args: array}> */
		public static array $commands = array();
		/** @var list<string> */
		public static array $logs = array();
		/** @var list<string> */
		public static array $success = array();
		/** @var list<string> */
		public static array $warnings = array();

		public static function reset(): void {
			self::$commands = array();
			self::$logs     = array();
			self::$success  = array();
			self::$warnings = array();
		}

		/**
		 * @param string                       $name
		 * @param callable|class-string        $callable
		 * @param array<string, mixed>         $args
		 */
		public static function add_command( string $name, mixed $callable, array $args = array() ): bool {
			self::$commands[] = array(
				'name'     => $name,
				'callable' => $callable,
				'args'     => $args,
			);
			return true;
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		public static function success( string $message ): void {
			self::$success[] = $message;
		}

		public static function warning( string $message ): void {
			self::$warnings[] = $message;
		}

		/**
		 * Simule l'exit non-zero de la vraie WP-CLI en levant une exception
		 * que les tests peuvent catcher.
		 */
		public static function error( string $message ): void {
			throw new \Son100_Htmln_Test_Cli_Exit_Exception( $message );
		}

		/** @param list<array<string, mixed>> $rows */
		public static function colorize( string $message ): string { return $message; }
	}
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Stub minimaliste — la vraie classe WP-CLI n'expose qu'une API vide,
	 * les sous-commandes héritent juste pour la sémantique.
	 */
	abstract class WP_CLI_Command {}
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
		public string $posts = 'wptests_posts';
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
