<?php
/**
 * @package mapper
 *
 * Shared mapset resolution - used by display_map.php, html/script.php, and display_map2.php,
 * so the registry-merge logic (old mapsets_inc.php/mapper_mapsets.php) and the newer
 * content_id/xref lookup (real Map objects, see includes/classes/Map.php) each only exist in
 * one place. Both entry points still coexist - not every mapset has been migrated to a real
 * Map content object yet - see mapper/CLAUDE.md.
 *
 * Each caller keeps its own page-specific concerns around this: permission checking (the
 * content_id path is protector-aware via Map::load(), the registry path uses the blanket
 * bit_p_view_mapper/bit_p_v_map_mapper permissions - genuinely different mechanisms, not
 * something to paper over here), the "explicit key given but not found" 404 vs "no key given,
 * fall back to default" distinction, and display_map.php's own anonymous-user soft-fallback
 * to the public 'test' demo.
 */
namespace Bitweaver\Mapper;

/**
 * @param int|null $pContentId already-validated positive int, or null
 * @param string $pResolvedMapsetKey a raw, regex-whitelisted key (empty string if none given) -
 *   ignored if $pContentId is given. Tried first as a real Map's slug (Map::lookupBySlug()),
 *   then as an old registry key; empty falls back to the registry's own default. Callers don't
 *   need their own separate "explicit key given but not found" pre-check - a null return here
 *   already covers every case (no slug match, no registry match, or the content_id-specific
 *   failures below), so a caller can always just fatalError() straight off a null result.
 * @return array{mapset: array, mapCgiPath: string, gdalWmsPath: ?string, resolvedKey: string, map: ?Map}|null
 */
function mapper_resolve_mapset( ?int $pContentId, string $pResolvedMapsetKey ): ?array {
	global $gBitDb, $gBitDbName;

	if( !$pContentId && $pResolvedMapsetKey !== '' ) {
		// Try a real Map object's slug before falling through to the old registry array - see
		// Map::lookupBySlug()'s own doc comment for why this needs no explicit collision
		// handling against a same-named registry entry.
		$pContentId = Map::lookupBySlug( $pResolvedMapsetKey );
	}

	if( $pContentId ) {
		$map = new Map( $pContentId );
		if( !$map->load() ) {
			return null;
		}

		// LibertyMime::getSourceFile() - not a plain mInfo['map_file'] array key, which doesn't
		// exist (found live: 'source_file' was never a real field, so this always silently
		// returned null before, masked because every earlier test happened to go through a path
		// that never actually needed a real filesystem path - display_map.php/display_map2.php's
		// WMS layer only ever needed 'map' as a query-string value handed straight to mapserv,
		// which happened to already resolve correctly elsewhere; render_tile.php's on-demand
		// cache was the first caller to actually depend on this check succeeding).
		// getSourceFile() reads 'file_name' from whatever hash it's given, defaulting to
		// $this->mInfo (the content object's own top-level fields) when called with no
		// argument - which doesn't have 'file_name' at all, that lives one level down in the
		// per-attachment mInfo['map_file'] sub-array (see the class doc comment above).
		$mapFilePath = $map->getSourceFile( $map->mInfo['map_file'] ?? [] );
		if( !$mapFilePath || !is_readable( $mapFilePath ) ) {
			return null;
		}

		// Self-healing, not just a one-shot fix at upload time - a real Map's stored file may
		// have been uploaded on a different server (its SYMBOLSET/TEMPLATE/etc paths baked
		// absolute at that server's own MAPPER_PKG_PATH, see Map::fixRelativePaths()'s own doc
		// comment) and this DB+storage state can end up mirrored elsewhere (firebird-restore,
		// desktop <-> srv9/srv10) - cheap enough to re-run on every resolve (idempotent, no-op
		// once already correct for the current server) rather than trying to catch every sync
		// path that would otherwise need to remember to fix this up.
		if( is_writable( $mapFilePath ) ) {
			$map->fixRelativePaths( $mapFilePath );
		}

		// mapserver.conf's MS_MAP_PATTERN explicitly allows storage/attachments/ (as well as
		// the old registry's mapper/ tree) precisely so the real attachment path can be handed
		// straight to MapServer here - no symlink dance needed, see mapper/CLAUDE.md.

		// EXCL/OVERVIEWHEIGHT are short scalars living in XKEY (template 'value', see
		// Map::upsertSingleXref()'s own doc comment) - EXTENT/SHPPATH are still in the DATA blob
		// (too long for XKEY's 32-char limit), so both columns are needed here.
		$generalRows = $gBitDb->query( "SELECT `item`, `xkey`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('EXTENT','SHPPATH','EXCL','OVERVIEWHEIGHT')", [ $pContentId ] );
		$generalData = $generalXkey = [];
		if( $generalRows ) {
			foreach( $generalRows as $row ) {
				$generalData[$row['item']] = $row['data'];
				$generalXkey[$row['item']] = $row['xkey'];
			}
		}
		$extentData = !empty( $generalData['EXTENT'] ) ? json_decode( $generalData['EXTENT'], true ) : null;
		$shapePath = $generalData['SHPPATH'] ?? null;
		$exclusive = !isset( $generalXkey['EXCL'] ) || $generalXkey['EXCL'] === '1';
		$overviewHeight = !empty( $generalXkey['OVERVIEWHEIGHT'] ) ? (int)$generalXkey['OVERVIEWHEIGHT'] : null;

		// "does the source actually exist on this server" - equivalent to the old registry's
		// dataDir check, but driven by the mapfile's own SHAPEPATH (extracted at upload time)
		// instead of a manually-maintained registry field.
		if( $shapePath && !is_dir( $shapePath ) && !is_readable( $shapePath ) ) {
			return null;
		}

		$layerList = $layerAlias = $layerVisible = $layerIsQueryable = $layerLink = [];
		$gdalWmsPath = null;
		if( $layerRows = $gBitDb->query( "SELECT `xkey`, `xkey_ext`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` = 'LAYER' ORDER BY `xorder`", [ $pContentId ] ) ) {
			foreach( $layerRows as $row ) {
				$layerList[] = $row['xkey'];
				$layerAlias[] = $row['xkey_ext'] ?: $row['xkey'];
				$decoded = json_decode( $row['data'], true ) ?: [];
				$layerVisible[] = !empty( $decoded['visible'] );
				$layerIsQueryable[] = !empty( $decoded['queryable'] );
				$layerLink[] = $decoded['link'] ?? 0;
				// XYZ/GDAL-WMS-backed layers reference their tile connector directly in the
				// mapfile's own DATA directive - every XYZ mapset in this project has exactly
				// one layer, so the first (only) match settles it for the whole mapset.
				if( $gdalWmsPath === null && !empty( $decoded['data'] ) && preg_match( '/_gdalwms\.xml$/i', $decoded['data'] ) ) {
					$gdalWmsPath = '/etc/webstack/mapserver/data/'.$decoded['data'];
				}
			}
		}

		return [
			'mapset' => [
				'title'            => $map->getTitle(),
				'layerList'        => $layerList,
				'layerAlias'       => $layerAlias,
				'layerVisible'     => $layerVisible,
				'layerIsQueryable' => $layerIsQueryable,
				'layerLink'        => $layerLink,
				'layerExclusive'   => $exclusive,
				'extent'           => $extentData ? "{$extentData['minX']} {$extentData['minY']} {$extentData['maxX']} {$extentData['maxY']}" : null,
				'overviewHeight'   => $overviewHeight,
			],
			'mapCgiPath'  => $mapFilePath,
			'gdalWmsPath' => $gdalWmsPath,
			'resolvedKey' => 'content_'.$pContentId,
			'map'         => $map,
		];
	}

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
	$resolvedKey = $pResolvedMapsetKey !== '' ? $pResolvedMapsetKey : $mapsets['default'];
	if( empty( $mapsets['mapsets'][$resolvedKey] ) ) {
		return null;
	}
	$mapset = $mapsets['mapsets'][$resolvedKey];

	return [
		'mapset'      => $mapset,
		'mapCgiPath'  => MAPPER_PKG_PATH.'map/'.$mapset['file'],
		'gdalWmsPath' => '/etc/webstack/mapserver/data/'.$resolvedKey.'_gdalwms.xml',
		'resolvedKey' => $resolvedKey,
		'map'         => null,
	];
}
