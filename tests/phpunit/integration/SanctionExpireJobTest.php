<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use Flow\Model\UUID;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionExpireJob;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\SanctionExpireJob
 * @group Database
 */
class SanctionExpireJobTest extends MediaWikiIntegrationTestCase {

	private function insertSanction( string $expiry ): Sanction {
		$author = new User();
		$author->setId( 10 );
		$target = new User();
		$target->setId( 11 );

		$sanction = new Sanction();
		$sanction->setAuthor( $author );
		$sanction->setTarget( $target );
		$sanction->setWorkflowId( UUID::create() );
		$sanction->setExpiry( $expiry );
		$sanction->insert();
		return $sanction;
	}

	public function testScheduleWithoutDelayedJobs() {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => \MediaWiki\JobQueue\JobQueueMemory::class ],
		] );
		$sanction = $this->insertSanction( wfTimestamp( TS_MW, time() + 3600 ) );

		SanctionExpireJob::schedule( $sanction );

		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'sanctionsExpire' );
		$this->assertSame( 0, $queue->getSize() + $queue->getDelayedCount() );
	}

	public function testScheduleWithDelayedJobs() {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => DelayedJobQueueMemory::class ],
		] );
		$expiry = wfTimestamp( TS_MW, time() + 3600 );
		$sanction = $this->insertSanction( $expiry );

		SanctionExpireJob::schedule( $sanction );

		$jobQueueGroup = $this->getServiceContainer()->getJobQueueGroup();
		$job = $jobQueueGroup->get( 'sanctionsExpire' )->pop();
		$this->assertInstanceOf( SanctionExpireJob::class, $job );
		$this->assertSame( $sanction->getId(), $job->getParams()['sanctionId'] );
		$this->assertSame( (int)wfTimestamp( TS_UNIX, $expiry ), $job->getReleaseTimestamp() );
	}

	public function testRunLeavesSanctionsNotExpired() {
		$sanction = $this->insertSanction( wfTimestamp( TS_MW, time() + 3600 ) );

		$job = new SanctionExpireJob( [ 'sanctionId' => $sanction->getId() ] );
		$this->assertTrue( $job->run() );

		$store = $this->getServiceContainer()->getService( 'SanctionStore' );
		$this->assertFalse( (bool)$store->newFromId( $sanction->getId() )->isHandled() );
	}

	public function testRunIgnoresMissingSanctions() {
		$job = new SanctionExpireJob( [ 'sanctionId' => 12345 ] );
		$this->assertTrue( $job->run() );
	}
}
