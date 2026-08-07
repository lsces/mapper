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
require_once( MAPPER_PKG_INCLUDE_PATH.'resolve_mapset_inc.php' );
use function Bitweaver\Mapper\mapper_resolve_mapset;

// script.php is ScriptFrame's own document - it's not just a page, it's the engine that
// sets mapPath/layerList and redirects every other frame (see Load2() below). A missing/
// invalid mapset/content_id here must fall back silently to the registry default, not replace
// this document's content with an error message - that would break the frame choreography
// entirely (empty map, no redirect). display_map.php resolves+validates+permission-checks once
// and passes the resolved key/content_id down via scriptURL, so this should only ever see a
// valid one in practice - the fallback here is a last-resort safety net, not the normal path.
$contentId = !empty( $_GET['content_id'] ) && ctype_digit( (string)$_GET['content_id'] ) ? (int)$_GET['content_id'] : null;
$rawMapset = !empty( $_GET['mapset'] ) ? $_GET['mapset'] : '';

$resolved = mapper_resolve_mapset( $contentId, $rawMapset ) ?? mapper_resolve_mapset( null, '' );
$mapset = $resolved['mapset'];
$mapPath = $resolved['mapCgiPath'];
$requestedMapset = $resolved['resolvedKey'];
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
var mapPath = <?php echo json_encode( $mapPath ); ?>;
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
