<?php
/**
 * PresetsController — endpoints REST de configuration des préréglages.
 *
 * Cf. cahier v2.0 §4.5 (endpoints REST) et §3.1 F4 (préréglages P1-P8).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de configuration des 8 préréglages V1.0.
 *
 * Routes (namespace `htmln/v1`) :
 *
 *  - `GET  /presets`         — liste les 8 préréglages (label, description,
 *    enabled, params, defaults) pour l'onglet Règles de la SPA.
 *  - `POST /presets/<id>`    — met à jour `enabled` et/ou `params` d'un
 *    préréglage. Retourne la config après normalisation.
 *
 * Toutes les routes : permission `manage_options` (cf. §14 hyp. 14).
 *
 * Co-existence avec la page V0.1 PHP « Préréglages » : les deux écrivent
 * dans la même option `son100_htmln_presets` via
 * `SettingsRepository::set_preset_config()`. Toute modification dans la
 * SPA est donc immédiatement visible dans la page V0.1 et inversement.
 * La sanitization par règle est centralisée ici pour éviter qu'une
 * divergence subtile avec `PresetsPage::handle_form()` n'écrive en BDD
 * un schéma incompatible avec la règle. Si vous modifiez le schéma
 * d'un paramètre, mettez à jour les **deux** sites.
 */
final class PresetsController extends BaseController {

	/**
	 * Liste fixe des 8 ids attendus (P1..P8). Permet de valider l'id
	 * URL en O(1) et de garantir l'ordre stable du listing.
	 *
	 * @var list<string>
	 */
	private const KNOWN_IDS = array( 'P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8' );

	/**
	 * Defaults par règle. Source de vérité dupliquée depuis
	 * `Admin\Pages\PresetsPage::render_preset_options()` pour ne pas
	 * créer de dépendance UI → REST. Mettre à jour les deux si schéma
	 * d'un paramètre évolue.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFAULTS = array(
		'P1' => array(),
		'P2' => array(),
		'P3' => array(),
		'P4' => array(),
		'P5' => array(
			'threshold' => 2,
		),
		'P6' => array(
			'keep_text_align' => true,
		),
		'P7' => array(
			'threshold'      => 2,
			'markers'        => array(
				'dash'    => false,
				'emdash'  => false,
				'asterix' => false,
				'bullet'  => false,
				'numeric' => false,
			),
			'custom_markers' => array(),
		),
		'P8' => array(
			'mappings' => array(
				'bold'   => true,
				'italic' => true,
			),
		),
	);

	/**
	 * @param SettingsRepository $settings Repo réglages.
	 * @param PresetRegistry     $registry Registre des préréglages (pour le metadata).
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly PresetRegistry $registry,
	) {}

	/**
	 * Enregistre les 2 routes au hook `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		$cap = array( $this, 'permission_check_manage_options' );

		register_rest_route( $ns, '/presets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_presets' ),
			'permission_callback' => $cap,
		) );

		register_rest_route( $ns, '/presets/(?P<id>P[1-8])', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'update_preset' ),
			'permission_callback' => $cap,
		) );
	}

	// =========================================================================
	//  Handlers
	// =========================================================================

	/**
	 * `GET /presets`
	 *
	 * Réponse 200 : `{ presets: [ { id, label, description, has_options,
	 * enabled, params, defaults }, … ] }` dans l'ordre P1..P8 canonique.
	 *
	 * @param WP_REST_Request $request Requête (inutilisée).
	 * @return WP_REST_Response
	 */
	public function list_presets( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$metadata = $this->registry->get_all_presets_metadata();
		$presets  = array();
		foreach ( self::KNOWN_IDS as $id ) {
			$meta             = $metadata[ $id ] ?? array(
				'label'       => $id,
				'description' => '',
				'has_options' => false,
			);
			$config           = $this->settings->get_preset_config( $id );
			$presets[]        = $this->preset_to_array( $id, $meta, $config );
		}
		return $this->respond( array(
			'presets' => $presets,
		) );
	}

	/**
	 * `POST /presets/<id>`
	 *
	 * Body : `{ enabled?: bool, params?: object }`. Tout absent reste
	 * inchangé. La sanitization des params dépend de l'id (cf.
	 * `sanitize_params_for()`).
	 *
	 * Réponse 200 : `{ preset: { id, label, description, has_options,
	 * enabled, params, defaults } }` après normalisation.
	 * Réponse 404 si l'id ne fait pas partie des 8 connus (filet —
	 * la regex de la route le garantit déjà).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public function update_preset( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		if ( ! in_array( $id, self::KNOWN_IDS, true ) ) {
			return $this->rest_error(
				'preset_not_found',
				'Unknown preset id ' . $id,
				404,
				array( 'id' => $id ),
			);
		}

		$config = $this->settings->get_preset_config( $id );

		// Mise à jour de `enabled` si fourni.
		$enabled_param = $request->get_param( 'enabled' );
		if ( null !== $enabled_param ) {
			$config['enabled'] = (bool) $enabled_param;
		}

		// Mise à jour des paramètres si fournis. Les params absents ou
		// invalides retombent sur les defaults (cf. `sanitize_params_for`).
		$params_param = $request->get_param( 'params' );
		if ( is_array( $params_param ) ) {
			$sanitized = $this->sanitize_params_for( $id, $params_param );
			foreach ( $sanitized as $key => $value ) {
				$config[ $key ] = $value;
			}
		}

		$this->settings->set_preset_config( $id, $config );

		$metadata = $this->registry->get_all_presets_metadata();
		$meta     = $metadata[ $id ] ?? array(
			'label'       => $id,
			'description' => '',
			'has_options' => false,
		);
		return $this->respond( array(
			'preset' => $this->preset_to_array( $id, $meta, $config ),
		) );
	}

	// =========================================================================
	//  Helpers privés
	// =========================================================================

	/**
	 * Sérialise un préréglage pour la SPA.
	 *
	 * @param string                                                                $id     Identifiant P1..P8.
	 * @param array{label: string, description: string, has_options: bool}          $meta   Metadata depuis le registry.
	 * @param array<string, mixed>                                                  $config Configuration courante (option WP).
	 * @return array<string, mixed>
	 */
	private function preset_to_array( string $id, array $meta, array $config ): array {
		$defaults       = self::DEFAULTS[ $id ] ?? array();
		$params         = $this->extract_params( $id, $config, $defaults );
		return array(
			'id'          => $id,
			'label'       => (string) ( $meta['label'] ?? $id ),
			'description' => (string) ( $meta['description'] ?? '' ),
			'has_options' => (bool) ( $meta['has_options'] ?? false ),
			'enabled'     => ! empty( $config['enabled'] ),
			'params'      => $params,
			'defaults'    => $defaults,
		);
	}

	/**
	 * Extrait les paramètres normalisés d'une config brute, en repliant sur
	 * les defaults pour toute clé manquante ou invalide. Identique à la
	 * lecture qu'opère `PresetsPage::render_preset_options()` pour rester
	 * cohérent.
	 *
	 * @param string               $id       Identifiant.
	 * @param array<string, mixed> $config   Config brute en BDD.
	 * @param array<string, mixed> $defaults Defaults canoniques.
	 * @return array<string, mixed>
	 */
	private function extract_params( string $id, array $config, array $defaults ): array {
		switch ( $id ) {
			case 'P5':
				return array(
					'threshold' => $this->int_in_range(
						$config['threshold'] ?? null,
						(int) $defaults['threshold'],
						2,
						20,
					),
				);
			case 'P6':
				return array(
					'keep_text_align' => ! isset( $config['keep_text_align'] )
						? (bool) $defaults['keep_text_align']
						: (bool) $config['keep_text_align'],
				);
			case 'P7':
				$markers = is_array( $config['markers'] ?? null ) ? $config['markers'] : array();
				$defaults_markers = is_array( $defaults['markers'] ?? null )
					? $defaults['markers']
					: array();
				$custom_markers   = is_array( $config['custom_markers'] ?? null )
					? array_values(
						array_filter(
							array_map( 'strval', $config['custom_markers'] ),
							static fn( string $m ): bool => '' !== $m,
						)
					)
					: array();
				return array(
					'threshold'      => $this->int_in_range(
						$config['threshold'] ?? null,
						(int) $defaults['threshold'],
						2,
						20,
					),
					'markers'        => array(
						'dash'    => ! empty( $markers['dash'] ),
						'emdash'  => ! empty( $markers['emdash'] ),
						'asterix' => ! empty( $markers['asterix'] ),
						'bullet'  => ! empty( $markers['bullet'] ),
						'numeric' => ! empty( $markers['numeric'] ),
					),
					'custom_markers' => $custom_markers,
				);
			case 'P8':
				$mappings = is_array( $config['mappings'] ?? null ) ? $config['mappings'] : array();
				$defaults_mappings = is_array( $defaults['mappings'] ?? null )
					? $defaults['mappings']
					: array();
				return array(
					'mappings' => array(
						'bold'   => ! isset( $mappings['bold'] )
							? (bool) ( $defaults_mappings['bold'] ?? true )
							: (bool) $mappings['bold'],
						'italic' => ! isset( $mappings['italic'] )
							? (bool) ( $defaults_mappings['italic'] ?? true )
							: (bool) $mappings['italic'],
					),
				);
			default:
				return array();
		}
	}

	/**
	 * Sanitize d'un payload `params` reçu par le POST pour la règle `$id`.
	 *
	 * Retourne un sous-tableau ne contenant **que** les clés
	 * effectivement présentes dans le payload (les autres ne sont pas
	 * touchées en BDD). Toute valeur invalide pour une clé présente
	 * retombe sur le default canonique.
	 *
	 * @param string               $id       Identifiant.
	 * @param array<string, mixed> $payload  Payload brut.
	 * @return array<string, mixed>
	 */
	private function sanitize_params_for( string $id, array $payload ): array {
		$defaults = self::DEFAULTS[ $id ] ?? array();
		$out      = array();
		switch ( $id ) {
			case 'P5':
				if ( array_key_exists( 'threshold', $payload ) ) {
					$out['threshold'] = $this->int_in_range(
						$payload['threshold'],
						(int) $defaults['threshold'],
						2,
						20,
					);
				}
				return $out;
			case 'P6':
				if ( array_key_exists( 'keep_text_align', $payload ) ) {
					$out['keep_text_align'] = (bool) $payload['keep_text_align'];
				}
				return $out;
			case 'P7':
				if ( array_key_exists( 'threshold', $payload ) ) {
					$out['threshold'] = $this->int_in_range(
						$payload['threshold'],
						(int) $defaults['threshold'],
						2,
						20,
					);
				}
				if ( array_key_exists( 'markers', $payload ) && is_array( $payload['markers'] ) ) {
					$out['markers'] = array(
						'dash'    => ! empty( $payload['markers']['dash'] ),
						'emdash'  => ! empty( $payload['markers']['emdash'] ),
						'asterix' => ! empty( $payload['markers']['asterix'] ),
						'bullet'  => ! empty( $payload['markers']['bullet'] ),
						'numeric' => ! empty( $payload['markers']['numeric'] ),
					);
				}
				if ( array_key_exists( 'custom_markers', $payload ) && is_array( $payload['custom_markers'] ) ) {
					$out['custom_markers'] = array_values(
						array_filter(
							array_map(
								static fn( mixed $m ): string => is_scalar( $m ) ? trim( (string) $m ) : '',
								$payload['custom_markers'],
							),
							static fn( string $m ): bool => '' !== $m,
						)
					);
				}
				return $out;
			case 'P8':
				if ( array_key_exists( 'mappings', $payload ) && is_array( $payload['mappings'] ) ) {
					$out['mappings'] = array(
						'bold'   => ! empty( $payload['mappings']['bold'] ),
						'italic' => ! empty( $payload['mappings']['italic'] ),
					);
				}
				return $out;
			default:
				// P1..P4 : pas de params, on ignore tout payload.
				return array();
		}
	}

	/**
	 * Sanitize entier dans une fourchette inclusive. Toute valeur non
	 * numérique ou hors fourchette retombe sur le default.
	 *
	 * @param mixed $value   Valeur brute.
	 * @param int   $default Default canonique.
	 * @param int   $min     Borne inclusive.
	 * @param int   $max     Borne inclusive.
	 * @return int
	 */
	private function int_in_range( mixed $value, int $default, int $min, int $max ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		$cast = (int) $value;
		if ( $cast < $min || $cast > $max ) {
			return $default;
		}
		return $cast;
	}
}
