//***************path information*************** 
//path to ...
//... mapserv binary
var exePath="/cgi-bin/mapserv";
//... application path (url-path to [...]/client/) - root-relative so this
//survives switching bitweaver5 between domains (see switch-site)
var applicationPath = "/mapper/";

//legend setting - html legend or not?
hasHTMLLegend = true;

//...map file - mapPath, layerList, layerAlias, layerVisible,
//layerIsQueryable and layerLink are NOT declared here - they're resolved
//server-side per site+mapset and emitted directly by html/script.php
//(see mapper/includes/mapsets_inc.php), since this file is loaded before
//that block runs and gets its variables overwritten there either way.

//***********************extent information*************************
//fullextent = extent in *.map
var fullExtent = "213000 464300 250900 505524";

//url to the page that contains the information
//(order as in layerList)
var layerLinkURL = new Array();
layerLinkURL[0] = "";
//layerLinkURL[1] = "https://lsces.uk/bitweaverdev/mapper/images/IOM1906Notes.html";
//layerLinkURL[2] = "https://lsces.uk/bitweaverdev/mapper/images/IOM1940Notes.html";
//layerLinkURL[3] = "https://lsces.uk/bitweaverdev/mapper/images/IOM1947Notes.html";

//tooltips for the layer links
//(order as in layerList)
var layerLinkName = new Array();
layerLinkName[0] = "1880 Ordnance Survey";
//layerLinkName[1] = "1906 Ordnance Survey";
//layerLinkName[2] = "1940 Ordnance Survey";
//layerLinkName[3] = "1947 Ordnance Survey";
layerLinkName[4] = "Grid Overlay";

//**************base layers********
var hasCommonLayers = false;
//array of layer names for the base layers = names as they appear in your mapfile
var commonLayerList = new Array();
//commonLayerList[0] = "[layername]";
//commonLayerList[1] = "[layername]";

//aliasnames for the base layer - these names will appear in the layertree
//(order as in commonLayerList)
var commonLayerAlias = new Array();
//commonLayerAlias[0] = "[layeralias]";
//commonLayerAlias[1] = "[layeralias]";

//should the base layer be visible on the first map?
//(order as commonLayerList)
//0 = not visible , 1 = visible
var commonLayerIsVisible = new Array();
//commonLayerIsVisible[0] = 1;
//commonLayerIsVisible[1] = 1;

//Colors
// ...Color1 = backgroundcolor,
//...Color2 = font color ...
var SharedColor1 = "#FFFFFF";
var SharedColor2 = "#F4B900";

//... various file pathes
var themePath = applicationPath + "theme/"
var htmlPath = applicationPath + "html/";
var startURL = htmlPath + "start.html";
var scriptURL = htmlPath  + "script.html";
var initURL = htmlPath + "map_init.html";
var naviURL = htmlPath + "navi.html";
var mapURL = htmlPath + "map.html";
var toolURL = htmlPath + "tool.html";
var legendURL = htmlPath + "legend.html";
var linkURL = htmlPath  + "link.html";
var helpURL = htmlPath + "help.html";
var impressURL = htmlPath + "impress.html"
var imagePath1 = applicationPath + "graphics/";
var imagePath2 = applicationPath + "graphics/";
var styleURL = applicationPath + "styles/client.css";

//*************Properties for the links that appear in the LinkFrame**************
//Title for the Links
var furtherLink = new Array();
furtherLink[0] = "LSCaine Electronic Services";
furtherLink[1] = "Mapserver Homepage";
furtherLink[2] = "";
furtherLink[3] = "";
furtherLink[4] = "";
furtherLink[5] = "";
furtherLink[6] = "";
furtherLink[7] = "";

//URL
//(order as in furtherLink)
var furtherLinkURL = new Array();
furtherLinkURL[0] = "https://lsces.uk";
furtherLinkURL[1] = "https://mapserver.gis.umn.edu";
furtherLinkURL[2] = "";
furtherLinkURL[3] = "";
furtherLinkURL[4] = "";
furtherLinkURL[5] = "";
furtherLinkURL[6] = "";
furtherLinkURL[7] = "";

//ToolTip for the Links
//(order as in furtherLink)
var furtherLinkName = new Array();
furtherLinkName[0] = "LSCaine Electronic Services";
furtherLinkName[1] = "MapServer Homepage";
furtherLinkName[2] = "";
furtherLinkName[3] = "";
furtherLinkName[4] = "";
furtherLinkName[5] = "";
furtherLinkName[6] = "";
furtherLinkName[7] = "";

//***********backgroundcolors for Frames and Windows********
//... MapFrame
var MapFrameColor = "#FFFFFF";
//... FormFrame and LinkFrame
var FormFrameColor = "#FFFFFF";
//... ToolFrame
var ToolFrameColor = "#FFFFFF";
//... LegendFrame
var LegendFrameColor = "#FFFFFF";
//... Identify Results (opens in new window)
var IdentifyWinColor = "#FFFFFF";
//... Layer links (opens in new windows)
var LinkWinColor = "#FFFFFF";
//... help window
var HelpWinColor = "#FFFFFF";
//... Impressum
var ImpressWinColor = "#FFFFFF";

//*************************Properties for the Toolbar**********************
//Which tools should be visible?
//true = visible, false = not visible
//Identify
var IdentifyTool = true;
//Zoom In
var ZoomInTool = true;
//Zoom out
var ZoomOutTool = true;
//Fullextent
var FullExtentTool = true;
//Pan
var PanTool = true;
//Show Help
var HelpTool = true;
//Directional Pan
var JumpTool_MF = true;

//should the map be refreshed everytime the layer selection changes
//true = map refresh is triggered via onmousedown events of the layer checkboxes
//false = a button is displayed under the layer tree for requesting a new map
var autoRefresh = false;

//distance the map is moved with the directional pan buttons (int > 0 )
var jumpDist = 200;

//Which tool should be selected on application load
//use any of the following: IdentifyTool, ZoomInTool, ZoomOutTool oder PanTool
var initialTool = "ZoomInTool";

//Path to the tool button images
// *_u = high, _o = low
// ... IdentifyTool
var IdentifyURL_o = imagePath1 + "info_high.gif";
var IdentifyURL_u = imagePath1 + "info_low.gif";
// ... ZoomInTool
var ZoomInURL_o = imagePath1 + "zoomin_high.gif";
var ZoomInURL_u = imagePath1 + "zoomin_low.gif";
//... ZoomOutTool
var ZoomOutURL_o = imagePath1 + "zoomout_high.gif";
var ZoomOutURL_u = imagePath1 + "zoomout_low.gif";
//... FullExtentTool
var FullExtentURL = imagePath1 + "fextent.gif";
//... PanTool
var PanURL_o = imagePath1 + "pan_high.gif";
var PanURL_u = imagePath1 + "pan_low.gif";
//... HelpTool
var HelpURL = imagePath1 + "help.gif";

//size of the toolbar buttons (pixel)
var ToolWidth = 23;
var ToolHeight = 23;

//Tooltips
var toolName = new Array();
toolName[0] = "Visible Topics"; //for the "eye" icon in the navigation bar
toolName[1] = "Move North";
toolName[2] = "Move South";
toolName[3] = "Move East";
toolName[4] = "Move west";
toolName[5] = "Zoom In";
toolName[6] = "Zoom Out";
toolName[7] = "Full Extent";
toolName[8] = "Pan";
toolName[9] = "Identify";
toolName[10] = "Help";
toolName[11] = "Legend select";


//************************Window Properties**************
//size of the help window
var HelpWidth = 400;
var HelpHeight = 800;

//titles for windows
//title for the identify windows
var identifyTitle = "Help Window";
//title for the help window
var helpTitle = "Help Window"

var LinkWidth = 800;
var LinkHeight = 600;

//***********properties for the status graphic (displayed when map is loaded)***************
//size of the image
var StatusWidth = 273;
var StatusHeight = 31;
//tooltip
var statusName = "Map is loading";
//image path
var StatusURL = imagePath1 + "loadMap.gif";

//************************ScaleBar properties**********************
//should the scale be displayed?
// true = yes, false = no
var ShowScale = true;
//where to display the scalebar
//true = within the mapimage, false = within the mapborder
var ScalePlace = true;
//in which corner should the scalebar be displayed?
//dabei: ol = upper left, or = upper right, ul = lower left, ur = lower right
var ScaleMode = "ur";
//distance of the scalebar from the outer mapborder (if ScalePlace = false)
//distance of the scalebar from the inner mapborder (if ScalePlace = true)
var ScaleDist = 3; //pixel
//Size of the scalebar image (pixel)
var ScaleWidth = 200;
var ScaleHeight = 17;


//*************************properties for the map and the mapborder***************************
//background color for the MapLayer (BackLayer4)
var MapBackColor = MapFrameColor;
//distance for the outer border from the edge of the MapFrame
// BorderLeft = from the left, BorderTop = from the top
//... for MSIE and Netscape 6+
var BorderLeft1 = 1;
var BorderTop1 = 1;
//... for Netscape <6
var BorderLeft2 = 1;
var BorderTop2 = 1;

//size of the outer border
var BorderOutSize = 1;
//color of the outer border (BackLayer1)
var BorderOutColor = SharedColor2;
//size of the inner border (BackLayer3)
var BorderInSize = 1;
//color of the inner border(BackLayer3)
var BorderInColor = SharedColor2;
//space between inner and outer border
//(gets replaced, when JumpToolMF = true, by the sum of ButtonOutDist, ButtonInDist and
//ButtonNorthSouthWidth (see parameters for the pan buttons in the MapFrame))
var BorderGap = 20;
//color for the space between the borders(BackLayer2)
//(not visible if BorderGapImage = true)
var BorderGapColor = "#FFFFFF";
//SHould a Image be displayed in the border?
//true = yes, false = no
var BorderGapImage = false;
//path to the image
var BorderGapImageURL = imagePath1 + "bg.jpg";


//*****************properties for the directional pan*****************
//specify if JumpToolMF = true,
//if JumpToolMF = false please set: var ... = ; bzw. var ... = "";
//distance of the buttons from the outer border
var JumpMFOutDist = 3;
//distance of the buttons from the inner border
var JumpMFInDist = 3;

//path to the images (pan arrows)
var NorthURL_MF = imagePath1 + "north_MF.gif";
var SouthURL_MF = imagePath1 + "south_MF.gif";
var EastURL_MF = imagePath1 + "east_MF.gif";
var WestURL_MF = imagePath1 + "west_MF.gif";

//size of the images

//North&South
var NorthSouthWidth_MF = 15;
var NorthSouthHeight_MF = 8;
//East&West
var EastWestWidth_MF = 8;
var EastWestHeight_MF = 15;


//*************************porperties for the zoombox****************************
//bordersize
var boxLineWidth = 2;
//bordercolor
var zoomColor = "#FF0000";
//zoom factor if zoomed with a single click in the map
var zoomsize = 2;


//***********************images path (various)***************
var spacerPixelURL = imagePath2 + "px_bunt.gif";
var pxURL = imagePath1 + "px.gif";
var visibilityIconURL = imagePath1 + "sichtbar.gif";
var controlIconURL = imagePath1 + "thsteuerung.gif";
var queryURL = imagePath1+ "aktiv.gif";

//***************************Sonstige Angaben***************************
//Title (title bar) and heading for the LinkFrame
var linkTitle = "Links";

//Other labels (e.g. for error messages)
var sonstName = new Array();
sonstName[0] = "";


//********************Frame sizes***************
var KopfHeight1 = 65; //Height for SkriptFrame (= Header)
var NaviWidth1 = 199; //Width for NaviFrame
var NaviHeight1 = 350; //Height for NaviFrame
var FormWidth1 = 175; //Width for FormFrame
var FormHeight1 = 143; //Height for FormFrame
var ToolHeight1 = 82; //Width for ToolFrame


