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
	$layersConfig[] = [
		'type'      => 'xyz',
		'url'       => str_replace( [ '${z}', '${x}', '${y}' ], [ '{z}', '{x}', '{y}' ], $matches[1] ),
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
			$sw = shell_exec( 'echo '.escapeshellarg( "$minX $minY" ).' | gdaltransform -s_srs '.escapeshellarg( "EPSG:$srcEpsg" ).' -t_srs EPSG:4326' );
			$ne = shell_exec( 'echo '.escapeshellarg( "$maxX $maxY" ).' | gdaltransform -s_srs '.escapeshellarg( "EPSG:$srcEpsg" ).' -t_srs EPSG:4326' );
			$swParts = preg_split( '/\s+/', trim( (string)$sw ) );
			$neParts = preg_split( '/\s+/', trim( (string)$ne ) );
			if( count( $swParts ) >= 2 && count( $neParts ) >= 2 ) {
				$mapBounds = [ [ (float)$swParts[1], (float)$swParts[0] ], [ (float)$neParts[1], (float)$neParts[0] ] ];
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

$gBitSystem->display( 'bitpackage:mapper/center_view_map2.tpl', NULL, array( 'display_mode' => 'display' ));
