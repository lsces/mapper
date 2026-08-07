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

$contentTypes = array();
foreach( $gLibertySystem->mContentTypes as $cType ) {
	$contentTypes[$cType['content_type_guid']] = $gLibertySystem->getContentTypeName( $cType['content_type_guid'] );
}
?>
