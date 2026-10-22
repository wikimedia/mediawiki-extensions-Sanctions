( function ( mw, OO, ve ) {
	'use strict';

	/**
	 * Tool for agree
	 *
	 * @class
	 * @extends ve.ui.InspectorTool
	 *
	 * @constructor
	 * @param {OO.ui.ToolGroup} toolGroup
	 * @param {Object} [config] Configuration options
	 */

	mw.sanctions.ve.ui.AgreeTool = function SanctionsVeUiAgreeTool() {
		const self = this;
		$( () => {
			if ( !mw.sanctions.ve.isForInsultingName( self.$element ) ) {
				self.destroy();
			}
		} );
		// Parent constructor
		mw.sanctions.ve.ui.AgreeTool.super.apply( this, arguments );
	};

	OO.inheritClass( mw.sanctions.ve.ui.AgreeTool, ve.ui.Tool );

	// Static
	mw.sanctions.ve.ui.AgreeTool.static.commandName = 'sanctions-agree';
	mw.sanctions.ve.ui.AgreeTool.static.name = 'sanctions-agree';
	// Only the toolbars of sanctions show the tool, not any toolbar that includes all tools.
	mw.sanctions.ve.ui.AgreeTool.static.autoAddToCatchall = false;
	mw.sanctions.ve.ui.AgreeTool.static.autoAddToGroup = false;
	mw.sanctions.ve.ui.AgreeTool.static.icon = 'support';
	mw.sanctions.ve.ui.AgreeTool.static.group = 'textStyle';
	mw.sanctions.ve.ui.AgreeTool.static.title = OO.ui.deferMsg(
		'sanctions-ve-vote-agree-tool-title'
	);

	ve.ui.toolFactory.register( mw.sanctions.ve.ui.AgreeTool );
}( mediaWiki, OO, ve ) );
