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

$gContent->verifyUpdatePermission();
$gBitSmarty->assign( 'gContent', $gContent );

if( !empty( $_REQUEST['cancel'] ) ) {
	KernelTools::bit_redirect( $gContent->getDisplayUrl() );
} elseif( !empty( $_REQUEST['save'] ) ) {
	$pParamHash = $_REQUEST;
	$pParamHash['content_id'] = $gContent->mContentId;
	$pParamHash['overview_height'] = $_REQUEST['overview_height'] ?? '';
	if( $gContent->store( $pParamHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
}

$errors = !empty( $gContent->mErrors ) ? $gContent->mErrors : [];
$gBitSmarty->assign( 'errors', $errors );

global $gBitDb;
// EXCL is a registered xref item (template 'value') now - editable through the generic xref UI
// below (loadXrefInfo()/$gXrefInfo), no dedicated form field needed here any more.
$currentOverviewHeight = $gBitDb->getOne( "SELECT `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'OVERVIEWHEIGHT'", [ $gContent->mContentId ] );
$gBitSmarty->assign( 'mapOverviewHeight', $currentOverviewHeight );

$gContent->loadXrefInfo();
$gBitSmarty->assign( 'gXrefInfo', $gContent->mXrefInfo );

$gContent->invokeServices( 'content_edit_function' );

$gBitSystem->setBrowserTitle( 'Edit Map: '.$gContent->getTitle() );
$gBitSystem->display( 'bitpackage:mapper/edit.tpl', NULL, [ 'display_mode' => 'edit' ] );
