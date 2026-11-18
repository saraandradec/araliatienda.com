<?php
/**
 * Lista blanca de entornos para las herramientas de prueba.
 *
 * @package Aralia
 */

namespace Aralia\Tests\Unit;

use Aralia\Support\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
	}

	/**
	 * @dataProvider provide_envs
	 */
	public function test_test_tools_whitelist( string $env, bool $tools, bool $replay ): void {
		$this->assertSame( $tools, Environment::allows_test_tools( $env ), "tools: $env" );
		$this->assertSame( $replay, Environment::allows_replay( $env ), "replay: $env" );
	}

	/**
	 * @return array<string,array{string,bool,bool}>
	 */
	public static function provide_envs(): array {
		return array(
			'local'      => array( 'local', true, true ),
			'test'       => array( 'test', true, true ),
			'staging'    => array( 'staging', false, true ),
			'production' => array( 'production', false, false ),
			'prod'       => array( 'prod', false, false ),
			'Production' => array( 'Production', false, false ),
			'vacío'      => array( '', false, false ),
			'Local'      => array( 'Local', false, false ),
			'live'       => array( 'live', false, false ),
		);
	}
}
