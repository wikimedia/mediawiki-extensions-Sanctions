<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration\Api;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\MainConfigNames;
use MediaWiki\Tests\Api\ApiTestCase;
use MediaWiki\User\User;

/**
 * @covers \MediaWiki\Extension\Sanctions\Api\ApiQuerySanctions
 * @group API
 * @group Database
 */
class ApiQuerySanctionsTest extends ApiTestCase {

	private User $author;
	private User $target;

	protected function setUp(): void {
		parent::setUp();
		$this->author = $this->getMutableTestUser()->getUser();
		$this->target = $this->getMutableTestUser()->getUser();
	}

	/**
	 * Let every registered user vote, without the waiting period and the edits.
	 */
	private function letEveryoneVote() {
		$this->overrideConfigValue( MainConfigNames::UseDatabaseMessages, true );
		$this->setTemporaryHook( 'MessagesPreLoad', static function ( $title, &$message ) {
			if ( $title === 'Sanctions-voting-right-verification-period' ) {
				$message = '-1';
			} elseif ( $title === 'Sanctions-voting-right-verification-edits' ) {
				$message = '0';
			}
		} );
	}

	/**
	 * @param int $expiresIn Seconds until the voting ends.
	 * @param bool $forInsultingName
	 * @return Sanction
	 */
	private function insertSanction( int $expiresIn, bool $forInsultingName = false ): Sanction {
		$sanction = new Sanction();
		$sanction->setAuthor( $this->author );
		$sanction->setTarget( $this->target );
		$sanction->setWorkflowId( UUID::create() );
		$sanction->setExpiry( wfTimestamp( TS_MW, time() + $expiresIn ) );
		$sanction->setTargetOriginalName( $forInsultingName ? $this->target->getName() : '' );
		$sanction->insert();
		return $sanction;
	}

	/**
	 * @param array $params
	 * @return int[] IDs of the listed sanctions.
	 */
	private function listIds( array $params = [] ): array {
		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'sanctions',
			'saprop' => '',
		] + $params, null, false, $this->getTestUser()->getUser() );
		return array_column( $result['query']['sanctions'], 'id' );
	}

	public function testExpiredSanctionsAreHiddenFromNonVoters() {
		$ongoing = $this->insertSanction( 3600 )->getId();
		$expired = $this->insertSanction( -3600 )->getId();

		$this->assertSame( [ $ongoing ], $this->listIds() );

		$this->letEveryoneVote();
		$this->assertSame( [ $expired, $ongoing ], $this->listIds() );
		$this->assertSame( [ $expired ], $this->listIds( [ 'sashow' => 'expired' ] ) );
		$this->assertSame( [ $ongoing ], $this->listIds( [ 'sashow' => '!expired' ] ) );
	}

	public function testFilters() {
		$this->letEveryoneVote();
		$first = $this->insertSanction( 3600 )->getId();
		$second = $this->insertSanction( 3600, true )->getId();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'sanctions' )
			->set( [ 'st_emergency' => 1 ] )
			->where( [ 'st_id' => $second ] )
			->caller( __METHOD__ )
			->execute();
		$other = $this->getMutableTestUser()->getUser();
		$third = new Sanction();
		$third->setAuthor( $this->author );
		$third->setTarget( $other );
		$third->setWorkflowId( UUID::create() );
		$third->setExpiry( wfTimestamp( TS_MW, time() + 3600 ) );
		$third = $third->insert();

		$this->assertSame( [ $third, $second, $first ], $this->listIds() );
		$this->assertSame( [ $first, $second, $third ], $this->listIds( [ 'sadir' => 'newer' ] ) );
		$this->assertSame( [ $first, $third ], $this->listIds( [ 'saids' => "$first|$third", 'sadir' => 'newer' ] ) );
		$this->assertSame( [ $second, $first ], $this->listIds( [ 'satarget' => $this->target->getName() ] ) );
		$this->assertSame( [ $second ], $this->listIds( [ 'sashow' => 'emergency' ] ) );
		$this->assertSame( [ $third, $first ], $this->listIds( [ 'sashow' => '!emergency|!handled' ] ) );
		$this->assertSame( [], $this->listIds( [ 'sashow' => 'handled' ] ) );
	}

	public function testContinue() {
		$first = $this->insertSanction( 3600 )->getId();
		$second = $this->insertSanction( 3600 )->getId();

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'sanctions',
			'salimit' => 1,
		] );
		$this->assertSame( [ $second ], array_column( $result['query']['sanctions'], 'id' ) );
		$this->assertSame( (string)$first, $result['continue']['sacontinue'] );

		$this->assertSame( [ $first ], $this->listIds( [ 'sacontinue' => $first ] ) );
	}

	public function testProps() {
		$this->letEveryoneVote();
		$sanction = $this->insertSanction( -3600, true );

		[ $result ] = $this->doApiRequest( [
			'action' => 'query',
			'list' => 'sanctions',
			'saprop' => 'author|target|type|expiry|flags|page|votes',
			'formatversion' => 2,
		], null, false, $this->getTestUser()->getUser() );

		$this->assertSame( [
			'id' => $sanction->getId(),
			'author' => $this->author->getName(),
			'authorid' => $this->author->getId(),
			'target' => $this->target->getName(),
			'targetid' => $this->target->getId(),
			'type' => 'insultingname',
			'expiry' => wfTimestamp( TS_ISO_8601, $sanction->getExpiry() ),
			'expired' => true,
			'handled' => false,
			'emergency' => false,
			'ns' => NS_TOPIC,
			'title' => $sanction->getDiscussionPage()->getPrefixedText(),
			'votes' => 0,
			'agree' => 0,
			'passed' => false,
			'period' => 0,
		], $result['query']['sanctions'][0] );
	}

	public function testUnknownTarget() {
		$this->expectApiErrorCode( 'nosuchuser' );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'sanctions',
			'satarget' => 'Nobody with this name',
		] );
	}

	public function testConflictingShow() {
		$this->expectApiErrorCode( 'show' );
		$this->doApiRequest( [
			'action' => 'query',
			'list' => 'sanctions',
			'sashow' => 'handled|!handled',
		] );
	}
}
