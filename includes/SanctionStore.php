<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Model\UUID;
use User;
use Wikimedia\Rdbms\IConnectionProvider;

class SanctionStore {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @param int $id
	 * @return Sanction|null
	 */
	public function newFromId( $id ) {
		$db = $this->dbProvider->getReplicaDatabase();

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ 'st_id' => $id ],
			__METHOD__
		);
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

		$conds = [
			'st_target' => $user->getId(),
		];

		if ( $expired !== null ) {
			$operator = $expired ? '<=' : '>';
			$now = wfTimestamp( TS_MW );
			$conds[] = "st_expiry $operator $now";
		}

		if ( $forInsertingName !== null ) {
			if ( $forInsertingName ) {
				$conds[] = "st_original_name <> ''";
			} else {
				// TODO
			}
		}

		if ( $handled !== null ) {
			$conds['st_handled'] = $handled ? 1 : 0;
		}

		$rows = $db->select(
			'sanctions',
			'*',
			$conds,
			__METHOD__
		);
		if ( !$rows ) {
			return [];
		}

		$sanctions = [];
		foreach ( $rows as $row ) {
			$sanctions[] = Sanction::newFromRow( $row );
		}

		return $sanctions;
	}

	/**
	 *
	 * @return Sanction[]
	 */
	public function findNotHandledExpired() {
		$db = $this->dbProvider->getReplicaDatabase();
		$rows = $db->select(
			'sanctions',
			'*',
			[
				'st_expiry <= ' . wfTimestamp( TS_MW ),
				'st_handled' => 0,
			],
			__METHOD__
		);
		if ( !$rows ) {
			return [];
		}

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
		$db = $this->dbProvider->getReplicaDatabase();

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ 'st_topic' => $uuid->getBinary() ],
			__METHOD__
		);
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}
}
