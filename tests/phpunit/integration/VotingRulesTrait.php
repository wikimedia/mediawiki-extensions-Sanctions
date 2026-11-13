<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Registration\ExtensionRegistry;

/**
 * Sets the voting rules where they are read: on the wiki where CommunityConfiguration is
 * installed, in the configuration variables otherwise.
 */
trait VotingRulesTrait {

	/**
	 * @param array $rules Configuration variable names and values, like
	 *  [ 'SanctionsVotingRightVerificationEdits' => 0 ]
	 */
	private function setVotingRules( array $rules ): void {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CommunityConfiguration' ) ) {
			$this->overrideConfigValues( $rules );
			return;
		}
		$spec = $this->getServiceContainer()->getService( 'CommunityConfiguration.ProviderFactory' )
			->getProviderSpec( 'Sanctions' );
		$spec['store'] = [ 'type' => 'static', 'args' => [ $rules ] ];
		$this->overrideConfigValue( 'CommunityConfigurationProviders', [ 'Sanctions' => $spec ] );
	}
}
