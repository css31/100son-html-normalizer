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
		return $this->normalize_regression_thresholds( $raw );
	}

	/**
	 * Persiste les 7 seuils γ de régression dans `son100_htmln_settings`.
	 *
	 * Le tableau d'entrée est normalisé (clés inconnues ignorées, valeurs
	 * non numériques ou négatives retombent sur les defaults du cahier
	 * §14 hyp. 24). Retourne le tableau **après** normalisation pour que
	 * la SPA puisse refléter immédiatement ce qui a été écrit.
	 *
	 * Cohérent avec le contrat défensif de `set_f8_per_page` /
	 * `set_f8_post_types_selection` : le setter ne lève pas, il normalise
	 * silencieusement — toute validation stricte doit être faite en
	 * amont (REST controller, UI). Cette approche évite à l'admin une
	 * page Settings cassée si une donnée corrompue dort en BDD.
	 *
	 * @param array<string, mixed> $thresholds Tableau brut (probablement issu du body REST).
	 * @return array{
	 *   text_loss_pct: int,
	 *   words_loss_pct: int,
	 *   paragraphs_loss_pct: int,
	 *   headings_loss: int,
	 *   images_loss: int,
	 *   links_loss: int,
	 *   lists_loss: int,
	 * } Tableau normalisé tel qu'il vient d'être persisté.
	 */
	public function setRegressionThresholds( array $thresholds ): array {
		$normalized                            = $this->normalize_regression_thresholds( $thresholds );
		$settings                              = $this->get_settings();
		$settings['regression_thresholds']     = $normalized;
		update_option( self::OPT_SETTINGS, $settings, false );
		return $normalized;
	}

	/**
	 * Normalise un tableau de seuils brut vers les 7 clés canoniques avec
	 * valeurs entières ≥ 0. Toute clé manquante ou invalide retombe sur
	 * le default. Toute clé inconnue est ignorée.
	 *
	 * Centralise la logique de validation pour les deux opérations
	 * `getRegressionThresholds()` (lecture défensive) et
	 * `setRegressionThresholds()` (écriture après validation).
	 *
	 * @param array<string, mixed> $raw Tableau brut.
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
	private function normalize_regression_thresholds( array $raw ): array {
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

	/**
	 * Sites externes (domaines) où ouvrir un article depuis l'onglet Normaliser.
	 *
	 * Pendant la migration du corpus *Ma Maison Mag*, l'admin a besoin de
	 * comparer un article rapidement entre l'ancienne version (« old ») et
	 * la prod. Les deux URLs sont configurables côté Réglages — defaults
	 * codés en dur sur les domaines historiques de MMM, mais l'option est
	 * générique et pourra resservir.
	 *
	 * Conventions : pas de slash final (les URLs sont concaténées avec le
	 * path du permalien côté SPA), schéma `http://` ou `https://` requis.
	 * Une valeur invalide retombe sur le default — même contrat défensif
	 * que les seuils γ.
	 *
	 * Pour chaque site (old / prod), trois champs sont stockés :
	 *  - `<site>_url`     : URL absolue (validation regex, slash final stripped) ;
	 *  - `<site>_label`   : libellé du bouton dans le tableau Normaliser, max 5 caractères ;
	 *  - `<site>_enabled` : booléen — si false, le bouton n'apparaît pas.
	 *
	 * @var array{old_url: string, old_label: string, old_enabled: bool, prod_url: string, prod_label: string, prod_enabled: bool}
	 */
	public const EXTERNAL_SITES_DEFAULTS = array(
		'old_url'      => 'https://old.ma-maison-mag.fr',
		'old_label'    => 'Old',
		'old_enabled'  => true,
		'prod_url'     => 'https://ma-maison-mag.fr',
		'prod_label'   => 'Prod',
		'prod_enabled' => true,
	);

	/**
	 * Longueur maximale d'un libellé de bouton (mesurée en caractères Unicode
	 * via `mb_strlen` — un emoji ou un caractère accentué = 1).
	 */
	private const EXTERNAL_SITE_LABEL_MAX_LENGTH = 5;

	/**
	 * Récupère les domaines externes (« Old » et « Prod ») configurés.
	 *
	 * Lecture du sous-tableau `external_sites` dans `son100_htmln_settings`,
	 * fusionne avec les defaults pour garantir que les 6 clés sont toujours
	 * présentes et valides.
	 *
	 * @return array{old_url: string, old_label: string, old_enabled: bool, prod_url: string, prod_label: string, prod_enabled: bool}
	 */
	public function getExternalSites(): array {
		$settings = $this->get_settings();
		$raw      = $settings['external_sites'] ?? array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return $this->normalize_external_sites( $raw );
	}

	/**
	 * Persiste les URLs + libellés + activations des sites externes dans
	 * `son100_htmln_settings`.
	 *
	 * Normalisation silencieuse (cf. `setRegressionThresholds`) : valeur
	 * non-string sur les URLs, label vide ou > 5 caractères, etc. retombent
	 * sur le default. Le slash final des URLs est strippé pour fiabiliser
	 * la concaténation avec un path côté SPA.
	 *
	 * @param array<string, mixed> $sites Tableau brut (probablement issu du body REST).
	 * @return array{old_url: string, old_label: string, old_enabled: bool, prod_url: string, prod_label: string, prod_enabled: bool} Tableau normalisé tel qu'il vient d'être persisté.
	 */
	public function setExternalSites( array $sites ): array {
		$normalized                     = $this->normalize_external_sites( $sites );
		$settings                       = $this->get_settings();
		$settings['external_sites']     = $normalized;
		update_option( self::OPT_SETTINGS, $settings, false );
		return $normalized;
	}

	/**
	 * Normalise un tableau brut vers les 6 clés canoniques.
	 *
	 * Par type de clé :
	 *  - `*_url`     : cast string, trim, rtrim('/'), validation regex
	 *                  `^https?://<host>`. Fallback default si invalide.
	 *  - `*_label`   : cast string, trim, troncature à 5 caractères Unicode
	 *                  (`mb_substr`). Fallback default si chaîne vide après
	 *                  trim — un libellé absent ne pourrait pas être affiché.
	 *  - `*_enabled` : cast booléen (filter_var FILTER_VALIDATE_BOOLEAN pour
	 *                  accepter `'true'`/`'false'` strings envoyés en JSON).
	 *
	 * Pas d'appel à `esc_url_raw()` ici : l'option ne sert qu'à composer un
	 * `href` côté SPA, qui passera par l'escape automatique de React. La
	 * regex couvre le risque XSS (pas de `javascript:`/`data:` autorisés).
	 *
	 * @param array<string, mixed> $raw Tableau brut.
	 * @return array{old_url: string, old_label: string, old_enabled: bool, prod_url: string, prod_label: string, prod_enabled: bool}
	 */
	private function normalize_external_sites( array $raw ): array {
		$result = array();
		foreach ( self::EXTERNAL_SITES_DEFAULTS as $key => $default ) {
			$value = $raw[ $key ] ?? $default;
			if ( self::ends_with( $key, '_url' ) ) {
				$value = is_string( $value ) ? trim( $value ) : '';
				$value = rtrim( $value, '/' );
				// Délimiteur `~` (et non `#`) : un `#` dans la classe terminerait
				// prématurément le motif et la regex échouerait à tout coup.
				if ( '' === $value || 1 !== preg_match( '~^https?://[^\s/?#]+~i', $value ) ) {
					$value = $default;
				}
			} elseif ( self::ends_with( $key, '_label' ) ) {
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( function_exists( 'mb_substr' ) ) {
					$value = mb_substr( $value, 0, self::EXTERNAL_SITE_LABEL_MAX_LENGTH );
				} else {
					$value = substr( $value, 0, self::EXTERNAL_SITE_LABEL_MAX_LENGTH );
				}
				if ( '' === $value ) {
					$value = $default;
				}
			} elseif ( self::ends_with( $key, '_enabled' ) ) {
				// `filter_var(..., FILTER_VALIDATE_BOOLEAN)` accepte 'true' /
				// 'false' / '1' / '0' (strings) ainsi que les booléens — utile
				// car JSON sérialise parfois les bools en strings selon le
				// client.
				$value = (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			}
			$result[ $key ] = $value;
		}
		/** @var array{old_url: string, old_label: string, old_enabled: bool, prod_url: string, prod_label: string, prod_enabled: bool} $result */
		return $result;
	}

	/**
	 * Polyfill `str_ends_with` (PHP 8.0+). On le redéfinit ici en interne
	 * pour rester compatible avec un environnement de tests qui ne charge
	 * pas forcément les built-ins string PHP 8.
	 *
	 * @param string $haystack Chaîne complète.
	 * @param string $needle   Suffixe attendu.
	 * @return bool
	 */
	private static function ends_with( string $haystack, string $needle ): bool {
		$len = strlen( $needle );
		if ( 0 === $len ) {
			return true;
		}
		return $needle === substr( $haystack, -$len );
	}
}
