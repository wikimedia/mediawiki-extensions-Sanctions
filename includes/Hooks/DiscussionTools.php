<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Sanctions\DiscussionToolsUtil;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\VoteHandler;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\Html\Html;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserFactory;

/**
 * Handles sanctions discussed on their own pages with DiscussionTools.
 */
class DiscussionTools implements
	\MediaWiki\Storage\Hook\PageSaveCompleteHook,
	\MediaWiki\Page\Hook\ArticleViewHeaderHook
{

	public function __construct(
		private readonly SanctionStore $sanctionStore,
		private readonly VoteStore $voteStore,
		private readonly UserFactory $userFactory,
	) {
	}

	/**
	 * Record the votes in the comments that the editor added.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( $flags & EDIT_NEW || $editResult->isNullEdit() ) {
			return;
		}
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'DiscussionTools' ) ) {
			return;
		}
		$pageId = $wikiPage->getId();
		if ( !$this->sanctionStore->newFromPageId( $pageId ) ) {
			return;
		}
		// The replies and the results of the bot have no votes.
		if ( $user->getName() === wfMessage( 'sanctions-bot-name' )->inContentLanguage()->text() ) {
			return;
		}
		$editor = $this->userFactory->newFromUserIdentity( $user );

		// Parsing the revisions is too slow to keep the editor waiting.
		DeferredUpdates::addCallableUpdate( function () use ( $pageId, $revisionRecord, $editor ) {
			$this->handleEdit( $pageId, $revisionRecord, $editor );
		} );
	}

	/**
	 * @param int $pageId
	 * @param RevisionRecord $revision
	 * @param \MediaWiki\User\User $editor
	 */
	private function handleEdit( int $pageId, RevisionRecord $revision, $editor ) {
		$sanction = $this->sanctionStore->newFromPageId( $pageId );
		if ( !$sanction || $sanction->isHandled() ) {
			return;
		}

		// The last vote of the editor counts when they add several comments at once.
		$votedComment = null;
		$period = null;
		foreach ( DiscussionToolsUtil::findAddedComments( $revision, $editor ) as $comment ) {
			$commentPeriod = DiscussionToolsUtil::extractPeriod( $comment );
			if ( $commentPeriod !== null ) {
				[ $votedComment, $period ] = [ $comment, $commentPeriod ];
			}
		}
		if ( $votedComment === null || $period === null ) {
			return;
		}

		$title = Title::newFromPageIdentity( $revision->getPage() );
		( new VoteHandler( $this->voteStore ) )->handle(
			$sanction,
			$editor,
			$period,
			$revision->getTimestamp(),
			static function ( string $content ) use ( $title, $votedComment ) {
				DiscussionToolsUtil::replyAsBot( $title, $votedComment, $content );
			}
		);
	}

	/**
	 * Show the status of the sanction above its discussion page.
	 *
	 * @inheritDoc
	 */
	public function onArticleViewHeader( $article, &$outputDone, &$pcache ) {
		$pageId = $article->getPage()->getId();
		if ( !$pageId ) {
			return;
		}
		$sanction = $this->sanctionStore->newFromPageId( $pageId );
		if ( !$sanction ) {
			return;
		}
		if ( $sanction->isExpired() ) {
			$sanction->execute();
		}

		$out = $article->getContext()->getOutput();
		$out->addJsConfigVars( [
			'wgSanctionsId' => $sanction->getId(),
			'wgSanctionsIsForInsultingName' => $sanction->isForInsultingName(),
		] );
		// The result is written on the page when the sanction is handled.
		if ( $sanction->isHandled() ) {
			return;
		}
		$out->addHTML( Html::noticeBox(
			$out->parseAsInterface( $sanction->getStatusWikitext() ),
			'ext-sanctions-status'
		) );
	}
}
