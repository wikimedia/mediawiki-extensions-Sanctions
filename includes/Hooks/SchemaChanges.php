<?php

namespace MediaWiki\Extension\Sanctions\Hooks;

use SanctionsCreateTemplates;

class SchemaChanges implements \MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook {

	/**
	 * Create tables in the database
	 *
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__;

		if ( $updater->getDB()->getType() === 'mysql' ) {
			$updater->addExtensionUpdate(
				[ 'addTable', 'sanctions',
				"$dir/../../sql/sanctions.tables.sql", true ]
			);
			$updater->addExtensionField(
				'sanctions_vote', 'stv_sanction', "$dir/../../sql/patch-sanctions_vote-stv_sanction.sql"
			);
		}
		// @todo else

		require_once "$dir/../../maintenance/SanctionsCreateTemplates.php";
		$updater->addPostDatabaseUpdateMaintenance( SanctionsCreateTemplates::class );

		return true;
	}
}
