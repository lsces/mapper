<?php
/**
 * @package mapper
 *
 * Registry of selectable mapsets (mapfile + layer config), for mapsets that don't have a real
 * Map content object yet. The public package demo ('test') has been migrated to a real Map
 * (content_id 16, title 'test' - matches this key exactly, so every existing 'test' reference
 * elsewhere in mapper needed no renaming) - see Map::lookupBySlug()/mapper_resolve_mapset() -
 * so 'default' below is now that Map's own slug, not a key into 'mapsets'; resolve_mapset_inc.php
 * tries it as a slug first, same as any other mapset key, before ever consulting this array.
 *
 * Sites with their own private mapsets (private data, not published to github) extend 'mapsets'
 * via an optional /etc/webstack/domains/{site}/mapper_mapsets.php returning the same shape,
 * merged in by whichever entry point resolves the active mapset (see html/script.php). 'file' is
 * always resolved relative to MAPPER_PKG_PATH - never hardcode an absolute /srv/website/... path
 * here, that's what broke things between desktop and server deployments.
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
	'mapsets' => [],
];
