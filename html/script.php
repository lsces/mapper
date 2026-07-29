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
document.write('<link rel="stylesheet" href="' + parent.styleURL + '" type="text/css">');

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
var mapPfad = <?php echo json_encode( MAPPER_PKG_PATH.'map/'.$mapset['file'] ); ?>;
var layerList = <?php echo json_encode( array_values( $mapset['layerList'] ) ); ?>;
var layerAlias = <?php echo json_encode( array_values( $mapset['layerAlias'] ) ); ?>;
var layerVisible = <?php echo json_encode( array_values( $mapset['layerVisible'] ) ); ?>;
var layerIsQueryable = <?php echo json_encode( array_values( $mapset['layerIsQueryable'] ) ); ?>;
var layerLink = <?php echo json_encode( array_values( $mapset['layerLink'] ) ); ?>;
</script>
<script type="text/javascript" language="JavaScript" src="../scripts/browser.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/common.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/layer.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/toolbar.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/zoombox.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/nav.js"></script>
<script type="text/javascript" language="JavaScript" src="../scripts/query.js"></script>
</head>

<script language="javascript">
document.writeln('<body onload="Load2()" bgcolor="' + BereichColor1 + '" leftmargin="0" topmargin="0" marginwidth="0" marginheight="0" background="" onunload="closeWindows()">');

</script>
<TABLE width="100%">
<TR>
<TD class="heading-lg">L.S.Caine Electronic Services - MapServer</TD>
<TD width="160px" align="center"><img src="../graphics/lsces_logo.jpg"></TD>

</TR>
</TABLE>
</body>
</html>
