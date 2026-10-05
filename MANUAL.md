# Mapper Package — Reference Manual

How the package actually works today. For the history of *why* — decisions, bugs found, wrong
turns — see `CLAUDE.md`'s dated session log instead; this file only tracks current behaviour.

## What this is

A MapServer-based GIS viewer, with two independent front ends sharing the same mapset-resolution
and permission layer:

- **Classic frameset** (`display_map.php`) — the original ported viewer. A real `<frameset>` of
  child iframes driving CGI requests to `/cgi-bin/mapserv`, MapServer doing all compositing
  server-side per request.
- **Leaflet viewer** (`display_map2.php`) — a modern single-page map embedded in the normal
  Bitweaver page shell, using standard XYZ/WMS tile layers.

Mapsets come from two sources, resolved through one shared function:
- **Real `Map` content objects** — uploaded `.map` files stored as Liberty attachments, with
  metadata (extent, layers, projection, etc) extracted into `liberty_xref`. The active,
  supported path.
- **The legacy registry** (`includes/mapsets_inc.php` + per-site `mapper_mapsets.php`) — a plain
  PHP array of mapset definitions, predates the `Map` content type. Still works, being phased
  out mapset-by-mapset as each one gets a real `Map` object instead.

## Classic frameset architecture

Top-level page (`display_map.php` → `html/map.html`) plus child frames (`ScriptFrame`,
`NaviFrame`, `FormFrame`, `ToolFrame`, `LinkFrame`, `MapFrame`, `LegendFrame`, `OverviewFrame`).

**Two independent JS contexts**: `scripts/param1.js` loads separately into both the top-level
page and `html/script.php` (ScriptFrame's own document) — two separate copies of its globals,
not one shared scope. Anything that needs to reach the actual map/navi logic must be set from
*inside* `script.php`, not the top-level page. `toolbar.js` and `common.js` both load directly
into `script.php` and share one plain JS scope with each other. Files in other frames
(`map_init.html`, `navi.html`, etc) reach `script.php`'s scope via `t = parent.ScriptFrame`,
then `t.<name>`.

**Frame load-choreography** — every child iframe's initial `src` points at a `_blank.html`
variant (a neutral placeholder before the server-resolved mapset config exists):
1. `map_blank.html`'s `Load1()` sets `ScriptFrame` → `parent.scriptURL` (i.e. `script.php`).
2. `script.php` resolves the mapset, then redirects `FormFrame`→`map_init.html`,
   `ToolFrame`→`tool.html`, `NaviFrame`→`navi.html`, `LinkFrame`→`link.html`.
3. `map_init.html` auto-submits a form to the MapServer CGI. The CGI response body **is**
   `html/form.html` — referenced as `WEB TEMPLATE` in the mapfile, won't turn up in a plain grep.
4. `form.html`'s `Load3()` redirects `MapFrame`→map image and `LegendFrame`→`legend.html`.

None of the 7 `_blank.html` files are dead leftovers — this whole choreography breaks if any are
removed without a replacement.

`scriptURL` and `styleURL` must both come from `html_head_inc.tpl` — `script.php`'s inline JS
reads `parent.styleURL` before its own `param1.js` has loaded; trimming to just `scriptURL`
produces a silent `href="undefined"` that 302-loops into a broken Overview/blank map, with
nothing throwing an error.

**Testing gotcha**: the browser's plain reload/refresh button mangles this choreography (looks
like a broken/stale render even with fresh code deployed) — reload via the address bar instead.
`.php`-served changes show up cleanly on next load, but plain `.html` files (`map.html`,
`navi.html`, etc) need a hard browser cache wipe.

## The `Map` content object

Plain `LibertyMime` content item (`mapper/includes/classes/Map.php`) — title + one uploaded
`.map` file, no own table. `content_id` is the sole identity. "Extra fields" that would
otherwise need an owned table go through generic `liberty_xref` rows instead:

| xref item | group | storage | meaning |
|---|---|---|---|
| `EXTENT` | `general` | `DATA` (JSON blob) | `{minX,minY,maxX,maxY}`, too long for `XKEY` |
| `SHPPATH` | `general` | `DATA` (blob) | `SHAPEPATH` as the mapfile wrote it — absolute for older maps, folder-relative (`.`, `data`) for maps following the folder rule below; too long for `XKEY` |
| `FOLDER` | `general` | `XKEY` (short) | The folder of the maps folder this map was loaded from (e.g. `osm_iom_2012`) — template `value`. Lets folder-relative paths be resolved; see *Self-contained map folders* |
| `EXCL` | `general` | `XKEY` (`'0'`/`'1'`) | Exclusive (radio-button) vs independent (checkbox) layer selection — template `value` |
| `OVERVIEWHEIGHT` | `general` | `XKEY` (int) | Per-mapset overview-box height override in `display_map2.php`; unset = 150px default — template `value` |
| `PROJECTION` | `general` | `XKEY` (short) + `XKEY_EXT` (full) | `init=epsg:27700` → `XKEY="epsg:27700"`, full original string in `XKEY_EXT(250)` — template `value` |
| `LAYER` | `layers` | `XKEY`=name, `XKEY_EXT`=name (no real alias yet), `DATA`=JSON `{type,status,group,data,visible,queryable,link}` | One row per mapfile layer, `xorder` preserves mapfile order |

`EXCL`/`OVERVIEWHEIGHT`/`PROJECTION` use the **generic `value` xref template**
(`liberty/templates/view_xref_value_item.tpl` / `edit_xref_value_item.tpl`) — value lives
directly in `XKEY` (VARCHAR(32) — short scalars only), `XKEY_EXT` (VARCHAR(250)) is a free-text
"Notes" field, no blob involved. `EXTENT`/`SHPPATH` stay on the older `text` template
(`view_xref_text_item.tpl`), value in the `DATA` blob, since both routinely exceed 32 chars.
Writing one of the `XKEY`-based items goes through `Map::upsertSingleXref(..., $pUseXkey: true)`;
the blob-based ones use the default `$pUseXkey: false`.

All three of `EXCL`/`OVERVIEWHEIGHT`/`PROJECTION` are editable two ways: through the standard
generic xref group tabs on `edit.php` (`{jstabs}` / `loadXrefInfo()`/`$gXrefInfo`, same mechanism
every other Liberty content type uses), or — for `EXCL` and `PROJECTION` — automatically at
upload/parse time from the mapfile's own content (see below). There's no dedicated form field
for any of these on `edit.php` any more; that was tried and removed once the generic xref UI
covered it.

### Upload-time parsing (`Map::parseMapFile()`)

A private, depth-tracking block parser (no external MapServer-file library) that walks a `.map`
file's own block structure — every block-opening keyword (`LAYER`, `METADATA`, `CLASS`, `STYLE`,
`LABEL`, `PROJECTION`, `WEB`, `LEGEND`, `SCALEBAR`, `REFERENCE`, ...) appears bare, alone on its
own line, in MapServer's grammar; directives always carry an inline value (`NAME foo`,
`STATUS ON`). That distinction is enough to track nesting depth without knowing MapServer's full
keyword set. Extracts: `name` (mapfile's own `NAME`), `extent`, `shapePath`, `excl` (from a
`# MAPPER: EXCL=true|false` comment, see below), `projection` (the `PROJECTION` block's own
quoted PROJ.4 string), and one entry per `LAYER` block (`name`/`type`/`status`/`group`/`data`).

**Title fallback**: if the mapfile's own `NAME` is empty or literally `"Demo"` (a real, recurring
copy-paste artifact in several source mapfiles), the uploaded filename (minus extension) is used
as the title instead.

**The `# MAPPER: EXCL=true|false` comment convention** — a namespaced, non-standard comment this
parser specifically recognises, letting `EXCL` travel with the mapfile itself rather than
depending on a per-upload form checkbox (which can't sensibly apply one value across a whole
batch-archive import of many unrelated files). Precedence when storing: the mapfile's own comment
wins if present; otherwise, on a brand new object with no existing `EXCL` row, it defaults to
`'0'` (non-exclusive/independent-checkboxes) — exclusive is opt-in, never assumed. Genuinely
exclusive (pick-one-of-several-editions) mapsets need the comment explicitly; as of writing that's
`iom_years`, `minisc_2019`/`_2026`, `omlras_gb`, `over_gb`.

**The `# MAPPER: DESCRIPTION=...` comment convention** — same idea for the description, so it lives
with the mapfile and survives a rebuild of the library. One comment line per paragraph, plain text
(escaped and wrapped in `<p>` when stored, so no HTML is needed):

    # MAPPER: DESCRIPTION=Isle of Man base map from OpenStreetMap, openstreetmap-carto style.
    # MAPPER: DESCRIPTION=Pre-rendered tiles, zoom 6-18, covering the whole island.

It fills the description only when none was supplied: a description typed on the upload or edit
form wins, and "Reload from disk" fills a blank description but never overwrites an edited one.

**Batch archive upload** (`upload_map.php`'s zip/tar.gz path, via `mapper_process_archive()`) —
one `Map` object per `.map` file found at the archive's top level, flat loop, no nesting.

**Load maps from folder** (`load_map.php`, linked from the upload page) — scans the *Maps folder*
admin setting (no default - blank switches the page off, so set it per site to only the maps that
site should see; one `<name>/<name>.map` per subfolder), lists every map not
yet imported (a Map with the same slug counts as imported) with its `DESCRIPTION`, and imports the
ticked ones through the normal `Map::store()` path, at most 10 per submit. The list is re-scanned
on every request, so it always shows what is still outstanding. Several folders can hold the same
mapfile (the `over_gb` editions), so the list offers **one entry per title** (first folder wins) —
without that, a single ticked batch loads the same map several times over. Each import records the
map's folder (the `FOLDER` record) and makes sure the site has its `storage/mapper/<folder>` link —
see *Self-contained map folders*.

### Cross-server path self-heal (`Map::fixRelativePaths()`)

A real `Map`'s mapfile references its own package assets with relative paths in the *original*
source (`SYMBOLSET`, `FONTSET`, `TEMPLATE`, `HEADER`, `FOOTER`, `EMPTY`, `IMAGE` — e.g.
`"../html/form.html"`). MapServer resolves relative paths against wherever the mapfile *physically
sits*, which for an uploaded attachment varies by `content_id` (no stable relative offset back to
the package root) — so these get rewritten to **absolute** paths anchored at `MAPPER_PKG_PATH`
instead, at store time.

That absolute path is anchored to *whichever server did the uploading* — fine until that DB and
storage state gets mirrored to a different server with a different docroot layout (desktop's
`bitweaver5`+`lsces` split vs. a real server's own `lsces` docroot). `fixRelativePaths()` is
therefore public and safe to call repeatedly — it matches either a relative `../` prefix *or* an
already-absolute `/srv/website/<any-domain>/mapper/...` prefix (any domain, including the
current one) and rewrites both to the *current* server's `MAPPER_PKG_PATH`. `resolve_mapset_inc.php`
calls it on every content_id resolve (idempotent, cheap no-op once already correct) — self-heals
across environment syncs automatically, no manual per-server fixup needed.

**What is covered now**: the same call also rewrites an absolute `SHAPEPATH` or `CONNECTION` under
another site's `storage/` onto the current site's own (`Map::siteStoragePath()`, which also
corrects a letter-case mismatch in the last folder name), normalises `IMAGEPATH` to the site's
`storage/maps/`, and resolves folder-relative paths (next section). **Still not covered**: the
`wms_onlineresource` WMS metadata string is genuinely site-specific and nothing auto-heals it —
cloning a site's `Map` attachments to stand up a new site needs it fixed by hand in every `.map`
file, or every real `Map` object silently reports the *source* site's address."

### Self-contained map folders (the folder rule)

A map's folder in the maps folder (`Maps/<name>/`) is meant to hold **everything the map needs**,
so it can be copied to another machine or site and still work:

- `<name>.map` — the mapfile
- the data it reads, or links *inside* the folder to shared data (for example `coastline.gpkg`
  linking to a shared water-polygon file) — so the folder declares what it depends on
- `tiles/reference.png` — the reference (overview) thumbnail; for tile maps `tiles/` is also the
  render cache
- `source/` — the original download, where there is one

The mapfile refers to all of this **relative to its own folder**: `SHAPEPATH "."` or `"data"`,
`CONNECTION "file.gpkg"`, `REFERENCE` … `IMAGE "tiles/reference.png"`. Package assets (symbols,
templates, fonts) keep their `../` paths, rewritten to the package as above.

**How it is resolved.** Loading a map from the folder — the load page, or *Refresh* — records the
folder in the `FOLDER` record, makes sure the site has `storage/mapper/<folder>` (a link to
`<maps folder>/<folder>`, created automatically from the *Maps folder* setting; an existing link or
directory is left alone), and rewrites the stored copy's relative `SHAPEPATH` and reference image
through that link. The link is the one path that reads the same on every machine and every site —
each machine points it at its own copy of the maps folder — so a stored copy that is synced
elsewhere still works. `SHPPATH` keeps the relative form, and the viewer's "does the data exist
here" check anchors it at the same link (`Map::recordedShapePath()`). A relative path with no folder
recorded is *unknown*, never tested against the process's working directory (which differs
between `display_map.php` and `html/script.php`).

Maps loaded before the rule have absolute paths. They keep working: another site's `storage/` path
is rewritten onto the current site's, with the case correction noted above.

**Deliberate exceptions**, shown in the archive tab as `OK (tile connector)` / `OK (multi-edition)` rather than "to fix" (`Map::folderException()`): tile maps read their GDAL
connector from a shared `data` directory (absolute `SHAPEPATH`), and the multi-edition maps
(`over_gb`, `omlras_gb`) read their editions from sibling folders.

**The Mapper Archive tab** (Mapper admin page) lists every map — the maps folder's files merged with
the loaded records — one line each: status (loaded / not loaded / not in folder), whether the record
has a description, whether the folder mapfile carries a `DESCRIPTION` comment, whether the reference
image exists *on the copy the viewer uses*, and a **Folder rule** column listing what in the folder
mapfile breaks the rule. **Refresh** replaces a loaded map's stored mapfile from its folder, keeping
the record, its description and its permissions (layers are re-read, so per-layer queryable flags
reset). **Refresh all from folder** (confirm-guarded) does that for every loaded map that has a mapfile
in the folder in one go — which also gives each map its `FOLDER` record, the key its tile cache lives under. A `.map` left in another folder under the same title (an old copy) is matched first if its
folder name sorts earlier — keep stale copies out of the maps folder (rename them to `.stale`).

**Operational notes**
- `FOLDER` must be registered as a record type on each site's database. A new install gets it from
  `admin/schema_inc.php`; an existing database needs one row, or the app never loads the record:

      INSERT INTO liberty_xref_item (item, content_type_guid, x_group, cross_ref_title, multiple,
        role_id, cross_ref_href, template, data)
      VALUES ('FOLDER', 'mapper', 'general', 'Maps Folder', 0, 3, '', 'value', NULL);

- Records are only loaded for users whose role may see the item's group, so `$this->mXrefInfo` can be
  empty for an account outside that role (the `root` login, a command-line script). Saving through
  it used to add a second copy of every record. `upsertSingleXref()` and `removeXrefItem()` therefore
  look rows up directly in the database (`xrefIdsForItem()`).
- Do not exercise a write path (a Refresh, an import) from a command-line script or as a user outside
  that role: use the web page as a normal admin account.

### `Map::expunge()`

`LibertyMime::expunge()` (the inherited default) only cleans up attachments — it deliberately
never calls `parent::expunge()`, so the actual content-deletion logic
(`LibertyContent::expunge()` — history, xrefs, permissions, favorites, the `liberty_content` row
itself) never runs on its own. `Map` overrides `expunge()` to call both halves explicitly. Reached
via `edit.php?content_id=X&delete=1`, standard `confirmDialog()`-then-`expunge()` two-step flow,
gated by the same `hasUpdatePermission()` check that already covers the whole page.

## Mapset resolution (`includes/resolve_mapset_inc.php`)

One shared function, `mapper_resolve_mapset( ?int $pContentId, string $pResolvedMapsetKey )`,
used by `display_map.php`, `display_map2.php`, `html/script.php`, and `render_tile.php` — so the
registry-merge logic and the content_id/xref lookup only exist in one place. Resolution order:

1. If `$pContentId` is given directly, skip straight to the content_id branch.
2. Otherwise, if a mapset key string was given, try it as a real `Map`'s **slug** first
   (`Map::lookupBySlug()` — see below). A match promotes it to the content_id branch.
3. If no real `Map` matched, fall back to the legacy registry array
   (`mapsets_inc.php` merged with the site's own `mapper_mapsets.php`), by key.
4. No match anywhere → `null`. Every caller can treat a `null` return as "not found" uniformly
   (`HTTP_NOT_FOUND` for a page, 404 for a tile) — no separate "explicit key given but missing"
   pre-check needed.

The content_id branch additionally gates on **"does the source actually exist on this server"**
— `SHPPATH`'s value must resolve to a real, readable directory, otherwise resolution fails (404)
even though the DB record itself is fine. This is the real-`Map` equivalent of the legacy
registry's `dataDir` field, driven by data extracted at upload time instead of a
manually-maintained registry entry.

Returns `['mapset' => [...], 'mapCgiPath' => ..., 'gdalWmsPath' => ..., 'resolvedKey' => ...,
'map' => ?Map]` — callers branch permission logic on whether `map` came back non-null
(protector-aware `$map->hasViewPermission()`/`verifyViewPermission()` for a real object, vs. the
registry's blanket `p_mapper_list_maps`/`p_mapper_view` permission check otherwise).

### Slugs

`Map::slugify( $title )` — lowercase, non-alnum runs collapsed to `_`, trimmed. Computed on the
fly from the title, not stored — simplest option while still under active development; would need
revisiting only if link stability across later title edits becomes a real concern.
`Map::lookupBySlug( $slug )` does a linear scan over every `Map`'s title (fine at this project's
real scale — dozens of mapsets, not thousands). A real `Map`'s slug is tried *before* the
registry, so it naturally supersedes a stale registry entry sharing the same name — the intended
migration path, not something to guard against.

### Naming convention

A `Map`'s title and its `.map` file's own `NAME` directive are kept identical. Both feed into
things that need a clean, stable identifier: the title drives `Map::slugify()` (→ URL slug → tile
cache directory, see above and Tile serving & caching below), and MapServer's own `NAME` is used
as an XML/HTML-safe token internally (WMS capabilities, form names) — it does **not** accept
spaces, quotes, or a leading digit, so the convention has to hold at that layer regardless of what
the DB could otherwise tolerate.

Standard: **Title Case, underscore-separated, year trailing.** `GB`, `OSM`, `RAS`, and `IOM` are
kept fully uppercase as whole tokens rather than title-cased. Examples: `Meridian_GB_2014`,
`OSM_Carto_GB`, `VectorMap_District_GB_2020`, `RAS_250_2026`, `IOM_Years`.

Renaming either the title or the `.map` `NAME` has two real costs, not just a cosmetic change:
existing links using the old slug break (`Map::lookupBySlug()` won't find the new one), and the
existing on-demand tile cache under the old slug is orphaned (not corrupted, just wasted disk
until a fresh cache builds under the new name). Worth deciding a mapset's final name before it
sees real traffic, rather than renaming casually afterward.

## Pretty URLs

All implemented as nginx rewrites (`bitweaver_rewrites.conf`, shared; plus desktop's separate
inline duplicate in its own local vhost — both need updating together, not DRY):

- `/mapper/map/<name>` → `display_map.php?mapset=<name>` (classic frameset)
- `/mapper/map2/<name>` → `display_map2.php?mapset=<name>` (Leaflet)
- `/mapper/view/<name>` → `view.php?mapset=<name>` (metadata page)

`<name>` is tried as a real `Map`'s slug first, then a legacy registry key — see resolution order
above. `list_maps.php`'s own links go straight to `view.php?content_id=X` (via
`Map::getDisplayUrlFromHash()`, preferring the slug form when the title produces a non-empty
one) rather than the generic `/index.php?content_id=` dispatcher.

## Tile serving & caching

Two independent tile mechanisms, both ultimately reached via `/tiles/...` nginx locations, and
both storing their cache in the **same shared location** —
`Maps/<mapname>/tiles/` on the archive disk (`/media3/Maps` on srv9, `/home/media1/Maps` on
desktop, `/srv/firebird/Maps` on srv10 — see `/etc/webstack/CLAUDE.md` for why srv10's differs),
reached via the uniform junction `/srv/website/rdm/maps`. One
cache per map, genuinely shared across every site — `lsces` and `rdmcloud` rendering the same
mapset/layer/tile now populate the same cache entry, not two separate copies.

### `/tiles/mapsrv/<mapset>/<layer>/{z}/{x}/{y}.png` — on-demand cache for classic mapsets

For any mapset resolved through the classic MapServer CGI path (real `Map` objects and legacy
registry entries alike, except gdalWms-backed ones — see below). `display_map2.php` emits one
Leaflet `xyz`-type tile layer per registry `layerList` entry, pointed at this URL — the *same*
client-side code path already used for renderd-backed mapsets, no separate WMS branch in the JS.

Always routes through `render_tile.php` (mapper package, needs a full kernel bootstrap — this is
the "per-site" half of the mechanism, since resolving a mapset/layer needs that site's own DB) via
fastcgi, on both a hit and a miss — **no nginx-level `try_files` fast-path**, unlike the renderd
cache below. An earlier attempt at one (checking the shared absolute path before falling back to
PHP) hit a real nginx footgun (a `root /` override needed to resolve the absolute path leaked into
`$document_root` for the PHP fallback too) and was reverted as a precaution; never actually
confirmed broken, might be worth revisiting later. For now every on-demand tile request pays the
kernel-bootstrap cost even on a cache hit, unlike `tile.php`'s genuinely stateless reads below.

On a miss, `render_tile.php` computes the tile's bbox using standard Web Mercator (EPSG:3857)
tile-grid math, invokes `/usr/bin/mapserv` directly via `proc_open()` (as a plain CGI program,
`REQUEST_METHOD=GET`/`QUERY_STRING` env vars — same technique as the documented `mode=browse`
manual-testing recipe below) with an explicit WMS 1.1.1 `GetMap` request, and — only if the
response's `Content-Type` genuinely starts `image/` (never cache an error page/XML exception as if
it were a tile) — writes the raw PNG bytes to `Maps/<folder>/tiles/<layer>/<z>/<x>/<y>.png`, where `<folder>` is the map's
recorded folder (its `FOLDER` record — see *Self-contained map folders*), so the cache sits inside the map's
own folder and is the same path on every machine. A map with no recorded folder (loaded before folders were
recorded) falls back to the title slug until it is refreshed. If
that still doesn't produce a real file (genuine render failure, or a legitimate no-data
coordinate — can't cleanly tell those apart from mapserv's output alone), returns **404, not
502** — a 502 means "upstream is down", which this isn't; 404 matches `tile.php`'s own convention
for "no tile here" and avoids reading as a real outage to any log/monitoring tooling.

**Ownership gotcha**: this cache directory needs to be writable by whichever user PHP-FPM actually
runs as (`nginx` on all three machines) — `Maps/` also holds read-only archive source data owned
differently in places (`firebird:firebird` on srv10, matching that machine's `/srv/firebird`
convenience-partition choice), and a stray `chown -R firebird:firebird` across the *whole* `Maps/`
tree will silently block every on-demand tile write, surfacing as a confusing wall of 502s with
nothing useful in any log (PHP never throws here — it's a deliberate, silent
`mkdir()`/`file_put_contents()` failure, not a crash). Worth checking ownership first if on-demand
tiles ever mysteriously stop caching.

**Known WMS gotcha**: MapServer 8.x rejects a `GetMap` request without an explicit `STYLES` param,
even an empty one (`"Missing required parameter STYLES"`) — Leaflet's own WMS layer always sends
this automatically as part of its default options, but a direct `proc_open()` call doesn't get it
for free.

**`http_build_query()` gotcha**: pass an explicit `'&'` as the third (separator) argument. Left to
the default, it uses whatever `arg_separator.output` says in `php.ini` — which can be `&amp;`,
silently truncating every param after the first as far as `mapserv`'s own CGI query parser is
concerned (it just sees one long garbled key after the first genuine `&`).

The cache directory itself is unbounded — no size cap or cleanup cron exists yet (a newer
mechanism than `storage/maps`', which does have one — see below). Worth building if it grows
enough to matter; not pre-built speculatively.

### `/tiles/<style>/{z}/{x}/{y}.png` — shared renderd/OSM tile cache

For mapsets backed by a pre-generated `renderd`/`mod_tile`-style tile cache (`osmcarto_gb`,
`osm_tiles_iom`, etc) — detected in `display_map2.php` by the presence of a `*_gdalwms.xml`
sidecar file (the same GDAL WMS/TMS connector the classic mapfile's own `DATA` directive already
points at). Served by the shared, domain-independent `/etc/webstack/tiles/tile.php` (no kernel
bootstrap — genuinely stateless, reads straight from renderd's own metatile files under
`Maps/<style>/tiles/` on disk). `display_map2.php` strips the sidecar's `<ServerUrl>` down to its
path component (`parse_url(..., PHP_URL_PATH)`) so the browser fetches same-origin rather than a
hardcoded absolute hostname that would always resolve to whichever server public DNS points at,
regardless of which server actually served the page — the XML's own `<ServerUrl>` stays absolute,
since GDAL's server-side WMS fetch (the classic `display_map.php` path) genuinely needs a real,
curl-able URL.

nginx include order matters for this one: the `/tiles/...` regex location must be defined
*before* the generic `\.(js|css|png|...)$` static-asset catch-all in the same vhost, or the
catch-all wins nginx's first-matching-regex race and 404s every tile request before `tile.php`
ever runs.

## Storage layout

- **`storage/mapper/<dataset>/`** — source raster/vector data (`SHAPEPATH`). Server-side only,
  nginx `deny all`s it (mapserv reads it directly off disk, never over HTTP). Symlinked to the
  shared `Maps/<name>/` archive per machine (`/media3/Maps/<name>` on srv9, `/home/media1/Maps/<name>`
  on desktop, `/srv/firebird/Maps/<name>` on srv10) rather than duplicated per site (renamed from
  `OS-Data` — see `/etc/webstack/CLAUDE.md` for the full migration). Each map's own
  `Maps/<name>/source/` holds the original downloaded archive it was built from, where one exists.
  A copy of the `.map` file itself also lives in each `Maps/<name>/` folder (source: each server's
  own local `/etc/webstack/mapserver/`, not git-tracked) — its presence flags a working mapset,
  its absence means raw/not-yet-built data only. A mapfile that layers several editions as
  parallel-viewable exclusive layers (`over_gb`, `omlras_gb` — same pattern as `iom_years`' year
  sheets) carries a copy in each edition's own folder, since each edition is independently a real
  map in its own right and the combined mapfile is just one way of viewing them together.
  A `storage/mapper/<folder>` link must point at the **root** of its maps-folder folder (an older
  link pointing at a `data` subfolder, or at a shared data directory, breaks the folder rule —
  see *Self-contained map folders*); new ones are created automatically when a map is loaded.
- **`storage/maps/`** — MapServer's own generated CGI output (`IMAGEPATH`/`IMAGEURL`) for the
  classic frameset path. *Is* served by nginx. Each render is a uniquely-named, never-revisited
  file — `/etc/webstack/cron.daily/mapper-maps-cleanup` deletes anything older than 2 days.
- **`Maps/<mapname>/tiles/`** — the on-demand cache described above, genuinely shared across every
  site (previously `storage/mapper_tiles/<mapset>/<layer>/`, per-site). No cleanup mechanism yet.
- **`storage/attachments/`** — standard Liberty attachment storage; a real `Map`'s uploaded
  `.map` file lives here (`<bucket>/<content_id>/<filename>.map`), gated by the generic
  `storage/attachments/` `auth_request` nginx location.

All are excluded from the `firebird-backup`/`firebird-restore` DR sync scripts
(`--exclude=maps --exclude=mapper`) — source/generated map data doesn't belong in a
database-focused DR sync, and would otherwise silently trim a site's `storage/mapper` down to
whatever the sync source happens to have.

## MapServer CGI semantics (verified empirically)

- `imgbox`/`imgxy` (drag-zoom/pan) are **pixel-space** — `mapserv` converts using `mapsize`,
  also submitted with the form. Don't pre-convert to geographic coordinates.
- `mapext` is the *target* extent; `imgext` is the *previous frame's* extent (used for
  click-to-geo math) — genuinely different fields.
- The `<!-- MapServer Template -->` magic line is required only for `WEB TEMPLATE`, `LAYER
  HEADER`/`FOOTER`/`TEMPLATE`, and `WEB EMPTY` files — not plain browser-loaded files, not
  `LEGEND TEMPLATE` (a different fragment-substitution path).
- Given a mismatched extent/`mapsize` aspect ratio, MapServer's default extent-adjustment is
  `contain`-style (expands the *looser* axis, never crops) — the classic frameset's own
  `computeCoverExtent()` in `scripts/toolbar.js` implements a `cover`-style fit on top of this
  where needed (Full Extent, initial load).
- `mode=map` CGI testing never exercises `IMAGEPATH`/`WEB TEMPLATE`/`REFERENCE` at all — only
  `mode=browse` does. A mapfile that renders fine under `mode=map` can still break
  `mode=browse`. Always test the real deploy target's actual `mode=browse` path, as the `nginx`
  user, with env vars passed explicitly through `sudo` (`-E` alone isn't enough):
  ```
  sudo -u nginx REQUEST_METHOD=GET QUERY_STRING="map=...&mode=browse&layers=..." \
    MS_MAP_PATTERN="..." mapserv
  ```
- MapServer 8.6.5 dropped MAP-level `TRANSPARENT` and WEB-level `MINSCALE`/`MAXSCALE` (replaced
  by per-`LAYER` `MAXSCALEDENOM`/`MINSCALEDENOM`) — a mapfile without per-layer scale tiers on a
  dense point/label layer can produce a solid-colour smear at whole-country zoom.
- MapServer's CGI `layers=` parameter matches against both layer *and* `GROUP` names — giving a
  layer the same name as its own group means any request naming the group re-activates every
  member layer regardless of their own individual toggle state.

## Permissions

Three permissions, checked via the standard `LibertyContent` mechanism
(`mViewContentPerm`/`mUpdateContentPerm`/`mAdminContentPerm` on `Map`, `p_mapper_list_maps`/
`p_mapper_edit`/`p_mapper_admin`). A real `Map` object is additionally
protector-aware — `Map::load()` calls `getServicesSql( 'content_load_sql_function', ..., $this )`
so per-object role restrictions set via the protector package are actually enforced (the base
`LibertyContent`/`LibertyMime` `load()` never calls this itself; only a subclass override does).

The legacy registry path has no per-object granularity — `p_mapper_list_maps` (registered users)
gates every registry mapset except the public `test` demo, which uses the more permissive
`p_mapper_view`. A bare `/mapper/display_map.php` URL (no explicit mapset/content_id) falls
back to the `test` demo for a user who lacks the registry default's permission, rather than a
login wall — an explicit `?mapset=<key>` still gets the normal permission-denied response.

## Deployment / sites

Every site-specific mapfile, plus `/etc/mapserver.conf` itself, are **not git-tracked** —
deliberately outside the public `mapper` repo (mapfiles reference private bulk map data) —
manual symlinks into `/etc/webstack/mapserver/` on every environment.
`mapserver.conf`'s `MS_MAP_PATTERN` (`^/srv/website/[^/]+/(mapper/|storage/attachments/)`)
covers desktop, the server-shared `_bw5` checkout, and any per-site symlink — widened to include
`storage/attachments/` specifically so real `Map` objects' uploaded files (which live in generic
Liberty attachment storage, not the `mapper/` package tree) can be handed straight to MapServer.

**Sites currently running mapper**, as of writing:
- **`lsces`** (desktop, srv9, srv10) — the production/DR-mirrored domain. srv10 is authoritative;
  desktop and srv9 receive it via `firebird-backup`/`firebird-restore`. srv10 only carries a
  small, deliberately cherry-picked subset of real `Map` objects and legacy registry mapsets
  (disk space is tight there); srv9 and desktop have historically carried the full archive.
- **`rdmcloud.uk`** (live on srv9 only) — a genuinely separate private cloud service, deliberately
  kept *out* of the srv10-authoritative backup chain, existing specifically so `lsces` doesn't
  have to serve two incompatible roles (clean DR mirror *and* active development/test bed) at
  once. Has its own DR topology instead (srv9-only `rdmcloud-backup` cron job, reverse direction
  from every other domain): a passive, un-enabled standby fully populated on srv10, and a real,
  accessible dev copy on desktop — see `/etc/webstack/CLAUDE.md` for the mechanics. Has its own
  isolated `storage/mapper_tiles` cache, own theme, own Firebird DB (`rdmcloud` alias in the
  shared `databases.conf`).

## Known limitations (as of writing)

- Legacy registry mapsets have no per-object permission granularity (see Permissions above).
- `storage/mapper_tiles/` has no size cap or cleanup cron.
- The generic xref `data`-blob JSON display has no dedicated template — `EXTENT`'s JSON and
  `LAYER`'s per-layer metadata both render as raw escaped JSON text via the `text` template's
  generic blob column. A `json` xref template (decode + render as a name/value list) would fix
  this for any package, not just mapper's own items.
- No "create a mapset from scratch" flow (`create.php`) — every mapset still needs an
  actually-authored `.map` file uploaded. Building one would need the DB to additionally capture
  `PROJECTION` (done), per-layer `CLASS`/`STYLE` (colour/symbol — not captured), and per-layer
  `MAXSCALEDENOM`/`MINSCALEDENOM` (not captured) at minimum; `REFERENCE`/`LEGEND` settings,
  `CONNECTIONTYPE`/`TILEINDEX` for GeoPackage/raster-tile sources would matter for a fuller
  version.
- No Print action yet (planned to match `stock`'s BOM print) — wants the JSON xref display tidied
  up first.
- `overviewHeight` currently only has a numeric-pixel form; no per-mapset aspect-ratio or
  auto-fit alternative.
- A legacy registry mapset's `mapper_mapsets.php` key and its `Maps/<name>/` archive folder name
  are independent naming spaces with nothing keeping them in sync, so a mismatch silently creates a
  second, orphaned cache folder. A real `Map` used to have the same problem — its cache was keyed on the
  title slug, which differs from the data folder's (dataset-derived) name on almost every map, so each
  machine grew a second cache-only folder next to the real one and tiles cached on one machine were never
  found on another. A `Map` with a recorded folder now caches inside that folder; one without (not yet
  refreshed) still uses the title slug. Existing title-named caches are moved across with
  `ops/mapper-cache-to-folders.py` (private notes repo), after *Refresh all* has given every map a folder.
