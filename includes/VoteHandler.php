<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\User\User;

/**
 * Records a vote found in a reply, whether the reply was posted on Flow or with DiscussionTools.
 */
class VoteHandler {

	public function __construct(
		private readonly VoteStore $voteStore,
	) {
	}

	/**
	 * @param Sanction $sanction
	 * @param User $user
	 * @param int $period
	 * @param string $timestamp
	 * @param callable $replyAsBot Called with wikitext when the vote is not counted.
	 */
	public function handle( Sanction $sanction, User $user, int $period, $timestamp, callable $replyAsBot ) {
		if ( $sanction->getAuthor()->equals( $user ) && $period > 0 ) {
			$replyAsBot(
				wfMessage( 'sanctions-topic-auto-reply-no-count' )->inContentLanguage()->text() .
				PHP_EOL . '* ' .
				wfMessage( 'sanctions-topic-auto-reply-unable-self-agree' )->inContentLanguage()->text()
			);
			return;
		}
		if ( !Utils::hasVoteRight( $user, $reasons, true ) ) {
			$replyAsBot(
				wfMessage( 'sanctions-topic-auto-reply-no-count' )->inContentLanguage()->text() .
				PHP_EOL . '* ' . implode( PHP_EOL . '* ', $reasons ?? [] )
			);
			return;
		}

		$lastVote = $this->voteStore->getVoteBySanction( $sanction, $user );
		if ( $lastVote ) {
			$lastVote->updatePeriod( $period, $timestamp );
		} else {
			$vote = new Vote();
			$vote->setSanction( $sanction );
			$vote->setUser( $user );
			$vote->setPeriod( $period );
			$vote->insert( $timestamp );
		}
		$sanction->onVotesChanged();
	}
}
