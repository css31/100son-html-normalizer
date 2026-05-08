<?php
/**
 * SettingsRepository — accès lecture/écriture aux options globales et configs présets.
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
 * Repository des réglages plugin et de la config des présets.
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
		$selection = $settings['f8_post_types_selection'] ?? [ 'post' ];
		if ( ! is_array( $selection ) ) {
			return [ 'post' ];
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
		$valid_slugs   = array_keys( get_post_types( [ 'public' => true ] ) );
		$filtered      = array_values( array_intersect( $slugs, $valid_slugs ) );
		$settings      = $this->get_settings();
		$settings['f8_post_types_selection'] = $filtered;
		update_option( self::OPT_SETTINGS, $settings, false );
	}

	/**
	 * Récupère les réglages globaux bruts.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$settings = get_option( self::OPT_SETTINGS, [] );
		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Récupère la configuration brute d'un préset.
	 *
	 * @param string $preset_id Identifiant (P1..P8).
	 * @return array<string, mixed>
	 */
	public function get_preset_config( string $preset_id ): array {
		$presets = $this->get_all_presets();
		$config  = $presets[ $preset_id ] ?? [];
		return is_array( $config ) ? $config : [];
	}

	/**
	 * Met à jour la configuration d'un préset.
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
	 * Indique si un préset est activé.
	 *
	 * @param string $preset_id Identifiant.
	 * @return bool
	 */
	public function is_preset_enabled( string $preset_id ): bool {
		$config = $this->get_preset_config( $preset_id );
		return ! empty( $config['enabled'] );
	}

	/**
	 * Récupère la config de tous les présets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_presets(): array {
		$presets = get_option( self::OPT_PRESETS, [] );
		if ( ! is_array( $presets ) ) {
			return [];
		}
		/** @var array<string, array<string, mixed>> $presets */
		return $presets;
	}
}
