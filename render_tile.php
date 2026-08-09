<?php
/**
 * @package mapper
 * @subpackage functions
 *
 * On-demand tile cache for classic MapServer-backed mapsets (the non-renderd, non-XYZ-tile-
 * backed ones - meridian, opmplc, vmdvec, zoomstack, over_gb, etc). display_map2.php's Leaflet
 * layer used to hit /cgi-bin/mapserv live via a WMS GetMap request on every single pan/zoom,
 * with zero caching - MapServer re-renders the vector data from scratch each time. Leaflet's WMS
 * requests are always tile-grid-aligned (256x256, EPSG:3857 - see center_view_map2.tpl's
 * L.tileLayer.wms() call), so the response for a given z/x/y is pixel-identical to what a "real"
 * pre-rendered XYZ tile at that coordinate would be. This reshapes that into genuine z/x/y.png
 * files on disk, written once on first request and served directly by nginx thereafter (see
 * webstack's nginx/snippets/tiles.conf) - the same storage shape the OSM/renderd tile stack
 * already uses, just generated on demand from a live mapserv call instead of a pre-populated
 * renderd cache. display_map2.php points classic mapsets' layers at this via a plain 'xyz' tile
 * URL now, same as any renderd-backed mapset - no separate WMS code path needed client-side.
 */
namespace Bitweaver\Mapper;

require_once( '../kernel/includes/setup_inc.php' );
require_once( MAPPER_PKG_INCLUDE_PATH.'resolve_mapset_inc.php' );
use function Bitweaver\Mapper\mapper_resolve_mapset;

global $gBitSystem, $gBitUser;

$gBitSystem->verifyPackage( 'mapper' );

$rawMapset = ( !empty( $_GET['mapset'] ) && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['mapset'] ) ) ? $_GET['mapset'] : '';
$rawLayer  = ( !empty( $_GET['layer'] )  && preg_match( '/^[A-Za-z0-9_-]+$/', $_GET['layer'] ) )  ? $_GET['layer']  : '';
$z = isset( $_GET['z'] ) && ctype_digit( $_GET['z'] ) ? (int)$_GET['z'] : null;
$x = isset( $_GET['x'] ) && ctype_digit( $_GET['x'] ) ? (int)$_GET['x'] : null;
$y = isset( $_GET['y'] ) && ctype_digit( $_GET['y'] ) ? (int)$_GET['y'] : null;

if( $rawMapset === '' || $rawLayer === '' || $z === null || $x === null || $y === null || $z < 0 || $z > 22 ) {
	http_response_code( 400 );
	exit;
}

// Slug-or-registry-key lookup, same as display_map.php/display_map2.php - a real Map's slug is
// what display_map2.php now hands to Leaflet as the URL's mapset segment (see that file), never
// the internal 'content_<id>' resolvedKey form, so this always resolves the same way a browser
// URL typed by hand would.
$resolved = mapper_resolve_mapset( null, $rawMapset );
if( $resolved === null ) {
	http_response_code( 404 );
	exit;
}
[ 'mapset' => $mapsetInfo, 'mapCgiPath' => $mapCgiPath, 'map' => $map ] = $resolved;

if( !in_array( $rawLayer, $mapsetInfo['layerList'] ?? [], true ) ) {
	http_response_code( 404 );
	exit;
}

// Same permission boundary display_map2.php's live path already enforces - a cached tile must
// not leak content a given viewer isn't allowed to see. Only guards the cache-miss/render path,
// though: a cache HIT is a plain static file served straight by nginx (see tiles.conf), so it
// never reaches this check - accepted tradeoff of file-based caching, same as how
// storage/attachments' own access control lives at the nginx auth_request layer, not re-checked
// by PHP per file read. Cache is shared across every site (see MAPS_DIR below), so this is a
// once-only cost per mapset/layer/tile - the first site to render it benefits every other site's
// requests for the same tile too, not just its own.
$allowed = $map ? $map->hasViewPermission() : $gBitUser->hasPermission( 'bit_p_view_mapper' );
if( !$allowed ) {
	http_response_code( 403 );
	exit;
}

// Maps/ is a shared archive location (not per-site) - one cache per map, used by both this
// on-demand cache and tile.php's renderd cache alike, both nested under {mapname}/tiles/ (see
// that file's own doc comment for the full reasoning).
const MAPS_DIR = '/srv/website/rdm/maps';

$cacheDir = MAPS_DIR.'/'.$rawMapset.'/tiles/'.$rawLayer.'/'.$z.'/'.$x;
$cacheFile = $cacheDir.'/'.$y.'.png';

if( !is_file( $cacheFile ) ) {
	// Standard Web Mercator (EPSG:3857) tile-to-bbox math - the same formula behind every
	// slippy-map tile source (see e.g. the well-known GlobalMercator algorithm), matching
	// exactly what Leaflet's own WMS tile requests already compute client-side.
	$originShift = 2 * M_PI * 6378137 / 2.0;
	$tileSize = $originShift * 2 / ( 2 ** $z );
	$minX = -$originShift + $x * $tileSize;
	$maxX = -$originShift + ( $x + 1 ) * $tileSize;
	$maxY = $originShift - $y * $tileSize;
	$minY = $originShift - ( $y + 1 ) * $tileSize;

	// Invoked as a plain CGI program rather than round-tripping through /cgi-bin/mapserv over
	// HTTP - same technique already documented for mode=browse testing (see mapper/CLAUDE.md).
	// Picks up /etc/mapserver.conf's MS_MAP_PATTERN etc automatically, same as any other mapserv
	// invocation - no need to replicate that config here.
	$queryString = http_build_query( [
		'map'         => $mapCgiPath,
		'SERVICE'     => 'WMS',
		'VERSION'     => '1.1.1',
		'REQUEST'     => 'GetMap',
		'layers'      => $rawLayer,
		// WMS 1.1.1 requires STYLES even when empty - MapServer 8.x rejects GetMap without it
		// ("Missing required parameter STYLES"). Leaflet's own WMS layer sends this
		// automatically as part of its default options, which is why the old live-WMS path
		// never needed to think about it - found live, this direct proc_open call didn't get
		// it for free the same way.
		'STYLES'      => '',
		'format'      => 'image/png',
		'transparent' => 'true',
		'BBOX'        => "$minX,$minY,$maxX,$maxY",
		'WIDTH'       => 256,
		'HEIGHT'      => 256,
		'SRS'         => 'EPSG:3857',
	// Explicit '&' separator - found live: this php.ini's arg_separator.output is '&amp;',
	// which http_build_query() uses by default. mapserv's CGI query parser only ever saw the
	// first param correctly ('map=...') with every param after the first '&amp;' silently
	// dropped, so it fell back to its own default browse-mode template instead of GetMap.
	], '', '&' );

	$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
	$proc = proc_open( '/usr/bin/mapserv', $descriptors, $pipes, null, [
		'REQUEST_METHOD' => 'GET',
		'QUERY_STRING'   => $queryString,
	] );
	if( is_resource( $proc ) ) {
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );

		// mapserv's CGI output is headers + blank line + body, same as any CGI script - split on
		// whichever line-ending it actually used (seen both LF-only and CRLF from direct
		// proc_open invocation, unlike going through a webserver that normalises this).
		$sep = strpos( $output, "\r\n\r\n" );
		$sepLen = 4;
		if( $sep === false ) {
			$sep = strpos( $output, "\n\n" );
			$sepLen = 2;
		}
		$headers = $sep !== false ? substr( $output, 0, $sep ) : '';
		$body = $sep !== false ? substr( $output, $sep + $sepLen ) : '';

		// A missing/wrong WMS param (found live: MapServer 8.x rejects GetMap without an
		// explicit, even-if-empty STYLES param) comes back as a real 200 with an XML
		// ServiceException or an HTML template body instead of a PNG - caching that as if it
		// were a valid tile would poison every subsequent request for that z/x/y. Only ever
		// cache a genuine image response.
		if( $body !== '' && preg_match( '#^Content-Type:\s*image/#mi', $headers ) ) {
			if( !is_dir( $cacheDir ) ) {
				mkdir( $cacheDir, 0755, true );
			}
			file_put_contents( $cacheFile, $body );
		}
	}
}

// 404, not 502 - matches tile.php's own convention for "no tile here" (a missing renderd
// metatile entry is also 404, never 502). This path covers both a genuine render failure
// (mapserv crashed, bad WMS params) and a legitimate no-data coordinate - can't cleanly tell
// those apart from mapserv's output alone, and either way this isn't "upstream is down", which
// is what 502 actually means. A 502 here reads as a real outage to any log/monitoring tooling
// watching for it; 404 is the correct "expected, not exceptional" signal for a missing tile.
if( !is_file( $cacheFile ) ) {
	http_response_code( 404 );
	exit;
}

header( 'Content-Type: image/png' );
header( 'Cache-Control: public, max-age=604800' );
readfile( $cacheFile );
