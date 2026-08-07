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
