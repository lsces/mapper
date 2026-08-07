<?php
/**
 * @package mapper
 * @subpackage functions
 */
namespace Bitweaver\Mapper;

require_once( '../kernel/includes/setup_inc.php' );
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

global $gBitSystem, $gBitSmarty, $gContent;

$contentId = $_REQUEST['content_id'] ?? null;
$rawMapset = ( !empty( $_GET['mapset'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['mapset'] ) ) ? $_GET['mapset'] : '';
if( !$contentId && $rawMapset !== '' ) {
	// /mapper/view/<name> pretty URL (see bitweaver_rewrites.conf) - same slug lookup
	// mapper_resolve_mapset() uses for display_map.php/display_map2.php, kept inline here
	// rather than routed through that shared function since view.php never touches the old
	// mapsets_inc.php registry - it only ever deals with real Map content objects.
	$contentId = Map::lookupBySlug( $rawMapset );
}
$gContent = new Map( $contentId );
if( !$contentId || !$gContent->load() ) {
	$gBitSystem->fatalError( KernelTools::tra( 'The requested map could not be found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

$gContent->verifyViewPermission();
$gContent->addHit();
$gBitSmarty->assign( 'gContent', $gContent );

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );

$gBitSystem->setBrowserTitle( 'Map: '.$gContent->getTitle() );
$gBitSystem->display( 'bitpackage:mapper/view.tpl', NULL, [ 'display_mode' => 'display' ] );
