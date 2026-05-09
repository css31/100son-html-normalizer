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
		$this->assertSame( array(), $this->registry->get_rules_for_subset( array( 'P99', 'PZZ' ) ) );
	}

	public function test_subset_returns_only_requested_rules(): void {
		$rules = $this->registry->get_rules_for_subset( array( 'P5' ) );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'P5', $rules[0]->id() );
	}

	public function test_subset_respects_canonical_order(): void {
		// Demande dans le desordre : P2, P5, P3, P1.
		// Ordre canonique PRESETS : P3, P4, P8, P6, P7, P5, P1, P2.
		// Donc l'ordre attendu : P3, P5, P1, P2.
		$rules = $this->registry->get_rules_for_subset( array( 'P2', 'P5', 'P3', 'P1' ) );
		$ids   = array_map( static fn( $r ) => $r->id(), $rules );
		$this->assertSame( array( 'P3', 'P5', 'P1', 'P2' ), $ids );
	}

	public function test_subset_filters_disabled_rules(): void {
		// Stub ou` seul P3 est active.
		$settings_p3_only = new class() extends SettingsRepository {
			public function is_preset_enabled( string $preset_id ): bool {
				return 'P3' === $preset_id;
			}
			public function get_preset_config( string $preset_id ): array {
				return array( 'enabled' => 'P3' === $preset_id );
			}
		};
		$registry = new PresetRegistry( $settings_p3_only );

		$rules = $registry->get_rules_for_subset( array( 'P3', 'P5', 'P7' ) );
		$this->assertCount( 1, $rules );
		$this->assertSame( 'P3', $rules[0]->id() );
	}

	public function test_subset_ignores_unknown_among_known(): void {
		$rules = $this->registry->get_rules_for_subset( array( 'P5', 'P99', 'P3' ) );
		$ids   = array_map( static fn( $r ) => $r->id(), $rules );
		$this->assertSame( array( 'P3', 'P5' ), $ids );
	}
}
