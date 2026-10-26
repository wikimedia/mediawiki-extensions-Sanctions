<?php

namespace MediaWiki\Extension\Sanctions\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\Utils;
use MediaWiki\ParamValidator\TypeDef\UserDef;
use MediaWiki\User\UserIdentity;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * List sanctions, like Special:Sanctions does.
 */
class ApiQuerySanctions extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly SanctionStore $sanctionStore,
	) {
		parent::__construct( $query, $moduleName, 'sa' );
	}

	/** @inheritDoc */
	public function execute() {
		$params = $this->extractRequestParams();
		$prop = array_fill_keys( $params['prop'], true );
		$db = $this->getDB();
		$now = wfTimestamp( TS_MW );

		$queryBuilder = $this->sanctionStore->newSelectQueryBuilder( $db );

		if ( $params['ids'] ) {
			$queryBuilder->andWhere( [ 'st_id' => $params['ids'] ] );
		}

		if ( $params['target'] !== null ) {
			$target = $params['target'];
			'@phan-var UserIdentity $target';
			if ( !$target->isRegistered() ) {
				$this->dieWithError( [ 'nosuchusershort', wfEscapeWikiText( $target->getName() ) ] );
			}
			$queryBuilder->andWhere( [ 'st_target' => $target->getId() ] );
		}

		if ( $params['show'] !== null ) {
			$show = array_fill_keys( $params['show'], true );
			if ( ( isset( $show['expired'] ) && isset( $show['!expired'] ) )
				|| ( isset( $show['handled'] ) && isset( $show['!handled'] ) )
				|| ( isset( $show['emergency'] ) && isset( $show['!emergency'] ) )
			) {
				$this->dieWithError( 'apierror-show' );
			}
			if ( isset( $show['expired'] ) ) {
				$queryBuilder->andWhere( $db->expr( 'st_expiry', '<=', $now ) );
			}
			if ( isset( $show['!expired'] ) ) {
				$queryBuilder->andWhere( $db->expr( 'st_expiry', '>', $now ) );
			}
			if ( isset( $show['handled'] ) || isset( $show['!handled'] ) ) {
				$queryBuilder->andWhere( [ 'st_handled' => isset( $show['handled'] ) ? 1 : 0 ] );
			}
			if ( isset( $show['emergency'] ) || isset( $show['!emergency'] ) ) {
				$queryBuilder->andWhere( [ 'st_emergency' => isset( $show['emergency'] ) ? 1 : 0 ] );
			}
		}

		// Like Special:Sanctions, hide expired sanctions from users who cannot vote. The reason
		// for the rule is not written down. Probably the list is meant as the to-do list of the
		// voters, and as the voting on an expired sanction is over, it is of no use to the others.
		if ( !Utils::hasVoteRight( $this->getUser() ) ) {
			$queryBuilder->andWhere( $db->expr( 'st_expiry', '>', $now ) );
		}

		$older = $params['dir'] === 'older';
		if ( $params['continue'] !== null ) {
			$cont = $this->parseContinueParamOrDie( $params['continue'], [ 'int' ] );
			$queryBuilder->andWhere( $db->expr( 'st_id', $older ? '<=' : '>=', $cont[0] ) );
		}

		$res = $queryBuilder
			->orderBy( 'st_id', $older ? SelectQueryBuilder::SORT_DESC : SelectQueryBuilder::SORT_ASC )
			->limit( $params['limit'] + 1 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$result = $this->getResult();
		$count = 0;
		foreach ( $res as $row ) {
			if ( ++$count > $params['limit'] ) {
				$this->setContinueEnumParameter( 'continue', $row->st_id );
				break;
			}
			$vals = $this->extractSanctionInfo( Sanction::newFromRow( $row ), $prop );
			$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $vals );
			if ( !$fit ) {
				$this->setContinueEnumParameter( 'continue', $row->st_id );
				break;
			}
		}
		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'sanction' );
	}

	/**
	 * @param Sanction $sanction
	 * @param array $prop
	 * @return array
	 */
	private function extractSanctionInfo( Sanction $sanction, array $prop ) {
		$vals = [
			ApiResult::META_TYPE => 'assoc',
			'id' => (int)$sanction->getId(),
		];
		if ( isset( $prop['author'] ) ) {
			$vals['author'] = $sanction->getAuthor()->getName();
			$vals['authorid'] = $sanction->getAuthor()->getId();
		}
		if ( isset( $prop['target'] ) ) {
			$vals['target'] = $sanction->getTarget()->getName();
			$vals['targetid'] = $sanction->getTarget()->getId();
		}
		if ( isset( $prop['type'] ) ) {
			$vals['type'] = $sanction->isForInsultingName() ? 'insultingname' : 'block';
		}
		if ( isset( $prop['expiry'] ) ) {
			$vals['expiry'] = ApiResult::formatExpiry( $sanction->getExpiry() );
		}
		if ( isset( $prop['flags'] ) ) {
			$vals['expired'] = $sanction->isExpired();
			$vals['handled'] = (bool)$sanction->isHandled();
			$vals['emergency'] = (bool)$sanction->isEmergency();
		}
		if ( isset( $prop['page'] ) ) {
			self::addTitleInfo( $vals, $sanction->getDiscussionPage() );
		}
		// The votes are deleted once the sanction is handled.
		if ( isset( $prop['votes'] ) && !$sanction->isHandled() ) {
			$votes = $sanction->getVotes();
			$vals['votes'] = count( $votes );
			$vals['agree'] = count( array_filter( $votes, static fn ( $period ) => $period > 0 ) );
			$vals['passed'] = $sanction->isPassed();
			$vals['period'] = (int)$sanction->getPeriod();
		}
		return $vals;
	}

	/** @inheritDoc */
	public function getCacheMode( $params ) {
		// The list depends on whether the user can vote.
		return 'private';
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'ids' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'target' => [
				ParamValidator::PARAM_TYPE => 'user',
				UserDef::PARAM_ALLOWED_USER_TYPES => [ 'name', 'id' ],
				UserDef::PARAM_RETURN_OBJECT => true,
			],
			'show' => [
				ParamValidator::PARAM_TYPE => [
					'expired',
					'!expired',
					'handled',
					'!handled',
					'emergency',
					'!emergency',
				],
				ParamValidator::PARAM_ISMULTI => true,
			],
			'prop' => [
				ParamValidator::PARAM_DEFAULT => 'author|target|type|expiry|flags|page',
				ParamValidator::PARAM_TYPE => [
					'author',
					'target',
					'type',
					'expiry',
					'flags',
					'page',
					'votes',
				],
				ParamValidator::PARAM_ISMULTI => true,
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [],
			],
			'dir' => [
				ParamValidator::PARAM_TYPE => [
					'newer',
					'older',
				],
				ParamValidator::PARAM_DEFAULT => 'older',
				ApiBase::PARAM_HELP_MSG => 'api-help-param-direction',
				ApiBase::PARAM_HELP_MSG_PER_VALUE => [
					'newer' => 'api-help-paramvalue-direction-newer',
					'older' => 'api-help-paramvalue-direction-older',
				],
			],
			'limit' => [
				ParamValidator::PARAM_DEFAULT => 10,
				ParamValidator::PARAM_TYPE => 'limit',
				IntegerDef::PARAM_MIN => 1,
				IntegerDef::PARAM_MAX => ApiBase::LIMIT_BIG1,
				IntegerDef::PARAM_MAX2 => ApiBase::LIMIT_BIG2,
			],
			'continue' => [
				ApiBase::PARAM_HELP_MSG => 'api-help-param-continue',
			],
		];
	}

	/** @inheritDoc */
	protected function getExamplesMessages() {
		return [
			'action=query&list=sanctions&sashow=!handled'
				=> 'apihelp-query+sanctions-example-not-handled',
			'action=query&list=sanctions&satarget=Example&saprop=type|flags|votes'
				=> 'apihelp-query+sanctions-example-target',
		];
	}

	/** @inheritDoc */
	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:Sanctions';
	}
}
