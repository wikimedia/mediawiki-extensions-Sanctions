<?php

namespace MediaWiki\Extension\Sanctions;

use BadMethodCallException;
use Flow\Model\PostRevision;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use stdClass;
use Wikimedia\Rdbms\IDatabase;

class Vote {

	/** @var Sanction|null */
	private $sanction;

	/** @var User|null */
	private $user;

	/** @var int|null */
	private $period;

	/**
	 * @param stdClass $row A row from the sanctions_vote table
	 * @return Vote
	 */
	public static function newFromRow( stdClass $row ): self {
		$vote = new Vote();
		$vote->loadFromRow( $row );
		return $vote;
	}

	/**
	 * Initialize this object from a row from the sanctions_vote table.
	 *
	 * @param stdClass $row Row from the user table to load.
	 */
	protected function loadFromRow( stdClass $row ): void {
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
	public function insert( ?string $timestamp = null ): void {
		$dbw = MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'sanctions_vote' )
			->row( [
				'stv_sanction' => $this->getSanction()->getId(),
				'stv_topic' => $this->getSanction()->getTopic(),
				'stv_user' => $this->getUser()->getId(),
				'stv_period' => $this->getPeriod(),
				'stv_last_update_timestamp' => $timestamp ?? $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
		$this->updateLastTouched( $timestamp, $dbw );
	}

	public function updateByPostRevision( PostRevision $post, string $timestamp ): void {
		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable FIXME, fails with null!
		$this->updatePeriod( self::extractPeriodFromReply( $post->getContentRaw() ), $timestamp );
	}

	public function updatePeriod( int $period, string $timestamp ): void {
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
	 */
	public function updateLastTouched( ?string $timestamp, IDatabase $dbw ): void {
		$dbw->newUpdateQueryBuilder()
			->update( 'sanctions' )
			->set( [ 'st_last_update_timestamp' => $timestamp ] )
			->where( [ 'st_id' => $this->sanction->getId() ] )
			->caller( __METHOD__ )
			->execute();
	}

	public static function extractPeriodFromReply( string $content ): ?int {
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

	public function getPeriod(): int {
		return $this->period;
	}

	public function setPeriod( int $period ): void {
		$this->period = $period;
	}

	public function setSanction( Sanction $sanction ): void {
		$this->sanction = $sanction;
	}

	/** @return Sanction */
	public function getSanction(): Sanction {
		return $this->sanction;
	}

	public function setUser( User $user ): void {
		$this->user = $user;
	}

	public function getUser(): User {
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
