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
