<?php

namespace Flow\Tests;

use MediaWiki\Extension\Sanctions\Hooks\SanctionsHookRunner;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Hooks\SanctionsHookRunner
 */
class SanctionsHookRunnerTest extends HookRunnerTestBase {

	public static function provideHookRunners() {
		yield SanctionsHookRunner::class => [ SanctionsHookRunner::class ];
	}
}
