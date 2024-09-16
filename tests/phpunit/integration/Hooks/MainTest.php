<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Hooks;

use MediaWiki\Extension\Sanctions\Hooks\Main;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Hooks\Main
 */
class MainTest extends MediaWikiIntegrationTestCase {

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Hooks\Main::__construct
	 */
	public function testConstruct() {
		$services = $this->getServiceContainer();
		$sanctionStore = new SanctionStore( $services->getConnectionProvider() );
		$voteStore = new VoteStore( $services->getConnectionProvider() );
		$actual = new Main(
			$sanctionStore,
			$voteStore,
			$services->getUserFactory()
		);
		$this->assertInstanceOf( Main::class, $actual );
	}
}
