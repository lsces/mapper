<?php
global $gBitInstaller;

$gBitInstaller->registerPackageInfo( MAPPER_PKG_NAME, array(
	'description' => "Mapserver client interface and archive.",
	'license' => '<a href="http://www.gnu.org/licenses/licenses.html#LGPL">LGPL</a>',
) );


// ### Default UserPermissions
$gBitInstaller->registerUserPermissions( MAPPER_PKG_NAME, array(
	array('bit_p_v_map_mapper', 'Can view MAP files', 'basic', 'mapper'),
	array('bit_p_view_mapper', 'Can view map archives', 'registered', 'mapper'),
	array('bit_p_create_mapper', 'Can create a map archive', 'registered', 'mapper'),
	array('bit_p_edit_mapper', 'Can edit map archives', 'registered', 'mapper'),
	array('bit_p_admin_mapper', 'Can admin map archives', 'editors', 'mapper')
) );

// ### Register the Map content type so getLibertyObject() can resolve it - registerContentType()
// itself is also called from Map's own constructor (matches StockAssembly/FisheyeImage - see
// mapper/CLAUDE.md), this call is what makes the content type visible to the admin installer.
$gBitInstaller->registerContentObjects( MAPPER_PKG_NAME, [
	'Map' => MAPPER_PKG_CLASS_PATH.'Map.php',
] );

// ### xref groups/items - no bespoke mapper_map table. 'general' (multiple=0, one value per
// map) covers fields auto-extractable from the uploaded .map file itself (EXTENT, SHAPEPATH)
// plus one admin-set toggle (EXCL) that isn't extractable. 'layers' (multiple=1) replaces the
// old mapsets_inc.php/mapper_mapsets.php registry's parallel layerList/layerAlias/layerVisible/
// layerIsQueryable/layerLink arrays - one xref row per actual mapfile LAYER block, xkey/xkey_ext
// hold name/alias, the remaining 3 flags pack into `data` as JSON, xorder preserves layer order
// (same mechanism as stock's BOM `quantity` group - see liberty/CLAUDE.md's xorder note).
$X = BIT_DB_PREFIX;
$xrefTypes = [];
$xrefItems = [];

$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('general','mapper','Map Details',1,3,'','')";
$xrefTypes[] = "INSERT INTO `{$X}liberty_xref_group` (`x_group`,`content_type_guid`,`title`,`sort_order`,`role_id`,`type_href`,`template`) VALUES ('layers', 'mapper','Layers',      2,3,'','')";

$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('EXTENT', 'mapper','general','Extent',          0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('SHPPATH','mapper','general','Data Directory',  0,3,'','text',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('EXCL',   'mapper','general','Exclusive Layers', 0,3,'','value',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('OVERVIEWHEIGHT','mapper','general','Overview Height',0,3,'','value',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('PROJECTION','mapper','general','Projection',      0,3,'','value',NULL)";
$xrefItems[] = "INSERT INTO `{$X}liberty_xref_item` (`item`,`content_type_guid`,`x_group`,`cross_ref_title`,`multiple`,`role_id`,`cross_ref_href`,`template`,`data`) VALUES ('LAYER',  'mapper','layers', 'Layer',            1,3,'','text',NULL)";

$gBitInstaller->registerSchemaDefault( MAPPER_PKG_NAME, array_merge( $xrefTypes, $xrefItems ) );

?>
