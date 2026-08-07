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
		// padded 0.1 each side so the initial view shows a little sea/breathing room around the
		// coastline rather than it sitting right on the frame edge - same idea as the old
		// REFERENCE image's own padded EXTENT. Was 0.5 (doubling both dimensions) - fine for the
		// small IOM extent, way too generous once GB-scale extents (and the corrected, wider
		// 4-corner bounds) were in the mix - the initial viewport ended up bigger than the whole
		// country, same overshoot the overview box was showing.
		bounds = L.latLngBounds( bounds ).pad( 0.1 );
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
			? L.tileLayer( cfg.url, { minZoom: 1, maxZoom: 18, attribution: "OSM contributors" } )
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
	// IMAGE/mod_overview.tpl did. Prefers a coastline-named layer over whatever initialCfg the
	// main map picked - initialCfg can be a sparse, non-exclusive layer (e.g. meridian_2014
	// defaults to "Woodland", first in its layerList) that renders mostly transparent, which
	// looks exactly like a sizing/cropping bug but is really just correctly-empty sea/land.
	// Same idea as the coastline-silhouette reference thumbnails used elsewhere in this project.
	var overviewCfg = layersConfig.find( function( cfg ) {
		return /coast/i.test( cfg.name );
	} ) || initialCfg;

	var OverviewControl = L.Control.extend( {
		options: { position: "bottomleft" },
		onAdd: function( map ) {
			var container = L.DomUtil.create( "div", "leaflet-bar" );
			container.style.width = "150px";
			container.style.height = "{$overviewHeight}px";
			L.DomEvent.disableClickPropagation( container );
			L.DomEvent.disableScrollPropagation( container );
			setTimeout( function() {
				// force a synchronous layout pass before Leaflet measures the container -
				// setTimeout(0) alone only guarantees ordering, not that the browser has actually
				// laid out the just-set height yet. Reading offsetHeight forces it.
				void container.offsetHeight;
				var overviewMap = L.map( container, {
					attributionControl: false, zoomControl: false, dragging: false,
					scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false,
					keyboard: false, touchZoom: false,
					// fractional zoom - contain-fit must round DOWN to an available zoom to
					// guarantee the whole extent stays visible, and with only whole-number
					// zoom levels available that can throw away up to a full zoom level (2x)
					// of size versus the true ideal fit. zoomSnap:0 allows any continuous zoom.
					zoomSnap: 0
				} );
				// non-square boxes (overviewHeight != 150) need Leaflet to re-measure the
				// container - getBoundsZoom() below reads a cached size that can be stale
				// immediately after construction, seen as an exact height-delta gap.
				overviewMap.invalidateSize();
				// Plain contain-fit, not cover-fit - the box height is now hand-tuned per mapset
				// (overviewHeight) rather than a fixed square, but it's still only a rough match
				// to each extent's real aspect ratio. Cover-fit crops whichever axis doesn't
				// match exactly (Shetland/south coast were getting cut off); contain-fit accepts
				// a small margin instead but never crops the extent.
				if( mapBounds ) { overviewMap.fitBounds( L.latLngBounds( mapBounds ).pad( 0.1 ) ); } else { overviewMap.setView( [54.225, -4.575], 8 ); }
				if( overviewCfg ) { buildLayer( overviewCfg ).addTo( overviewMap ); }
				var viewportRect = L.rectangle( map.getBounds(), { color: "#d00", weight: 2, fill: false } ).addTo( overviewMap );
				map.on( "moveend", function() { viewportRect.setBounds( map.getBounds() ); } );
				overviewMap.on( "click", function( e ) { map.panTo( e.latlng ); } );
			}, 0 );
			return container;
		}
	} );
	leafletMap.addControl( new OverviewControl() );
</script>
