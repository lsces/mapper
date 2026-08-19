<?php
/**
 * @package install
 * @subpackage pumps
 */
namespace Bitweaver\Mapper;

// test_rlp.map is mapper's own bundled portable demo mapfile (see mapper/CLAUDE.md's
// "test_rlp.map / OS250.map" entry) - already used as the anonymous-accessible 'test' fallback
// mapset, but purely a filesystem/registry construct with no real Map content object of its own.
// This creates that missing object, the same way upload_map.php's mapper_process_archive() turns
// any uploaded .map file into one - Map::store() parses the file itself for title/extent/layers,
// nothing else to pass here.
$mapFilePath = MAPPER_PKG_PATH.'map/test_rlp.map';
if( is_readable( $mapFilePath ) ) {
	$fileHash = [
		'name'     => basename( $mapFilePath ),
		'type'     => 'text/plain',
		'tmp_name' => $mapFilePath,
		'error'    => 0,
		'size'     => filesize( $mapFilePath ),
	];
	$pParamHash = [
		'title'           => '',
		'_files_override' => [ 'map_file' => $fileHash ],
		'user_id'         => ROOT_USER_ID,
	];
	$map = new Map();
	if( $map->store( $pParamHash ) ) {
		$pumpedData['Mapper'][] = $pParamHash['title'];
	} else {
		$error = $map->mErrors;
		$gBitSmarty->assign( 'error', $error );
	}
}
