<?php
/**
 * RuleAutoDisabler — désactive automatiquement les règles épuisées.
 *
 * À la fin d'un scan couvrant 100 % du corpus, une règle dans l'état
 * `complete` (au moins une fois appliquée + 0 occurrence restante) n'a
 * plus aucune raison d'être exécutée par le pipeline. On flippe son
 * `enabled` à `false` une fois pour toutes, on marque la date dans
 * `auto_disabled_at` et on ne retouchera plus jamais cette règle —
 * même si l'utilisateur la réactive manuellement (le marqueur
 * `auto_disabled_at` reste posé en BDD comme garde-fou anti-récidive).
 *
 * Hors scope v1 : les règles `unused` (jamais appliquées, 0 occurrences)
 * ne sont pas auto-désactivées car un `countMatches()` bugué donnerait
 * aussi 0 et masquerait silencieusement une règle cassée.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Core\Lifecycle;

defined( 'ABSPATH' ) || exit;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;

/**
 * Évaluateur d'auto-désactivation des règles épuisées.
 *
 * Stateless : toutes les dépendances sont injectées au constructeur, le
 * service peut être instancié à la demande (typiquement dans
 * `DiagnosticsController::finalize_scan`).
 */
final class RuleAutoDisabler {

	/**
	 * @param SettingsRepository    $settings    Lecture/écriture config presets.
	 * @param DiagnosticsRepository $diagnostics Source de `applicable_count` + couverture corpus.
	 * @param StepsRepository       $steps       Source de `last_applied_at` par règle.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly DiagnosticsRepository $diagnostics,
		private readonly StepsRepository $steps,
	) {}

	/**
	 * Évalue chaque règle de `PresetRegistry::PRESETS` et désactive
	 * celles qui sont dans l'état `complete`.
	 *
	 * No-op si le scan ne couvre pas 100 % du corpus — on n'écrit jamais
	 * sur une vue partielle qui pourrait faussement faire passer une
	 * règle pour épuisée. Les règles déjà désactivées (manuellement ou
	 * lors d'un appel précédent) ne sont pas re-traitées et ne figurent
	 * pas dans `disabled`.
	 *
	 * @return array{disabled: list<string>, fully_scanned: bool}
	 */
	public function evaluate_and_disable(): array {
		$fully_scanned = $this->diagnostics->is_corpus_fully_scanned( $this->settings );
		if ( ! $fully_scanned ) {
			return array(
				'disabled'      => array(),
				'fully_scanned' => false,
			);
		}

		$applicable = $this->diagnostics->count_by_applicable_rule();
		$disabled   = array();

		foreach ( PresetRegistry::PRESETS as $rule_id ) {
			if ( ! $this->is_complete( $rule_id, $applicable ) ) {
				continue;
			}
			$config = $this->settings->get_preset_config( $rule_id );
			// Déjà auto-désactivée : ne pas re-désactiver après une
			// réactivation manuelle. Le marqueur sert de mémoire
			// permanente — la décision auto n'est prise qu'une fois par
			// règle, ensuite c'est à l'utilisateur de piloter.
			if ( isset( $config['auto_disabled_at'] ) && '' !== $config['auto_disabled_at'] ) {
				continue;
			}
			// Déjà désactivée à la main : on ne marque pas auto, sinon
			// on s'attribuerait un comportement utilisateur.
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$config['enabled']          = false;
			$config['auto_disabled_at'] = gmdate( 'Y-m-d H:i:s' );
			$this->settings->set_preset_config( $rule_id, $config );
			$disabled[] = $rule_id;
		}

		return array(
			'disabled'      => $disabled,
			'fully_scanned' => true,
		);
	}

	/**
	 * Une règle est `complete` si elle a 0 occurrences applicables ET
	 * qu'elle a été appliquée au moins une fois dans un pas fini.
	 *
	 * @param string             $rule_id    Identifiant interne.
	 * @param array<string, int> $applicable Map `rule_id => count`.
	 * @return bool
	 */
	private function is_complete( string $rule_id, array $applicable ): bool {
		$count = (int) ( $applicable[ $rule_id ] ?? 0 );
		if ( 0 !== $count ) {
			return false;
		}
		return null !== $this->steps->last_applied_for_rule( $rule_id );
	}
}
