<?php
/**
 * Tests RuleAutoDisabler — auto-désactivation des règles épuisées.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Core\Lifecycle;

use Cent_Son\Html_Normalizer\Core\Lifecycle\RuleAutoDisabler;
use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Diagnostics\DiagnosticsRepository;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use Cent_Son\Html_Normalizer\Steps\StepsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Couvre le contrat : seules les règles `complete` sont désactivées, le
 * scan partiel est un no-op, l'idempotence est garantie par `auto_disabled_at`.
 *
 * Les dépendances `Diagnostics` et `Steps` sont des stubs anonymes — on
 * teste la logique de décision sans toucher au SQL. `SettingsRepository`
 * est concret car il a un comportement non trivial (lecture/écriture
 * d'options) et la fake options table de bootstrap.php le supporte
 * nativement.
 */
final class RuleAutoDisablerTest extends TestCase {

	private SettingsRepository $settings;

	protected function setUp(): void {
		// Reset complet de l'option presets pour chaque test.
		delete_option( 'son100_htmln_presets' );
		delete_option( 'son100_htmln_settings' );
		$this->settings = new SettingsRepository();
	}

	// =========================================================================
	//  Cas positifs — désactivation effective
	// =========================================================================

	public function test_complete_rule_gets_disabled_and_marked(): void {
		$this->seed_preset( 'R5', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$result = $disabler->evaluate_and_disable();

		$this->assertTrue( $result['fully_scanned'] );
		$this->assertSame( array( 'R5' ), $result['disabled'] );
		$config = $this->settings->get_preset_config( 'R5' );
		$this->assertFalse( (bool) $config['enabled'] );
		$this->assertNotEmpty( $config['auto_disabled_at'] );
	}

	public function test_multiple_complete_rules_disabled_in_one_call(): void {
		$this->seed_preset( 'R3', enabled: true );
		$this->seed_preset( 'R4', enabled: true );
		$this->seed_preset( 'R8', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R3' => 0, 'R4' => 0, 'R8' => 0 ),
			applied_at: array(
				'R3' => '2026-05-15 12:00:00',
				'R4' => '2026-05-15 12:01:00',
				'R8' => '2026-05-15 12:02:00',
			),
		);

		$result = $disabler->evaluate_and_disable();
		sort( $result['disabled'] );
		$this->assertSame( array( 'R3', 'R4', 'R8' ), $result['disabled'] );
	}

	// =========================================================================
	//  Cas négatifs — no-op
	// =========================================================================

	public function test_partial_scan_returns_empty_list(): void {
		$this->seed_preset( 'R5', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: false,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertFalse( $result['fully_scanned'] );
		$this->assertSame( array(), $result['disabled'] );
		// Et surtout : pas d'effet de bord sur la config.
		$config = $this->settings->get_preset_config( 'R5' );
		$this->assertTrue( (bool) $config['enabled'] );
		$this->assertArrayNotHasKey( 'auto_disabled_at', $config );
	}

	public function test_pending_rule_skipped_when_occurrences_remain(): void {
		$this->seed_preset( 'R5', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 12 ),  // > 0 → pending
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertSame( array(), $result['disabled'] );
		$this->assertTrue( $this->settings->is_preset_enabled( 'R5' ) );
	}

	public function test_unused_rule_skipped_when_never_applied(): void {
		// 0 occurrences MAIS jamais appliquée → état `unused`, hors scope v1.
		$this->seed_preset( 'R5', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => null ),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertSame( array(), $result['disabled'] );
		$this->assertTrue( $this->settings->is_preset_enabled( 'R5' ) );
	}

	public function test_already_auto_disabled_rule_is_not_retouched(): void {
		// L'utilisateur a réactivé manuellement après une auto-désactivation.
		// Le marqueur `auto_disabled_at` est conservé → on ne retouche pas,
		// même si l'état redevient `complete`.
		$this->seed_preset( 'R5', enabled: true, auto_disabled_at: '2026-04-01 09:00:00' );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertSame( array(), $result['disabled'] );
		$this->assertTrue( $this->settings->is_preset_enabled( 'R5' ) );
	}

	public function test_manually_disabled_rule_is_not_marked_auto(): void {
		// Règle déjà off manuellement : on ne s'attribue pas son état.
		$this->seed_preset( 'R5', enabled: false );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertSame( array(), $result['disabled'] );
		$config = $this->settings->get_preset_config( 'R5' );
		$this->assertArrayNotHasKey( 'auto_disabled_at', $config );
	}

	public function test_idempotent_second_call_returns_empty(): void {
		$this->seed_preset( 'R5', enabled: true );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R5' => 0 ),
			applied_at: array( 'R5' => '2026-05-15 12:00:00' ),
		);

		$first  = $disabler->evaluate_and_disable();
		$second = $disabler->evaluate_and_disable();

		$this->assertSame( array( 'R5' ), $first['disabled'] );
		$this->assertSame( array(), $second['disabled'] );
	}

	public function test_mixed_state_only_complete_rules_disabled(): void {
		// R3 complete → désactiver
		// R4 pending → ignorer
		// R8 unused → ignorer
		// R5 already auto-disabled → ignorer
		$this->seed_preset( 'R3', enabled: true );
		$this->seed_preset( 'R4', enabled: true );
		$this->seed_preset( 'R8', enabled: true );
		$this->seed_preset( 'R5', enabled: true, auto_disabled_at: '2026-04-01 09:00:00' );

		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array( 'R3' => 0, 'R4' => 7, 'R8' => 0, 'R5' => 0 ),
			applied_at: array(
				'R3' => '2026-05-15 12:00:00',
				'R4' => '2026-05-15 12:00:00',
				'R8' => null,                       // unused
				'R5' => '2026-05-15 12:00:00',
			),
		);

		$result = $disabler->evaluate_and_disable();
		$this->assertSame( array( 'R3' ), $result['disabled'] );
	}

	public function test_iterates_through_all_preset_registry_ids(): void {
		// Smoke : un disabler appelé avec une applicable map vide ne crash
		// sur aucun ID de la registry. Garantit que la boucle `foreach
		// PresetRegistry::PRESETS` ne saute pas un ID introduit plus tard.
		foreach ( PresetRegistry::PRESETS as $id ) {
			$this->seed_preset( $id, enabled: true );
		}
		$disabler = $this->make_disabler(
			fully_scanned: true,
			applicable: array(),  // toutes les règles à 0 implicite
			applied_at: array(),  // jamais appliquées → toutes `unused`
		);
		$result = $disabler->evaluate_and_disable();
		// Aucune `complete` → aucune désactivée.
		$this->assertSame( array(), $result['disabled'] );
	}

	// =========================================================================
	//  Helpers
	// =========================================================================

	/**
	 * Écrit une entrée de preset dans l'option son100_htmln_presets.
	 *
	 * @param string      $id
	 * @param bool        $enabled
	 * @param string|null $auto_disabled_at
	 */
	private function seed_preset( string $id, bool $enabled, ?string $auto_disabled_at = null ): void {
		$config = array( 'enabled' => $enabled );
		if ( null !== $auto_disabled_at ) {
			$config['auto_disabled_at'] = $auto_disabled_at;
		}
		$this->settings->set_preset_config( $id, $config );
	}

	/**
	 * Construit un RuleAutoDisabler avec des stubs anonymes pour
	 * DiagnosticsRepository et StepsRepository — on contrôle exactement
	 * ce que retournent `is_corpus_fully_scanned`, `count_by_applicable_rule`
	 * et `last_applied_for_rule`.
	 *
	 * @param bool                  $fully_scanned
	 * @param array<string, int>    $applicable
	 * @param array<string, ?string> $applied_at
	 * @return RuleAutoDisabler
	 */
	private function make_disabler( bool $fully_scanned, array $applicable, array $applied_at ): RuleAutoDisabler {
		$diagnostics = new class( $fully_scanned, $applicable ) extends DiagnosticsRepository {
			/**
			 * @param array<string, int> $applicable
			 */
			public function __construct( private bool $fake_scanned, private array $applicable ) {
				// Skip parent constructor (no wpdb wiring needed for stub).
			}
			public function is_corpus_fully_scanned( SettingsRepository $settings ): bool {
				return $this->fake_scanned;
			}
			public function count_by_applicable_rule(): array {
				return $this->applicable;
			}
		};
		$steps       = new class( $applied_at ) extends StepsRepository {
			/**
			 * @param array<string, ?string> $applied_at
			 */
			public function __construct( private array $applied_at ) {
				// Skip parent constructor.
			}
			public function last_applied_for_rule( string $rule_id ): ?string {
				return $this->applied_at[ $rule_id ] ?? null;
			}
		};

		return new RuleAutoDisabler( $this->settings, $diagnostics, $steps );
	}
}
