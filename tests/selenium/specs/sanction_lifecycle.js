import { createApiClient } from 'wdio-mediawiki/Api.js';
import Config from '../config.js';
import FlowApi from '../flow_api.js';
import FlowTopic from '../pageobjects/flow_topic.page.js';
import Sanction from '../sanction.js';
import LoginPage from 'wdio-mediawiki/LoginPage.js';
import { getTestString } from 'wdio-mediawiki/Util.js';

async function queryBlocks() {
	const apiClient = await createApiClient();
	const result = await apiClient.request( {
		action: 'query',
		list: 'blocks',
		bkprop: 'user'
	} );
	if ( !result.query || !result.query.blocks ) {
		return [];
	}
	return result.query.blocks.map( ( e ) => e.user );
}

describe( 'Sanction', () => {
	/* eslint-disable mocha/no-setup-in-describe */
	const targetName = getTestString( 'Sanction-target-' );
	const targetPassword = getTestString();
	/* eslint-enable mocha/no-setup-in-describe */
	let voters;
	let apiClient;

	before( async () => {
		await new Config().setup();
		apiClient = await createApiClient();
		await apiClient.createAccount( targetName, targetPassword );
		voters = await Sanction.createVoters( apiClient );
	} );

	async function createPassedSanction( support = 3, logout = false ) {
		// Create a sanction
		const uuid = await Sanction.create( targetName );
		const created = Date.now();

		for ( let count = 0; count < support; count++ ) {
			await FlowApi.reply( '{{Support}}', uuid, voters[ count ] );
		}

		// Wait for topic summary is updated by the bot.
		await browser.pause( 500 );

		await Sanction.open( uuid );

		await expect( FlowTopic.topicSummary ).toHaveText(
			expect.stringContaining( 'Status: Passed to block 1 day(s) (prediction)' )
		);

		if ( logout ) {
			await browser.deleteAllCookies();
		}

		const spentTime = Date.now() - created;
		// Wait until expired
		await browser.pause( Config.VOTING_PERIOD * 1000 - spentTime );
		return uuid;
	}

	it( 'should be canceled by the author', async () => {
		const uuid = await Sanction.create( targetName );
		await Sanction.open( uuid );
		await FlowApi.reply( '{{Oppose}}', uuid, apiClient );

		await browser.pause( 500 );
		await browser.refresh();
		await expect( FlowTopic.topicSummary ).toHaveText(
			"Status: Rejected (Canceled by the sanction's author.)"
		);
	} );

	it( 'should be rejected if three users object', async () => {
		const uuid = await Sanction.create( targetName );

		for ( let count = 0; count < 3; count++ ) {
			await FlowApi.reply( '{{Oppose}}', uuid, voters[ count ] );
		}

		await browser.pause( 500 );
		await Sanction.open( uuid );
		await expect( FlowTopic.topicSummary ).toHaveText(
			'Status: Immediately rejected (Rejected by first three participants.)'
		);
	} );

	it( 'should be passed if three users support before expired', async () => {
		await createPassedSanction();
		await browser.refresh();

		const blocks = await queryBlocks();
		expect( blocks ).toContain( targetName );
		await apiClient.unblockUser( targetName );
	} );

	it( 'should block the target user of the passed sanction when logged in', async () => {
		await createPassedSanction();
		await LoginPage.login( targetName, targetPassword );

		const blocks = await queryBlocks();
		expect( blocks ).toContain( targetName );
		await apiClient.unblockUser( targetName );
	} );

	// This tests https://github.com/femiwiki/Sanctions/issues/223
	it( 'should not touch the summary of a expired handled sanction', async () => {
		// Create a sanction
		const uuid = await createPassedSanction( 1, true );

		// Log in as the target user
		await LoginPage.login( targetName, targetPassword );

		await Sanction.open( uuid );
		await expect( FlowTopic.topicSummary ).toHaveText(
			expect.stringContaining( 'Status: Passed to block 1 day(s)' )
		);

		const manualSum = 'Manually touched summary.';
		await FlowApi.editTopicSummary( manualSum, uuid, apiClient );
		await FlowApi.reply( 'An additional comment.', uuid, apiClient );

		await browser.refresh();
		await expect( FlowTopic.topicSummary ).toHaveText(
			expect.stringContaining( manualSum )
		);
		await apiClient.unblockUser( targetName );
	} );
} );
