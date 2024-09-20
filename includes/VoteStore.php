<?php

namespace MediaWiki\Extension\Sanctions;

use User;
use Wikimedia\Rdbms\IConnectionProvider;

class VoteStore {

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @param Sanction $sanction
	 * @param User $user
	 * @return Vote|null
	 */
	public function getVoteBySanction( Sanction $sanction, User $user ) {
		$db = $this->dbProvider->getReplicaDatabase();
		$row = $db->selectRow(
			'sanctions_vote',
			[
				'stv_user',
				'stv_topic',
				'stv_period',
			],
			[
				'stv_topic' => $sanction->getWorkflowId()->getBinary(),
				'stv_user' => $user->getId(),
			],
			__METHOD__
		);
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

		$dbw->delete(
			'sanctions_vote',
			[ 'stv_topic' => $sanction->getWorkflowId()->getBinary() ],
			__METHOD__
		);

		if ( $dbw->affectedRows() === 0 ) {
			return false;
		}
		return true;
	}

}
