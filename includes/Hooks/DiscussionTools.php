<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Html\Html;

/**
 * Handles sanctions discussed on their own pages with DiscussionTools.
 */
class DiscussionTools implements
	\MediaWiki\Page\Hook\ArticleViewHeaderHook
{

	public function __construct(
		private readonly SanctionStore $sanctionStore,
	) {
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
