<?php
/**
 * Tests PresetRegistry::get_rules_for_subset() (Phase 1 V1.0).
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Core\Registry;

use Cent_Son\Html_Normalizer\Core\Registry\PresetRegistry;
use Cent_Son\Html_Normalizer\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class PresetRegistryTest extends TestCase {

	private PresetRegistry $registry;

	protected function setUp(): void {
		// Stub : tous les presets sont actives, config par defaut.
		$settings = new class() extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool {
				return true;
			}
			public function get_preset_config( string $preset_id ): array {
				return array( 'enabled' => true );
			}
		};
		$this->registry = new PresetRegistry( $settings );
	}

	public function test_empty_subset_returns_empty_list(): void {
		$this->assertSame( array(), $this->registry->get_rules_for_subset( array() ) );
	}

	public function test_subset_with_unknown_ids_returns_empty(): void {
		$this->assertSame( array(), $this->registry->get_rules_for_subset( array( 'R99', 'RZZ' ) ) );
	}

	public function test_subset_returns_only_requested_rules(): void {
		$rules = $this->registry->get_rules_for_subset( array( 'R5' ) );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'R5', $rules[0]->id() );
	}

	public function test_subset_respects_canonical_order(): void {
		// Demande dans le desordre : R2, R5, R3, R1.
		// Ordre canonique PRESETS : R3, R4, R8, R6, R7, R5, R1, R2.
		// Donc l'ordre attendu : R3, R5, R1, R2.
		$rules = $this->registry->get_rules_for_subset( array( 'R2', 'R5', 'R3', 'R1' ) );
		$ids   = array_map( static fn( $r ) => $r->id(), $rules );
		$this->assertSame( array( 'R3', 'R5', 'R1', 'R2' ), $ids );
	}

	public function test_subset_filters_disabled_rules(): void {
		// Stub ou` seul R3 est active.
		$settings_p3_only = new class() extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool {
				return 'R3' === $preset_id;
			}
			public function get_preset_config( string $preset_id ): array {
				return array( 'enabled' => 'R3' === $preset_id );
			}
		};
		$registry = new PresetRegistry( $settings_p3_only );

		$rules = $registry->get_rules_for_subset( array( 'R3', 'R5', 'R7' ) );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'R3', $rules[0]->id() );
	}

	public function test_subset_ignores_unknown_among_known(): void {
		$rules = $this->registry->get_rules_for_subset( array( 'R5', 'R99', 'R3' ) );
		$ids   = array_map( static fn( $r ) => $r->id(), $rules );
		$this->assertSame( array( 'R3', 'R5' ), $ids );
	}
}
