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
				'stv_sanction',
				'stv_period',
			] )
			->from( 'sanctions_vote' );
	}

	public function getVoteBySanction( Sanction $sanction, User $user ): ?Vote {
		$row = $this->newSelectQueryBuilder()
			->where( [
				'stv_sanction' => $sanction->getId(),
				'stv_user' => $user->getId(),
			] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return Vote::newFromRow( $row );
	}

	public function deleteOn( Sanction $sanction ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'sanctions_vote' )
			->where( [ 'stv_sanction' => $sanction->getId() ] )
			->caller( __METHOD__ )
			->execute();

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}
		return true;
	}

}
