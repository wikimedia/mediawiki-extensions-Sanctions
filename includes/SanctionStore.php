<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Model\UUID;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class SanctionStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
	) {
	}

	/**
	 * Return a query builder that selects the fields of sanctions which Sanction::newFromRow()
	 * takes.
	 *
	 * @param IReadableDatabase|null $db The replica database is used when it is not given.
	 * @return SelectQueryBuilder
	 */
	public function newSelectQueryBuilder( ?IReadableDatabase $db = null ): SelectQueryBuilder {
		$db ??= $this->dbProvider->getReplicaDatabase();
		return $db->newSelectQueryBuilder()
			->select( [
				'st_id',
				'st_author',
				'st_topic',
				'st_target',
				'st_original_name',
				'st_expiry',
				'st_handled',
				'st_emergency',
				'st_last_update_timestamp',
			] )
			->from( 'sanctions' );
	}

	/**
	 * @param int $id
	 * @return Sanction|null
	 */
	public function newFromId( $id ) {
		$row = $this->newSelectQueryBuilder()
			->where( [ 'st_id' => $id ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}

	/**
	 * @param User $user
	 * @param bool|null $forInsertingName
	 * @param bool|null $expired If true, only returns expired sanctions.
	 * @param bool|null $handled
	 * @return Sanction[]
	 */
	public function findByTarget( User $user, $forInsertingName = null, $expired = null, $handled = null ) {
		$db = $this->dbProvider->getReplicaDatabase();
		$queryBuilder = $this->newSelectQueryBuilder( $db )
			->where( [ 'st_target' => $user->getId() ] );

		if ( $expired !== null ) {
			$operator = $expired ? '<=' : '>';
			$queryBuilder->andWhere( $db->expr( 'st_expiry', $operator, wfTimestamp( TS_MW ) ) );
		}

		if ( $forInsertingName !== null ) {
			if ( $forInsertingName ) {
				$queryBuilder->andWhere( $db->expr( 'st_original_name', '!=', '' ) );
			} else {
				// TODO
			}
		}

		if ( $handled !== null ) {
			$queryBuilder->andWhere( [ 'st_handled' => $handled ? 1 : 0 ] );
		}

		$rows = $queryBuilder->caller( __METHOD__ )->fetchResultSet();

		$sanctions = [];
		foreach ( $rows as $row ) {
			$sanctions[] = Sanction::newFromRow( $row );
		}

		return $sanctions;
	}

	/**
	 * @return Sanction[]
	 */
	public function findNotHandledExpired() {
		$db = $this->dbProvider->getReplicaDatabase();
		$rows = $this->newSelectQueryBuilder( $db )
			->where( [
				$db->expr( 'st_expiry', '<=', wfTimestamp( TS_MW ) ),
				'st_handled' => 0,
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$sanctions = [];
		foreach ( $rows as $row ) {
			$sanctions[] = Sanction::newFromRow( $row );
		}

		return $sanctions;
	}

	/**
	 * @param UUID $uuid
	 * @return Sanction|null
	 */
	public function newFromWorkflowId( UUID $uuid ) {
		$row = $this->newSelectQueryBuilder()
			->where( [ 'st_topic' => $uuid->getBinary() ] )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}
}
