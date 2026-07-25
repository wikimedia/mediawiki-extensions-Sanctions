import { createApiClient } from 'wdio-mediawiki/Api.js';
import Config from '../config.js';
import FlowApi from '../flow_api.js';
import Sanction from '../sanction.js';
import SanctionsPage from '../pageobjects/sanctions.page.js';
import LoginPage from 'wdio-mediawiki/LoginPage.js';
import { getTestString } from 'wdio-mediawiki/Util.js';

describe( 'Special:Sanctions', () => {
	let voters;
	let apiClient;

	before( async () => {
		await new Config().setup();
		apiClient = await createApiClient();
		voters = await Sanction.createVoters( apiClient );
	} );

	describe( 'should show', () => {
		it( 'an anonymous user not-logged-in warning', async () => {
			// Logout
			await browser.deleteAllCookies();
			await SanctionsPage.open();

			await expect( SanctionsPage.reasonsDisabledParticipation ).toExist();
			await expect( SanctionsPage.reasonsDisabledParticipation ).toHaveText(
				'(sanctions-reason-not-logged-in)'
			);
		} );

		it( 'a newly registered user that you are too new', async () => {
			const username = getTestString( 'Sanction-newcomer-' );
			const password = getTestString();
			await apiClient.createAccount( username, password );

			await LoginPage.login( username, password );
			await SanctionsPage.open();

			await expect( SanctionsPage.reasonsDisabledParticipation ).toHaveText(
				expect.stringMatching(
					/\(sanctions-reason-unsatisfying-verification-period: \d+\.?\d*, .+\)/
				)
			);
		} );
	} );

	it( 'should hide and show the form as the conditions change', async () => {
		const username = getTestString( 'Sanction-user-' );
		const password = getTestString();
		await apiClient.createAccount( username, password );
		await LoginPage.login( username, password );

		await SanctionsPage.open();
		await expect( SanctionsPage.reasonsDisabledParticipation ).toHaveText(
			expect.stringContaining( 'sanctions-reason-unsatisfying-verification-period' )
		);

		await SanctionsPage.waitUntilUserIsNotNew();

		await browser.refresh();
		const warning = await SanctionsPage.reasonsDisabledParticipation.getText();
		expect( warning ).toBe( '' );
	} );

	it( 'should add voted tag on a sanction', async () => {
		// Creates a sanction
		const username = getTestString( 'Sanction-another-' );
		const password = getTestString();
		await apiClient.createAccount( username, password );
		const uuid = await Sanction.create( username, username, password );

		await LoginPage.loginAdmin();
		await SanctionsPage.open();
		await expect( $( `#sanction-${ uuid }` ) ).toExist();

		// Votes
		await FlowApi.reply( '{{Oppose}}', uuid, apiClient );
		await browser.pause( 500 );
		await browser.refresh();

		await expect( $( `#sanction-${ uuid }.voted` ) ).toExist();

		// Closes the sanction
		for ( let count = 0; count < 2; count++ ) {
			await FlowApi.reply( '{{Oppose}}', uuid, voters[ count ] );
		}
	} );
} );
