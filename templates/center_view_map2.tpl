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

	// cover-fit, same idea as the old MapFrame's computeCoverExtent() (scripts/toolbar.js) -
	// zoom in past the plain contain-fit level just far enough that the extent fills the frame
	// on both axes, cropping the looser one, instead of leaving blank letterbox margins.
	function fitBoundsCover( map, bounds ) {
		bounds = L.latLngBounds( bounds );
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
	if( mapBounds ) { fitBoundsCover( leafletMap, mapBounds ); } else { leafletMap.setView( [54.225, -4.575], 10 ); }
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
				if( mapBounds ) { overviewMap.fitBounds( mapBounds ); } else { overviewMap.setView( [54.225, -4.575], 8 ); }
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
