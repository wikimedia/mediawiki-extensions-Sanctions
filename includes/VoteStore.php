<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\User\User;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class VoteStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Return a query builder that selects the fields of votes which Vote::newFromRow() takes.
	 *
	 * @param IReadableDatabase|null $db The replica database is used when it is not given.
	 * @return SelectQueryBuilder
	 */
	public function newSelectQueryBuilder( ?IReadableDatabase $db = null ): SelectQueryBuilder {
		$db ??= $this->dbProvider->getReplicaDatabase();
		return $db->newSelectQueryBuilder()
			->select( [
				'stv_user',
				'stv_topic',
				'stv_period',
			] )
			->from( 'sanctions_vote' );
	}

	/**
	 * @param Sanction $sanction
	 * @param User $user
	 * @return Vote|null
	 */
	public function getVoteBySanction( Sanction $sanction, User $user ) {
		$row = $this->newSelectQueryBuilder()
			->where( [
				'stv_topic' => $sanction->getWorkflowId()->getBinary(),
				'stv_user' => $user->getId(),
			] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return Vote::newFromRow( $row );
	}

	/**
	 * @param Sanction $sanction
	 * @return bool
	 */
	public function deleteOn( Sanction $sanction ) {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'sanctions_vote' )
			->where( [ 'stv_topic' => $sanction->getWorkflowId()->getBinary() ] )
			->caller( __METHOD__ )
			->execute();

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}
		return true;
	}

}
