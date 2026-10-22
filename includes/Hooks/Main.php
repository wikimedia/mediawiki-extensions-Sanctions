<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use Flow\Data\Listener\RecentChangesListener;
use Flow\Exception\InvalidInputException;
use Flow\Model\UUID;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Sanctions\FlowUtil;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\Extension\Sanctions\Utils;
use MediaWiki\Extension\Sanctions\Vote;
use MediaWiki\Extension\Sanctions\VoteHandler;
use MediaWiki\Extension\Sanctions\VoteStore;
use MediaWiki\Output\OutputPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;

class Main implements
	\Flow\Hooks\FlowAddModulesHook,
	\MediaWiki\Hook\RecentChange_saveHook,
	\MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook
{

	public function __construct(
		private readonly SanctionStore $sanctionStore,
		private readonly VoteStore $voteStore,
		private readonly UserFactory $userFactory,
	) {
	}

	/**
	 * @param OutputPage $out
	 * @return bool
	 */
	public function onFlowAddModules( OutputPage $out ) {
		$title = $out->getTitle();
		if ( !$title ) {
			return true;
		}

		$specialSanctionTitle = SpecialPage::getTitleFor( 'Sanctions' );
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )
			->inContentLanguage()->text();

		// The Flow board for sanctions.
		if ( $title->equals( Title::newFromText( $discussionPageName ) ) ) {
			// Flow does not support redirection, so implement it.
			// See https://phabricator.wikimedia.org/T102300
			$redirect = $out->getRequest()->getRawVal( 'redirect' );
			if ( !$redirect || $redirect !== 'no' ) {
				$out->redirect( $specialSanctionTitle->getLocalURL() );
			}

			$out->addModules( 'ext.sanctions.flow-board' );

			return true;
		}

		// Each Flow topic.
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException ) {
			return true;
		}

		// Do nothing if the UUID is invalid.
		if ( !$uuid ) {
			return true;
		}

		// Do nothing if the topic is not about sanction.
		$sanction = $this->sanctionStore->newFromWorkflowId( $uuid );
		if ( !$sanction ) {
			return true;
		}
		if ( $sanction->isExpired() ) {
			$sanction->execute();
		}

		$out->addModules( 'ext.sanctions.flow-topic' );

		return true;
	}

	/**
	 * export static key and id to JavaScript
	 * @inheritDoc
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars['wgSanctionsAgreeTemplate'] = wfMessage( 'sanctions-agree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsDisagreeTemplate'] = wfMessage( 'sanctions-disagree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsInsultingNameTopicTitle'] = wfMessage( 'sanctions-type-insulting-name' )
			->inContentLanguage()->text();
		$vars['wgSanctionsMaxBlockPeriod'] = (int)wfMessage( 'sanctions-max-block-period' )
			->inContentLanguage()->text();
	}

	/**
	 * We use this hook as a alternative of PageSaveComplete for Flow. (T283459)
	 * @inheritDoc
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onRecentChange_save( $recentChange ) {
		if ( $recentChange->getAttribute( 'rc_source' ) != RecentChangesListener::SRC_FLOW ) {
			return;
		}

		$params = $recentChange->parseParams();
		if ( !isset( $params['flow-workflow-change'] ) ) {
			return;
		}
		$params = $params['flow-workflow-change'];
		$user = $this->userFactory->newFromUserIdentity( $recentChange->getPerformerIdentity() );
		switch ( $params['action' ] ) {
			case 'reply':
				$this->handleReply( $params, $user );
				break;
		}
	}

	/**
	 * @param array $change
	 * @param User $user
	 */
	public function handleReply( array $change, User $user ) {
		$sanction = $this->sanctionStore->newFromWorkflowId( UUID::create( $change['workflow'] ) );
		if ( !$sanction || $sanction->isHandled() ) {
			return;
		}

		$post = FlowUtil::findPostRevisionFromUUID( UUID::create( $change['revision'] ) );
		if ( !$post ) {
			return;
		}

		$period = Vote::extractPeriodFromReply( $post->getContentRaw() );
		if ( $period === null ) {
			return;
		}

		( new VoteHandler( $this->voteStore ) )->handle(
			$sanction,
			$user,
			$period,
			$post->getPostId()->getTimestamp(),
			static function ( string $content ) use ( $post ) {
				// Reply as the bot.
				FlowUtil::replyTo( $post, Utils::getBot(), $content );
			}
		);
	}
}
