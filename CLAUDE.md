# Mapper Package — Developer Notes

Session log — decisions, bugs found, and why things are the way they are. For how the package
actually works today (architecture, xref schema, tile caching, permissions, deployment), see
`MANUAL.md` instead; this file doesn't repeat that, only the history behind it.

MapServer-based GIS viewer, ported from a pre-PHP7 codebase (2026-07-28/29). Stock MapServer
JS frameset client (`html/map.html` + child frames) driving CGI requests to `/cgi-bin/mapserv`.

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

## Meridian vector mapset (`meridian_2014`/`_2016`) — wired up 2026-07-30
GB-wide vector (roads by class, rail, settlements, boundaries, lakes, woodland, coastline,
rivers). Layers: `woodland_region`, `lake_region`, `county_region` (off), `coast_ln_polyline`,
`river_polyline` (off), `rail_ln_polyline` (off), `motorway_polyline`, `a_road_polyline`,
`b_road_polyline` (off), `minor_rd_polyline` (off), `settlemt_point`, `station_point` (off). The
33k-record cartotext label layer was deferred.

MapServer 8.6.5 dropped MAP-level `TRANSPARENT` and WEB-level `MINSCALE`/`MAXSCALE` (per-`LAYER`
`MAXSCALEDENOM`/`MINSCALEDENOM` now, see `MANUAL.md`'s CGI-semantics section) —
`settlemt_point`/`station_point` needed `MAXSCALEDENOM 300000` specifically, found live as a
solid black smear across the whole-GB render without it.

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
key downstream. **Lesson**: `script.php` isn't ordinary content — fix belongs one layer up, in
whatever assigns its inputs, not by intercepting `script.php` itself. (Superseded by the fuller
resolver rewrite on 2026-08-07, see below — kept for the lesson.)

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
(was 81% full) onto `/home/media1` (matching srv9's `/media3` tiering pattern: the full archive
lives on the big disk, `storage/mapper/<dataset>` symlinks in only for datasets wired into a kept
mapset, srv10 gets only whichever datasets are actually kept). Symlink target names don't always
match the mapfile-facing name (e.g. `meridian_2016` → `merid2_essh_gb_2016`) — always check the
mapfile's own `SHAPEPATH` for the exact expected subfolder name.

`storage/maps/` (generated tile cache) also had no cleanup mechanism — added
`/etc/webstack/cron.daily/mapper-maps-cleanup`, deletes files older than 2 days (each render is
uniquely-named and never revisited, so short retention is safe).

## srv9 storage/mapper — meridian_2014/iom_years converted to symlinks, 2026-08-03
Found two lingering physical directories under srv9's `storage/mapper/` left over from before the
archive-symlink pattern above was established. Verified byte-identical (`diff -rq`/`md5sum`)
before converting: `meridian_2014` duplicated data already archived under
`/media3/OS-Data/merid2_essh_gb_2014`; `iom_years` had no archive copy on srv9 at all (only on
desktop), copied into both `/media3/` and `/media4/` (srv9's two independent OS-Data backup
disks) for the same redundancy the other sets already had. srv9's `storage/mapper/` now matches
desktop exactly — symlinks throughout, no physical duplicates. Not replicated to srv10 (no
`/media3`/`/media4` there — single-disk hardware, real copies not symlinks).

## Reference thumbnails (`gb_overview.png` + `refmap_IOM1880.png`), 2026-08-02
Both resized 200x160/100x109 → 320x320 and rebuilt for legibility (old ones were downscaled
detailed rasters, illegible at thumbnail size). `gb_overview.png`: meridian's `coast_ln_polyline`
+ thin `county_region` outlines, vector-rendered fresh, moved from the public `mapper` repo to
`/etc/webstack/mapserver/` (private — only private mapsets ever used it). `refmap_IOM1880.png`:
re-rendered from the same public-safe `IOM1880bw.tif` (kept public deliberately).

**Gotcha worth remembering for any future fixed-size reference image from a non-matching-aspect
source**: `gdalwarp -ts <w> <h>` without a matching `-te` stretches rather than pads — breaks
click-to-recenter accuracy since the image no longer matches its declared `EXTENT`. Pass both
together (`-te` computed via `min`/`max` scale-fit math) so it pads instead. The resulting
padding then defaults to black (GDAL's nodata-fills-0 behaviour) unless you add `-dstalpha` and
composite onto a white background in a second pass.

## OSM-derived mapsets (`osm_iom`, `osm_gb`) — built 2026-08-03
First mapsets built from raw OpenStreetMap data rather than OS products. Source is the
self-hosted `britain-and-ireland-latest.osm.pbf` on srv9 (`/srv/osm`, kept current by
`/etc/webstack/cron.daily/osm-update` for the IOM clip only — `osm_gb` is a one-off build, not in
the nightly cron). GDAL's OSM driver doesn't pre-split by theme like the OS vector products do —
it exports 5 generic geometry-type layers with real OSM tags as columns, so themed layers
(`Water`, `Woodland`, `Building`, `Waterway`, `Road`, `Place`) are `FILTER` expressions against
those tag columns. Whole-GB render with all layers came back in 0.3-0.5s using the same
`MAXSCALEDENOM` tiers already proven safe by `opmplc_2020.map`/`vmdvec_2020.map`.

**Coastline layer, both mapsets**: OS vector products don't carry real coastline geometry, so
this uses the OSM `water-polygons-split-4326` extract instead — clipped, reprojected, styled as a
filled polygon so land shows as a natural gap in a solid sea fill.

**Tile-seam artifact, found + fixed for GB**: the water-polygons dataset is genuinely split into
a world-wide tile grid — invisible in most viewers but the `Coastline` layer's `OUTLINECOLOR`
styling drew every tile-internal seam as a visible line at GB scale (323 raw tile features).
Fixed at the data level: `ogr2ogr -dialect sqlite -sql "SELECT ST_Union(geom) AS geom FROM
water_polygons"` dissolves all tiles into one contiguous MultiPolygon before it ever reaches the
mapfile.

**Reference thumbnails, both mapsets**: rendered via classic CGI `mode=map` (not WMS — neither
mapfile has `wms_enable_request` metadata) with only `Coastline` active. Filled the true void
(outside the clipped data extent) with the sea's own fill colour via a flood-fill from all four
image corners (`PIL.ImageDraw.floodfill`) — works because the true void is contiguous with the
image edges, while land is always a separate, disconnected region enclosed by the coastline
outline, so the flood never crosses into it.

**GROUP/NAME collision, found + fixed**: `Coastline` and `Water` both started out in `GROUP
"Water"` — the same string as the `Water` layer's own `NAME` (see `MANUAL.md`'s CGI-semantics
note on this class of bug). Fixed by renaming the group to `GROUP "WaterFeatures"` in both
mapfiles. Audited every other mapfile for the same pattern — none found elsewhere.

**Registry gotcha, hit for `osm_iom`**: adding a layer to the mapfile alone wasn't enough to make
it appear in the live interactive viewer — `mapper_mapsets.php`'s per-mapset `layerList` arrays
are what actually get requested on initial load, so a layer missing from the registry never
appears even though direct `mode=map` testing (which takes an explicit `layers=` param) renders
it fine. **Always update the registry in the same pass as any mapfile layer change.**

Data layout: both mapsets symlink straight to `/srv/osm` (not per-dataset subfolders). `gb_osm.gpkg`
is ~18.6GB, took ~50 min end-to-end on srv9. Deployed to **srv9 only**.

## OSM historic planet dumps — found + first ones built, 2026-08-03/04
The Internet Archive hosts historic whole-world OSM planet dumps via torrent back to 2012 — full
source detail in `[[reference_osm_historic_dumps]]` memory. `osm-planet-20120912` (24GB)
downloaded, MD5-verified, archived on both `/media3/` and `/media4/` on srv9. A GB-sized clip
pulled via `osmium extract` (bzip2 XML read natively, ~2h20m). A larger `osm-planet-20200914`
(101GB) found on the same source, downloading — never landed as of writing.

**`osm_gb_2012`/`osm_iom_2012` built + deployed to srv9, 2026-08-04.** Same recipe as
`osm_gb`/`osm_iom`, Coastline layer pointed at the *existing* current-day water-polygons data via
an absolute `CONNECTION` path rather than rebuilt (real coastlines don't move meaningfully in 12
years) — same reasoning applied to the reference thumbnails (pointed at the existing current-day
ones directly, since a coastline-only 2012 render would be pixel-identical). Build ran on
**desktop, not srv9** — meaningfully faster hardware, full GB gpkg took 2m22s vs. ~50min for the
current-day build on srv9. Verified via an authenticated `users_cnxn` cookie-insert session (see
`MANUAL.md`... actually see the top-level CLAUDE.md's testing-without-a-password recipe;
`user_id = 3`, not `1`). First verification attempt falsely looked broken until `curl --resolve`
was used — `lsces.uk` resolves to production (srv10) even from srv9 itself, see
`[[reference_srv9_web_testing]]`.

**Found + fixed in passing**: srv9's `/media3` vs `/media4` had drifted — `opmplc_gpkg_gb_2026`
(7.4GB) had never been copied to media4, and `osm-update`'s own nightly archive mirror only ever
targeted media3. Both fixed.

## Known follow-ups (not actioned)
- OSM road-class colour styling (blue motorways / green primary / red A roads) — flagged by the
  user as "the next logical step", explicitly *not* wanted immediately. Needs OSM `highway` tag
  mapped to a colour/width table with its own `MAXSCALEDENOM` tiers per class. Do not action
  until asked.
- `osm_gb`/`osm_iom` and `osm_gb_2012`/`osm_iom_2012` not promoted to srv10 — srv9-only per the
  cherry-pick policy.
- 2012 reference thumbnails are coastline-only silhouettes with no place/road context — flagged
  as needing "more detail at some point", fine for now.
- 2020 OSM planet dump still downloading as of last check — once landed, same GB+IOM
  extract/build/deploy pattern as the 2012 mapsets.
- Neither the OS-derived vector mapsets nor plain OSM vectors render well as-is at a glance, per
  direct user feedback — actual preference leans toward the raster OS products (`minisc`,
  `ras250`, `omlras_gb`) for general viewing, OSM's role being the "build my own styled map"
  experiment, not a replacement for the OS rasters.
- **OSM tile server, floated 2026-08-04, built the same day** — see below, not still open.

## OSM tile server prototype — built + wired into the viewer, 2026-08-04
Full slippy-map tile server built on desktop (PostGIS/osm2pgsql/Mapnik/renderd, OS-atlas road
colours, custom PHP metatile reader since this stack is nginx-only with no Apache/mod_tile) and
wired into the existing viewer as a new standalone mapset, `osm_tiles_iom` — confirmed working
live. Full build detail, real measured tile-cache sizes, and several real bugs found along the
way (a `render_list --all` bbox coverage gap, a desktop vhost-root symlink gotcha) — all in
`[[project_osm_tile_server]]` memory, not duplicated here since this is infrastructure-adjacent
rather than package code per se.

Other loose ends, still open:
- Maughold Head's promontory tip is clipped by the historic IOM raster source data itself — same
  class of issue as `[[project_iom_raster_whitespace]]`, original scans cropped too tight. No
  `EXTENT` fix possible; would need re-sourcing wider originals.
- Status icon (`turnLayerVisible("Status")` target in `map.html`) restored zero-sized rather
  than fully removed — some interaction handlers call it unconditionally.
- `theme/land_header.html` still has original leftover German "Rheinland-Pfalz" demo content.
- OSRM routing link (referenced from the wiki Mapping Index page) has no running instance
  anywhere — not part of this revival.
- `pancon_gb_2016` (Land-Form PANORAMA Contours, DXF, 812 tiles) — investigated, held for later.
  No embedded CRS, needs a `gdaltindex`-built TILEINDEX across all 812 files.
- srv10 cherry-pick list (old registry) — `meridian_2014`, `minisc_2026`, `over_gb`, `iom_years`
  are there (`ras250_2026` was, deleted 2026-08-08 — see below); `meridian_2016`, `minisc_2019`,
  `opmplc_*`, `vmdvec_*`, `zoomstack_2026`, `omlras_gb` are srv9-only. Increasingly superseded by
  real `Map` content objects.

See `[[project_mapper_osrm_revival]]` memory for the full session-by-session history (wrong
turns included) behind the choices above.

## display_map2 XYZ tile URLs made host-relative — 2026-08-07
`display_map2.php` originally passed a renderd-backed mapset's `*_gdalwms.xml` `<ServerUrl>`
straight through to Leaflet — absolute, always public-DNS-resolving to srv10 regardless of which
server actually served the page. srv9 had a fuller local tile archive that was completely
unreachable through any browser path as a result, invisible by accident (not a deliberate gate).
Fixed by stripping the sidecar URL down to its path — see `MANUAL.md`'s tile-serving section for
the current mechanism.

Hit a real nginx location-precedence bug wiring up the matching `/tiles/` location on the real
site vhost: `html_common.conf`'s generic static-asset catch-all was included earlier and won
nginx's first-matching-regex race, 404ing every `/tiles/*.png` request before `tile.php` ever ran
— looked exactly like missing data until traced. Fixed by include-ordering `tiles.conf` first.

## display_map.php slug-permission bypass, EXCL default, /mapper/view/ pretty URL — 2026-08-07
`display_map.php` only extracted the resolver's `map` result inside its `content_id`-given
branch, so a real `Map` reached via a slug match on `?mapset=` (the `/mapper/map/<name>` pretty
URL) silently skipped `verifyViewPermission()` and fell through to the registry's blanket
permission check instead — a genuine per-item permission bypass, found live testing
`/mapper/map/over_gb`. `display_map2.php` never had this. Fixed by restructuring
`display_map.php` to match: one resolver call, branch purely on whether `$map` came back truthy
(this is the resolver shape `MANUAL.md` now documents as current).

Separately found `Map::storeParsedMapFileDetails()` defaulted `EXCL` to exclusive whenever a
mapfile had no `# MAPPER: EXCL=` comment — wrong for ordinary multi-layer thematic datasets,
caught live when batch-imported `meridian_2014` collapsed its 12 independent overlay layers into
a single-choice group. Default flipped to non-exclusive (now documented as current behaviour in
`MANUAL.md`).

Deployed to both srv9 and srv10 same session — first real deploy of the whole Map-object feature,
not just that day's fixes. User then uploaded the first 4 real `Map` objects to srv10 directly.

## On-demand tile cache for classic mapsets, xref XKEY tidy, delete button — 2026-08-08
The bigger piece this session: `display_map2.php`'s Leaflet layer hit `/cgi-bin/mapserv` live on
every single pan/zoom for every classic mapset, zero caching. Built `render_tile.php` +
`/tiles/mapsrv/...` to fix this — see `MANUAL.md`'s tile-serving section for how it actually
works now. Two real bugs found building it, both now documented as gotchas in `MANUAL.md`:
`http_build_query()`'s default arg separator silently truncating WMS params, and
`resolve_mapset_inc.php` reading a `'source_file'` key that never existed (fixed via
`LibertyMime::getSourceFile()`).

**Cross-server path self-heal**: found live via a `firebird-restore` pulling srv10-baked absolute
paths onto desktop, breaking `content_id=7390`'s TEMPLATE resolution — a real Map's stored
mapfile bakes `SYMBOLSET`/`TEMPLATE`/etc paths absolute to whichever server did the uploading.
Generalized `Map::fixRelativePaths()` to self-heal on every resolve instead of only once at
upload — see `MANUAL.md` for the current mechanism.

**Xref schema tidy**: `EXCL`/`OVERVIEWHEIGHT` moved to the generic `value` xref template (`XKEY`
directly, no blob), new `PROJECTION` item added the same way — full field reference now in
`MANUAL.md`. Found and fixed a real regression migrating existing data: the 5 real Map objects'
`EXCL` rows still had the *old* xkey-holds-item-name pattern, briefly making every mapset resolve
as non-exclusive — migrated via `UPDATE ... SET xkey = CAST(data AS VARCHAR(32))` (reads the real
value out of the old blob rather than manual transcription). Same bug hit srv9 (22 test mapsets)
and srv10 (4) once deployed there, fixed the same way on both.

Removed the redundant `EXCL` checkbox from `edit.php` and `upload_map.php` — editable through the
generic xref group tabs now, and the upload form's version never applied correctly to a
batch-archive import anyway.

**Delete button**: `view.php` had Edit but no Delete — added, standard `confirmDialog()`-then-
`expunge()` flow. Found a real bug doing this: `LibertyMime::expunge()` only cleans up
attachments, never calls `parent::expunge()`, so the actual content-deletion logic never ran —
the delete flow reported success but the object was still there. `Map::expunge()` now overrides
to call both halves (documented in `MANUAL.md`). Stock's own components never hit this since
`StockComponent` extends `LibertyContent` directly, no attachment involved.

More floaticon options (Print, matching stock's BOM print) flagged for later — wants the xref
`data`-blob JSON display tidied up first (a generic `json` xref template — see `MANUAL.md`'s
known-limitations list).

**Deployed to both srv9 and srv10** — both were still on the pre-tile-cache commit, so this
covered the previous day's fixes too. Ran the same `liberty_xref_item` schema tidy and `EXCL`
data migration on both servers by hand (installer not yet exercised for this — see
`[[feedback_installer_permission_cleanup]]`). Found a real data gap while verifying: `ras250_2026`
404s on srv10 — `Map` object existed but the actual raster tile data was never copied there
(confirmed via the "does the source exist" gate correctly rejecting it, not a code bug). Not
worth copying more data to srv10 given its tight disk space — user deleted the `Map` object on
both srv10 and desktop instead, resolving the gap by removing the mapset rather than adding data.

## rdmcloud.uk — new private cloud service on srv9, 2026-08-08
Real architectural fix for a recurring friction point this whole session: `lsces` was being
asked to serve two incompatible roles at once — the production domain that must mirror srv10
cleanly, *and* the active mapper development/test bed. Every bit of friction hit this session
(desktop's stale test data, needing the same `EXCL` migration on three separate machines, "will
tonight's backup mess things up again") traced back to that same conflict. `rdmcloud.uk` is the
fix — a genuinely separate site, srv9-only, deliberately kept **out** of the srv10-authoritative
backup/restore chain (its own DR coverage, srv9-side, still to be built — explicitly not folded
into the existing scripts, which assume srv10 is always the source). Current structure documented
in `MANUAL.md`'s Deployment section.

Built as a full clone of `lsces` (DB via `gbak`, `config/`+`storage/` via `cp -a`), then given its
own identity — new DB alias, `config_inc.php` properly symlinked (not left as the real,
Kate-breakable file the clone produced), own theme, own isolated `mapper_tiles` cache (confirmed
working live against `ras250_2026` — cache miss → real render → cache hit on the next request),
distinct `site_title`. Found a real leftover-from-clone bug along the way: `kernel_config.
site_temp_dir` still said `/srv/tmp/lsces/` post-clone, so Smarty kept compiling/caching
templates against the *old* site's temp path — manifested as the theme CSS still resolving to
`lsces.css` long after every other theme-selection setting was already correctly `rdmcloud`.
Fixed by updating the DB value and creating the real `/srv/tmp/rdmcloud/` directory.

nginx vhost created straight on srv9's filesystem, deliberately **not** pushed to the shared
webstack repo — `skip-worktree`'d, and kept staged-but-uncommitted (not committed) so a future
`git push` from srv9 for anything unrelated can never leak it upstream (a commit, even
skip-worktree'd, would still get pushed — only staged-uncommitted changes are genuinely safe from
that). SSL cert for `rdmcloud.uk` already existed (issued back in July, unclear exactly when/why,
but real and valid) — no cert-bootstrap dance needed. DNS already resolved to the same home IP as
every other domain here, so no DNS changes were needed either.

**Deliberately left for later**: stripping `lsces` back down to match `srv10`'s minimal real set
now that the "stuff I'm hiding from the other sites" has a proper home in `rdmcloud` instead.
(`rdmcloud`'s own backup/DR coverage was left for later here too, originally — see the dedicated
entry below, same day, for how that turned out.)

## MANUAL.md split — 2026-08-08
This file had grown into a long chronological log with genuinely useful reference material
(architecture, xref schema, tile caching, permissions, deployment topology) buried inside dated
"found and fixed" entries. Pulled the stable "how it works now" material out into `MANUAL.md` —
this file keeps the history (why decisions were made, bugs found along the way, what's still
open), `MANUAL.md` is what to read to understand the current system. Trimmed this file's own
duplicated reference sections accordingly (frame architecture, load choreography, `scriptURL`/
`styleURL`, `MAPPER_PKG_PATH`, storage rules, MapServer CGI semantics, the pre-`Map`-object
mapset-registry description — all now in `MANUAL.md` only).

## rdmcloud DR/dev topology completed — 2026-08-08
Picked up the "left for later" thread from earlier today: `rdmcloud`'s own backup/DR coverage now
spans desktop, srv9, and srv10 properly. This was entirely backup-script/nginx/cert infra work,
not mapper-specific — full detail in `/etc/webstack/CLAUDE.md` instead.

## Real Map objects standardized on a consistent naming convention — 2026-08-08
Auditing the tile-cache "top ends" (see `/etc/webstack/CLAUDE.md`'s tile-sync work, same session)
surfaced that most real `Map` content objects still carried their `.map` file's unedited `NAME
Demo` placeholder, or a run-together `MeridianGB2014`-style title with no consistent casing.
Settled on a standard — see `MANUAL.md`'s new "Naming convention" section (under Mapset
resolution) for the actual rule and why it has to hold at the MapServer `NAME` layer too, not just
the DB title.

Applied it to all of `lsces`'s (4) and `rdmcloud`'s (21, `omlras_gb` deliberately excluded - dead,
no real underlying dataset) real mapsets: both the `.map` file's `NAME` and the `Map`'s DB title,
kept identical as always. Touched three places per mapset - the real uploaded attachment file, the
matching `Map` title, and the shared `/etc/webstack/mapserver/` package copy - propagated through
each site's actual DR mechanism (`firebird-backup`/`firebird-restore` for `lsces`, `srv9-backup` +
a manual `gbak` restore on srv10 for `rdmcloud`, since that topology has no restore script of its
own for the srv9→srv10 direction) rather than left as ad-hoc file copies. A mid-session methodology
mixup (comparing two out-of-sync database/filesystem copies as if they were one) and the new
`switch-site.sh` tool it prompted are infra concerns, not mapper-specific - see
`/etc/webstack/CLAUDE.md` for both.

## rdmcloud's real Map objects were unusable via their own content-object URL — found + fixed, 2026-08-08
Chasing a much smaller-sounding report ("`display_map.php` not finding the overview, but no error
for [a working mapset]") turned into a real, previously-undiscovered bug affecting all 21 of
`rdmcloud`'s real `Map` objects, not just the one first reported.

First red herring: my own test used the wrong case (`mapset=Over_GB` instead of the always-lowercase
slug `over_gb`) — genuinely produced "could not be found", but that was my mistake, not the bug.
Retesting with the correct slug got a login prompt instead — meaning resolution actually succeeded
and the real issue was a level deeper.

Actual cause: every one of `rdmcloud`'s real `Map` attachments still had `SHAPEPATH`, `IMAGEPATH`,
and `wms_onlineresource` hardcoded to `lsces` — leftover from the clone that built `rdmcloud`
(see the "new private cloud service" entry above), never updated. `Map::fixRelativePaths()`
*looked* like it should have caught this (it's the documented self-heal for exactly this class of
problem) but its regex only covers package-asset directives (`SYMBOLSET`/`FONTSET`/`TEMPLATE`/etc)
- `SHAPEPATH`/`IMAGEPATH`/`wms_onlineresource` were never in scope, and never will auto-heal (see
`MANUAL.md`'s "What this does not cover" note, same section). `IMAGEPATH` was the concerning one
functionally - a real render would have written its CGI output into **lsces's** `storage/maps/`,
not rdmcloud's own. That this had never been noticed before is itself informative: nothing had
ever actually exercised the real content-object resolution path for any of these 21 mapsets -
they'd only ever been reached via the legacy registry array fallback, which doesn't have this
problem since it always builds its paths from `MAPPER_PKG_PATH`/`$resolvedKey`, never a baked-in
site name.

Fixed with a global `/srv/website/lsces/` → `/srv/website/rdmcloud/` (and `https://lsces.uk/` →
`https://rdmcloud.uk/`) substitution across all 21 files on srv9 (the real source) - safe as a
blind substitution specifically because `rdmcloud/storage/mapper/`'s dataset symlinks mirror
`lsces`'s exactly, name for name. Propagated via `srv9-backup` to srv10 and desktop. Verified with
a real authenticated request (`users_cnxn` cookie-insert technique) against `over_gb` on srv9
directly: `HTTP 200`, full classic frameset, no "could not be found" - confirms the fix for all 21,
not just the one originally reported, since they all shared the identical root cause.

## On-demand tile cache unified with the renderd cache, shared across sites — 2026-08-09
Continuation of the `OS-Data` → `Maps` archive reorganization (infra-heavy, full story in
`/etc/webstack/CLAUDE.md`) - the mapper-specific piece was moving `render_tile.php`'s on-demand
cache out of `storage/mapper_tiles/<mapset>/<layer>/` (per-site) into the same shared
`Maps/<mapname>/tiles/` location `tile.php`'s renderd cache now uses too. One cache per map,
genuinely shared: `lsces` and `rdmcloud` rendering the same mapset/layer/tile now populate the
same file, not two separate copies - a real gain given several mapsets are near-identical clones
between the two sites.

Cost of the move: dropped the nginx-level `try_files` cache-hit fast-path for this route (see
`MANUAL.md`'s Tile serving & caching section for why - a real nginx footgun with `root /`
overrides leaking `$document_root`, reverted as a precaution, never actually confirmed broken).
Every on-demand tile request now pays the kernel-bootstrap cost even on a hit, unlike `tile.php`'s
genuinely stateless reads. Not fixed today; flagged as worth revisiting if it ever matters enough.

Also changed `render_tile.php`'s "couldn't produce a tile" response from 502 to 404 - 502 means
"upstream is down", which was never accurate for this path (covers a genuine render failure and a
legitimate no-data coordinate alike), and reads as a real outage to any log/monitoring tooling for
what's normally just an edge-case tile with no content. Matches `tile.php`'s own convention for a
missing renderd metatile.

Real bug found chasing an apparent regression: a `chown -R firebird:firebird` swept across the
*whole* `Maps/` tree during the srv10 disk-space migration (see webstack log), silently blocking
every on-demand tile write with zero PHP-level error (a deliberate, silent `mkdir()`/
`file_put_contents()` failure, not a crash) - looked exactly like a code regression from this
session's own changes for a long stretch of debugging before the ownership mismatch surfaced.
Worth remembering: PHP-FPM's own error log staying completely silent despite consistent 502s is
itself a strong signal to check filesystem permissions before assuming a code-level crash.

## `Maps/` given per-map `source/` + `.map` copies, srv9 and srv10 — 2026-08-09
Finished the self-contained-per-map principle: each map's original download archive moved from
the two flat buckets (`Maps/source/`, `OSM-Tiles-builds/`) into its own `Maps/<name>/source/`,
and a copy of its `.map` file (from each server's own `/etc/webstack/mapserver/`) placed in its
folder too — presence of a `.map` now flags a working mapset, see `MANUAL.md`'s storage layout.
srv9 got the full set; srv10 scoped to its live subset only, copying source zips over from srv9
where one existed. One disambiguation needed: `osmcarto-build-2026-08-05.zip` had no region in
its name, resolved via its own `README.txt` ("IOM data, not yet GB-wide") to `osmcarto-iom/`.

Also renamed srv10's `Maps/meridian_2014` → `meridian_gb_2014` to match srv9. Desktop's own
`Maps/` archive wasn't touched by any of this pass — still has pre-reorg names, flagged not fixed.

**Real bug found along the way**: verifying the meridian rename via its URL (`mapset=meridian_2014`)
created a *second*, stray `Maps/meridian_2014/tiles/` cache folder on srv10 — `render_tile.php`
keys its cache path off the resolved mapset key, and srv10 has no real `Map` object for meridian
(still legacy-registry-resolved), so the registry key and the archive folder name are independent
naming spaces with nothing keeping them in sync. User deleted the stray folder rather than rename
the registry key — now documented as a known limitation in `MANUAL.md`, not fixed.

`over_gb.map`/`omlras_gb.map` got copied into more than one folder each, since both layer several
editions as parallel-viewable exclusive layers, same pattern as `iom_years`' year sheets — each
edition is a real map in its own right, the combined mapfile is just one way to view them
together. **Direction flagged for later, not actioned**: `iom_years`' individual year sheets
(1880/1906/1940/1947/25000a) arguably deserve the same treatment — their own `Maps/` entries,
with `iom_years` itself becoming the "view several in parallel" convenience layer rather than the
only entry point. Same idea applies to future additional editions of `over_gb`/`omlras_gb`. Not
started; current structure "is looking very good" per direct user feedback, revisit later.

## Desktop `Maps/` brought in line with srv9 — 2026-08-09
Desktop's own `Maps/` archive (flagged not-done in the entry above) got the same treatment:
`meridian_2014`/`merid2_essh_gb_2016` renamed to match srv9, three more leftover bucket-style
folders merged in (`mapper-tile-cache` → per-map `tiles/`, discarding stale/duplicate entries same
as the srv9 precedent; `IOM-Historic-Imagery` → `iom_2001`/`iom_2007_25k`'s `tiles/`; a 367GB
`OSM-Tiles/tiles/` → `osmcarto-gb`/`osmcarto-iom`/`os-style`, all three genuinely missing from
desktop's `Maps/` until now), plus `.map` file copies added throughout. `osmcarto-midlands`
(17GB, an intermediate region-limited build stage, tile counts a small subset of the completed
`osmcarto-gb` build at every zoom checked) discarded as superseded; the separate
`OSM-Tiles-Midlands/` folder (build logs/source pbf, not tile output) left alone.

**Real bug found while verifying live**: `/srv/website/rdm/maps` — the junction every server is
supposed to have pointing at its own `Maps/` archive (`/media3/Maps` on srv9, etc) — was never
actually created on desktop. It existed as a bare directory instead of a symlink, so every tile
render this whole session had been writing into a disconnected phantom location, invisible to
anything checking the real archive. Fixed by merging the 602 stranded tiles (all under
`meridian_gb_2016`, the only mapset touched while this was broken) into the real archive, then
replacing the bare directory with a proper `-> /home/media1/Maps` symlink. Single shared path, not
per-site, so this fixes `lsces` and `rdmcloud` on desktop alike in one go.

## lsces's legacy registry trimmed to just its default fallback — 2026-08-09
Verifying srv9/srv10 after the pulls above surfaced the meridian stray-folder bug again (same
mechanism as the srv10 entry above) — but this time traced to the real root cause instead of
patched around it. `lsces` has exactly 4 real `Map` objects (`Meridian_GB_2014`,
`MiniScale_GB_2026`, `Over_GB`, `IOM_Years`) and `list_maps.php` only ever surfaces real `Map`
objects — so every one of `mapper_mapsets.php`'s other ~18 entries (`meridian_2016`, `minisc_2019`,
`opmplc_*`, `vmdvec_*`, `osm_*`, `ras250_2026`, `omlras_gb`, `zoomstack_2026`, `osmcarto_*`,
`iom_2001`, `iom_2007_25k`) was dead, unreachable duplication — nothing on `lsces` ever links to
them, so it was never a "broken/invisible mapset" bug (an early wrong turn this session), just
harmless dead weight until a stale entry's slug drifted from its data folder's name and started
silently forking the tile cache (as happened to `meridian_2014` twice today). Trimmed
`/etc/webstack/domains/lsces/mapper_mapsets.php` down to just `'iom'` (the no-mapset-given
default, no real `Map` object of its own yet) - `rdmcloud` already proves the end state works,
with all 21 of its mapsets real `Map` objects and no `mapper_mapsets.php` file at all. Also removed
two now-fully-dangling `/srv/website/rdm/tiles` symlinks (desktop, srv10) left over from before
today's `tiles/<name>` → `<name>/tiles` restructuring - harmless once the entries referencing them
were gone, but no reason to leave dead symlinks lying around. `'iom'` migrating to a real `Map`
object too is a real future step, but needs the installer's own per-package default/demo handling
built first (see `[[feedback_installer_permission_cleanup]]`) since `'default'` has no database
equivalent yet - not actioned.

**Verified clean across all three machines afterward**: desktop/srv9/srv10 all have the identical
trimmed `lsces` registry, `rdmcloud` has no `mapper_mapsets.php` anywhere, and none of the three
has a dangling `/srv/website/rdm/tiles` symlink left.
