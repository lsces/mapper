<?php
/**
 * list_content
 *
 * @author   spider <spider@steelsun.com>
 * @version  $Revision$
 * @package  mapper
 * @subpackage functions
 */

/**
 * required setup
 */
require_once("../kernel/includes/setup_inc.php");

// now that we have all the offsets, we can get the content list
include_once( MAPPER_PKG_INCLUDE_PATH.'get_map_list_inc.php' );
 
//$gBitSmarty->assign_by_ref('offset', $offset);
$gBitSmarty->assign( 'contentSelect', $contentSelect );
$gBitSmarty->assign( 'contentTypes', $contentTypes );
$gBitSmarty->assign( 'contentList', $contentList );
// built by getContentList() via postGetList(); rendered by {pagination} in list_map_inc.tpl
$gBitSmarty->assign( 'listInfo', $pListHash['listInfo'] ?? null );

$gBitSystem->setBrowserTitle( 'List Map Archive' );
$gBitSystem->display( 'bitpackage:mapper/list_maps.tpl' , NULL, array( 'display_mode' => 'list' ));
?>
