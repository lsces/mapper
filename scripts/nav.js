//pan functions

function startPan(e){
	if (insideMap) {
		mLayerImage("mapimage",posleft,postop);
		cLayer("mapimage", 0, 0, layerwidth, layerheight);
		getIxIy(e);
		psX = xPosition;
		psY = yPosition;
		poX = posleft - xPosition;
		poY = postop - yPosition;
		panning=true;
	}
}

function stopPan(e){
	if (insideMap) {
		panning = false;
		getIxIy(e);
		
		if(Math.abs(psX - xPosition) < 2 && Math.abs(psY - yPosition) < 2) {
			pan(e);
			return true;
		}
		
		var mX = -(xPosition - psX);
		var mY =   psY - yPosition;

		//convert the shifted pixel viewport into real map coordinates using the live extent
		var newExtent = pixelBoxToMapExtent(mX, mY, mX+layerwidth, mY+layerheight);
		xmin = newExtent.xmin;
		ymax = newExtent.ymax;
		xmax = newExtent.xmax;
		ymin = newExtent.ymin;
		refreshMap("box");
		return true;
	}
}

function pan(e){
	if (insideMap) {
		if (isNav6) {
			x2 = xPosition-posleft;
			y2 = yPosition-postop;
		} else {
			x2 = xPosition;
			y2 = yPosition;
		}
	}
}

function jump(direction) {
	
	switch (direction) {
	
		case "north":
			turnLayerVisible("Status");
			var jumpNorth = "0 "+ (-jumpDist).toString() +" "+ MapWidth.toString() +" "+ (MapHeight-jumpDist).toString();
			mode = "browse";
			imgbox = jumpNorth;
			writeCGIForm();		
		break;
		
		case "south":
			turnLayerVisible("Status");
			var jumpSouth = "0 "+ jumpDist.toString() +" "+ MapWidth.toString() +" "+ (MapHeight+jumpDist).toString();
			mode= "browse";
			imgbox = jumpSouth;
			writeCGIForm();			
		break;	
		
		case "east":
			turnLayerVisible("Status");
			var jumpEast = jumpDist.toString() +" 0 "+ (MapWidth+jumpDist).toString() +" "+ MapHeight.toString();
			mode = "browse";
			imgbox = jumpEast;
			writeCGIForm();	
		break;
		
		case "west":
			turnLayerVisible("Status");
			var jumpWest = (-jumpDist).toString() +" 0 "+ (MapWidth-jumpDist).toString() +" "+ MapHeight.toString();
			mode = "browse";	
			imgbox = jumpWest;
			writeCGIForm();	
		break;
	}
}	


