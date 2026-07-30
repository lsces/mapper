<?php
/**
 * $Header$
 *
 * Copyright ( c ) 2004 bitweaver.org
 * All Rights Reserved. See below for details and a complete list of authors.
 * Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See http://www.gnu.org/copyleft/lesser.html for details
 *
 * @package mapper
 * @subpackage functions
 */

/**
 * required setup
 */
require_once( '../kernel/includes/setup_inc.php' );

// require_once( LIBERTY_PKG_PATH.'lookup_content_inc.php');

$gBitSystem->verifyPackage( 'mapper' );
//$gBitSystem->verifyFeature( 'feature_map_display' );

$gBitSystem->setBrowserTitle( 'Display Mapsever map');
// PDF for '.$gContent->mInfo['title'] );

//$gDefaultCenter = 'bitpackage:mapper/center_view_map.tpl';
//$gBitSmarty->assign_by_ref( 'gDefaultCenter', $gDefaultCenter );
$modMap = true;
$gBitSmarty->assign( 'modMap', $modMap );

// whitelist only - actual mapset validity is re-checked by html/script.php
// against includes/mapsets_inc.php before it's ever used
$mapset = ( !empty( $_GET['mapset'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['mapset'] ) ) ? $_GET['mapset'] : '';
$gBitSmarty->assign( 'mapset', $mapset );

// resolve the mapset's title for the page heading - same merge logic as
// html/script.php / select_map.php (see includes/mapsets_inc.php). Stopgap
// until mapsets are real LibertyContent objects with their own title field.
$mapsets = require( MAPPER_PKG_INCLUDE_PATH.'mapsets_inc.php' );
$siteMapsetsFile = '/etc/webstack/domains/'.$gBitDbName.'/mapper_mapsets.php';
if( file_exists( $siteMapsetsFile ) ) {
	$siteMapsets = require( $siteMapsetsFile );
	if( !empty( $siteMapsets['mapsets'] ) ) {
		$mapsets['mapsets'] = array_merge( $mapsets['mapsets'], $siteMapsets['mapsets'] );
	}
	if( !empty( $siteMapsets['default'] ) ) {
		$mapsets['default'] = $siteMapsets['default'];
	}
}
$resolvedMapsetKey = ( !empty( $mapset ) && !empty( $mapsets['mapsets'][$mapset] ) ) ? $mapset : $mapsets['default'];
$gBitSmarty->assign( 'mapsetTitle', $mapsets['mapsets'][$resolvedMapsetKey]['title'] );

$gBitSystem->display( 'bitpackage:mapper/center_view_map.tpl', NULL, array( 'display_mode' => 'display' ));
?>
