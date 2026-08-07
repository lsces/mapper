<?php
/**
 * @package mapper
 * @subpackage functions
 */
namespace Bitweaver\Mapper;

require_once( '../kernel/includes/setup_inc.php' );
use Bitweaver\KernelTools;
use function Bitweaver\Liberty\liberty_process_archive;

global $gBitSystem, $gBitSmarty, $gBitUser;

$gBitSystem->verifyPermission( 'bit_p_create_mapper' );

/**
 * Batch-import every *.map file found directly inside an extracted archive - flat only, no
 * subdirectory recursion or SHAPEPATH rewriting (unlike fisheye's gallery-building recursion -
 * a mapfile bundle here is just many independent .map files pointing at data that already
 * exists on disk, not a self-contained per-item data tree - see mapper/CLAUDE.md). Returns any
 * per-file errors, keyed by filename.
 */
function mapper_process_archive( array &$pFileHash, int $pUserId ): array {
	$errors = [];
	if( !( $destDir = liberty_process_archive( $pFileHash ) ) || !is_dir( $destDir ) ) {
		return [ 'archive' => KernelTools::tra( 'Unable to extract archive.' ) ];
	}

	foreach( glob( $destDir.'/*.map' ) as $mapFilePath ) {
		$fileHash = [
			'name'     => basename( $mapFilePath ),
			'type'     => 'text/plain',
			'tmp_name' => $mapFilePath,
			'error'    => 0,
			'size'     => filesize( $mapFilePath ),
		];
		$map = new Map();
		$pParamHash = [
			'title'            => '',
			'_files_override'  => [ 'map_file' => $fileHash ],
			'user_id'          => $pUserId,
		];
		if( !$map->store( $pParamHash ) ) {
			$errors[basename( $mapFilePath )] = implode( '; ', $map->mErrors );
		}
	}

	KernelTools::unlink_r( $destDir );
	return $errors;
}

$errors = [];
if( !empty( $_FILES['map_file']['name'] ) ) {
	$_FILES['map_file']['type'] = $gBitSystem->verifyMimeType( $_FILES['map_file']['tmp_name'] );
	if( preg_match( '#zip|tar|gzip|x-rar|stuffit#i', $_FILES['map_file']['type'] ) ) {
		$errors = mapper_process_archive( $_FILES['map_file'], $gBitUser->mUserId );
	} else {
		$map = new Map();
		$pParamHash = [
			'title'            => $_REQUEST['title'] ?? '',
			'data'             => $_REQUEST['data'] ?? '',
			'_files_override'  => [ 'map_file' => $_FILES['map_file'] ],
			'user_id'          => $gBitUser->mUserId,
			'excl'             => !empty( $_REQUEST['excl'] ),
		];
		if( !$map->store( $pParamHash ) ) {
			$errors = $map->mErrors;
		}
	}
	if( empty( $errors ) ) {
		KernelTools::bit_redirect( MAPPER_PKG_URL.'list_maps.php' );
	}
}

$gBitSmarty->assign( 'errors', $errors );
$gBitSystem->setBrowserTitle( 'Upload Map' );
$gBitSystem->display( 'bitpackage:mapper/upload_map.tpl', NULL, [ 'display_mode' => 'edit' ] );
