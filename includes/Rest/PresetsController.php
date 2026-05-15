<?php
/**
 * PresetsController — endpoints REST de configuration des règles.
 *
 * Cf. cahier v2.0 §4.5 (endpoints REST) et §3.1 F4 (règles R1-R12).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Rest;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Surface REST de configuration des 12 règles V1.0.
 *
 * Routes (namespace `htmln/v1`) :
 *
 *  - `GET  /presets`         — liste les 12 règles (label, description,
 *    enabled, params, defaults) pour l'onglet Règles de la SPA.
 *  - `POST /presets/<id>`    — met à jour `enabled` et/ou `params` d'un
 *    règle. Retourne la config après normalisation.
 *
 * Toutes les routes : permission `manage_options` (cf. §14 hyp. 14).
 *
 * Co-existence avec la page V0.1 PHP « Règles » : les deux écrivent
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
	 * Liste fixe des 12 ids attendus (R1..R12). Permet de valider l'id
	 * URL en O(1) et de garantir l'ordre stable du listing.
	 *
	 * @var list<string>
	 */
	private const KNOWN_IDS = array( 'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10', 'R11', 'R12', 'R13', 'R14', 'R15', 'R16' );

	/**
	 * Defaults par règle. Source de vérité dupliquée depuis
	 * `Admin\Pages\PresetsPage::render_preset_options()` pour ne pas
	 * créer de dépendance UI → REST. Mettre à jour les deux si schéma
	 * d'un paramètre évolue.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const DEFAULTS = array(
		'R1' => array(),
		'R2' => array(),
		'R3' => array(),
		'R4' => array(),
		'R5' => array(
			'threshold' => 2,
		),
		'R6' => array(
			'keep_text_align' => true,
		),
		'R7' => array(
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
		'R8' => array(
			'mappings' => array(
				'bold'   => true,
				'italic' => true,
			),
		),
		'R9'  => array(),
		'R10' => array(),
		'R11' => array(),
		'R12' => array(),
		'R13' => array(),
		'R14' => array(),
		'R15' => array(),
		'R16' => array(),
	);

	/**
	 * @param SettingsRepository         $settings    Repo réglages.
	 * @param PresetRegistry             $registry    Registre des règles (pour le metadata).
	 * @param StepsRepository|null       $steps       Optionnel : repo des pas, pour le tag « dernière application ». Si null, la propriété `last_applied_at` est toujours null dans la réponse.
	 * @param DiagnosticsRepository|null $diagnostics Optionnel : repo des diagnostics, pour le compteur d'articles concernés (`applicable_count`). Si null, la propriété est toujours 0.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly PresetRegistry $registry,
		private readonly ?StepsRepository $steps = null,
		private readonly ?DiagnosticsRepository $diagnostics = null,
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

		// Regex `R(?:1[0-6]|[1-9])` : matche R1..R16 et **rien d'autre**. On
		// préfère l'alternation explicite à `R\d+` pour ne pas accepter des
		// Rxx encore inexistants (R17, R18…) et garantir le 404 propre via
		// la regex elle-même.
		register_rest_route( $ns, '/presets/(?P<id>R(?:1[0-6]|[1-9]))', array(
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
	 * enabled, params, defaults }, … ] }` dans l’ordre R1..R12 canonique.
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
	 * Réponse 404 si l'id ne fait pas partie des 12 connus (filet —
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
	 * Sérialise une règle pour la SPA.
	 *
	 * @param string                                                                $id     Identifiant R1..R12.
	 * @param array{label: string, description: string, has_options: bool}          $meta   Metadata depuis le registry.
	 * @param array<string, mixed>                                                  $config Configuration courante (option WP).
	 * @return array<string, mixed>
	 */
	private function preset_to_array( string $id, array $meta, array $config ): array {
		$defaults = self::DEFAULTS[ $id ] ?? array();
		$params   = $this->extract_params( $id, $config, $defaults );

		// Tag « dernière application » : dérivé de la table des pas.
		$last_applied_at = null;
		if ( null !== $this->steps ) {
			$last_applied_at = $this->steps->last_applied_for_rule( $id );
		}

		// Compteur d'articles concernés : dérivé de la facette
		// `applicable_rules` calculée par DiagnosticsRepository. La
		// même donnée alimente déjà la colonne « Règles applicables »
		// du tableau Normaliser — source unique de vérité.
		$applicable_count = 0;
		if ( null !== $this->diagnostics ) {
			$counts            = $this->diagnostics->count_by_applicable_rule();
			$applicable_count  = (int) ( $counts[ $id ] ?? 0 );
		}

		// État dérivé pour l'UI :
		//  - 'complete' : la règle a tourné au moins une fois (timestamp
		//    non null) ET aucun article ne nécessite plus son passage
		//    (compteur à 0). C'est l'état « appliquée à tout le corpus ».
		//  - 'unused' : la règle n'a jamais été appliquée ET aucun article
		//    ne la nécessite (la règle n'a rien à faire sur ce corpus).
		//  - 'pending' : il reste des articles à traiter.
		if ( $applicable_count > 0 ) {
			$completion_state = 'pending';
		} elseif ( null !== $last_applied_at ) {
			$completion_state = 'complete';
		} else {
			$completion_state = 'unused';
		}

		$auto_disabled_at = null;
		if ( isset( $config['auto_disabled_at'] ) && '' !== $config['auto_disabled_at'] ) {
			$auto_disabled_at = (string) $config['auto_disabled_at'];
		}

		return array(
			'id'               => $id,
			'label'            => (string) ( $meta['label'] ?? $id ),
			'description'      => (string) ( $meta['description'] ?? '' ),
			'has_options'      => (bool) ( $meta['has_options'] ?? false ),
			'enabled'          => ! empty( $config['enabled'] ),
			'params'           => $params,
			'defaults'         => $defaults,
			'last_applied_at'  => $last_applied_at,
			'applicable_count' => $applicable_count,
			'completion_state' => $completion_state,
			'auto_disabled_at' => $auto_disabled_at,
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
			case 'R5':
				return array(
					'threshold' => $this->int_in_range(
						$config['threshold'] ?? null,
						(int) $defaults['threshold'],
						2,
						20,
					),
				);
			case 'R6':
				return array(
					'keep_text_align' => ! isset( $config['keep_text_align'] )
						? (bool) $defaults['keep_text_align']
						: (bool) $config['keep_text_align'],
				);
			case 'R7':
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
			case 'R8':
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
			case 'R5':
				if ( array_key_exists( 'threshold', $payload ) ) {
					$out['threshold'] = $this->int_in_range(
						$payload['threshold'],
						(int) $defaults['threshold'],
						2,
						20,
					);
				}
				return $out;
			case 'R6':
				if ( array_key_exists( 'keep_text_align', $payload ) ) {
					$out['keep_text_align'] = (bool) $payload['keep_text_align'];
				}
				return $out;
			case 'R7':
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
			case 'R8':
				if ( array_key_exists( 'mappings', $payload ) && is_array( $payload['mappings'] ) ) {
					$out['mappings'] = array(
						'bold'   => ! empty( $payload['mappings']['bold'] ),
						'italic' => ! empty( $payload['mappings']['italic'] ),
					);
				}
				return $out;
			default:
				// R1..R4 : pas de params, on ignore tout payload.
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
