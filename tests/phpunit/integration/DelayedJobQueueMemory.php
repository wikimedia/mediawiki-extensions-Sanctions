<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\JobQueue\JobQueueMemory;

/**
 * An in-memory job queue that accepts delayed jobs, for testing the jobs of sanctions.
 */
class DelayedJobQueueMemory extends JobQueueMemory {

	/** @inheritDoc */
	protected function supportsDelayedJobs() {
		return true;
	}
}
