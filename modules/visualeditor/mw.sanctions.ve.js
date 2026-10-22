mw.sanctions.ve = mw.sanctions.ve || {};

/**
 * Whether the sanction being discussed is for an insulting name, which takes no period.
 *
 * @param {jQuery} $tool
 * @return {boolean}
 */
mw.sanctions.ve.isForInsultingName = function ( $tool ) {
	const isForInsultingName = mw.config.get( 'wgSanctionsIsForInsultingName' );
	if ( isForInsultingName !== null ) {
		// A page discussed with DiscussionTools
		return isForInsultingName;
	}
	const title = $tool
		.closest( '.flow-topic' )
		.find( '.flow-topic-title' )
		.text();
	return new RegExp( mw.config.get( 'wgSanctionsInsultingNameTopicTitle' ) ).test( title );
};
