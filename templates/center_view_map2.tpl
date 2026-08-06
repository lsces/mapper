{* $Header$ *}
<link rel="stylesheet" href="{$smarty.const.MAPPER_PKG_URL}styles/leaflet.css">
<style type="text/css">
/* full-bleed map - keep the site header/footer, drop the left/right module columns and BS3
   grid gutters that would otherwise inset the map. $gHideModules also suppresses <header>
   (see kernel/templates/html.tpl) so this is done in page-scoped CSS instead. */
#bw-main-content.container-fluid,
#bw-main-content > .row { padding-left: 0; padding-right: 0; margin-left: 0; margin-right: 0; }
#navigation, #extra { display: none; }
#wrapper { width: 100%; padding-left: 0; padding-right: 0; float: none; }
#leafletMap { height: 80vh; width: 100%; }
</style>

<div class="floaticon">{bithelp}</div>

<div class="display map">
	<div class="header">
		<h1>{tr}Map{/tr} - {$mapsetTitle|escape}</h1>
	</div>
	<div id="leafletMap"></div>
</div>

<script src="{$smarty.const.MAPPER_PKG_URL}scripts/leaflet.js"></script>
<script>
	var layersConfig = {$layersConfigJson nofilter};
	var mapBounds = {$mapBoundsJson nofilter};

	// Fit to width, then centre vertically - matches the old display_map.php's look (full width,
	// vertically centred) more closely than either a 2-axis cover-fit (too zoomed in on a wide
	// full-bleed viewport - it was cropping more of the island out the wider the screen) or a
	// plain contain-fit (leaves blank side margins whenever height is the binding axis). Zoom is
	// picked purely from matching the extent's real-world width to the container's pixel width;
	// height is never part of the decision.
	function fitBoundsWidth( map, bounds ) {
		// padded 0.5 each side (doubles both width and height) so the initial view shows some
		// sea around the island rather than the coastline sitting right on the frame edge - same
		// idea as the old REFERENCE image's own padded EXTENT.
		bounds = L.latLngBounds( bounds ).pad( 0.5 );
		var containerWidth = map.getSize().x;
		var zoom = map.getBoundsZoom( bounds );
		while( zoom < map.getMaxZoom() ) {
			var nw = map.project( bounds.getNorthWest(), zoom );
			var se = map.project( bounds.getSouthEast(), zoom );
			if( Math.abs( se.x - nw.x ) >= containerWidth ) { break; }
			zoom++;
		}
		map.setView( bounds.getCenter(), zoom );
	}

	// 2-axis cover-fit (fills both width and height, cropping the looser axis) - right for a
	// small fixed-size box like the overview, same job the old computeCoverExtent() did for the
	// old fixed-size MapFrame. Wrong for the full-bleed main map (see fitBoundsWidth above) -
	// the overview never resizes with the viewport, so this doesn't have that problem.
	function fitBoundsCover( map, bounds ) {
		bounds = L.latLngBounds( bounds ).pad( 0.5 );
		var size = map.getSize();
		var zoom = map.getBoundsZoom( bounds );
		while( zoom < map.getMaxZoom() ) {
			var nw = map.project( bounds.getNorthWest(), zoom );
			var se = map.project( bounds.getSouthEast(), zoom );
			if( Math.abs( se.x - nw.x ) >= size.x && Math.abs( se.y - nw.y ) >= size.y ) { break; }
			zoom++;
		}
		map.setView( bounds.getCenter(), zoom );
	}

	var leafletMap = L.map( "leafletMap" );
	if( mapBounds ) { fitBoundsWidth( leafletMap, mapBounds ); } else { leafletMap.setView( [54.225, -4.575], 10 ); }
	var baseLayers = {ldelim}{rdelim};
	var overlays = {ldelim}{rdelim};
	var initialBase = null;
	var initialCfg = null;

	function buildLayer( cfg ) {
		return ( cfg.type === "xyz" )
			? L.tileLayer( cfg.url, { minZoom: 6, maxZoom: 18, attribution: "OSM contributors" } )
			: L.tileLayer.wms( cfg.url, { map: cfg.map, SERVICE: "WMS", VERSION: "1.1.1", REQUEST: "GetMap", layers: cfg.layer, format: "image/png", transparent: true, attribution: "LSCES" } );
	}

	layersConfig.forEach( function( cfg ) {
		var layer = buildLayer( cfg );
		if( cfg.exclusive ) {
			baseLayers[cfg.name] = layer;
			if( cfg.visible ) { initialBase = layer; initialCfg = cfg; }
		} else {
			overlays[cfg.name] = layer;
			if( cfg.visible ) { layer.addTo( leafletMap ); }
		}
	} );

	var initialLayer = initialBase || Object.values( baseLayers )[0];
	if( initialLayer ) { initialLayer.addTo( leafletMap ); }
	if( !initialCfg ) { initialCfg = layersConfig[0]; }
	L.control.layers( baseLayers, overlays ).addTo( leafletMap );

	// Overview box - fixed small inset showing the whole mapset extent with a rectangle for the
	// current viewport, click to recentre. Genuinely useful here (unlike osm.org's planet-scale
	// map) since every mapset covers a small, bounded area - same job the old REFERENCE
	// IMAGE/mod_overview.tpl did.
	var OverviewControl = L.Control.extend( {
		options: { position: "bottomleft" },
		onAdd: function( map ) {
			var container = L.DomUtil.create( "div", "leaflet-bar" );
			container.style.width = "150px";
			container.style.height = "150px";
			L.DomEvent.disableClickPropagation( container );
			L.DomEvent.disableScrollPropagation( container );
			setTimeout( function() {
				var overviewMap = L.map( container, {
					attributionControl: false, zoomControl: false, dragging: false,
					scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false,
					keyboard: false, touchZoom: false
				} );
				if( mapBounds ) { fitBoundsCover( overviewMap, mapBounds ); } else { overviewMap.setView( [54.225, -4.575], 8 ); }
				if( initialCfg ) { buildLayer( initialCfg ).addTo( overviewMap ); }
				var viewportRect = L.rectangle( map.getBounds(), { color: "#d00", weight: 2, fill: false } ).addTo( overviewMap );
				map.on( "moveend", function() { viewportRect.setBounds( map.getBounds() ); } );
				overviewMap.on( "click", function( e ) { map.panTo( e.latlng ); } );
			}, 0 );
			return container;
		}
	} );
	leafletMap.addControl( new OverviewControl() );
</script>
