//Toolbar functions

// Computes a "cover"-style fit: fills the frame completely along whichever
// axis needs the smaller scale (units per pixel), cropping and centering
// the other axis. This is the opposite of MapServer's own default extent
// adjustment (which expands the *looser* axis instead, leaving blank
// margins around a small, real-content island) - used for "Full Extent"
// and the initial map load so both land on a fully-populated view rather
// than a mostly-empty whole-mapset extent. extentStr is "minx miny maxx maxy".
function computeCoverExtent(extentStr, frameWidth, frameHeight) {
	var e = extentStr.split(" ");
	var minx = parseFloat(e[0]), miny = parseFloat(e[1]);
	var maxx = parseFloat(e[2]), maxy = parseFloat(e[3]);

	var scale = Math.min((maxx - minx) / frameWidth, (maxy - miny) / frameHeight);
	var newWidth = frameWidth * scale;
	var newHeight = frameHeight * scale;
	var centerX = (minx + maxx) / 2;
	var centerY = (miny + maxy) / 2;

	return (centerX - newWidth / 2) + " " + (centerY - newHeight / 2) + " " +
		(centerX + newWidth / 2) + " " + (centerY + newHeight / 2);
}

//check if window is already open
//set to false on window open
var tooglelink = true;
var toogleimpress = true;
var tooglehelp = true;

//open layer link (thematic layer) in a new window
function WindowLink(i) {
	LinkWindow = open(layerLinkURL[i],"Links","width=" + LinkWidth + ",height=" + LinkHeight + ",scrollbars=yes,resizable=yes");
	tooglelink = false;
	setTimeout("LinkWindow.focus()",100);
}

//open window
function WindowFirst(windowName){
	switch (windowName){
		case "Impressum":
			ImpressWindow = open(impressURL,"Impressum","width=" + ImpressWidth + ",height=" + ImpressHeight + ",scrollbars=yes,resizable=yes");
			toogleimpress = false;
			setTimeout("ImpressWindow.focus()",100);
			break;
		case "Help":
			HelpWindow = open(helpURL,"Help","width=" + HelpWidth + ",height=" + HelpHeight + ",scrollbars=yes,resizable=yes");
			tooglehelp = false;
			setTimeout("HelpWindow.focus()",100);
			break;
	}
}

//check if window is already open. bring it to front or open it again
function WindowSecond(windowName){
	switch (windowName) {
		case "Impressum":
			if (ImpressWindow.closed == true){
				ImpressWindow = open(impressURL,"Impressum","width=" + ImpressWidth + ",height=" + ImpressHeight + ",scrollbars=yes,resizable=yes");
				setTimeout("ImpressWindow.focus()",100);
			} else {
				setTimeout("ImpressWindow.focus()",100);
			}
			break;
		case "Help":
			if (HelpWindow.closed == true){
				HelpWindow = open(helpURL,"Help","width=" + HelpWidth + ",height=" + HelpHeight + ",scrollbars=yes,resizable=yes");
				setTimeout("HelpWindow.focus()",100);
			} else {
				setTimeout("HelpWindow.focus()",100);
			}
			break;
	}
}

//set initial tool
var tool = new String;
var IdentifyURL = IdentifyURL_o;
var ZoomInURL = ZoomInURL_o;
var ZoomOutURL = ZoomOutURL_o;
var PanURL = PanURL_o;
var ZoomInURL = ZoomInURL_o;

switch (initialTool) {
	case "IdentifyTool":
		tool = "identify";
		IdentifyURL = IdentifyURL_u;
		break;
	case "ZommInTool":
		tool = "zoomin";
		ZoomInURL = ZoomInURL_u;
		break;
	case "ZoomOutTool":
		tool = "zoomout";
		ZoomOutURL = ZoomOutURL_u;
		break;
	case "PanTool":
		tool = "panning";
		PanURL = PanURL_u;
	default:
		tool = "zoomin";
		ZoomInURL = ZoomInURL_u;
}

//set toolpic
function turnUnselected() {
	if ("identify") parent.ToolFrame.document.identify.src = IdentifyURL_o;
	if ("zoomin") parent.ToolFrame.document.zoomin.src = ZoomInURL_o;
	if ("zoomout") parent.ToolFrame.document.zoomout.src = ZoomOutURL_o;
	if ("pan") parent.ToolFrame.document.pan.src = PanURL_o;
}
		
function turnSelected(selTool) {
	turnUnselected();
	if (selTool == "identify") parent.ToolFrame.document.identify.src = IdentifyURL_u;
	if (selTool == "zoomin") parent.ToolFrame.document.zoomin.src = ZoomInURL_u;
	if (selTool == "zoomout") parent.ToolFrame.document.zoomout.src = ZoomOutURL_u;
	if (selTool == "pan") parent.ToolFrame.document.pan.src = PanURL_u;
}


function setTool(selectedTool) {
	switch (selectedTool) {
			
		case "zoomin":
			parent.ScriptFrame.mode = "browse";
			parent.ScriptFrame.mapext = "shapes";
			parent.ScriptFrame.zoomdir = 1;
			tool = "zoomin";
			zoomdir = 1;
			
		break;
		
		case "zoomout":
			parent.ScriptFrame.mode = "browse";
			parent.ScriptFrame.mapext = "shapes";
			parent.ScriptFrame.zoomdir = -1;
			zoomdir = -1;
			tool = "zoomout";
		
		break;
		
		case "fullextent":
			turnLayerVisible("Status");
			parent.ScriptFrame.mode = "browse";
			parent.ScriptFrame.mapext = computeCoverExtent(fullExtent, MapWidth, MapHeight);
			if (parent.ScriptFrame.imgbox != "-1 -1 -1 -1") {
				parent.ScriptFrame.imgbox = "-1 -1 -1 -1";
			}
			parent.ScriptFrame.writeCGIForm();
			parent.ScriptFrame.mapext="shapes";
		break;
			
		case "pan":
			parent.ScriptFrame.mode="browse";
			parent.ScriptFrame.zoomdir = 0;
			tool = "panning";
									
		break;
		
		case "identify":
			parent.ScriptFrame.mode="query";
			tool = "identify";
		
		break;	
		
		case "help":
			if (tooglehelp == true){
				WindowFirst('Help');
			} else {
				WindowSecond('Help');	
			}
			break;
		
	}
}


