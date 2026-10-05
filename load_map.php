<?php
/**
 * Lists the maps in the configured maps folder (<maps_dir>/<name>/<name>.map - see the 'Maps
 * folder' admin setting) that have not been imported yet, and imports the ticked ones. Same
 * shape as fisheyemedia's load_collection.php: re-scans on every request, so the list always
 * shows what is still outstanding, even straight after an import.
 *
 * @package mapper
 * @subpackage functions
 */
namespace Bitweaver\Mapper;

require_once( '../kernel/includes/setup_inc.php' );
use Bitweaver\KernelTools;

global $gBitSystem, $gBitSmarty, $gBitUser, $gBitDb;

$gBitSystem->verifyPermission( 'bit_p_create_mapper' );

// Mapfile descriptions are longer than a title, so rows are tall - keep each submit small.
const LOAD_MAP_BATCH = 10;

$mapper = new BitMapper();
$baseDir = rtrim( $mapper->mSettings['maps_dir'], '/' );

/**
 * Every <dir>/<file>.map directly under $pBaseDir whose title does not already belong to a Map,
 * keyed by its path relative to $pBaseDir. Imported means "a Map with the same slug exists" -
 * the same identity the pretty /mapper/map/<name> URLs use.
 */
function load_map_candidates( string $pBaseDir ): array {
	global $gBitDb;
	// Instantiating Map first is what loads Map.php, which define()s MAPPER_CONTENT_TYPE_GUID.
	$reader = new Map();
	$existing = [];
	foreach( $gBitDb->getCol( "SELECT `title` FROM `".BIT_DB_PREFIX."liberty_content` WHERE `content_type_guid` = ?", [ MAPPER_CONTENT_TYPE_GUID ] ) as $title ) {
		$existing[Map::slugify( (string)$title )] = true;
	}

	$candidates = [];
	$entries = is_dir( $pBaseDir ) ? scandir( $pBaseDir ) : [];
	natsort( $entries );
	foreach( $entries as $entry ) {
		if( str_starts_with( $entry, '.' ) || !is_dir( $pBaseDir.'/'.$entry ) ) {
			continue;
		}
		foreach( glob( $pBaseDir.'/'.$entry.'/*.map' ) ?: [] as $mapFilePath ) {
			$info = $reader->describeMapFile( $mapFilePath );
			if( isset( $existing[Map::slugify( $info['title'] )] ) ) {
				continue;
			}
			$candidates[$entry.'/'.basename( $mapFilePath )] = [
				'path'        => $entry.'/'.basename( $mapFilePath ),
				'title'       => $info['title'],
				'description' => $info['description'],
			];
		}
	}
	return $candidates;
}

$result = [ 'created' => [], 'errors' => [], 'skipped' => 0 ];
if( !empty( $_POST['import'] ) && is_array( $_POST['import'] ) ) {
	// Only paths the fresh scan itself offers can be imported - nothing from the request is ever
	// used to build a filesystem path directly.
	$candidates = load_map_candidates( $baseDir );
	$wanted = array_values( array_filter( $_POST['import'], fn( $path ) => is_string( $path ) && isset( $candidates[$path] ) ) );
	$result['skipped'] = max( 0, count( $wanted ) - LOAD_MAP_BATCH );

	foreach( array_slice( $wanted, 0, LOAD_MAP_BATCH ) as $path ) {
		// Liberty copies a non-uploaded file before storing it (liberty_lib.php), but hand it a
		// throwaway copy anyway so the original in the maps folder can never be moved or removed.
		$tmpFile = tempnam( sys_get_temp_dir(), 'mapimp' );
		if( !$tmpFile || !copy( $baseDir.'/'.$path, $tmpFile ) ) {
			$result['errors'][$path] = KernelTools::tra( 'Unable to read the map file.' );
			continue;
		}
		$fileHash = [
			'name'     => basename( $path ),
			'type'     => 'text/plain',
			'tmp_name' => $tmpFile,
			'error'    => 0,
			'size'     => filesize( $tmpFile ),
		];
		$map = new Map();
		$pParamHash = [
			'title'           => '',
			'_files_override' => [ 'map_file' => $fileHash ],
			'user_id'         => $gBitUser->mUserId,
		];
		if( $map->store( $pParamHash ) ) {
			$result['created'][] = [ 'path' => $path, 'content_id' => $map->mContentId, 'title' => $map->getTitle() ];
		} else {
			$result['errors'][$path] = implode( '; ', $map->mErrors );
		}
		if( is_file( $tmpFile ) ) {
			unlink( $tmpFile );
		}
	}
}

// Re-scan every time, so what is shown is what is still outstanding - but only ever show one
// batch's worth, since a row with its description is tall.
$outstanding = load_map_candidates( $baseDir );
$gBitSmarty->assign( 'baseDir', $baseDir );
$gBitSmarty->assign( 'batchSize', LOAD_MAP_BATCH );
$gBitSmarty->assign( 'outstanding', count( $outstanding ) );
$gBitSmarty->assign( 'candidates', array_slice( $outstanding, 0, LOAD_MAP_BATCH, true ) );
$gBitSmarty->assign( 'result', $result );

$gBitSystem->setBrowserTitle( 'Load Maps' );
$gBitSystem->display( 'bitpackage:mapper/load_map.tpl', NULL, [ 'display_mode' => 'edit' ] );
