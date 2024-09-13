<?php

namespace MediaWiki\Extension\Sanctions;

use Flow\Model\UUID;
use User;
use Wikimedia\Rdbms\ILoadBalancer;

class SanctionStore {

	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @return ILoadBalancer
	 */
	private function getDBLoadBalancer() {
		return $this->loadBalancer;
	}

	/**
	 * @param int $id
	 * @return Sanction|null
	 */
	public function newFromId( $id ) {
		$db = $this->loadBalancer->getConnection( DB_REPLICA );

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ 'st_id' => $id ]
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
		$db = $this->loadBalancer->getConnection( DB_REPLICA );

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
		$db = $this->loadBalancer->getConnection( DB_REPLICA );
		$rows = $db->select(
			'sanctions',
			'*',
			[
				'st_expiry <= ' . wfTimestamp( TS_MW ),
				'st_handled' => 0,
			]
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
		$db = $this->loadBalancer->getConnection( DB_REPLICA );

		$row = $db->selectRow(
			'sanctions',
			'*',
			[ 'st_topic' => $uuid->getBinary() ]
		);
		if ( !$row ) {
			return null;
		}
		return Sanction::newFromRow( $row );
	}
}
