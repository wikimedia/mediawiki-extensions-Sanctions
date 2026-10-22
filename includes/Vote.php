<?php

namespace MediaWiki\Extension\Sanctions;

use BadMethodCallException;
use Flow\Model\PostRevision;
use InvalidArgumentException;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

class Vote {

	/** @var Sanction */
	private $sanction;

	/** @var User */
	private $user;

	/** @var int */
	private $period;

	/**
	 * @param stdClass $row A row from the sanctions_vote table
	 * @return Vote
	 */
	public static function newFromRow( $row ) {
		$vote = new Vote();
		$vote->loadFromRow( $row );
		return $vote;
	}

	/**
	 * Initialize this object from a row from the sanctions_vote table.
	 *
	 * @param stdClass $row Row from the user table to load.
	 */
	protected function loadFromRow( $row ) {
		if ( !is_object( $row ) ) {
			throw new InvalidArgumentException( '$row must be an object' );
		}

		if ( isset( $row->stv_user ) ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			$this->user = $userFactory->newFromId( (int)$row->stv_user );
		}
		if ( isset( $row->stv_sanction ) ) {
			/** @var SanctionStore $store */
			$store = MediaWikiServices::getInstance()->getService( 'SanctionStore' );
			$this->sanction = $store->newFromId( (int)$row->stv_sanction );
		}
		if ( isset( $row->stv_period ) ) {
			$this->period = (int)$row->stv_period;
		}
	}

	/**
	 * @param string|null $timestamp
	 */
	public function insert( $timestamp = null ) {
		$dbw = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'sanctions_vote' )
			->row( [
				'stv_sanction' => $this->getSanction()->getId(),
				'stv_topic' => $this->getSanction()->getWorkflowId()?->getBinary(),
				'stv_user' => $this->getUser()->getId(),
				'stv_period' => $this->getPeriod(),
				'stv_last_update_timestamp' => $timestamp ?? $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		$this->updateLastTouched( $timestamp, $dbw );
	}

	/**
	 * @param PostRevision $post
	 * @param string $timestamp
	 */
	public function updateByPostRevision( PostRevision $post, $timestamp ) {
		$this->updatePeriod( self::extractPeriodFromReply( $post->getContentRaw() ), $timestamp );
	}

	/**
	 * @param int $period
	 * @param string $timestamp
	 */
	public function updatePeriod( int $period, $timestamp ) {
		$dbw = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();
		$this->period = $period;

		$dbw->newUpdateQueryBuilder()
			->update( 'sanctions_vote' )
			->set( [
				'stv_period' => $period,
				'stv_last_update_timestamp' => $timestamp,
			] )
			->where( [
				'stv_sanction' => $this->getSanction()->getId(),
				'stv_user' => $this->user->getId(),
			] )
			->caller( __METHOD__ )
			->execute();
		$this->updateLastTouched( $timestamp, $dbw );
	}

	/**
	 * Update the time of the sanction.
	 * @param string $timestamp
	 * @param IDatabase $dbw
	 */
	public function updateLastTouched( $timestamp, IDatabase $dbw ) {
		$dbw->newUpdateQueryBuilder()
			->update( 'sanctions' )
			->set( [ 'st_last_update_timestamp' => $timestamp ] )
			->where( [ 'st_id' => $this->sanction->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @param string $content
	 * @return int|null
	 */
	public static function extractPeriodFromReply( $content ) {
		if ( MediaWikiServices::getInstance()->getMainConfig()->get( 'FlowContentFormat' ) === 'html' ) {
			$agreementWithDayRegex = '/<span class="sanction-vote-agree-period">(\d+)<\/span>/';
			$agreementRegex = '"sanction-vote-agree"';
			$disagreementRegex = '"sanction-vote-disagree"';
		} else {
			$agreementTemplateTitle = wfMessage( 'sanctions-agree-template-title' )->inContentLanguage()->text();
			$agreementTemplateTitle = preg_quote( $agreementTemplateTitle );
			$agreementWithDayRegex = '/\{\{' . $agreementTemplateTitle . '\|(\d+)\}\}/';
			$agreementRegex = '{{' . $agreementTemplateTitle . '}}';
			$disagreementRegex = wfMessage( 'sanctions-disagree-template-title' )->inContentLanguage()->text();
			$disagreementRegex = '{{' . $disagreementRegex . '}}';
		}

		if ( strpos( $content, $disagreementRegex ) !== false ) {
			return 0;
		}
		if ( preg_match( $agreementWithDayRegex, $content, $matches ) ) {
			return (int)$matches[1];
		}
		// If the affirmative opinion is without explicit length, it would be considered as a day.
		if ( strpos( $content, $agreementRegex ) !== false ) {
			return 1;
		}
		return null;
	}

	/**
	 * @return int
	 */
	public function getPeriod() {
		return $this->period;
	}

	/**
	 * @param int $period
	 */
	public function setPeriod( int $period ) {
		$this->period = $period;
	}

	/**
	 * @param Sanction $sanction
	 */
	public function setSanction( Sanction $sanction ) {
		$this->sanction = $sanction;
	}

	/** @return Sanction */
	public function getSanction(): Sanction {
		return $this->sanction;
	}

	/**
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * @return User
	 */
	public function getUser() {
		return $this->user;
	}

	/**
	 * @param PostRevision $post
	 * @return never
	 *
	 * @throws BadMethodCallException
	 */
	public function loadFromPostRevision( PostRevision $post ) {
		throw new BadMethodCallException( 'Not implemented' );
	}
}
