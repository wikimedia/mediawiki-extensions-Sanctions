<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Api;

use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\Sanctions\Api\ApiSanctionsPropose
 * @group API
 * @group Database
 */
class ApiSanctionsProposeTest extends ApiTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'DiscussionTools' );
		$this->overrideConfigValues( [
			'SanctionsDiscussionBackend' => 'DiscussionTools',
			MainConfigNames::LanguageCode => 'en',
			MainConfigNames::RCFeeds => [],
		] );
	}

	/**
	 * @return User A user who has the right to vote.
	 */
	private function getVoter(): User {
		$user = $this->getMutableTestUser()->getUser();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_registration' => $this->getDb()->timestamp( '20200101000000' ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )
			->execute();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->editPage( 'Sanctions test edits', (string)$i . $user->getName(), '', NS_MAIN, $user );
		}
		return $this->getServiceContainer()->getUserFactory()->newFromId( $user->getId() );
	}

	private function propose( User $user, array $params ): array {
		[ $result ] = $this->doApiRequestWithToken( [
			'action' => 'sanctionspropose',
		] + $params, null, $user );
		return $result['sanctionspropose'];
	}

	public function testPropose() {
		$voter = $this->getVoter();
		$target = $this->getMutableTestUser()->getUser();

		$result = $this->propose( $voter, [
			'target' => $target->getName(),
			'content' => 'Test reason',
		] );

		$store = $this->getServiceContainer()->getService( 'SanctionStore' );
		'@phan-var SanctionStore $store';
		$sanction = $store->newFromId( $result['id'] );
		$this->assertNotNull( $sanction );
		$this->assertSame( 'Success', $result['result'] );
		$this->assertSame( $sanction->getDiscussionPage()->getPrefixedText(), $result['title'] );
		$this->assertTrue( $sanction->getAuthor()->equals( $voter ) );
		$this->assertTrue( $sanction->getTarget()->equals( $target ) );
		$this->assertFalse( $sanction->isForInsultingName() );
	}

	public function testDuplicateInsultingName() {
		$voter = $this->getVoter();
		$target = $this->getMutableTestUser()->getUser();
		$params = [ 'target' => $target->getName(), 'insultingname' => 1 ];

		$this->propose( $voter, $params );

		$this->expectApiErrorCode( 'sanctions-insulting-name-exists' );
		$this->propose( $voter, $params );
	}

	public function testUserWhoCannotVote() {
		$this->expectApiErrorCode( 'sanctions-cannot-propose' );
		$this->propose( $this->getMutableTestUser()->getUser(), [
			'target' => $this->getMutableTestUser()->getUser()->getName(),
		] );
	}

	public function testUnknownTarget() {
		$this->expectApiErrorCode( 'nosuchuser' );
		$this->propose( $this->getVoter(), [
			'target' => 'Nobody with this name',
		] );
	}
}
