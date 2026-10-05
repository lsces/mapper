<?php

use Bitweaver\Mapper\BitMapper;
use Bitweaver\Mapper\Map;

//defaults
$mapper = new BitMapper();

if( !empty( $_REQUEST['save'] ) ) {
	if( $gBitSystem->isPackageActive( 'mapper' ) ) {
		$mapper->storeSettings( $_REQUEST );
	}
}

// assign to smarty
$gBitSmarty->assign('mapperSettings', $mapper->mSettings );

// "Refresh" in the Mapper Archive tab: replace one loaded map's stored mapfile from its folder.
$refreshResult = null;
if( !empty( $_REQUEST['refresh_map'] ) && ctype_digit( (string)$_REQUEST['refresh_map'] ) && $gBitSystem->isPackageActive( 'mapper' ) ) {
	$refreshMap = new Map( (int)$_REQUEST['refresh_map'] );
	if( $refreshMap->load() ) {
		$refreshOk = $refreshMap->refreshFromFolder( $mapper->mSettings['maps_dir'] );
		$refreshResult = [ 'title' => $refreshMap->getTitle(), 'ok' => $refreshOk, 'errors' => $refreshMap->mErrors ];
	}
}
$gBitSmarty->assign( 'refreshResult', $refreshResult );

// The "Mapper Archive" tab: every map this site knows about - the maps folder's .map files merged
// with the Map records already loaded (read after any save above, so a changed folder shows at once).
$gBitSmarty->assign( 'archiveRows', $gBitSystem->isPackageActive( 'mapper' ) ? Map::archiveOverview( $mapper->mSettings['maps_dir'] ) : [] );
?>
