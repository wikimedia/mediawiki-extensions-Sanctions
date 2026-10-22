( function () {
	'use strict';

	// Only the pages of sanctions get the tools.
	if ( !mw.config.get( 'wgSanctionsId' ) ) {
		return;
	}

	/**
	 * Add the vote tools to the reply tool of DiscussionTools.
	 *
	 * @param {Function} targetClass The target class of DiscussionTools
	 */
	function addTools( targetClass ) {
		targetClass.static.toolbarGroups = [
			{
				name: 'sanctions',
				include: [ 'sanctions-agreewithdays', 'sanctions-agree', 'sanctions-disagree' ]
			}
		].concat( targetClass.static.toolbarGroups );
	}

	// The source mode inserts the templates as wikitext.
	const agree = mw.config.get( 'wgSanctionsAgreeTemplate' );
	const disagree = mw.config.get( 'wgSanctionsDisagreeTemplate' );
	ve.ui.wikitextCommandRegistry.register(
		new ve.ui.Command( 'sanctions-agree', 'content', 'insert', {
			args: [ '{{' + agree + '}}', false, true ],
			supportedSelections: [ 'linear' ]
		} )
	);
	ve.ui.wikitextCommandRegistry.register(
		new ve.ui.Command( 'sanctions-agreewithdays', 'mwWikitext', 'toggleWrapSelection', {
			args: [ '{{' + agree + '|', '}}', () => '1' ],
			supportedSelections: [ 'linear' ]
		} )
	);
	ve.ui.wikitextCommandRegistry.register(
		new ve.ui.Command( 'sanctions-disagree', 'content', 'insert', {
			args: [ '{{' + disagree + '}}', false, true ],
			supportedSelections: [ 'linear' ]
		} )
	);

	const target = ve.init.mw.targetFactory.lookup( 'discussionTools' );
	if ( target ) {
		addTools( target );
	} else {
		ve.init.mw.targetFactory.on( 'register', ( name, data ) => {
			if ( name === 'discussionTools' ) {
				addTools( data );
			}
		} );
	}
}() );
