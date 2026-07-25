import { createApiClient } from 'wdio-mediawiki/Api.js';
import Page from 'wdio-mediawiki/Page.js';
import LoginPage from 'wdio-mediawiki/LoginPage.js';
import { getTestString } from 'wdio-mediawiki/Util.js';
import SanctionsPage from './pageobjects/sanctions.page.js';
import Config from './config.js';

class Sanction {
	/**
	 * @param {string} target
	 * @param {string} username
	 * @param {string} password
	 * @return {string} lower-cased uuid of the workflow for the sanction.
	 */
	async create(
		target = browser.options.capabilities[ 'mw:user' ],
		username = browser.options.capabilities[ 'mw:user' ],
		password = browser.options.capabilities[ 'mw:pwd' ]
	) {
		await LoginPage.login( username, password );

		const apiClient = await createApiClient( { username, password } );
		for ( let count = 0; count < Config.VERIFICATION_EDITS; count++ ) {
			await apiClient.edit( 'Sanctions-dummy-edit', getTestString() );
		}

		await SanctionsPage.open();
		await SanctionsPage.waitUntilUserIsNotNew();
		await SanctionsPage.submit( target );

		const result = $( '.sanction-execute-result a' );
		await result.waitForDisplayed();
		let uuid = await result.getText();
		if ( uuid.includes( ':' ) ) {
			uuid = uuid.split( ':' )[ 1 ];
		}
		return uuid.toLowerCase();
	}

	async open( uuid ) {
		await new Page().openTitle( 'Topic:' + uuid );
	}

	async createVoters( apiClient, size = 3 ) {
		const voters = [];
		for ( let count = 0; count < size; count++ ) {
			const username = getTestString( `Sanction-voter${ count }-` );
			const password = getTestString();
			await apiClient.createAccount( username, password );
			const voter = await createApiClient( { username, password } );
			voters.push( voter );
		}
		await browser.pause( Config.VERIFICATION_PERIOD * 1000 );
		return voters;
	}
}

export default new Sanction();
