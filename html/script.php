<?php
/**
 * @package mapper
 *
 * ScriptFrame's own page - loaded by map_blank.html's Load1(). PHP (not
 * static html) so it can resolve the site's selectable mapset server-side
 * - see includes/mapsets_inc.php. Not read by mapserv, so no MapServer
 * template magic-string requirement here.
 */
require_once( '../../kernel/includes/setup_inc.php' );
$gBitSystem->verifyPackage( 'mapper' );

global $gBitDbName;

$mapsets = require( MAPPER_PKG_INCLUDE_PATH.'mapsets_inc.php' );
$siteMapsetsFile = '/etc/webstack/domains/'.$gBitDbName.'/mapper_mapsets.php';
if( file_exists( $siteMapsetsFile ) ) {
	$siteMapsets = require( $siteMapsetsFile );
	if( !empty( $siteMapsets['mapsets'] ) ) {
		$mapsets['mapsets'] = array_merge( $mapsets['mapsets'], $siteMapsets['mapsets'] );
	}
	if( !empty( $siteMapsets['default'] ) ) {
		$mapsets['default'] = $siteMapsets['default'];
	}
}

// script.php is ScriptFrame's own document - it's not just a page, it's the engine that
// sets mapPath/layerList and redirects every other frame (see Load2() below). A missing/
// invalid mapset here must fall back silently, not replace this document's content with an
// error message - that would break the frame choreography entirely (empty map, no redirect).
// display_map.php resolves+validates the mapset once (with its own fallback) and passes the
// resolved key down via scriptURL, so this should only ever see a valid key in practice.
$requestedMapset = ( !empty( $_GET['mapset'] ) && !empty( $mapsets['mapsets'][$_GET['mapset']] ) ) ? $_GET['mapset'] : $mapsets['default'];
$mapset = $mapsets['mapsets'][$requestedMapset];
?>
<!-- MapServer Template -->
<!DOCTYPE html>
<html>
<head>
<title>Page for loading Javascript</title>
<meta charset="UTF-8">
<meta http-equiv="cache-control" content="no-cache">
<script language="javascript">
var styleLink = document.createElement('link');
styleLink.rel = 'stylesheet';
styleLink.type = 'text/css';
styleLink.href = parent.styleURL;
document.head.appendChild(styleLink);

var m = parent.MapFrame;

//get the MapFrame-Size from map_blank.html
var MapFrameWidth = m.MapFrameWidth;
var MapFrameHeight = m.MapFrameHeight;

function Load2() {
	parent.FormFrame.document.location = initURL;
	parent.ToolFrame.document.location = toolURL;
	parent.NaviFrame.document.location = naviURL;
	parent.LinkFrame.document.location = linkURL;

}

//keeps track of child windows and closes them upon unload
function closeWindows() {
	if (tooglelink == false) {
		LinkWindow.close();
	}
	if (tooglehelp == false) {
		HelpWindow.close();
	}
	if (toogleimpress == false) {
		ImpressWindow.close();
	}
}

</script>

<script type="text/javascript" language="JavaScript" src="../scripts/form.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/param1.js"></script>
<script language="javascript">
//active mapset, resolved server-side - see includes/mapsets_inc.php
var mapsetKey = <?php echo json_encode( $requestedMapset ); ?>;
var mapPath = <?php echo json_encode( MAPPER_PKG_PATH.'map/'.$mapset['file'] ); ?>;
var layerList = <?php echo json_encode( array_values( $mapset['layerList'] ) ); ?>;
var layerAlias = <?php echo json_encode( array_values( $mapset['layerAlias'] ) ); ?>;
var layerVisible = <?php echo json_encode( array_values( $mapset['layerVisible'] ) ); ?>;
var layerIsQueryable = <?php echo json_encode( array_values( $mapset['layerIsQueryable'] ) ); ?>;
var layerLink = <?php echo json_encode( array_values( $mapset['layerLink'] ) ); ?>;
var layerExclusive = <?php echo json_encode( array_key_exists( 'layerExclusive', $mapset ) ? (bool)$mapset['layerExclusive'] : true ); ?>;
var fullExtent = <?php echo json_encode( $mapset['extent'] ); ?>;
</script>
<script type="text/javascript" language="JavaScript" src="../scripts/browser.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/common.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/layer.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/toolbar.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/zoombox.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/nav.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/query.js"></script>
</head>

<body leftmargin="0" topmargin="0" marginwidth="0" marginheight="0" background="">
<script language="javascript">
document.body.setAttribute('bgcolor', SharedColor1);
window.onload = Load2;
window.onunload = closeWindows;
</script>
<TABLE width="100%">
<TR>
<TD class="heading-lg">L.S.Caine Electronic Services - MapServer</TD>
<TD width="160px" align="center"><img src="../graphics/lsces_logo.jpg"></TD>

</TR>
</TABLE>
</body>
</html>
