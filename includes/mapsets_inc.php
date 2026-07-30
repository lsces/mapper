<?php
/**
 * @package mapper
 *
 * Registry of selectable mapsets (mapfile + layer config). THIS IS A
 * STOPGAP - the real version should be DB-backed content objects
 * (LibertyContent, like everything else in bitweaver), tying into a
 * proper "list of maps" catalog feature (see list_maps.php, currently
 * dead scaffolding bypassed entirely by display_map.php hardcoding a
 * single map). Not built yet - deliberately deferred, this plain PHP
 * array just needs to unblock selectable mapPath today.
 *
 * Package default only has the public demo mapset - sites with their own
 * private mapsets (private data, not published to github) extend this via
 * an optional /etc/webstack/domains/{site}/mapper_mapsets.php returning
 * the same shape, merged in by whichever entry point resolves the active
 * mapset (see html/script.php). 'file' is always resolved relative to
 * MAPPER_PKG_PATH - never hardcode an absolute /srv/website/... path here,
 * that's what broke things between desktop and server deployments.
 *
 * 'layerExclusive' (optional, defaults true if omitted - see html/script.php)
 * controls whether html/navi.html renders the per-layer toggles as radio
 * buttons (true - pick one, e.g. iom's mutually-exclusive year overlays) or
 * checkboxes (false - independently combinable layers, e.g. meridian's
 * roads/water/boundaries stack).
 *
 * 'extent' must match the mapfile's own top-level EXTENT exactly - it's
 * what the toolbar's "Full Extent" button resets to (scripts/toolbar.js).
 * Used to be a single hardcoded constant in scripts/param1.js, which broke
 * as soon as a second mapfile with a different EXTENT existed - see
 * mapper/CLAUDE.md.
 */
return [
	'default' => 'test',
	'mapsets' => [
		'test' => [
			'file'             => 'test_rlp.map',
			'title'            => 'Demo (Isle of Man 1880)',
			'layerList'        => [ 'IOM1880' ],
			'layerAlias'       => [ 'Isle of Man 1880' ],
			'layerVisible'     => [ 1 ],
			'layerIsQueryable' => [ false ],
			'layerLink'        => [ 0 ],
			'layerExclusive'   => true,
			'extent'           => '213000 464300 250900 505524',
		],
	],
];
