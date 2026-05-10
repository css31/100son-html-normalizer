<?php
/**
 * Admin\Assets — enqueue scope-restreint du bundle SPA V1.0.
 *
 * Cf. cahier v2.0 §13 garde-fou « ne pas charger d'assets globalement sur
 * toutes les pages admin ». L'enqueue se fait uniquement quand le
 * hook_suffix correspond à la page SPA (slug défini dans `Menu::SPA_PAGE_SLUG`).
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

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SON100_HTMLN_URL . 'assets/build/admin-spa.js',
			$deps,
			$version,
			true
		);

		// Localisation des chaînes JS (cf. cahier §11.27 + §13).
		// Le dossier `languages/` peut ne pas exister encore (Phase 6.7) —
		// `wp_set_script_translations` est tolérant.
		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'100son-html-normalizer',
			SON100_HTMLN_PATH . 'languages'
		);

		// Bundle CSS (généré par `@wordpress/scripts` quand des fichiers
		// SCSS/CSS sont importés depuis l'entry). Optionnel — Phase 6.1
		// n'a pas encore de styles.
		$css_path = SON100_HTMLN_PATH . 'assets/build/admin-spa.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				SON100_HTMLN_URL . 'assets/build/admin-spa.css',
				array( 'wp-components' ),
				$version
			);
		}
	}

	/**
	 * Indique si le hook_suffix courant correspond à la page SPA.
	 *
	 * Le hook_suffix WordPress pour une sous-page d'un top-level menu suit
	 * la formule `<top-level-slug>_page_<sub-slug>`. On compare exactement
	 * pour éviter les faux-positifs sur d'autres pages contenant le slug.
	 *
	 * @param string $hook_suffix Hook suffix fourni par WordPress.
	 * @return bool
	 */
	private function is_spa_page( string $hook_suffix ): bool {
		$expected = Menu::SLUG . '_page_' . Menu::SPA_PAGE_SLUG;
		return $hook_suffix === $expected;
	}
}
