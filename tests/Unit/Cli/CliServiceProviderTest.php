<?php
/**
 * Tests CliServiceProvider — Phase 5.5 V1.0.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Cli;

use Cent_Son\Html_Normalizer\Cli\CliServiceProvider;
use PHPUnit\Framework\TestCase;
use WP_CLI;

final class CliServiceProviderTest extends TestCase {

	protected function setUp(): void {
		WP_CLI::reset();
	}

	public function test_register_with_empty_list_is_noop(): void {
		( new CliServiceProvider( array() ) )->register();
		$this->assertSame( array(), WP_CLI::$commands );
	}

	public function test_register_adds_each_command(): void {
		$cmd_a = new \stdClass();
		$cmd_b = new \stdClass();
		$provider = new CliServiceProvider( array(
			array( 'name' => 'htmln scan',       'callable' => $cmd_a ),
			array( 'name' => 'htmln steps list', 'callable' => array( $cmd_b, 'list_steps' ) ),
		) );

		$provider->register();

		$this->assertCount( 2, WP_CLI::$commands );
		$this->assertSame( 'htmln scan', WP_CLI::$commands[0]['name'] );
		$this->assertSame( $cmd_a, WP_CLI::$commands[0]['callable'] );
		$this->assertSame( 'htmln steps list', WP_CLI::$commands[1]['name'] );
		$this->assertSame( array( $cmd_b, 'list_steps' ), WP_CLI::$commands[1]['callable'] );
	}

	public function test_register_is_idempotent(): void {
		$provider = new CliServiceProvider( array(
			array( 'name' => 'htmln scan', 'callable' => new \stdClass() ),
		) );

		$provider->register();
		$provider->register();
		$provider->register();

		$this->assertCount(
			1,
			WP_CLI::$commands,
			'register() doit être idempotent : un seul add_command par commande'
		);
	}

	public function test_commands_returns_input_list(): void {
		$entries = array(
			array( 'name' => 'htmln scan', 'callable' => new \stdClass() ),
		);
		$provider = new CliServiceProvider( $entries );
		$this->assertSame( $entries, $provider->commands() );
	}
}
