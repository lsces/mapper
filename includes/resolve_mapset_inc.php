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
 * @param string $pResolvedMapsetKey a registry key already resolved+validated by the caller
 *   (explicit-but-invalid should have been 404'd by the caller before ever reaching here) -
 *   ignored if $pContentId is given.
 * @return array{mapset: array, mapCgiPath: string, gdalWmsPath: ?string, resolvedKey: string, map: ?Map}|null
 *   null means the content_id case failed (not found, unreadable file, or the "does the actual
 *   source exist on this server" gate below) - the registry case can't fail here since the
 *   caller is expected to have already validated $pResolvedMapsetKey exists.
 */
function mapper_resolve_mapset( ?int $pContentId, string $pResolvedMapsetKey ): ?array {
	global $gBitDb, $gBitDbName;

	if( $pContentId ) {
		$map = new Map( $pContentId );
		if( !$map->load() ) {
			return null;
		}

		$mapFilePath = $map->mInfo['map_file']['source_file'] ?? null;
		if( !$mapFilePath || !is_readable( $mapFilePath ) ) {
			return null;
		}

		$generalRows = $gBitDb->getAssoc( "SELECT `item`, `data` FROM `".BIT_DB_PREFIX."liberty_xref` WHERE `content_id` = ? AND `item` IN ('EXTENT','SHPPATH','EXCL')", [ $pContentId ] );
		$extentData = !empty( $generalRows['EXTENT'] ) ? json_decode( $generalRows['EXTENT'], true ) : null;
		$shapePath = $generalRows['SHPPATH'] ?? null;
		$exclusive = !isset( $generalRows['EXCL'] ) || $generalRows['EXCL'] === '1';

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
