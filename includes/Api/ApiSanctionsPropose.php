<?php

namespace MediaWiki\Extension\Sanctions\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\Utils;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Propose a new sanction, like the form on Special:Sanctions does.
 */
class ApiSanctionsPropose extends ApiBase {

	public function __construct(
		ApiMain $main,
		string $moduleName,
		private readonly SanctionStore $sanctionStore,
		private readonly UserFactory $userFactory,
	) {
		parent::__construct( $main, $moduleName );
	}

	/** @inheritDoc */
	public function execute() {
		$params = $this->extractRequestParams();
		$user = $this->getUser();

		$reasons = [];
		if ( !Utils::hasVoteRight( $user, $reasons ) ) {
			$this->dieWithError( [ 'apierror-sanctions-cannot-propose', implode( ' ', $reasons ) ] );
		}

		$targetIdentity = $params['target'];
		'@phan-var UserIdentity $targetIdentity';
		$target = $this->userFactory->newFromUserIdentity( $targetIdentity );
		if ( !$target->isRegistered() ) {
			$this->dieWithError( [ 'nosuchusershort', wfEscapeWikiText( $targetIdentity->getName() ) ] );
		}

		$forInsultingName = $params['insultingname'];
		if ( $forInsultingName && $this->sanctionStore->findByTarget( $target, true, false ) ) {
			$this->dieWithError( [ 'apierror-sanctions-insulting-name-exists', wfEscapeWikiText( $target->getName() ) ] );
		}

		$content = $params['content'] ?? $this->msg( 'sanctions-topic-no-description' )->text();
		$sanction = Sanction::write( $user, $target, $forInsultingName, $content );
		if ( !$sanction ) {
			$this->dieWithError( 'apierror-sanctions-propose-failed' );
		}

		$result = [
			'result' => 'Success',
			'id' => (int)$sanction->getId(),
		];
		ApiQuerySanctions::addTitleInfo( $result, $sanction->getDiscussionPage() );
		$this->getResult()->addValue( null, $this->getModuleName(), $result );
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
			'target' => [
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'id' ],
				UserDef::PARAM_RETURN_OBJECT => true,
				ParamValidator::PARAM_REQUIRED => true,
			],
			'insultingname' => [
				ParamValidator::PARAM_TYPE => 'boolean',
			],
			'content' => [
				ParamValidator::PARAM_TYPE => 'text',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=sanctionspropose&target=Example&content=Vandalism&token=123ABC'
				=> 'apihelp-sanctionspropose-example-block',
			'action=sanctionspropose&target=Example&insultingname=1&token=123ABC'
				=> 'apihelp-sanctionspropose-example-insulting-name',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:Sanctions';
	}
}
