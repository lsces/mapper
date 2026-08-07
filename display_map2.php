<?php
/**
 * $Header$
 *
 * Copyright ( c ) 2004 bitweaver.org
 * All Rights Reserved. See below for details and a complete list of authors.
 * Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See http://www.gnu.org/copyleft/lesser.html for details
 *
 * @package mapper
 * @subpackage functions
 *
 * display_map2 - Leaflet-based replacement for display_map, dropped straight into the normal
 * Bitweaver page shell (header/footer intact) instead of the classic MapServer CGI/frameset
 * choreography (see mapper/CLAUDE.md). Resolves any mapset from the real registry (same merge logic as
 * display_map.php / html/script.php) and builds one Leaflet layer per underlying source:
 * - XYZ tile-backed mapsets (osmcarto_iom, osm_tiles_iom, iom_2001, iom_2007_25k, ...) are
 *   detected by the presence of their *_gdalwms.xml sidecar - same file the classic mapfile's
 *   GDAL WMS/TMS connector already points at, reused here as the tile URL source of truth
 *   rather than inventing a parallel config value.
 * - Everything else is treated as a classic MapServer mapset and served as a live WMS layer
 *   per registry layerList entry - MapServer reprojects on the fly regardless of the mapfile's
 *   own PROJECTION, proven standalone via a raw WMS GetMap curl request before wiring this in.
 * `layerExclusive` (defaults true, same semantics as html/navi.html) maps directly onto
 * Leaflet's own baseLayers (radio, L.control.layers) vs overlays (checkbox) split - no new
 * concept needed, the registry already models this correctly.
 *
 * Initial view + overview box use a real per-mapset lat/lng extent (reprojected via
 * gdaltransform, correct regardless of the mapfile's own CRS), cover-fit to the frame the same
 * way the old MapFrame's computeCoverExtent() did.
 *
 * Still not done: legend and identify/tool panels, WMS support for anything the *_gdalwms.xml
 * sidecar check misses.
 */

require_once( '../kernel/includes/setup_inc.php' );
use Bitweaver\KernelTools;
use Bitweaver\HttpStatusCodes;

$gBitSystem->verifyPackage( 'mapper' );

// mapset resolution - same merge logic as display_map.php / html/script.php (see
// mapper/includes/mapsets_inc.php)
$rawMapset = ( !empty( $_GET['mapset'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['mapset'] ) ) ? $_GET['mapset'] : '';
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
if( !empty( $rawMapset ) && empty( $mapsets['mapsets'][$rawMapset] ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'The requested map could not be found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}
$resolvedMapsetKey = !empty( $rawMapset ) ? $rawMapset : $mapsets['default'];
$mapset = $mapsets['mapsets'][$resolvedMapsetKey];

$gBitSystem->setBrowserTitle( 'Map - '.$mapset['title'] );

$gBitSystem->verifyPermission( 'bit_p_view_mapper' );

$gdalWmsPath = '/etc/webstack/mapserver/data/'.$resolvedMapsetKey.'_gdalwms.xml';
$layersConfig = [];
if( is_readable( $gdalWmsPath ) && preg_match( '#<ServerUrl>(.*?)</ServerUrl>#', file_get_contents( $gdalWmsPath ), $matches ) ) {
	// Host-relative, not the XML's own absolute ServerUrl - that stays absolute for GDAL's
	// server-side fetch (display_map.php's old path, needs a real curl-able URL), but the
	// browser should fetch from whichever server actually served this page, matching
	// /cgi-bin/mapserv's existing same-origin behaviour. tiles.rdm1.uk always public-resolves
	// to srv10 regardless of origin server, which silently hid srv9's own fuller local tile
	// archive (srv9 also serving its own /tiles/ location - see webstack's snippets/tiles.conf).
	$tileUrlPath = parse_url( $matches[1], PHP_URL_PATH );
	$layersConfig[] = [
		'type'      => 'xyz',
		'url'       => str_replace( [ '${z}', '${x}', '${y}' ], [ '{z}', '{x}', '{y}' ], $tileUrlPath ),
		'name'      => $mapset['title'],
		'visible'   => true,
		'exclusive' => true,
	];
} else {
	// Classic MapServer mapset - one live WMS layer per registry layerList entry. mapCgiPath
	// reuses MAPPER_PKG_PATH, the same desktop-vs-server-resolving convention html/script.php
	// already relies on for 'file' (see mapsets_inc.php's own doc comment). 'map' is passed as
	// its own field rather than baked into a query string - some output stage between here and
	// the browser rewrites '&' to '&amp;' even with Smarty's nofilter, so no '&'-joined string
	// can survive the round trip; Leaflet's WMS layer builds the querystring client-side instead.
	$mapCgiPath = MAPPER_PKG_PATH.'map/'.$mapset['file'];
	$exclusive = !isset( $mapset['layerExclusive'] ) || $mapset['layerExclusive'];
	foreach( $mapset['layerList'] as $i => $layerName ) {
		$layersConfig[] = [
			'type'      => 'wms',
			'url'       => '/cgi-bin/mapserv',
			'map'       => $mapCgiPath,
			'layer'     => $layerName,
			'name'      => $mapset['layerAlias'][$i] ?? $layerName,
			'visible'   => !empty( $mapset['layerVisible'][$i] ),
			'exclusive' => $exclusive,
		];
	}
}
if( empty( $layersConfig ) ) {
	$gBitSystem->fatalError( KernelTools::tra( 'The requested map could not be found' ), null, null, HttpStatusCodes::HTTP_NOT_FOUND );
}

// Real per-mapset lat/lng bounds for the initial view + overview box, replacing the fixed
// IOM-centred view every mapset was using until now (fine for IOM-scoped mapsets, wrong for
// GB-wide ones like meridian_2014 - found live, not guessed). The registry's 'extent' is in
// each mapfile's own native CRS (EPSG:27700 for most, EPSG:3857 for the XYZ tile-backed ones) -
// reprojected via gdaltransform (already used throughout this project) rather than a hand-rolled
// conversion, so it's correct regardless of which CRS a given mapfile happens to use.
$mapBounds = null;
if( !empty( $mapset['extent'] ) ) {
	$mapFilePath = MAPPER_PKG_PATH.'map/'.$mapset['file'];
	$realMapFilePath = realpath( $mapFilePath );
	if( $realMapFilePath && preg_match( '/epsg:(\d+)/i', file_get_contents( $realMapFilePath ), $projMatch ) ) {
		$srcEpsg = $projMatch[1];
		$extentParts = preg_split( '/\s+/', trim( $mapset['extent'] ) );
		if( count( $extentParts ) === 4 ) {
			[ $minX, $minY, $maxX, $maxY ] = array_map( 'floatval', $extentParts );
			// Transform all 4 corners, not just SW/NE - source CRSes like British National Grid
			// are rotated relative to true north, so a perfect rectangle in the source CRS does
			// NOT project to a perfect rectangle in lat/lng. Using only 2 opposite corners can
			// under-represent the true extent on whichever side the rotation skews away from
			// them (found live: meridian_2014's NW corner projects further west than its SW
			// corner does, cropping real coastline data off one edge while leaving unearned
			// margin on the other).
			$corners = "$minX $minY\n$maxX $minY\n$maxX $maxY\n$minX $maxY\n";
			$cmd = 'echo '.escapeshellarg( trim( $corners ) ).' | gdaltransform -s_srs '.escapeshellarg( "EPSG:$srcEpsg" ).' -t_srs EPSG:4326';
			$output = shell_exec( $cmd );
			$lines = array_filter( explode( "\n", trim( (string)$output ) ) );
			$lons = $lats = [];
			foreach( $lines as $line ) {
				$parts = preg_split( '/\s+/', trim( $line ) );
				if( count( $parts ) >= 2 ) {
					$lons[] = (float)$parts[0];
					$lats[] = (float)$parts[1];
				}
			}
			if( count( $lons ) === 4 ) {
				$mapBounds = [ [ min( $lats ), min( $lons ) ], [ max( $lats ), max( $lons ) ] ];
			}
		}
	}
}

// Full-width layout, header/footer kept: $gHideModules looked like the right tool (matches
// fisheye_image_hide_modules / stock_gallery_hide_modules) but kernel/templates/html.tpl gates
// the *entire* <header> banner/nav behind it too, not just the side module columns - too blunt
// for this. Side columns are hidden with page-scoped CSS instead, in center_view_map2.tpl.

$gBitSmarty->assign( 'mapset', $resolvedMapsetKey );
$gBitSmarty->assign( 'mapsetTitle', $mapset['title'] );
$gBitSmarty->assign( 'layersConfigJson', json_encode( $layersConfig ) );
$gBitSmarty->assign( 'mapBoundsJson', json_encode( $mapBounds ) );
// Overview box height - defaults to a square 150px matching the width, but some mapsets (GB-
// scale, portrait-shaped extents) need a taller box or the bottom edge gets cropped under
// cover-fit. Static per-mapset override rather than computing it dynamically client-side -
// same idea as the old system's per-mapfile REFERENCE SIZE, avoids the stale-cached-container-
// size timing bug a dynamic JS computation hit.
$gBitSmarty->assign( 'overviewHeight', $mapset['overviewHeight'] ?? 150 );

$gBitSystem->display( 'bitpackage:mapper/center_view_map2.tpl', NULL, array( 'display_mode' => 'display' ));
