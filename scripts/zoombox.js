//zoombox functions

var zooming = false;
var panning = false;

var layerheight = MapHeight;
var layerwidth = MapWidth;

var z_index = 100;
var beginX;
var beginY;
var endX;
var endY;

function startBox(e) {
	getIxIy(e);
	zooming = true;
	beginX = xPosition;
	beginY = yPosition;
}

function stopBox(e) {
	if (insideMap) {
		zooming=false;
		getIxIy(e);
		parent.MapFrame.jg.clear(); 
		endX = xPosition;
		endY = yPosition;
		//calculate new mapextent
		if(beginX>endX){
			tmp = endX;
			endX =  beginX;
			beginX = tmp;
		}
		if(beginY>endY){
			tmp = endY;
			endY =  beginY;
			beginY = tmp;
		}
		//convert the pixel box into real map coordinates using the live extent
		var newExtent = pixelBoxToMapExtent(beginX, beginY, endX, endY);
		xmin = newExtent.xmin;
		ymin = newExtent.ymin;
		xmax = newExtent.xmax;
		ymax = newExtent.ymax;

		if (xmax-xmin > 10 && ymax-ymin > 10 && zoomdir==1) {
			refreshMap("box");
		}
		else {
			refreshMap("point");
		}
		return true;
	}
}