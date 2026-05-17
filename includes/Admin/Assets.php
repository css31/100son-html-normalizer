<?php
/**
 * Admin\Assets — enqueue scope-restreint du bundle SPA V1.0.
 *
 * Cf. cahier v2.0 §13 garde-fou « ne pas charger d'assets globalement sur
 * toutes les pages admin ». L'enqueue se fait uniquement quand l'URL admin
 * cible la SPA — soit le top-level `Menu::SLUG`, soit l'alias rétro-compat
 * `Menu::SPA_PAGE_SLUG` (cf. `is_spa_page()`).
 *
 * Convention `@wordpress/scripts` : la sortie webpack produit un fichier
 * `<entry>.asset.php` qui retourne `[ 'dependencies' => [...], 'version' => '...' ]`.
 * On l'inclut pour brancher automatiquement les bonnes dépendances WP
 * (`wp-element`, `wp-i18n`, `wp-components`, `wp-api-fetch`, `wp-data`, …)
 * — pas de duplication manuelle ici, ça dériverait à chaque ajout.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Branche `admin_enqueue_scripts` et charge les artefacts du bundle SPA
 * uniquement si le hook_suffix courant correspond à la page SPA.
 */
final class Assets {

	/**
	 * Handle WordPress du script principal — référencé par `wp_localize_script`,
	 * `wp_set_script_translations` et tout futur `wp_enqueue_style` lié.
	 */
	public const SCRIPT_HANDLE = 'son100-htmln-admin-spa';

	/**
	 * Branche `admin_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'on_enqueue' ) );
	}

	/**
	 * Callback `admin_enqueue_scripts` — charge le bundle uniquement sur
	 * la page SPA.
	 *
	 * @param string $hook_suffix Hook suffix WP fourni par WordPress.
	 * @return void
	 */
	public function on_enqueue( string $hook_suffix ): void {
		if ( ! $this->is_spa_page( $hook_suffix ) ) {
			return;
		}

		$asset_path = SON100_HTMLN_PATH . 'assets/build/admin-spa.asset.php';
		if ( ! file_exists( $asset_path ) ) {
			// Build absent : la page SPA affiche le titre + conteneur vide,
			// l'admin comprend qu'il faut lancer `npm run build`. Pas de
			// fatal côté serveur.
			return;
		}

		$asset = include $asset_path;
		/** @var array{dependencies?: list<string>, version?: string} $asset */
		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();
		$version = isset( $asset['version'] ) && is_string( $asset['version'] )
			? $asset['version']
			: SON100_HTMLN_VERSION;

		// Onglet « Notes » (post-rc1) : l'éditeur Gutenberg restreint a besoin
		// de la médiathèque pour le bloc `core/image` (sélecteur Media Frame
		// de WP). `wp_enqueue_media()` est idempotent — sans effet si la page
		// l'a déjà appelé, et sans surcoût mesurable en pratique.
		wp_enqueue_media();

		// CSS éditeur Gutenberg : nécessaires pour que `<BlockEditorProvider>`
		// rende les blocs avec leur style natif (paragraphe, titre, liste,
		// citation, code, table, image). Sans ça, l'éditeur fonctionne mais
		// les blocs s'affichent sans aucun style — UX incompréhensible.
		// L'enqueue est scope-restreint à la page SPA — pas de pollution
		// globale (cf. §13 « ne pas charger d'assets globalement »).
		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-block-library-theme' );

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SON100_HTMLN_URL . 'assets/build/admin-spa.js',
			$deps,
			$version,
			true
		);

		// Expose l'état SiteOrigin Page Builder au front (window.htmlnEnv).
		// La SPA affiche un avertissement contextualisé quand SO est actif
		// (risque immédiat de double-rendu via `panels_data`) ou installé
		// mais inactif (risque latent de réactivation pendant la migration).
		// Pas d'autre information sensible ici — `wp_localize_script`
		// suffit, pas besoin d'un endpoint REST dédié.
		$environment = new SiteOriginEnvironment();
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'htmlnEnv',
			$environment->to_array()
		);

		// Localisation des chaînes JS (cf. cahier §11.27 + §13).
		// Le dossier `languages/` peut ne pas exister encore (Phase 6.7) —
		// `wp_set_script_translations` est tolérant.
		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'100son-html-normalizer',
			SON100_HTMLN_PATH . 'languages'
		);

		// Bundle CSS (généré par `@wordpress/scripts` à partir des `.scss`
		// importés depuis l'entry). `wp-scripts` produit aussi une variante
		// `admin-spa-rtl.css` ; `wp_style_add_data(..., 'rtl', 'replace')`
		// fait basculer automatiquement vers cette variante quand `is_rtl()`
		// est vrai, ce qui couvre les langues RTL côté admin sans surcoût.
		//
		// `?ver=` basé sur `filemtime()` (rc4 — fix cache navigateur) :
		// le hash du `*.asset.php` ne reflète que les changements JS, donc
		// une édition CSS-seule ne le bumpait pas et le navigateur servait
		// l'ancien fichier. `filemtime` change à chaque rebuild → cache
		// invalidé sûrement.
		$css_path = SON100_HTMLN_PATH . 'assets/build/admin-spa.css';
		if ( file_exists( $css_path ) ) {
			$css_version = (string) filemtime( $css_path );
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				SON100_HTMLN_URL . 'assets/build/admin-spa.css',
				array( 'wp-components' ),
				'' !== $css_version ? $css_version : $version
			);
			wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Indique si la page admin courante correspond à la page SPA.
	 *
	 * Pourquoi pas un test sur `$hook_suffix` direct : WordPress calcule le
	 * hook_suffix d'une sous-page comme `sanitize_title($menu_title)_page_<sub-slug>`
	 * — c'est le **titre** du top-level, pas son slug, qui sert de préfixe.
	 * Reproduire cette formule côté Assets serait fragile (changement du
	 * menu_title localisé = hook_suffix différent → mismatch silencieux).
	 *
	 * `$_GET['page']` est stable, exposé par WordPress sur toute requête
	 * `wp-admin/admin.php?page=<sub-slug>`, et on contrôle directement les
	 * constantes `Menu::SLUG` / `Menu::SPA_PAGE_SLUG` pour la comparaison
	 * stricte. Pas de sanitize nécessaire : on compare à des constantes
	 * littérales.
	 *
	 * Deux slugs acceptés depuis le masquage des pages V0.1 :
	 *  - `Menu::SLUG` (`100son-html-normalizer`) — top-level, route principale
	 *    de la SPA depuis post-rc4 ;
	 *  - `Menu::SPA_PAGE_SLUG` (`100son-html-normalizer-spa`) — alias
	 *    rétro-compat pour les favoris utilisateur.
	 *
	 * Le paramètre `$hook_suffix` reste dans la signature pour cohérence
	 * avec la callback `admin_enqueue_scripts` (et future télémétrie
	 * éventuelle), mais n'est plus utilisé.
	 *
	 * @param string $hook_suffix Hook suffix fourni par WordPress (ignoré).
	 * @return bool
	 */
	private function is_spa_page( string $hook_suffix ): bool {
		unset( $hook_suffix );
		if ( ! isset( $_GET['page'] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = (string) $_GET['page'];
		return Menu::SLUG === $page || Menu::SPA_PAGE_SLUG === $page;
	}
}
