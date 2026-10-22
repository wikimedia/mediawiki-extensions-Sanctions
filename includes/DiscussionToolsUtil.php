<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiQueryTokens;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Context\DerivativeContext;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\DiscussionTools\ContentThreadItemSet;
use MediaWiki\Extension\DiscussionTools\Hooks\HookUtils;
use MediaWiki\Extension\DiscussionTools\ThreadItem\ContentCommentItem;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Request\DerivativeRequest;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Helpers for sanctions discussed on their own pages with DiscussionTools.
 */
class DiscussionToolsUtil {

	/**
	 * Create the discussion page of a sanction. It has one topic, and the description of the
	 * sanction is the first comment of the author.
	 *
	 * @param Title $title
	 * @param User $author
	 * @param string $topic wikitext.
	 * @param string $content wikitext.
	 * @return PageIdentity|null
	 */
	public static function createPage( Title $title, User $author, string $topic, string $content ) {
		$wikitext = "== $topic ==\n" . rtrim( $content ) . " ~~~~\n";

		$updater = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( $title )
			->newPageUpdater( $author )
			->setContent( SlotRecord::MAIN, new WikitextContent( $wikitext ) );
		$revision = $updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				wfMessage( 'sanctions-page-creation-summary' )->inContentLanguage()->text()
			),
			EDIT_NEW
		);
		if ( !$revision || !$updater->wasSuccessful() ) {
			Utils::getLogger()->warning( 'Failed to create {title}: {status}', [
				'title' => $title->getPrefixedText(),
				'status' => (string)$updater->getStatus(),
			] );
			return null;
		}
		return $revision->getPage();
	}

	/**
	 * Append wikitext to the end of the discussion page as the bot.
	 *
	 * @param Title $title
	 * @param string $wikitext
	 * @return bool
	 */
	public static function appendAsBot( Title $title, string $wikitext ) {
		$services = MediaWikiServices::getInstance();
		$page = $services->getWikiPageFactory()->newFromTitle( $title );
		$content = $page->getContent( RevisionRecord::RAW );
		if ( !$content instanceof WikitextContent ) {
			return false;
		}

		$updater = $page->newPageUpdater( Utils::getBot() )
			->setContent(
				SlotRecord::MAIN,
				new WikitextContent( rtrim( $content->getText() ) . "\n\n" . $wikitext . "\n" )
			);
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				wfMessage( 'sanctions-page-result-summary' )->inContentLanguage()->text()
			),
			EDIT_UPDATE | EDIT_FORCE_BOT
		);
		return $updater->wasSuccessful();
	}

	/**
	 * Find the comments that the user added in the revision, oldest first.
	 *
	 * @param RevisionRecord $revision
	 * @param User $user
	 * @return ContentCommentItem[]
	 */
	public static function findAddedComments( RevisionRecord $revision, User $user ): array {
		$previous = MediaWikiServices::getInstance()->getRevisionStore()
			->getPreviousRevision( $revision, IDBAccessObject::READ_LATEST );
		if ( !$previous ) {
			return [];
		}

		$oldComments = self::groupCommentsByName( self::parse( $previous ) );
		$newComments = self::groupCommentsByName( self::parse( $revision ) );

		$added = [];
		foreach ( $newComments as $name => $items ) {
			$oldItems = $oldComments[$name] ?? [];
			$addedCount = count( $items ) - count( $oldItems );
			if ( $addedCount <= 0 ) {
				continue;
			}
			// Prefer the IDs that did not occur in the old revision, like DiscussionTools does
			// when it notifies new comments.
			$candidates = array_merge(
				array_diff_key( $items, $oldItems ),
				array_intersect_key( $items, $oldItems )
			);
			$added = array_merge( $added, array_slice( $candidates, 0, $addedCount ) );
		}

		$added = array_filter(
			$added,
			static fn ( ContentCommentItem $item ) => $item->getAuthor() === $user->getName()
		);
		usort(
			$added,
			static fn ( ContentCommentItem $a, ContentCommentItem $b ) =>
				$a->getTimestamp() <=> $b->getTimestamp()
		);
		return $added;
	}

	/**
	 * Find a vote in the templates that the comment transcludes.
	 *
	 * @param ContentCommentItem $comment
	 * @return int|null
	 */
	public static function extractPeriod( ContentCommentItem $comment ) {
		$agreeTemplate = Title::newFromText(
			wfMessage( 'sanctions-agree-template-title' )->inContentLanguage()->text(), NS_TEMPLATE );
		$disagreeTemplate = Title::newFromText(
			wfMessage( 'sanctions-disagree-template-title' )->inContentLanguage()->text(), NS_TEMPLATE );

		// The first node of a transclusion holds what was transcluded, and the body of the comment
		// may only include the other nodes of the transclusion.
		preg_match_all( '/\babout="(#mwt\d+)"/', $comment->getBodyHTML( true ), $matches );
		$periods = [];
		foreach ( array_unique( $matches[1] ) as $about ) {
			$node = DOMCompat::querySelector( $comment->getRootNode(), "[about=\"$about\"][data-mw]" );
			$dataMw = $node ? json_decode( $node->getAttribute( 'data-mw' ), true ) : null;
			foreach ( $dataMw['parts'] ?? [] as $part ) {
				$template = Title::newFromText( $part['template']['target']['wt'] ?? '', NS_TEMPLATE );
				if ( !$template ) {
					continue;
				}
				if ( $template->equals( $disagreeTemplate ) ) {
					$periods[] = 0;
				} elseif ( $template->equals( $agreeTemplate ) ) {
					$days = trim( $part['template']['params']['1']['wt'] ?? '' );
					if ( $days === '' ) {
						// If the affirmative opinion is without explicit length, it would be considered as a day.
						$periods[] = 1;
					} elseif ( ctype_digit( $days ) ) {
						$periods[] = (int)$days;
					}
				}
			}
		}
		if ( !$periods ) {
			return null;
		}
		// Opposition wins over support in one comment, like in the comments on Flow.
		return in_array( 0, $periods, true ) ? 0 : $periods[0];
	}

	/**
	 * Reply to the comment as the bot.
	 *
	 * @param Title $title
	 * @param ContentCommentItem $comment
	 * @param string $wikitext
	 * @return bool
	 */
	public static function replyAsBot( Title $title, ContentCommentItem $comment, string $wikitext ) {
		$bot = Utils::getBot();
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setUser( $bot );
		$session = $context->getRequest()->getSession();
		$token = ApiQueryTokens::getToken(
			$bot,
			$session,
			ApiQueryTokens::getTokenTypeSalts()['csrf']
		)->toString();

		$context->setRequest( new DerivativeRequest( $context->getRequest(), [
			'action' => 'discussiontoolsedit',
			'paction' => 'addcomment',
			'page' => $title->getPrefixedText(),
			'commentid' => $comment->getId(),
			'wikitext' => $wikitext,
			'autosubscribe' => 'no',
			'token' => $token,
			'formatversion' => 2,
		], true ) );

		try {
			$api = new ApiMain( $context, true );
			$api->execute();
		} catch ( \Exception $e ) {
			Utils::getLogger()->warning( 'Failed to reply to {comment}: {message}', [
				'comment' => $comment->getId(),
				'message' => $e->getMessage(),
			] );
			return false;
		}
		return true;
	}

	/**
	 * @param RevisionRecord $revision
	 * @return ContentThreadItemSet
	 */
	private static function parse( RevisionRecord $revision ): ContentThreadItemSet {
		return HookUtils::parseRevisionParsoidHtml( $revision, __METHOD__ )->getValueOrThrow();
	}

	/**
	 * @param ContentThreadItemSet $itemSet
	 * @return ContentCommentItem[][] Comments keyed by their names, then by their IDs.
	 */
	private static function groupCommentsByName( ContentThreadItemSet $itemSet ): array {
		$comments = [];
		foreach ( $itemSet->getCommentItems() as $item ) {
			$comments[$item->getName()][$item->getId()] = $item;
		}
		return $comments;
	}
}
