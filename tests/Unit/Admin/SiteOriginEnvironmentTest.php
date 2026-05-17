<?php
/**
 * Tests unitaires pour `Admin\SiteOriginEnvironment`.
 *
 * Couvre les 4 combinaisons (actif × installé) + le contrat de `to_array()`
 * consommé par le front via `wp_localize_script`.
 *
 * @package Cent_Son\Html_Normalizer\Tests
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Unit\Admin;

use Cent_Son\Html_Normalizer\Admin\SiteOriginEnvironment;
use PHPUnit\Framework\TestCase;

final class SiteOriginEnvironmentTest extends TestCase {

	private static string $plugins_dir = '';
	private string $so_main_file;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// `WP_PLUGIN_DIR` est une constante PHP : on ne peut la définir
		// qu'une seule fois pour toute la suite. On la pointe sur un dossier
		// temporaire dédié, partagé par tous les tests du fichier — chaque
		// test gère ensuite la présence du fichier SO via setUp/tearDown.
		if ( '' === self::$plugins_dir ) {
			self::$plugins_dir = sys_get_temp_dir() . '/son100-htmln-so-env-' . uniqid();
			mkdir( self::$plugins_dir . '/siteorigin-panels', 0777, true );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', self::$plugins_dir );
		}
	}

	public static function tearDownAfterClass(): void {
		if ( '' !== self::$plugins_dir ) {
			$so_dir = self::$plugins_dir . '/siteorigin-panels';
			if ( is_dir( $so_dir ) ) {
				$main = $so_dir . '/siteorigin-panels.php';
				if ( file_exists( $main ) ) {
					unlink( $main );
				}
				rmdir( $so_dir );
			}
			if ( is_dir( self::$plugins_dir ) ) {
				rmdir( self::$plugins_dir );
			}
		}
		parent::tearDownAfterClass();
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['son100_htmln_test_active_plugins'] = array();
		$this->so_main_file = self::$plugins_dir . '/siteorigin-panels/siteorigin-panels.php';

		// Garde-fou : si une exécution antérieure a défini `WP_PLUGIN_DIR`
		// sur un autre chemin (ordre de tests imprévisible), les fixtures
		// disque ne seront pas observées. On signale plutôt que de mentir.
		if ( defined( 'WP_PLUGIN_DIR' ) && WP_PLUGIN_DIR !== self::$plugins_dir ) {
			self::markTestSkipped( 'WP_PLUGIN_DIR fixé par un autre test — sandbox indisponible.' );
		}
	}

	protected function tearDown(): void {
		if ( file_exists( $this->so_main_file ) ) {
			unlink( $this->so_main_file );
		}
		$GLOBALS['son100_htmln_test_active_plugins'] = array();
		parent::tearDown();
	}

	public function test_neither_installed_nor_active(): void {
		// WP_PLUGIN_DIR pointe sur un dossier temporaire commun à tous les
		// tests de ce fichier (cf. setUp). Pour ce cas on ne crée jamais le
		// fichier principal — donc is_installed() est faux.
		$env = new SiteOriginEnvironment();

		self::assertFalse( $env->is_active() );
		self::assertFalse( $env->is_installed() );
		self::assertSame(
			array(
				'siteoriginActive'    => false,
				'siteoriginInstalled' => false,
			),
			$env->to_array()
		);
	}

	public function test_installed_but_inactive(): void {
		touch( $this->so_main_file );

		$env = new SiteOriginEnvironment();

		self::assertFalse( $env->is_active() );
		self::assertTrue( $env->is_installed() );
		self::assertSame(
			array(
				'siteoriginActive'    => false,
				'siteoriginInstalled' => true,
			),
			$env->to_array()
		);
	}

	public function test_active(): void {
		$GLOBALS['son100_htmln_test_active_plugins']['siteorigin-panels/siteorigin-panels.php'] = true;

		$env = new SiteOriginEnvironment();

		self::assertTrue( $env->is_active() );
		// `to_array()` doit propager `installed = true` quand `active = true`,
		// même si le fichier n'a pas été matérialisé sur disque dans ce test
		// (court-circuit volontaire : actif implique installé).
		self::assertTrue( $env->to_array()['siteoriginInstalled'] );
	}

	public function test_to_array_shape_is_stable(): void {
		$env  = new SiteOriginEnvironment();
		$keys = array_keys( $env->to_array() );

		// La forme exacte est consommée par le front (cf. SiteOriginWarning.jsx).
		// Tout changement de clés est un breaking change UI.
		self::assertSame(
			array( 'siteoriginActive', 'siteoriginInstalled' ),
			$keys
		);
	}
}
