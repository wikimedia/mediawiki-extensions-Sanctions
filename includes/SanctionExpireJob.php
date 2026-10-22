<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\MediaWikiServices;

/**
 * Handles a sanction when its voting period ends. The job is released at the expiry of the
 * sanction, on job queues that support delayed jobs.
 */
class SanctionExpireJob extends Job implements GenericParameterJob {

	/**
	 * @param array $params
	 *  - sanctionId: The ID of the sanction.
	 */
	public function __construct( array $params ) {
		parent::__construct( 'sanctionsExpire', $params );
	}

	/**
	 * Queue the job to handle the sanction at its expiry, if the job queue supports delayed jobs.
	 * Otherwise, the sanction is handled when someone views it after the expiry.
	 *
	 * @param Sanction $sanction
	 */
	public static function schedule( Sanction $sanction ) {
		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();
		if ( !$jobQueueGroup->get( 'sanctionsExpire' )->delayedJobsEnabled() ) {
			return;
		}
		$jobQueueGroup->push( new self( [
			'sanctionId' => $sanction->getId(),
			'jobReleaseTimestamp' => (int)wfTimestamp( TS_UNIX, $sanction->getExpiry() ),
		] ) );
	}

	/** @inheritDoc */
	public function run() {
		$store = MediaWikiServices::getInstance()->getService( 'SanctionStore' );
		'@phan-var SanctionStore $store';
		$sanction = $store->newFromId( $this->params['sanctionId'] );
		// Already handled sanctions and sanctions that are not expired yet are left as they are.
		$sanction?->execute();
		return true;
	}
}
