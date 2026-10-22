<?php

namespace MediaWiki\Extension\Sanctions\Tests\Integration;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\Sanctions\Sanction;
use MediaWiki\Extension\Sanctions\SanctionStore;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Sanctions\Hooks\DiscussionTools
 * @covers \MediaWiki\Extension\Sanctions\DiscussionToolsUtil
 * @group Database
 */
class DiscussionToolsTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->markTestSkippedIfExtensionNotLoaded( 'DiscussionTools' );
		$this->overrideConfigValues( [
			'SanctionsDiscussionBackend' => 'DiscussionTools',
			MainConfigNames::LanguageCode => 'en',
			MainConfigNames::RCFeeds => [],
		] );
	}

	/**
	 * @return User A user who has the right to vote.
	 */
	private function getVoter(): User {
		$user = $this->getMutableTestUser()->getUser();
		$this->getDb()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_registration' => $this->getDb()->timestamp( '20200101000000' ) ] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )
			->execute();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->editPage( 'Sanctions test edits', (string)$i . $user->getName(), '', NS_MAIN, $user );
		}
		return $this->getServiceContainer()->getUserFactory()->newFromId( $user->getId() );
	}

	private function reply( Sanction $sanction, User $user, string $text ) {
		$title = $sanction->getDiscussionPage();
		$content = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title )->getContent();
		$this->assertInstanceOf( WikitextContent::class, $content );
		$this->editPage( $title, $content->getText() . "\n:$text ~~~~", '', NS_MAIN, $user );
		DeferredUpdates::doUpdates();
	}

	private function getVotes( Sanction $sanction ): array {
		$store = $this->getServiceContainer()->getService( 'SanctionStore' );
		'@phan-var SanctionStore $store';
		return array_map( 'intval', $store->newFromId( $sanction->getId() )->getVotes() );
	}

	public function testVotesInComments() {
		$author = $this->getMutableTestUser()->getUser();
		$target = $this->getMutableTestUser()->getUser();
		$voter = $this->getVoter();

		$sanction = Sanction::write( $author, $target, false, 'Test sanction' );
		$this->assertInstanceOf( Sanction::class, $sanction );
		$this->assertNotNull( $sanction->getPageId() );
		$this->assertSame( 'Sanctions/' . $sanction->getId(), $sanction->getDiscussionPage()->getText() );
		$this->assertSame( [], $this->getVotes( $sanction ) );

		$this->reply( $sanction, $voter, '{{Support|3}} I agree.' );
		$this->assertSame( [ $voter->getId() => 3 ], $this->getVotes( $sanction ) );

		$this->reply( $sanction, $voter, 'A comment without a vote keeps the last vote.' );
		$this->assertSame( [ $voter->getId() => 3 ], $this->getVotes( $sanction ) );

		$this->reply( $sanction, $voter, 'I changed my mind. {{Oppose}}' );
		$this->assertSame( [ $voter->getId() => 0 ], $this->getVotes( $sanction ) );
	}

	public function testVotesOfOthersAreNotChanged() {
		$author = $this->getMutableTestUser()->getUser();
		$target = $this->getMutableTestUser()->getUser();
		$voter = $this->getVoter();
		$other = $this->getVoter();

		$sanction = Sanction::write( $author, $target, false, 'Test sanction' );
		$this->reply( $sanction, $voter, '{{Support}}' );
		$this->assertSame( [ $voter->getId() => 1 ], $this->getVotes( $sanction ) );

		// Another user rewrites the vote of the voter.
		$title = $sanction->getDiscussionPage();
		$content = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title )->getContent();
		$this->editPage(
			$title,
			str_replace( '{{Support}}', '{{Oppose}}', $content->getText() ),
			'',
			NS_MAIN,
			$other
		);
		DeferredUpdates::doUpdates();
		$this->assertSame( [ $voter->getId() => 1 ], $this->getVotes( $sanction ) );
	}

	public function testSelfSupportIsNotCounted() {
		$author = $this->getVoter();
		$target = $this->getMutableTestUser()->getUser();

		$sanction = Sanction::write( $author, $target, false, 'Test sanction' );
		$this->reply( $sanction, $author, '{{Support}}' );
		$this->assertSame( [], $this->getVotes( $sanction ) );

		// The bot replies why the vote is not counted.
		$content = $this->getServiceContainer()->getWikiPageFactory()
			->newFromTitle( $sanction->getDiscussionPage() )->getContent();
		$this->assertStringContainsString(
			wfMessage( 'sanctions-topic-auto-reply-unable-self-agree' )->inContentLanguage()->text(),
			$content->getText()
		);
	}
}
