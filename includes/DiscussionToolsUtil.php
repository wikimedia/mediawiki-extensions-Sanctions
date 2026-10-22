<?php

namespace MediaWiki\Extension\Sanctions;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

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
}
