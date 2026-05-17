<?php
/**
 * SiteOriginEnvironment — détecte si SiteOrigin Page Builder est présent
 * et/ou actif sur l'installation WordPress courante.
 *
 * L'extension HTML Normalizer écrit directement dans `wp_posts.post_content`.
 * Lorsque SiteOrigin Page Builder est **actif**, son filtre `the_content`
 * régénère le rendu front à partir du post-meta `panels_data` — toute
 * normalisation du `post_content` sur un article avec `panels_data` non vide
 * est alors masquée au front, et la prochaine sauvegarde dans l'éditeur SO
 * écrase silencieusement la normalisation. Voir `PostNormalizer` (refus
 * STATUS_SKIPPED_SO par défaut) et le post de test 1392.
 *
 * Cette classe expose les deux états aux écrans admin pour permettre un
 * avertissement contextualisé :
 *  - `is_active()`    : extension chargée → risque immédiat ;
 *  - `is_installed()` : présente mais désactivée → risque latent
 *                       (réactivation accidentelle).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Détection de SiteOrigin Page Builder. Sans état, sans dépendance — peut
 * être instanciée n'importe où et testée directement.
 */
final class SiteOriginEnvironment {

	/**
	 * Chemin du fichier principal de SiteOrigin Page Builder, relatif à
	 * `WP_PLUGIN_DIR`. Le slug du dossier (`siteorigin-panels`) est figé
	 * sur le repo .org de l'extension depuis sa création — pas de variante
	 * connue à supporter en V1.
	 */
	private const PLUGIN_FILE = 'siteorigin-panels/siteorigin-panels.php';

	/**
	 * Indique si SiteOrigin Page Builder est actuellement actif.
	 *
	 * S'appuie sur `is_plugin_active()` (api officielle WP), qui couvre à
	 * la fois l'activation simple et l'activation réseau (multisite). La
	 * fonction n'existe que dans `wp-admin/includes/plugin.php` — chargé
	 * automatiquement sur les écrans admin, mais on l'include
	 * défensivement au cas où l'appelant nous monterait hors `wp-admin`.
	 *
	 * @return bool Vrai si l'extension est chargée par WordPress.
	 */
	public function is_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_helper = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! file_exists( $plugin_helper ) ) {
				return false;
			}
			require_once $plugin_helper;
		}
		return is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Indique si l'extension est présente sur disque (active ou non).
	 *
	 * Une extension présente mais désactivée reste un risque latent : un
	 * administrateur peut la réactiver pendant la migration et invalider
	 * silencieusement tout le travail de normalisation effectué jusque-là.
	 *
	 * On vérifie le fichier principal plutôt que `get_plugins()` qui
	 * scanne tout `WP_PLUGIN_DIR` et fait des allocations inutiles.
	 *
	 * @return bool Vrai si le fichier principal de SO existe.
	 */
	public function is_installed(): bool {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return false;
		}
		return file_exists( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
	}

	/**
	 * Snapshot consommable par le front (sérialisé dans `window.htmlnEnv`).
	 *
	 * @return array{siteoriginActive: bool, siteoriginInstalled: bool}
	 */
	public function to_array(): array {
		$active    = $this->is_active();
		$installed = $active ? true : $this->is_installed();
		return array(
			'siteoriginActive'    => $active,
			'siteoriginInstalled' => $installed,
		);
	}
}
