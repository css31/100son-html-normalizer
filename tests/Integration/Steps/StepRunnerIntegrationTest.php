<?php
/**
 * Tests d'intégration StepRunner — Phase 4.4 V1.0.
 *
 * Vérifie l'assemblage bout-en-bout via la factory `Plugin::make_step_runner()` :
 * la grappe complète des 8 dépendances doit produire un StepRunner valide,
 * stateless, et capable d'exécuter au moins une opération qui touche le wpdb
 * stub global (smoke check du câblage du StepsRepository réel).
 *
 * Note : ce test ne charge pas WordPress réel (cf. convention `PublicApiTest`
 * pour l'usage du terme "intégration" dans ce projet — bootstrap stubs partagé).
 * Un test full-WP suit Phase 5 quand les contrôleurs REST/CLI seront en place.
 *
 * @package Cent_Son\Html_Normalizer
 */

declare( strict_types=1 );

namespace Cent_Son\Html_Normalizer\Tests\Integration\Steps;

use Cent_Son\Html_Normalizer\Plugin;
use Cent_Son\Html_Normalizer\Steps\StepRunner;
use PHPUnit\Framework\TestCase;
use Son100_Htmln_Test_Wpdb;

final class StepRunnerIntegrationTest extends TestCase {

	private Son100_Htmln_Test_Wpdb $wpdb;

	protected function setUp(): void {
		// Les repos réels (StepsRepository, DiagnosticsRepository) cherchent
		// `$GLOBALS['wpdb']` quand on les instancie sans argument — la factory
		// `make_step_runner()` les construit ainsi par défaut (cf. Phase 4.4).
		$this->wpdb                       = new Son100_Htmln_Test_Wpdb();
		$GLOBALS['wpdb']                  = $this->wpdb;
		$GLOBALS['son100_htmln_options']  = array();
	}

	public function test_factory_returns_step_runner_instance(): void {
		$runner = Plugin::make_step_runner();
		$this->assertInstanceOf( StepRunner::class, $runner );
	}

	public function test_factory_creates_independent_instances_each_call(): void {
		// Stateless : chaque appel = nouvelle grappe (pas de cache singleton).
		// Important pour la Phase 5 où chaque requête REST instanciera un
		// runner indépendant sans interférence entre requêtes concurrentes.
		$a = Plugin::make_step_runner();
		$b = Plugin::make_step_runner();

		$this->assertNotSame( $a, $b );
	}

	public function test_factory_runner_exposes_full_public_api(): void {
		// Smoke check : les 6 méthodes publiques de Phase 4 doivent être
		// présentes après assemblage par la factory. Garde-fou contre une
		// régression d'API silencieuse (ex. renommage non répercuté).
		$runner = Plugin::make_step_runner();

		$this->assertTrue( method_exists( $runner, 'start_step' ) );
		$this->assertTrue( method_exists( $runner, 'process_article' ) );
		$this->assertTrue( method_exists( $runner, 'confirm_article' ) );
		$this->assertTrue( method_exists( $runner, 'refuse_article' ) );
		$this->assertTrue( method_exists( $runner, 'resume_progress' ) );
		$this->assertTrue( method_exists( $runner, 'finalize_step' ) );
	}

	public function test_factory_start_step_writes_to_steps_table_via_real_repository(): void {
		// Bout-en-bout du câblage Plugin → StepRunner → StepsRepository → $wpdb.
		// On invoque start_step et on vérifie que la requête INSERT atterrit
		// bien sur la table préfixée son100_htmln_steps. C'est le seul test
		// qui prouve que la factory utilise le vrai StepsRepository (et non
		// un stub de test) avec le bon préfixe de table.
		$this->wpdb->insert_return = 1;

		$runner = Plugin::make_step_runner();
		$uuid   = $runner->start_step( array( 100, 101 ), array( 'P1' ), 5 );

		// UUID v4 généré côté serveur (cf. §13 garde-fou cahier).
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$uuid
		);

		// Le repos réel a bien envoyé un INSERT à la bonne table.
		$this->assertCount( 1, $this->wpdb->insert_log );
		$this->assertSame(
			$this->wpdb->prefix . 'son100_htmln_steps',
			$this->wpdb->insert_log[0]['table']
		);
		$inserted = $this->wpdb->insert_log[0]['data'];
		$this->assertSame( $uuid, $inserted['step_uuid'] );
		$this->assertSame( 2, $inserted['total_articles'] );
		$this->assertSame( 5, $inserted['user_id'] );
		$this->assertSame( 0, $inserted['successful_articles'] );
		$this->assertSame( 0, $inserted['refused_articles'] );
		$this->assertSame( 0, $inserted['errored_articles'] );
		$this->assertNull( $inserted['finished_at'] );
	}
}
