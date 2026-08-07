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
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;
require_once( MAPPER_PKG_INCLUDE_PATH.'resolve_mapset_inc.php' );
use function Bitweaver\Mapper\mapper_resolve_mapset;

$gBitSystem->verifyPackage( 'mapper' );

$gBitSystem->setBrowserTitle( 'Display Mapsever map');

// whitelist the raw params, then resolve+validate below - html/script.php trusts whatever
// 'mapset'/'content_id' Smarty variable this assigns, so it must never be passed through
// unvalidated (a stale/renamed key here used to reach scriptURL raw, which broke the whole
// frame choreography rather than falling back - see mapper/CLAUDE.md).
$contentId = !empty( $_GET['content_id'] ) && ctype_digit( (string)$_GET['content_id'] ) ? (int)$_GET['content_id'] : null;
$rawMapset = ( !empty( $_GET['mapset'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['mapset'] ) ) ? $_GET['mapset'] : '';

$map = null;
if( $contentId ) {
	// content_id path - protector-aware (per-item role gating actually enforced via
	// Map::load(), see protector/CLAUDE.md), no registry-style anonymous soft-fallback (that's
	// specific to the registry's shared 'test' demo below, not a per-object concept here).
	$resolved = mapper_resolve_mapset( $contentId, '' );
	if( $resolved === null ) {
		$gBitSystem->fatalError( KernelTools::tra( 'The requested map could not be found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	[ 'mapset' => $mapset, 'resolvedKey' => $resolvedMapsetKey, 'map' => $map ] = $resolved;
	$map->verifyViewPermission();
} else {
	// Explicitly requested and not found - standard site "not found" error, same as any other
	// package (see wiki/backlinks.php etc), instead of loading the map viewer at all. An empty
	// $rawMapset instead resolves to the registry's own default (see resolve_mapset_inc.php).
	$resolved = mapper_resolve_mapset( null, $rawMapset );
	if( $resolved === null ) {
		$gBitSystem->fatalError( KernelTools::tra( 'The requested map could not be found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
	}
	[ 'mapset' => $mapset, 'resolvedKey' => $resolvedMapsetKey ] = $resolved;

	// 'test' is the public package demo mapset (bit_p_v_map_mapper, basic/anonymous) - everything
	// else (iom, meridian, minisc, opmplc, vmdvec) is real OS-licensed data or private family
	// genealogy data, gated behind bit_p_view_mapper (registered).
	$requiredPermission = $resolvedMapsetKey === 'test' ? 'bit_p_v_map_mapper' : 'bit_p_view_mapper';

	// No mapset was explicitly requested (bare URL) and the resolved site default isn't visible
	// to this user - fall back to the public demo instead of a login wall, since they didn't ask
	// for anything specific. An explicit ?mapset=iom (or similar) still gets the normal login
	// prompt below - only the no-param "just take me to the default" case gets this softer landing.
	if( empty( $rawMapset ) && $requiredPermission !== 'bit_p_v_map_mapper' && !$gBitUser->hasPermission( $requiredPermission ) ) {
		$resolvedMapsetKey = 'test';
		$requiredPermission = 'bit_p_v_map_mapper';
		$resolved = mapper_resolve_mapset( null, 'test' );
		$mapset = $resolved['mapset'];
	}
	$gBitSystem->verifyPermission( $requiredPermission );
}

$modMap = true;
$gBitSmarty->assign( 'modMap', $modMap );
$gBitSmarty->assign( 'mapset', $map ? '' : $resolvedMapsetKey );
$gBitSmarty->assign( 'contentId', $map ? $contentId : '' );
$gBitSmarty->assign( 'mapsetTitle', $mapset['title'] );

$gBitSystem->display( 'bitpackage:mapper/center_view_map.tpl', NULL, array( 'display_mode' => 'display' ));
?>
