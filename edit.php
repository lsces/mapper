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
} elseif( !empty( $_REQUEST['reload'] ) ) {
	// Re-parse whatever's already on disk and re-sync every xref field from it - for a
	// mapfile that's already correct on disk but whose stored xref data has drifted (the
	// database was never re-synced after a direct server-side edit). See
	// Map::reloadFromDisk()'s own doc comment.
	$gContent->reloadFromDisk();
	KernelTools::bit_redirect( MAPPER_PKG_URL.'edit.php?content_id='.$gContent->mContentId );
} elseif( !empty( $_REQUEST['save'] ) ) {
	$pParamHash = $_REQUEST;
	$pParamHash['content_id'] = $gContent->mContentId;
	$pParamHash['overview_height'] = $_REQUEST['overview_height'] ?? '';
	if( !empty( $_FILES['map_file']['name'] ) ) {
		// A replacement file was uploaded - store()'s own upload branch (see its doc comment)
		// re-parses this exactly like a fresh upload would, same as fisheye's edit_image.php
		// re-purposes its own single file input for both initial upload and later replacement.
		//
		// attachment_id must be threaded through explicitly here - mime_default_verify()'s
		// isset($upload['attachment_id']) check is how it decides "update" vs "brand new
		// attachment" (see its own "little cluge" comment); a Map has exactly one attachment,
		// created with attachment_id == content_id (LibertyMime's own convention for a
		// content object's first upload - see mime_default_verify()), so that's always the
		// right value to pass here. Omitting it (found live 2026-08-09, editing content_id
		// 7406) makes it fall through to the brand-new-attachment path, which tries to INSERT
		// a fresh liberty_files row using a freshly GenID()'d id that can collide with an
		// entirely unrelated existing row - a real, previously-unexercised bug in the shared
		// mime.flatdefault.php plugin (its own code even flags this as an open @todo), not
		// something specific to mapper.
		$fileUpload = $_FILES['map_file'];
		$fileUpload['attachment_id'] = $gContent->mContentId;
		$pParamHash['_files_override'] = [ 'map_file' => $fileUpload ];
	}
	if( $gContent->store( $pParamHash ) ) {
		KernelTools::bit_redirect( $gContent->getDisplayUrl() );
	}
} elseif( !empty( $_REQUEST['delete'] ) ) {
	// verifyUpdatePermission() above already gates this whole page - no separate permission
	// check needed here, same as stock's edit_component.php delete=1 handling this mirrors.
	if( empty( $_REQUEST['confirm'] ) ) {
		$gBitSystem->confirmDialog( [ 'delete' => true, 'content_id' => $gContent->mContentId ], [
			'confirm_item' => $gContent->getTitle(),
			'warning'      => KernelTools::tra( 'Are you sure you want to delete this map?' ).' ('.$gContent->getTitle().')',
			'error'        => KernelTools::tra( 'This cannot be undone!' ),
		] );
		// confirmDialog() renders the prompt and die()s - never reaches here.
	} elseif( $gContent->expunge() ) {
		KernelTools::bit_redirect( MAPPER_PKG_URL.'list_maps.php' );
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

$mapFilePath = $gContent->getSourceFile( $gContent->mInfo['map_file'] ?? [] );
$gBitSmarty->assign( 'mapFileContent', ( $mapFilePath && is_readable( $mapFilePath ) ) ? file_get_contents( $mapFilePath ) : '' );

$gContent->invokeServices( 'content_edit_function' );

$gBitSystem->setBrowserTitle( 'Edit Map: '.$gContent->getTitle() );
$gBitSystem->display( 'bitpackage:mapper/edit.tpl', NULL, [ 'display_mode' => 'edit' ] );
