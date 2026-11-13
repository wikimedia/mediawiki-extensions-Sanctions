<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\Extension\CommunityConfiguration\Schema\JsonSchema;

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase

/**
 * Voting rules set on the wiki at Special:CommunityConfiguration/Sanctions.
 *
 * Each property overrides the configuration variable of the same name, and its default is the
 * default of that variable.
 */
class SanctionsSchema extends JsonSchema {
	public const VERSION = '1.0.0';

	public const SanctionsVotingPeriod = [
		self::TYPE => self::TYPE_NUMBER,
		self::MINIMUM => 0,
		self::DEFAULT => 3,
	];

	public const SanctionsVotingRightVerificationPeriod = [
		self::TYPE => self::TYPE_NUMBER,
		self::MINIMUM => 0,
		self::DEFAULT => 20,
	];

	public const SanctionsVotingRightVerificationEdits = [
		self::TYPE => self::TYPE_INTEGER,
		self::MINIMUM => 0,
		self::DEFAULT => 3,
	];

	public const SanctionsMaxBlockPeriod = [
		self::TYPE => self::TYPE_INTEGER,
		self::MINIMUM => 1,
		self::DEFAULT => 30,
	];
}
