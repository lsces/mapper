////dhtml functions

var m = parent.MapFrame;

//Maplayer and ZoomBoxLayer
// html = image, uses backgroundcolor
function createMapLayer(name, left, top, width, height, z, bgColor, visible, html) {
	var div = m.document.createElement('div');
	div.id = name;
	div.style.cssText = 'position:absolute; overflow:inherit; left:' + left + 'px; top:' + top + 'px; width:' + width + 'px; height:' + height + 'px;' + 'z-index:' + z + ';' + 'background-color:' + bgColor + ';' + 'visibility:' + (visible ? 'visible;' : 'hidden;');
	div.innerHTML = html;
	m.document.body.appendChild(div);
	cLayer(name, 0, 0, width, height);
}

// BackLayer 1, 3 and 2 (if the space between the mapborders should be filled with a color
//no html, uses backgroundcolor
function createBackLayer1(name, left, top, width, height, z, bgColor, visible) {
	var div = m.document.createElement('div');
	div.id = name;
	div.style.cssText = 'position:absolute; overflow:inherit; left:' + left + 'px; top:' + top + 'px; width:' + width + 'px; height:' + height + 'px;' + 'z-index:' + z + ';' + 'background-color:' + bgColor + ';' + 'visibility:' + (visible ? 'visible;' : 'hidden;');
	m.document.body.appendChild(div);
}

//BackLayer 2 (if the space between the mapborders should be filled with a image)
//no html, uses background (image)
function createBackLayer2(name, left, top, width, height, z, background, visible) {
	var div = m.document.createElement('div');
	div.id = name;
	div.style.cssText = 'position:absolute; overflow:inherit; left:' + left + 'px; top:' + top + 'px; width:' + width + 'px; height:' + height + 'px;' + 'z-index:' + z + ';' + 'background-image: url(' + background + ');' + 'visibility:' + (visible ? 'visible;' : 'hidden;');
	m.document.body.appendChild(div);
}

//Layer for the directional pan buttons
//html = image, no backgroundcolor
function createElseLayer(name, left, top, width, height, z, visible, html) {
	var div = m.document.createElement('div');
	div.id = name;
	div.style.cssText = 'position:absolute; overflow:inherit; left:' + left + 'px; top:' + top + 'px; width:' + width + 'px; height:' + height + 'px;' + 'z-index:' + z + ';' + 'visibility:' + (visible ? 'visible;' : 'hidden;');
	div.innerHTML = html;
	m.document.body.appendChild(div);
}


function returnLayerbyName(name) {
	var el = parent.MapFrame.document.getElementById(name);
	return el ? el.style : null;
}


function turnLayerVisible(name) {
  	var layer = returnLayerbyName(name);
  	if (layer) layer.visibility = "visible";
}


function cLayer(name, left, top, right, bottom) {
	var layer = returnLayerbyName(name);
  	if (layer) layer.clip = 'rect(' + top + ' ' +  right + ' ' + bottom + ' ' + left +')';
}


function mLayerImage(lName, thisX, thisY) {
  	var layerToMove = returnLayerbyName(lName);
	if (layerToMove) {
		layerToMove.left = thisX + "px";
		layerToMove.top  = thisY + "px";
	}
}
