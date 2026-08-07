# Mapper Package — Developer Notes

MapServer-based GIS viewer, ported from a pre-PHP7 codebase (2026-07-28/29). Stock MapServer
JS frameset client (`html/map.html` + child frames) driving CGI requests to `/cgi-bin/mapserv`.

## Frame architecture — two independent JS contexts
The viewer is a classic frameset: top-level page (`display_map.php` → `map.html`) plus child
frames (`ScriptFrame`, `NaviFrame`, `FormFrame`, `ToolFrame`, `LinkFrame`, `MapFrame`, etc).
**`scripts/param1.js` loads independently in both the top-level page and `html/script.php`
(ScriptFrame's own document)** — two separate copies of its globals, not one shared context.
Anything that needs to reach the actual map/navi logic must be set from inside `script.php`,
not from the top-level page — see `[[project_mapper_osrm_revival]]` if this trips anyone up.

`toolbar.js` and `common.js` both load directly into `script.php` (ScriptFrame's own document) —
they share one plain JS scope, no cross-frame access needed between them. Files in *other*
frames (`map_init.html`, `navi.html`, etc) reach these via `t = parent.ScriptFrame` then `t.<name>`.

## `document.write()` elimination — done 2026-07-29
The original codebase built nearly every page in `html/`, `theme/noFeature.html`, and
`html/script.php` via `document.write()`/`writeln()` — risking the "unbalanced tree"
speculative-parser discard/reparse (a real, reproduced bug on `script.php`, not just theoretical).
All converted to `createElement`/`appendChild`/`insertAdjacentHTML`. One real bug found: `navi.html`'s
`<form name="navi">` opened *mid-table* rather than wrapping it — only "worked" before via a legacy
backward-compat parsing quirk that `insertAdjacentHTML` doesn't trigger. Fixed by properly wrapping.

`scripts/form.js`'s `writeCGIForm()` (the package's hottest code path — every pan/zoom/click) was
**deliberately left as-is** — it runs on an already-loaded document (implicit `document.open()`
replacement), not mid-network-parse, so it doesn't have the hazard this cleanup targeted.

## Frame load-choreography chain
Every child iframe's *initial* `src` points at a `_blank.html` variant — a neutral placeholder
before the server-resolved mapset config exists. Verified reachable end-to-end:
1. `map_blank.html`'s `Load1()` sets `ScriptFrame` → `parent.scriptURL` (i.e. `script.php`).
2. `script.php` resolves the mapset, then redirects `FormFrame`→`map_init.html`,
   `ToolFrame`→`tool.html`, `NaviFrame`→`navi.html`, `LinkFrame`→`link.html`.
3. `map_init.html` auto-submits a form to the MapServer CGI. The CGI response body **is**
   `html/form.html` — referenced as `WEB TEMPLATE` in `map/*.map`, won't turn up in a plain grep.
4. `form.html`'s `Load3()` redirects `MapFrame`→map image and `LegendFrame`→`legend.html`.

None of the 7 `_blank.html` files are dead leftovers — don't remove any without replacing this
whole choreography.

**Testing gotchas**: the browser's plain reload/refresh button mangles this choreography (looks
like a broken/stale render even with fresh code deployed) — reload via the address bar instead.
Also: `.php`-served changes show up cleanly on next load, but plain `.html` files (`map.html`,
`navi.html`, etc) need a hard browser cache wipe — check which type a "fix isn't showing" is
before assuming the deploy failed.

## theme/ and modules/ — also fully reachable
- **`theme/` (5 files)** — referenced by `map/*.map` via MapServer's own `TEMPLATE`/`HEADER`/
  `FOOTER`/`EMPTY` directives, genuinely can't be ordinary Smarty `.tpl`.
- **`modules/` (5 files)** — follow the standard Bitweaver `{bitmodule}` convention (gated by
  `$modMap`, unconditionally `true` in `display_map.php`).

## Selectable mapsets (script.php / mapsets_inc.php)
`html/script.php` resolves which map to load, server-side, before `param1.js` runs:
1. Reads `includes/mapsets_inc.php` — package-default registry (public; just `test` demo).
2. Merges in `/etc/webstack/domains/{$gBitDbName}/mapper_mapsets.php` if present — private,
   site-specific extension. Keyed off `$gBitDbName` (resolves identically desktop vs servers).
3. Resolves `?mapset=` against the merged registry, falling back to the resolved default.
4. Emits `mapPfad`/`layerList`/`layerAlias`/`layerVisible`/`layerIsQueryable`/`layerLink`/
   `fullExtent` as inline `json_encode()` vars. `param1.js` no longer declares any of these itself.

Deliberate stopgap, not the intended end state — the real fix is a DB-backed map catalog
(`LibertyContent` objects), not built yet.

## `scriptURL`/`styleURL` — must both come from `html_head_inc.tpl`
Both required — `script.php`'s inline JS reads `parent.styleURL` before its own `param1.js` has
loaded. Trimming to just `scriptURL` produces a silent `href="undefined"` that 302-loops into a
broken Overview/blank map, easy to misread as caching/mapset bug since nothing throws.

## MAPPER_PKG_PATH / MAPPER_PKG_URL
Auto-defined by `BitSystem::registerPackage()` as `BIT_ROOT_PATH . basename($path)` — always
environment-correct with zero extra plumbing. No `env.js` file needed.

## storage/maps vs storage/mapper — different rules
- `storage/mapper/` — source raster/vector data (`SHAPEPATH`). Server-side only, nginx `deny all`s it.
- `storage/maps/` — mapserv-generated output tiles (`IMAGEPATH`/`IMAGEURL`). **Is** served by
  nginx. Desktop's flat checkout needs `storage/maps → {domain}/storage/maps`, handled by
  `switch-site`.
- Both are excluded from the nightly `firebird-backup`/`firebird-restore` DR sync — see the
  DR-sync section below for why `mapper` needed adding the hard way.

## OS data library — storage tiering policy (decided 2026-07-30)
See `[[project_mapper_genealogy_goal]]` in memory for the full dataset list. Three-tier policy:
- **`srv9:/media3/OS-Data/`** — the full archive, every dataset. Not a live serving path.
- **`storage/mapper/<dataset>/`** (real per-site storage) — reserved for datasets wired into a
  *kept* mapset. Symlink to the archive while a dataset is still being evaluated; promote to a
  real copy only once kept.
- **srv10** gets only whichever datasets are actually kept — never the speculative full library.
- **Desktop follows the same pattern**: `/home/media1/OS-Data/` is desktop's equivalent of srv9's
  `/media3/OS-Data/`, with `storage/mapper/<name>` symlinked in.

## Mapfile / mapserver.conf — not git-tracked, manual per environment
Every site-specific mapfile plus `/etc/mapserver.conf` are manual symlinks into
`/etc/webstack/mapserver/` — deliberately outside the public `mapper` repo (mapfiles reference
private bulk map data). Must be recreated by hand on every new environment.
`mapserver.conf`'s `MS_MAP_PATTERN` (`^/srv/website/[^/]+/mapper/`) covers desktop, server-shared
(`_bw5`), and any per-site symlink.

## Bulk map data — never git-tracked
`data/` and `dataIOM/` are `.gitignore`'d, same as `storage/attachments`. A small curated public
subset (readme/license text, index shapefiles, one public IOM1880 raster) stays tracked.

## MapServer CGI semantics (verify empirically, don't assume)
- `imgbox`/`imgxy` (drag-zoom/pan) are **pixel-space** — `mapserv` converts using `mapsize` also
  submitted with the form. Don't pre-convert to geographic coordinates ("nothing moves", no error).
- `mapext` is the *target* extent; `imgext` is the *previous frame's* extent (click-to-geo math) —
  a different field, confirmed the hard way via a render that silently ignored `imgext`.
- The `<!-- MapServer Template -->` magic line is required only for `WEB TEMPLATE`, `LAYER
  HEADER`/`FOOTER`/`TEMPLATE`, and `WEB EMPTY` files — not plain browser-loaded files, not
  `LEGEND TEMPLATE` (different fragment-substitution path).
- Given a mismatched extent/`mapsize` aspect ratio, MapServer's default is `contain`-style —
  expands the *looser* axis, never crops. See the cover-fit section below for why that's
  sometimes the wrong default here.
- `mode=map` CGI testing never exercises `IMAGEPATH`/`WEB TEMPLATE`/`REFERENCE` at all — only
  `mode=browse` does. A mapfile that renders fine under `mode=map` on desktop can still break
  `mode=browse` on a real server. Always test the real deploy target's full `mode=browse` path,
  as the `nginx` user (`sudo -u nginx REQUEST_METHOD=... QUERY_STRING=... MS_MAP_PATTERN=...
  mapserv` — env vars must be passed explicitly through `sudo`, `-E` alone isn't enough).

## Meridian vector mapset (`meridian_2014`/`_2016`) — wired up 2026-07-30
GB-wide vector (roads by class, rail, settlements, boundaries, lakes, woodland, coastline,
rivers). Layers: `woodland_region`, `lake_region`, `county_region` (off), `coast_ln_polyline`,
`river_polyline` (off), `rail_ln_polyline` (off), `motorway_polyline`, `a_road_polyline`,
`b_road_polyline` (off), `minor_rd_polyline` (off), `settlemt_point`, `station_point` (off). The
33k-record cartotext label layer was deferred.

Two MapServer 8.6.5 compatibility gotchas, relevant to any future mapfile: `MAP`-level
`TRANSPARENT` is no longer valid, and `WEB`-level `MINSCALE`/`MAXSCALE` were removed in favour of
per-`LAYER` `MAXSCALEDENOM`/`MINSCALEDENOM`. `settlemt_point`/`station_point` needed
`MAXSCALEDENOM 300000` — without it, whole-GB rendering of all 23,868 points produces a solid
black smear.

Data provenance: no download-date metadata, but all 4,853 files share a tight mtime window
(5 June 2014) — reflected as "circa 2014" in the title, not stated as fact. A 2016 edition was
added as a *second*, separate mapset rather than replacing 2014 — see
`[[feedback_mapper_historic_no_replace]]`.

## Bitweaver-module vs. raw-frameset dimension mismatch — found + fixed 2026-07-30
Original mapper HTML assumed one hand-tuned mapset (`iom`, near-square, 5 layers). Adding
`meridian` (tall portrait, 12 layers) exposed hardcoded iframe heights in `mod_navi.tpl`/
`mod_overview.tpl`. Fixed both: JS now sets `window.frameElement.style.height` to
`document.body.scrollHeight` once real content loads, so the iframe always fits the active mapset.

**Deliberately did NOT make the Overview reference image CSS-responsive** — MapServer's
click-to-recenter reads click x/y in the image's *rendered* pixel space and assumes it matches
`REFERENCE SIZE` exactly; any CSS rescaling breaks that mapping silently. A `width:100%` attempt
was reverted for this reason.

**Still open**: visibly unused horizontal space in the Overview box on wider layouts, since
`meridian.map`'s `REFERENCE SIZE 100 217` is narrower than the sidebar allows. Needs the real
column width measured first (no Chrome available on desktop for automation, see
`[[feedback_no_chrome]]`).

## OS Data library — mapsets built, 2026-07-31 to 2026-08-02
All follow the same pattern (mapfile in `/etc/webstack/mapserver/`, data in
`storage/mapper/<dataset>/`, `mapper_mapsets.php`'s `dataDir` gate — `is_dir()`-filtered at
request time, so one registry deploys identically to srv9's full archive and srv10's
cherry-picked subset):
- `minisc_2019`/`_2026` — OS MiniScale, 5 mutually-exclusive raster styles. 2026 is a pure clone
  of 2019.
- `opmplc_2020`/`_2026`, `vmdvec_2020`/`_2026` — OS Open Map Local / VectorMap District,
  GeoPackage vector, `CONNECTIONTYPE OGR` straight into the `.gpkg`. 7 curated "core" layers
  (`Building` alone is 14.3M features nationwide) with empirically-chosen `MAXSCALEDENOM` tiers
  (all sub-1.5s, even whole-GB). 2020/2026 editions use different layer-name casing internally
  (`Building` vs `building`) — confirmed per-edition via `ogrinfo`, not assumed.
- `over_gb` — both editions (2014/2026) *and* both styles combined into **one** mapset as four
  `layerExclusive` layers (matches the `iom` year-layer pattern).
- `zoomstack_2026` — OS Open Zoomstack, all 21 layers, using its own built-in tiering.
- `ras250_2026` — OS 1:250,000 Colour Raster. 56 tiles, clean embedded GeoTIFF georeferencing, a
  `gdaltindex` TILEINDEX built in seconds.
- `omlras_gb` — OS Open Map Local *raster*, both 2020/2026 editions as exclusive layers (matches
  `over_gb`). 10,591 tiles/edition, 1m/pixel — genuinely detailed (individual buildings, field
  boundaries visible). The `gdaltindex` build itself is a non-issue (~3-4 min, mostly I/O) — the
  real finding was **whole-GB rendering timing out (2min+)**: every tile intersects a whole-GB
  view and must be opened individually, unlike vector layers which scale-cull by feature density —
  no raster equivalent of `MAXSCALEDENOM` culling exists for a TILEINDEX. Default `extent` is a
  small ~15km box (renders <1s) centred on Evesham, matching the site's contact-us map location.
  Deployed to **srv9 only** (30GB/edition) — matches the "srv10 only gets kept/cherry-picked
  data" policy.

**TILEINDEX gotcha, hit twice**: tile paths in a `.shp` built by `gdaltindex` resolve relative to
the mapfile's `SHAPEPATH`, not relative to wherever the `.shp` itself lives. Works fine if the
index sits alongside its tiles in a single flat folder (`ras250_2026`) — but when multiple
editions share one `SHAPEPATH` in the same mapfile (`omlras_gb`), the index needs the full
relative subfolder path baked in (build `gdaltindex` from the `storage/mapper` level referencing
`<dataset>/data/*/*.tif`, not from within each dataset's own `data/` folder).

## Full Extent cover-fit + per-mapset extent audit, 2026-08-01/02
`scripts/param1.js` originally hardcoded a single IOM-box `fullExtent`, shared across every
mapset — broke instantly once a second mapset existed. Fixed with a per-mapset `'extent'` key in
`mapsets_inc.php`/`mapper_mapsets.php`, emitted by `script.php`.

That alone wasn't enough: MapServer's own extent-adjustment is `contain`-style (expands the
*looser* axis, never crops) — a portrait GB extent in the landscape `MapFrame` rendered as a thin
populated strip surrounded by huge blank margins. Added `computeCoverExtent(extentStr,
frameWidth, frameHeight)` to `scripts/toolbar.js` — `cover`-style fit instead (`scale =
min(scaleX, scaleY)`, crop-and-centre the looser axis, same idea as CSS `object-fit:cover`).
Wired into the `fullextent` case in `setTool()` and into `map_init.html`'s initial CGI submission
(previously sent no `mapext` at all), so opening a map now behaves as if Full Extent had already
been clicked. Deliberately axis-agnostic — whichever axis is tighter for a given mapset/frame
becomes the fill axis.

**Audited every mapset's configured `extent` against real data** (`gdalinfo`/`ogrinfo`), since
this value now actually matters for rendering, not just documentation. `opmplc_2020`/`_2026` and
`vmdvec_2020` had all been copy-pasted from meridian's exact extent string — implausible as
independently-derived bounds across three different products, confirmed wrong; their real shared
bounds are `0 0 660000 1230000`. `vmdvec_2026` and `zoomstack_2026` each have their own genuine,
tighter coastline-hugging box. Everything else (`iom`, `meridian`, `minisc`, `over_gb`) was
already correct — `minisc`'s `0 0 700000 1300000` and `over_gb`'s wide box both turned out to be
real data bounds, not placeholders, despite looking suspicious at first glance. Fixed in both
`mapper_mapsets.php` and each mapfile's own `EXTENT`, deployed to both servers.

## display_map.php — mapset resolution bug, found + fixed, 2026-07-31
Resolved a fallback mapset key for the page **title** but passed the **raw, unvalidated**
`$_GET['mapset']` to the `scriptURL`-feeding variable. Since `script.php` is the JS *engine* (not
just content), a stale key broke the whole frame choreography rather than falling back
gracefully. Fixed by resolving+validating once in `display_map.php` and passing the *resolved*
key downstream; an unresolvable explicit `?mapset=` now gets `HTTP_NOT_FOUND` before any iframe
is created. **Lesson**: `script.php` isn't ordinary content — fix belongs one layer up, in
whatever assigns its inputs, not by intercepting `script.php` itself.

## mapper permissions — never actually wired up, found + fixed, 2026-07-31
5 permissions were declared in `admin/schema_inc.php` but never `verifyPermission()`-checked
anywhere, and never synced into the live database — the entire module, including the public
`test` demo, was open to anonymous users on dev and both real servers. Fixed:
`display_map.php` now calls `verifyPermission()`, bare URLs fall back to `test` rather than a
login wall. **The missing DB rows were the real fix, not the code** — the Bitweaver admin
installer's own permission-cleanup stage handles this; see
`[[feedback_installer_permission_cleanup]]` before hand-writing permission SQL again.

## test_rlp.map / OS250.map — symlink-vs-real-location gotcha, 2026-07-31
`test_rlp.map` (public demo) had `SHAPEPATH`/`IMAGEPATH` hardcoded to desktop's path — worked on
desktop by coincidence, broke on servers (never caught before the permission gap above meant
nobody could reach it live).

**Root cause is a real architectural conflict**: every site's `mapper/` is a **symlink** to the
shared `_bw5/mapper` checkout. MapServer resolves a mapfile's *relative* paths against that real,
symlink-followed location — never the apparent per-site path used to reach it. Every other
mapfile hardcodes an absolute, site-specific path for exactly this reason; `test_rlp.map` can't
do that and stay the portable public demo. Fixed with `SHAPEPATH` → relative `"."` plus `git
update-index --skip-worktree map/test_rlp.map` on each server, so each server carries a
local-only hardcoded `IMAGEPATH` that `git pull` never fights.

**`skip-worktree` + an incoming upstream change to the same file aborts the whole pull** (hit
2026-08-02, `server-pull-all.sh mapper` failed on both servers with "local changes would be
overwritten"). Fix: `git update-index --no-skip-worktree`, `git checkout --` the file, pull
normally, manually reapply the local `IMAGEPATH` override, `git update-index --skip-worktree`
again.

`OS250.map` had the same path bugs plus invalid syntax plus tile data that was never actually
downloaded — removed entirely, superseded by `minisc_2019`/`minisc_2026`.

## MapFrame scrollbar / clipped pan-arrows — found + fixed, 2026-08-01
A vertical scrollbar inside `MapFrame` was clipping the pan-arrow overlay. Several plausible
causes were ruled out empirically (frame sizing, mapfile `SIZE` mismatch, `common.js` layer
math) before finding the real one: classic CSS gotcha — `<img>` is inline by default, so
injected images get a few px of "phantom" baseline space unless given `display:block`, and
`scripts/layer.js`'s layer-creation functions used `overflow:inherit` rather than
`overflow:hidden`, letting that space bleed out and accumulate into a real scrollbar. Fixed with
both `overflow:hidden` (layer.js) *and* `display:block` on every injected `<img>` in `map.html` —
`overflow:hidden` alone clipped the 8px-tall North/South pan buttons almost entirely, since the
same offset was now consuming most of their height instead of spilling past the page edge.
`MapFrame`'s fixed `731px` height was correct all along, no dynamic resize needed.

## storage/mapper DR-sync data loss + relocation, 2026-08-01/02
`firebird-backup`'s nightly `rsync --delete --exclude=maps` (srv10→desktop) excluded the
ephemeral tile-output dir but never the *source data* dir, so it silently trimmed desktop's
`storage/mapper` down to match srv10's cherry-picked subset every night — desktop lost 7 of 12
wired datasets before this was caught. `firebird-restore` (srv10→srv9) had the same gap, worse
(no excludes at all), but hadn't bitten since it's manual-only and most of srv9's entries are
symlinks into the archive rather than real copies. Fixed both scripts (`--exclude=maps
--exclude=mapper`), deployed to both servers.

Used the recovery as an opportunity to move the whole OS-Data library off desktop's root disk
(was 81% full) onto `/home/media1` (matching srv9's `/media3` tiering pattern — see the storage
tiering policy above). `lsces/storage/mapper/<name>` is symlinked into `/home/media1/OS-Data/<name>`
for every dataset (every mapfile's `SHAPEPATH` hardcodes the `lsces` path, so these symlinks are
functionally required). `bitweaver5/storage/mapper` symlinks **directly** to `/home/media1/OS-Data`
(not through `lsces` — mapper's data has effectively become site-independent). Symlink target
names don't always match the mapfile-facing name (e.g. `meridian_2016` → `merid2_essh_gb_2016`) —
always check the mapfile's own `SHAPEPATH` for the exact expected subfolder name.

`storage/maps/` (generated tile cache) also had no cleanup mechanism — added
`/etc/webstack/cron.daily/mapper-maps-cleanup`, deletes files older than 2 days (each render is
uniquely-named and never revisited, so short retention is safe).

## srv9 storage/mapper — meridian_2014/iom_years converted to symlinks, 2026-08-03
Found two lingering physical directories under srv9's `storage/mapper/` — `meridian_2014` and
`iom_years` — left over from before the archive-symlink pattern above was established; every
other mapset there was already correctly symlinked, and desktop's `storage/mapper` was already
symlinks throughout. Verified byte-identical (`diff -rq`/`md5sum`) before touching anything:
- `meridian_2014` duplicated data already archived under `/media3/OS-Data/merid2_essh_gb_2014`
  (same naming-mismatch pattern as `meridian_2016` above — accepted as-is, not worth unifying
  across the whole set) — just needed the physical copy removed and a symlink added.
- `iom_years` had no archive copy on srv9 at all, only on desktop's `/home/media1/OS-Data/`.
  Copied into **both** `/media3/OS-Data/` and `/media4/OS-Data/` (srv9's two independent OS-Data
  backup disks — confirmed genuinely separate via `df`/`mount`, not one symlinked to the other)
  for the same redundancy the meridian sets already had, verified both copies byte-identical,
  then symlinked `storage/mapper/iom_years` → `/media3/OS-Data/iom_years`.

srv9's `storage/mapper/` now matches desktop exactly — symlinks throughout, no physical
duplicates. Not replicated to srv10 — srv10 has no `/media3`/`/media4` (single-disk hardware,
real copies not symlinks there, per the srv10 cherry-pick list below).

## Reference thumbnails (`gb_overview.png` + `refmap_IOM1880.png`), 2026-08-02
Both resized 200x160/100x109 → 320x320 and rebuilt for legibility (old ones were downscaled
detailed rasters, illegible at thumbnail size). `gb_overview.png`: meridian's `coast_ln_polyline`
+ thin `county_region` outlines, vector-rendered fresh, moved from the public `mapper` repo to
`/etc/webstack/mapserver/` (private — only private mapsets ever used it) with all referencing
mapfiles switched to an absolute path. `refmap_IOM1880.png`: re-rendered from the same
public-safe `IOM1880bw.tif` (kept public deliberately — don't swap in private data for this one).
`mod_overview.tpl`'s pre-JS fallback height updated `120px` → `384px` to match.

**Gotcha worth remembering for any future fixed-size reference image from a non-matching-aspect
source**: `gdalwarp -ts <w> <h>` without a matching `-te` stretches rather than pads — breaks
click-to-recenter accuracy since the image no longer matches its declared `EXTENT`. Pass both
together (`-te` computed via `min`/`max` scale-fit math) so it pads instead. The resulting
padding then defaults to black (GDAL's nodata-fills-0 behaviour) unless you add `-dstalpha` and
composite onto a white background in a second pass.

## OSM-derived mapsets (`osm_iom`, `osm_gb`) — built 2026-08-03
First mapsets built from raw OpenStreetMap data rather than OS products. Source is the
self-hosted `britain-and-ireland-latest.osm.pbf` on srv9 (`/srv/osm`, kept current by
`/etc/webstack/cron.daily/osm-update` for the IOM clip only — **`osm_gb` is a one-off build, not
in the nightly cron**, per explicit decision not to rebuild the whole-GB gpkg nightly). GDAL's
OSM driver doesn't pre-split by theme like the OS vector products do — it exports 5 generic
geometry-type layers (`points`/`lines`/`multilinestrings`/`multipolygons`/`other_relations`) with
real OSM tags as columns, so themed layers (`Water`, `Woodland`, `Building`, `Waterway`, `Road`,
`Place`) are `FILTER` expressions against those tag columns rather than separate source layers.
`osm_gb.map` reused `osm_iom.map`'s exact layer/filter/style structure, plus the MAXSCALEDENOM
tiers already proven safe at GB scale by `opmplc_2020.map`/`vmdvec_2020.map` (Water 300000,
Woodland 300000, Road 150000, Building 20000, Place 200000) — whole-GB render with all layers
came back in 0.3-0.5s, no smear/timeout issues.

**Coastline layer, both mapsets**: OS vector products don't carry real coastline geometry, so
this uses the OSM `water-polygons-split-4326` extract (osmdata.openstreetmap.de) instead —
downloaded, clipped to the mapset's bbox in EPSG:4326, reprojected to EPSG:27700 via `ogr2ogr
-clipsrc`, appended as its own `water_polygons` GeoPackage layer, styled as a filled polygon (not
a line, unlike `meridian`'s `coast_ln_polyline`) so land shows as a natural gap in a solid sea
fill.

**Tile-seam artifact, found + fixed for GB**: the water-polygons dataset is genuinely split into
a world-wide tile grid (hence the filename) — invisible in most viewers but our `Coastline`
layer's `OUTLINECOLOR` styling drew every tile-internal seam as a visible line, not just real
coastlines. Barely noticeable for IOM (small bbox, touches only 1-2 tiles) but a full checkerboard
across the sea at GB scale (323 raw tile features). Fixed at the data level, not by hiding the
outline: `ogr2ogr -dialect sqlite -sql "SELECT ST_Union(geom) AS geom FROM water_polygons"`
dissolves all tiles into one contiguous MultiPolygon (1 feature) before it ever reaches the
mapfile — real coastline boundaries stay outlined, tile seams vanish because they're no longer
separate polygon edges. ~1.5 min for GB's 323 features.

**Reference thumbnails, both mapsets** (`refmap_iom_osm.png`, `refmap_gb_osm.png`, in the public
`mapper` repo's `graphics/`): rendered via classic CGI `mode=map` (not WMS — `osm_iom.map`/
`osm_gb.map` have no `wms_enable_request` metadata, confirmed via a real `ServiceException` before
switching approach) with only the `Coastline` layer active, at the mapset's `REFERENCE` `SIZE`/
`EXTENT`. This leaves the true void — outside the clipped data extent, and the disconnected
white island-interior region — both flat white, indistinguishable at a glance. Filled the void
with the sea's own fill colour via a **flood-fill from all four image corners** (`PIL.ImageDraw.
floodfill`, same idea as an old bucket-fill tool) — works because the true void is one region
contiguous with the image edges, while land is always a separate, disconnected white region
enclosed by the coastline outline, so the flood never crosses into it. Leftover thin diagonal
lines where the bbox clip cuts across open sea (visible on both, more so IOM) were deliberately
left alone — confirmed with the user these read fine as "projection lines", not worth chasing
further.

**GROUP/NAME collision, found + fixed**: `Coastline` and `Water` both started out in `GROUP
"Water"` — the same string as the `Water` layer's own `NAME`. MapServer's CGI `layers=` parameter
matches against both layer names *and* group names, so any request that included `Water` (to keep
the Water layer on) silently reactivated the whole group, `Coastline` included, regardless of
Coastline's own toggle state — switching "Sea" off only ever worked if "Lakes / water bodies" was
switched off too. Confirmed by direct `mode=map` testing (`layers=Water` alone still rendered the
full sea fill). Fixed by renaming the group to `GROUP "WaterFeatures"` in both mapfiles (also
covers `Waterway`, third member of the same group) — re-verified `layers=Water` alone now renders
blank and `layers=Coastline` alone still renders the sea correctly. Audited every other mapfile in
`/etc/webstack/mapserver/` for the same layer-name/group-name collision pattern — none found
elsewhere, isolated to these two new mapfiles. **Worth checking for on any future mapfile that
gives a layer the same name as its own group.**

**Registry gotcha, hit for `osm_iom`**: adding the `Coastline` layer to the mapfile alone wasn't
enough to make it appear in the live interactive viewer — `mapper_mapsets.php`'s per-mapset
`layerList`/`layerAlias`/`layerVisible` arrays are what `map_init.html` actually submits on
initial load, so a layer missing from the registry never gets requested even though direct
`mode=map` testing (which takes an explicit `layers=` param) renders it fine. Caught before it
shipped only because `osm_gb`'s registry entry was written from scratch and prompted a check of
`osm_iom`'s existing one. **Always update the registry in the same pass as any mapfile layer
change** — `mode=map` testing alone can't catch this, same class of gotcha as the existing
`mode=map` vs `mode=browse` note above.

Data layout: `storage/mapper/osm_iom` and `storage/mapper/osm_gb` both symlink straight to
`/srv/osm` (not per-dataset subfolders like the OS Data library) since both mapsets' `gpkg`s
already live there together with the shared PBF source. `gb_osm.gpkg` is ~18.6GB (points/lines/
multilinestrings/multipolygons/other_relations, unfiltered — filtering happens at render time via
`FILTER`, not at build time) — took ~50 min end-to-end on srv9 (6 cores), most of it in the
`multipolygons` layer (relation assembly for buildings/woodland/water areas). Deployed to
**srv9 only** — matches the "srv10 only gets kept/cherry-picked data" policy, and this hasn't
been proven yet.

## OSM historic planet dumps — found + first one acquired, 2026-08-03/04
The "not readily available" lament below (now out of date, kept struck-through context in the
follow-up entry) turned out to be wrong — the Internet Archive hosts historic whole-world OSM
planet dumps via torrent back to 2012, not just OSM's own current-data infrastructure. Full
source detail in `[[reference_osm_historic_dumps]]` memory.

`osm-planet-20120912` (24GB, whole-world, bzip2 OSM XML — not PBF) downloaded, MD5-verified
against its own checksum file, and pushed to **both** `/media3/OSM-Historic/` and
`/media4/OSM-Historic/` on srv9 (matching the OS-Data archive's dual-disk redundancy pattern) —
each copy independently MD5-verified after transfer. A GB-sized clip was pulled out on desktop
via `osmium extract -b -8.7,49.8,2.0,61.0` straight from the `.bz2` (osmium reads bzip2 XML
natively, no separate decompress step) — took ~2h20m end-to-end (bz2 XML decompression is far
slower than the PBF extracts used for `osm_iom`/`osm_gb`'s current-day source), landed at
`/home/media1/OSM-Historic/gb-120912.osm.pbf` (516MB, verified via `osmium fileinfo`). Nothing
built from it yet — next step would be the same `ogr2ogr`-into-`gpkg` treatment `osm_gb.map` got,
producing a genuine 2012-vintage historic mapset once there's a reason to prioritise it.

A second, much larger dump (`osm-planet-20200914`, 101GB) was found on the same source and
started downloading via torrent 2026-08-03 (multi-day ETA) — would bridge the gap between the
2012 snapshot and current data. Not yet landed.

**`osm_gb_2012`/`osm_iom_2012` mapsets built + deployed to srv9, 2026-08-04.** Same recipe as
`osm_gb`/`osm_iom`: `ogr2ogr -t_srs EPSG:27700` straight into a gpkg (5 generic OSM layers,
themed via `FILTER`), Coastline layer pointed at the *existing* `gb_water_polygons.gpkg`/
`iom_osm.gpkg` water_polygons data via an absolute `CONNECTION` path rather than rebuilt — real
coastlines don't move meaningfully in 12 years, confirmed as the right call before building
anything. Same reasoning applied to the reference thumbnails: `REFERENCE IMAGE` in both new
mapfiles points directly at the existing `refmap_gb_osm.png`/`refmap_iom_osm.png` rather than
generating duplicates, since a coastline-only 2012 render would be pixel-identical.

Build ran on **desktop, not srv9** — deliberately, since desktop (Ryzen 5600G, 12 threads,
62GB RAM) is meaningfully faster than srv9 (FX-6300, 6 threads, 31GB, older Piledriver-era
core): the IOM extract was near-instant and the full GB gpkg (2.4GB, 1.3M points/3.16M
lines/2.38M multipolygons) took 2m22s, vs. ~50min for the much bigger current-day `osm_gb` build
on srv9. IOM clip pulled straight from the already-downloaded `gb-120912.osm.pbf` via `osmium
extract` with the same `IOM_BBOX` as `osm-update`'s cron script (fast — PBF is a far cheaper
source than re-extracting from the original 24GB bz2 XML dump). Both finished gpkgs rsynced to
srv9's `/srv/osm` (same shared dir `osm_gb`/`osm_iom` already symlink to) and checksum-verified.
New `storage/mapper/osm_gb_2012`/`osm_iom_2012` symlinks added on srv9 alongside the existing
ones; `mapper_mapsets.php` (webstack repo) got two new registry entries, `dataDir`-gated the same
way. Mapfiles committed to the **private `/etc/webstack` repo** (not the public mapper package
repo) like every other private mapfile, pushed to the bare repo and pulled on srv9.

Verified live end-to-end, not just via direct `mode=map` CGI: `mode=browse` (the real deploy
path) rendered correctly for both, and — catching the exact "registry gotcha" flagged above for
`osm_iom` — confirmed the merged `mapper_mapsets.php` registry actually reaches `script.php`'s
resolved output (`layerList`/`layerAlias`) for both new mapset keys, using an authenticated
`users_cnxn` cookie-insert session (see the top-level CLAUDE.md's testing-without-a-password
recipe; use `user_id = 3`, not `1` — see `[[reference_firebird_isql]]`). First attempt at this
falsely looked broken — both new mapsets fell back to the default registry entry — until DNS was
checked: `lsces.uk` resolves to production (srv10), so a plain `curl https://lsces.uk/...` run
from srv9 itself silently tests the wrong box. Fixed with `curl --resolve
lsces.uk:443:127.0.0.1`; see `[[reference_srv9_web_testing]]`.

Deployed to **srv9 only** — matches the existing cherry-pick policy, not promoted to srv10 yet.
`osm_iom_2012` was requested explicitly alongside `osm_gb_2012` (both built in the same pass, not
sequenced). Follow-up, not yet actioned: user flagged the reference thumbnails as needing "more
detail at some point" — current ones are coastline-only silhouettes with no place/road context,
adequate for now but a real limitation once more mapsets accumulate.

**Found + fixed in passing**: auditing srv9's `/media3` vs `/media4` for drift (prompted by the
Films directory needing a manual re-sync) turned up two mapper-relevant gaps — `OS-Data
opmplc_gpkg_gb_2026` (7.4GB) had never been copied to media4 at all, and
`/etc/webstack/cron.daily/osm-update`'s own nightly bulk-archive mirror step
(`rsync ... /media3/osm/`) only ever targeted media3, so it would have kept drifting every night
indefinitely. Both fixed: the dataset copied across (verified identical), and the cron script
itself changed to loop over both `/media3/osm` and `/media4/osm`.

## Known follow-ups (not actioned)
- OSM road-class colour styling (blue motorways / green primary / red A roads, etc, matching a
  classic OS/road-atlas look) — flagged by the user as "the next logical step" while reviewing
  `osm_gb`, explicitly *not* wanted immediately. Current `Road` layer is one FILTER/one colour,
  same as `osm_iom`. Needs OSM `highway` tag value mapped to a colour/width table, ideally with
  its own MAXSCALEDENOM tiers per class (motorway visible further out than residential) — same
  pattern as `opmplc`/`vmdvec`'s road-class layers. Do not action until asked.
- `osm_gb`/`osm_iom` and the new `osm_gb_2012`/`osm_iom_2012` not yet promoted to srv10 —
  one-off/unproven builds, srv9-only per the existing cherry-pick policy (see the srv10 list
  below).
- 2012 reference thumbnails (reusing `refmap_gb_osm.png`/`refmap_iom_osm.png`) are coastline-only
  silhouettes with no place/road context — flagged by the user as needing "more detail at some
  point". Fine for now, worth revisiting once more historic-edition mapsets accumulate.
- 2020 OSM planet dump (`osm-planet-20200914`) still downloading — once landed, same GB+IOM
  extract/build/deploy pattern as the 2012 mapsets above.
- Neither the OS-derived vector mapsets (`meridian`, `opmplc`, `vmdvec`) nor plain OSM vectors
  render well as-is at a glance, per direct user feedback while reviewing `osm_gb` — the user's
  actual preference leans toward the raster OS products (`minisc`, `ras250`, `omlras_gb`) for
  general viewing, with OSM's role being the "build my own styled map" experiment above, not a
  replacement for the OS rasters.
- ~~10-20-year-old OSM planet/regional history dumps aren't readily available~~ — turned out
  wrong, see "OSM historic planet dumps" above. 2012 mapsets (`osm_gb_2012`/`osm_iom_2012`) built
  and deployed to srv9, 2026-08-04. 2020 dump still downloading — same build pattern once it
  lands, alongside the OS historic editions already kept per
  `[[feedback_mapper_historic_no_replace]]`.
- **OSM tile server, floated as the next step after the 2020 dump lands (2026-08-04), not
  decided.** Would be a different order of workload from anything built so far — everything to
  date (`osm_gb`/`osm_iom` included) is on-demand WMS-style rendering straight off a GeoPackage
  via MapServer/GDAL, no persistent daemon or pre-generated cache. A real slippy-map tile server
  (`osm2pgsql`→PostGIS import, `renderd`/`mod_tile`, on-disk tile cache across zoom levels) is
  much heavier on RAM (import) and disk (cache), and srv9 is the weaker, older box (see
  `[[project_srv9_hardware]]` — 6-thread FX-6300, 31GB RAM, already flagged as thermally
  marginal). Worth prototyping GB/IOM-scoped (not planet-wide) on desktop first, given desktop's
  already-proven speed advantage for the 2012 gpkg builds, before committing srv9 hardware to a
  persistent service. Do not action until asked.

## OSM tile server prototype — built + wired into the viewer, 2026-08-04
Full slippy-map tile server built on desktop (PostGIS/osm2pgsql/Mapnik/renderd, OS-atlas road
colours, custom PHP metatile reader since this stack is nginx-only with no Apache/mod_tile) and
wired into the existing viewer as a new standalone mapset, `osm_tiles_iom` — confirmed working
live by the user, not just via CGI tests. Full build detail, real measured tile-cache sizes,
several real bugs found+fixed along the way (a `render_list --all` bbox coverage gap, a desktop
vhost-root symlink gotcha), and the road-line-width-at-thumbnail-scale issue (accepted as-is, not
reused for other IOM mapsets) — all in `[[project_osm_tile_server]]` memory, not duplicated here
since this is infrastructure-adjacent rather than package code per se.

**Session end 2026-08-04**: everything committed (webstack + mapper package repos), desktop-only
so far — deliberately not pushed to srv9/srv10 yet, picking back up next session.
- Maughold Head's promontory tip is clipped by the historic IOM raster source data itself
  (found while building the new IOM reference thumbnail) — same class of issue as
  `[[project_iom_raster_whitespace]]`, the original scans were cropped slightly too tight on the
  east side. No `EXTENT` fix is possible; would need re-sourcing wider originals.
- Overview box unused horizontal space on wide layouts — see "Bitweaver-module vs. raw-frameset
  dimension mismatch" above. Needs real column width measured first.
- Status icon (`turnLayerVisible("Status")` target in `map.html`) restored zero-sized rather
  than fully removed — some interaction handlers call it unconditionally.
- `theme/land_header.html` still has original leftover German "Rheinland-Pfalz" demo content.
- OSRM routing link (referenced from the wiki Mapping Index page) has no running instance
  anywhere — not part of this revival.
- Whether `storage/mapper` source rasters should eventually become real `LibertyMime`
  attachments (once the DB-backed map catalog exists) — raised, not decided.
- `pancon_gb_2016` (Land-Form PANORAMA Contours, DXF, 812 tiles) — investigated, held for later.
  No embedded CRS, needs a `gdaltindex`-built TILEINDEX across all 812 files.
- srv10 cherry-pick list (old registry, `mapper_mapsets.php`) — `meridian_2014`, `minisc_2026`,
  `over_gb`, `ras250_2026`, `iom_years` are there (`IOM250k2026` isn't a mapset of its own — it's
  one layer inside `iom_years`, mislabeled here previously); `meridian_2016`, `minisc_2019`,
  `opmplc_*`, `vmdvec_*`, `zoomstack_2026`, `omlras_gb` are srv9-only. Decision on which (if any)
  more to promote not made — `dataDir` gate means nothing breaks either way. Increasingly
  superseded by real `Map` content objects (see below) — srv10 uploads started 2026-08-07.

See `[[project_mapper_osrm_revival]]` memory for the full session-by-session history (wrong
turns included) behind the choices above.

## display_map2 XYZ tile URLs made host-relative — 2026-08-07
`display_map2.php` originally passed a renderd-backed mapset's `*_gdalwms.xml` `<ServerUrl>`
straight through to Leaflet — absolute (`https://tiles.rdm1.uk/...`), which always public-DNS-
resolves to srv10 regardless of which server actually served the page. srv9 had a fuller local
tile archive (`osm_carto_gb`'s full z6-18 vs srv10's deliberate z6-17-only subset, see
`[[project_osm_tile_server]]`) that was completely unreachable through any browser path as a
result — not gated by srv9's new access lockdown (`[[project_srv9_lockdown]]`), just invisible by
accident. Fixed by stripping the sidecar URL down to its path (`parse_url(..., PHP_URL_PATH)`) so
the browser fetches same-origin, matching `/cgi-bin/mapserv`'s existing relative behaviour — the
XML's own `<ServerUrl>` stays absolute (GDAL's server-side WMS fetch, `display_map.php`'s old
path, genuinely needs a real curl-able URL).

Needed a matching `/tiles/` location added to the real site vhost, not just the pre-existing
standalone `tiles.rdm1.uk` vhost — new shared `webstack/nginx/snippets/tiles.conf`, included from
`10-lsces.uk.vhost.conf`. Hit a real nginx location-precedence bug doing this: `html_common.conf`'s
generic `\.(js|css|png|...)$` static-asset catch-all was included earlier in the vhost and won
nginx's first-matching-regex race, 404ing every `/tiles/*.png` request before `tile.php` ever ran
— looked exactly like missing data until traced. Fixed by including `tiles.conf` before
`html_common.conf`. Verified end-to-end post-fix: a z18 Trafalgar Square tile returns 200 from
srv9, clean 404 from srv10 — the per-server data difference is now genuinely reachable/gated,
not masked.

**Any future renderd-backed mapset needs this same relative-URL treatment automatically** (it's
generic in `display_map2.php`, not per-mapset) — but a *new* site vhost wiring up `/tiles/` for the
first time will need the same include-order awareness (`tiles.conf` before `html_common.conf`).

## display_map.php slug-permission bypass, EXCL default, /mapper/view/ pretty URL — 2026-08-07
`display_map.php` only extracted the resolver's `map` result inside its `content_id`-given
branch, so a real `Map` reached via a slug match on `?mapset=` (the `/mapper/map/<name>` pretty
URL) silently skipped `verifyViewPermission()` (the protector-aware check) and fell through to
the registry's blanket permission check instead — a genuine per-item permission bypass, found
live testing `/mapper/map/over_gb`. `display_map2.php` never had this (always used a single
resolver-call/unconditional-extract pattern). Fixed by restructuring `display_map.php` to match:
one `mapper_resolve_mapset()` call, then branch purely on whether `$map` came back truthy.

Separately found `Map::storeParsedMapFileDetails()` defaulted `EXCL` to exclusive (radio-button,
pick-one-of-several-layers) whenever a mapfile had no `# MAPPER: EXCL=` comment — wrong for
ordinary multi-layer thematic datasets, caught live when batch-imported `meridian_2014`
collapsed its 12 independent overlay layers into a single-choice group (only one layer, close to
"settlements", stayed visible). Default flipped to non-exclusive; exclusive is now opt-in via the
comment, added to the 5 mapsets that are genuinely edition-picker style (`iom_years`,
`minisc_2019`/`_2026`, `omlras_gb`, `over_gb`). Existing wrongly-defaulted xref rows for the other
10 already-imported multi-layer content objects corrected directly in the DB.

Added a pretty `/mapper/view/<name>` URL for `view.php` (slug lookup, same pattern as
`/mapper/map/` and `/mapper/map2/`), and pointed `Map::getDisplayUrlFromHash()` at it so
`list_maps.php`'s links use it directly instead of a plain `?content_id=` dispatch.

Deployed to **both srv9 and srv10** same session (mapper + kernel package pulls, webstack pull,
nginx tested+reloaded on both, php-fpm auto-reloaded by `server-pull-all.sh`) — srv9 and srv10
were both still on the pre-Map-class commit, so this was the first real deploy of the whole
Map-object feature, not just today's fixes. No DB schema step needed — xref group/item
registration already present on both servers from earlier. Smoke-tested clean on both (bare
demo, `list_maps.php`, a bogus slug 404ing correctly). User then uploaded the first 4 real `Map`
objects to srv10 directly and confirmed them working.

**Known follow-ups for next session:**
- Add small view/map/map2 launch icons in front of the title link on `list_maps.php` — one icon
  routes to the classic frameset viewer (`display_map.php`), one to the Leaflet viewer
  (`display_map2.php`), alongside the existing metadata `view.php` link.
- `overviewHeight` (the per-mapset overview-box height override, see the "Bitweaver-module vs.
  raw-frameset dimension mismatch" section above) only exists in the old registry array
  (`mapper_mapsets.php`) — `resolve_mapset_inc.php`'s content_id branch never populates it, so
  every real `Map` object silently falls back to the fixed 150px default in `display_map2.php`,
  losing the taller-box override GB-scale/portrait mapsets need. Needs a new xref item
  (`general`/`OVERVIEWHEIGHT` or similar) alongside `EXTENT`/`SHPPATH`/`EXCL`, settable from
  `edit.php`, read back in `resolve_mapset_inc.php`'s content_id branch.
