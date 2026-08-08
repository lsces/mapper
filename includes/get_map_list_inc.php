<?php
/**
 * get_content_list
 *
 * @author   Christian Fowler>
 * @version  $Revision$
 * @package  mapper
 * @subpackage functions
 */

use Bitweaver\Mapper\Map;

global $gContent;
global $gLibertySystem;

if( empty( $gContent ) || !is_object( $gContent ) ) {
	$gContent = new Map();
}

// list_maps.php only ever lists Map objects - not left open to $_REQUEST['content_type']
// like a generic cross-type admin listing would be.
$contentSelect = MAPPER_CONTENT_TYPE_GUID;

// get_content_list_inc doesn't use $_REQUEST parameters as it might not be the only list in the page that needs sorting and limiting
$pListHash = [
	'content_type_guid' => $contentSelect,
	'offset'            => isset( $offset_content ) ? $offset_content : 0,
	'max_records'       => isset( $max_content ) ? $max_content : 500,
	'sort_mode'         => isset( $content_sort_mode ) ? $content_sort_mode : 'title_asc',
	'find'              => empty( $_REQUEST["find_objects"] ) ? NULL : $_REQUEST["find_objects"],
	'user_id'           => isset( $_REQUEST['user_id'] ) ? $_REQUEST['user_id'] : NULL,
];
$contentList = $gContent->getContentList( $pListHash );

// getContentList() is LibertyContent's own generic, independent query-builder - it does not
// call Map::getList() at all (only extends via the package-wide content_list_sql_function
// service hook, a bigger lift than this one extra column needs), so PROJECTION has to be
// fetched separately and merged in here rather than joined into a query that was never actually
// running. Single batched lookup keyed on xkey (see Map::upsertSingleXref()'s own doc comment
// on why PROJECTION lives there, not in the data blob).
if( $contentList ) {
	global $gBitDb;
	// getContentList() returns a plain sequential array (0,1,2,...), not content_id-keyed like
	// Map::getList() - content_id has to come from each row's own field, not the array key.
	$contentIds = array_column( $contentList, 'content_id' );
	$placeholders = implode( ',', array_fill( 0, count( $contentIds ), '?' ) );
	$projections = $gBitDb->getAssoc( "SELECT `content_id`, `xkey` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `item` = 'PROJECTION' AND `content_id` IN ($placeholders)", $contentIds );
	foreach( $contentList as &$row ) {
		$row['projection'] = $projections[$row['content_id']] ?? null;
	}
	unset( $row );
}

$contentTypes = array();
foreach( $gLibertySystem->mContentTypes as $cType ) {
	$contentTypes[$cType['content_type_guid']] = $gLibertySystem->getContentTypeName( $cType['content_type_guid'] );
}
?>
