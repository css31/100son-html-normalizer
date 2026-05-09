<?php
/**
 * SettingsRepository — accès lecture/écriture aux options globales et configs préréglages.
 *
 * Encapsule l'API options de WordPress pour offrir une surface typée et un
 * point unique de validation/normalisation.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Repository des réglages plugin et de la config des préréglages.
 *
 * Volontairement non-final pour permettre l'extension/stub en tests
 * d'intégration (HtmlNormalizerTest, PublicApiTest…). Ne pas la rendre
 * final sans extraire au préalable une interface dediee.
 */
class SettingsRepository {

	private const OPT_SETTINGS = 'son100_htmln_settings';
	private const OPT_PRESETS  = 'son100_htmln_presets';

	/**
	 * Liste des post_types cochés dans F8.
	 *
	 * @return list<string>
	 */
	public function get_f8_post_types_selection(): array {
		$settings  = $this->get_settings();
		$selection = $settings['f8_post_types_selection'] ?? array( 'post' );
		if ( ! is_array( $selection ) ) {
			return array( 'post' );
		}
		return array_values(
			array_filter(
				array_map( 'strval', $selection ),
				static fn( string $slug ): bool => '' !== $slug
			)
		);
	}

	/**
	 * Met à jour la sélection F8 (slugs validés contre les post_types publics).
	 *
	 * @param list<string> $slugs Slugs candidats.
	 * @return void
	 */
	public function set_f8_post_types_selection( array $slugs ): void {
		$valid_slugs   = array_keys( get_post_types( array( 'public' => true ) ) );
		$filtered      = array_values( array_intersect( $slugs, $valid_slugs ) );
		$settings      = $this->get_settings();
		$settings['f8_post_types_selection'] = $filtered;
		update_option( self::OPT_SETTINGS, $settings, false );
	}

	/**
	 * Nombre d'articles par page sur la liste F8.
	 *
	 * @return int Validé contre la liste des choix autorisés (10, 25, 50, 100, 200), défaut 25.
	 */
	public function get_f8_per_page(): int {
		$settings = $this->get_settings();
		$value    = (int) ( $settings['f8_per_page'] ?? 25 );
		$allowed  = array( 10, 25, 50, 100, 200 );
		return in_array( $value, $allowed, true ) ? $value : 25;
	}

	/**
	 * Met à jour le nombre d'articles par page (F8).
	 *
	 * @param int $per_page Valeur candidate.
	 * @return void
	 */
	public function set_f8_per_page( int $per_page ): void {
		$allowed                  = array( 10, 25, 50, 100, 200 );
		$valid                    = in_array( $per_page, $allowed, true ) ? $per_page : 25;
		$settings                 = $this->get_settings();
		$settings['f8_per_page']  = $valid;
		update_option( self::OPT_SETTINGS, $settings, false );
	}

	/**
	 * Récupère les réglages globaux bruts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$settings = get_option( self::OPT_SETTINGS, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Récupère la configuration brute d'un préréglage.
	 *
	 * @param string $preset_id Identifiant (P1..P8).
	 * @return array<string, mixed>
	 */
	public function get_preset_config( string $preset_id ): array {
		$presets = $this->get_all_presets();
		$config  = $presets[ $preset_id ] ?? array();
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Met à jour la configuration d'un préréglage.
	 *
	 * @param string               $preset_id Identifiant.
	 * @param array<string, mixed> $config    Nouvelle configuration.
	 * @return void
	 */
	public function set_preset_config( string $preset_id, array $config ): void {
		$presets               = $this->get_all_presets();
		$presets[ $preset_id ] = $config;
		update_option( self::OPT_PRESETS, $presets, false );
	}

	/**
	 * Indique si un préréglage est activé.
	 *
	 * @param string $preset_id Identifiant.
	 * @return bool
	 */
	public function is_preset_enabled( string $preset_id ): bool {
		$config = $this->get_preset_config( $preset_id );
		return ! empty( $config['enabled'] );
	}

	/**
	 * Récupère la config de tous les préréglages.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_presets(): array {
		$presets = get_option( self::OPT_PRESETS, array() );
		if ( ! is_array( $presets ) ) {
			return array();
		}
		/** @var array<string, array<string, mixed>> $presets */
		return $presets;
	}

	/**
	 * Seuils de régression γ utilisés par F15 (RegressionDetector). Source de
	 * vérité : cahier v2.0 §14 hyp. 24 (defaults) et §3.1 F15 (sémantique).
	 *
	 * Les 7 clés correspondent aux 7 métriques structurelles (cf. §3.1 F15) ;
	 * leur unité dépend de la métrique :
	 *  - `text_loss_pct`, `words_loss_pct`, `paragraphs_loss_pct` : pourcentage
	 *    de perte tolérée (entier ou flottant >= 0).
	 *  - `images_loss`, `headings_loss`, `links_loss`, `lists_loss` : nombre
	 *    absolu d'éléments perdus toléré (entier >= 0). `headings_loss`
	 *    s'applique à chaque niveau h1..h6 indépendamment.
	 *
	 * Le dépassement (perte > seuil) déclenche la modale "Régression détectée"
	 * de F15. Les valeurs sont overridables dans `son100_htmln_settings`
	 * via la clé `regression_thresholds` (UI Réglages, cf. F15 §4.2).
	 */
	public const REGRESSION_THRESHOLD_DEFAULTS = array(
		'text_loss_pct'       => 0,
		'words_loss_pct'      => 0,
		'paragraphs_loss_pct' => 5,
		'headings_loss'       => 0,
		'images_loss'         => 0,
		'links_loss'          => 0,
		'lists_loss'          => 0,
	);

	/**
	 * Récupère les 7 seuils γ de régression structurelle (F15).
	 *
	 * Lecture du sous-tableau `regression_thresholds` dans
	 * `son100_htmln_settings`, fusionne avec les defaults pour garantir que
	 * les 7 clés sont toujours présentes et correctement typées (int ≥ 0).
	 *
	 * @return array{
	 *   text_loss_pct: int,
	 *   words_loss_pct: int,
	 *   paragraphs_loss_pct: int,
	 *   headings_loss: int,
	 *   images_loss: int,
	 *   links_loss: int,
	 *   lists_loss: int,
	 * }
	 */
	public function getRegressionThresholds(): array {
		$settings = $this->get_settings();
		$raw      = $settings['regression_thresholds'] ?? array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$result = array();
		foreach ( self::REGRESSION_THRESHOLD_DEFAULTS as $key => $default ) {
			$value = $raw[ $key ] ?? $default;
			if ( ! is_numeric( $value ) ) {
				$value = $default;
			}
			$value = (int) $value;
			if ( $value < 0 ) {
				$value = $default;
			}
			$result[ $key ] = $value;
		}
		/** @var array{text_loss_pct:int,words_loss_pct:int,paragraphs_loss_pct:int,headings_loss:int,images_loss:int,links_loss:int,lists_loss:int} $result */
		return $result;
	}
}
