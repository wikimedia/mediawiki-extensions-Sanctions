<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Sanction
 * @group Database
 */
class SanctionTest extends MediaWikiIntegrationTestCase {
	/**
	 * @covers \MediaWiki\Extension\Sanctions\Sanction::newFromRow
	 */
	public function testNewFromRow() {
		$sanction = Sanction::newFromRow( (object)[
			'st_author' => 10,
			'st_target' => 11,
		] );
		$this->assertInstanceOf( Sanction::class, $sanction );
		$this->assertSame( 10, $sanction->getAuthor()->getId() );
		$this->assertSame( 11, $sanction->getTarget()->getId() );
	}

	/**
	 * @covers \MediaWiki\Extension\Sanctions\Sanction::toggleEmergency
	 * @covers \MediaWiki\Extension\Sanctions\Utils::unblock
	 */
	public function testToggleEmergencyBackRemovesTheBlock() {
		$target = $this->getMutableTestUser()->getUser();
		$sanction = new Sanction();
		$sanction->setAuthor( $this->getMutableTestUser()->getUser() );
		$sanction->setTarget( $target );
		$sanction->setWorkflowId( UUID::create() );
		$sanction->setExpiry( wfTimestamp( TS_MW, time() + 3600 ) );
		$sanction->setTargetOriginalName( '' );
		$sanction->insert();
		$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();
		$sysop = $this->getTestSysop()->getUser();

		$this->assertTrue( $sanction->toggleEmergency( $sysop ) );
		$this->assertNotNull( $blockStore->newFromTarget( $target ) );

		$this->assertTrue( $sanction->toggleEmergency( $sysop ) );
		$this->assertNull( $blockStore->newFromTarget( $target ) );
	}
}
