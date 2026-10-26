<?php

namespace MediaWiki\Extension\Sanctions\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\Sanctions\SanctionStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Switch a sanction between the normal and the emergency process, like the button on
 * Special:Sanctions does.
 */
class ApiSanctionsProcedure extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly SanctionStore $sanctionStore,
	) {
		parent::__construct( $main, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		$params = $this->extractRequestParams();
		$this->checkUserRightsAny( 'block' );

		$sanction = $this->sanctionStore->newFromId( $params['id'] );
		if ( !$sanction ) {
			$this->dieWithError( [ 'apierror-sanctions-nosuchsanction', $params['id'] ] );
		}
		if ( $sanction->isExpired() ) {
			$this->dieWithError( 'apierror-sanctions-expired' );
		}

		$emergency = $params['procedure'] === 'emergency';
		if ( (bool)$sanction->isEmergency() !== $emergency &&
			!$sanction->toggleEmergency( $this->getUser() )
		) {
			$this->dieWithError( 'apierror-sanctions-procedure-failed' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result' => 'Success',
			'id' => (int)$sanction->getId(),
			'procedure' => $params['procedure'],
		] );
	}

	/** @inheritDoc */
	public function mustBePosted() {
		return true;
	}

	/** @inheritDoc */
	public function isWriteMode() {
		return true;
	}

	/** @inheritDoc */
	public function needsToken() {
		return 'csrf';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'id' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'procedure' => [
				ParamValidator::PARAM_TYPE => [ 'normal', 'emergency' ],
				ParamValidator::PARAM_REQUIRED => true,
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=sanctionsprocedure&id=1&procedure=emergency&token=123ABC'
				=> 'apihelp-sanctionsprocedure-example-emergency',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:Sanctions';
	}
}
