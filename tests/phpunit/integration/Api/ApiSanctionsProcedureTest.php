<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Api;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\Sanctions\Api\ApiSanctionsProcedure
 * @group API
 * @group Database
 */
class ApiSanctionsProcedureTest extends ApiTestCase {

	private function insertSanction( User $target, int $expiresIn ): int {
		$sanction = new Sanction();
		$sanction->setAuthor( $this->getMutableTestUser()->getUser() );
		$sanction->setTarget( $target );
		$sanction->setWorkflowId( UUID::create() );
		$sanction->setExpiry( wfTimestamp( TS_MW, time() + $expiresIn ) );
		$sanction->setTargetOriginalName( '' );
		return $sanction->insert();
	}

	private function setProcedure( int $id, string $procedure, ?User $user = null ): array {
		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'sanctionsprocedure',
			'id' => $id,
			'procedure' => $procedure,
		], null, $user ?? $this->getTestSysop()->getUser() );
		return $result['sanctionsprocedure'];
	}

	private function isEmergency( int $id ): bool {
		$store = $this->getServiceContainer()->getService( 'SanctionStore' );
		'@phan-var SanctionStore $store';
		return (bool)$store->newFromId( $id )->isEmergency();
	}

	public function testSwitch() {
		$target = $this->getMutableTestUser()->getUser();
		$id = $this->insertSanction( $target, 3600 );
		$blockStore = $this->getServiceContainer()->getDatabaseBlockStore();

		$result = $this->setProcedure( $id, 'emergency' );
		$this->assertSame( [ 'result' => 'Success', 'id' => $id, 'procedure' => 'emergency' ], $result );
		$this->assertTrue( $this->isEmergency( $id ) );
		$this->assertNotNull( $blockStore->newFromTarget( $target ) );

		// Asking for the current process changes nothing.
		$this->setProcedure( $id, 'emergency' );
		$this->assertTrue( $this->isEmergency( $id ) );

		$this->setProcedure( $id, 'normal' );
		$this->assertFalse( $this->isEmergency( $id ) );
		$this->assertNull( $blockStore->newFromTarget( $target ) );
	}

	public function testUserWhoCannotBlock() {
		$id = $this->insertSanction( $this->getMutableTestUser()->getUser(), 3600 );
		$this->expectApiErrorCode( 'permissiondenied' );
		$this->setProcedure( $id, 'emergency', $this->getTestUser()->getUser() );
	}

	public function testExpiredSanction() {
		$id = $this->insertSanction( $this->getMutableTestUser()->getUser(), -3600 );
		$this->expectApiErrorCode( 'sanctions-expired' );
		$this->setProcedure( $id, 'emergency' );
	}

	public function testUnknownSanction() {
		$this->expectApiErrorCode( 'sanctions-nosuchsanction' );
		$this->setProcedure( 12345, 'emergency' );
	}
}
