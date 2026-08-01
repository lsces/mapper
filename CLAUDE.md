# Mapper Package — Developer Notes

MapServer-based GIS viewer, ported from a pre-PHP7 codebase (2026-07-28/29). Stock MapServer
JS frameset client (`html/map.html` + child frames) driving CGI requests to `/cgi-bin/mapserv`.

## Frame architecture — two independent JS contexts
The viewer is a classic frameset: top-level page (`display_map.php` → `map.html`) plus child
frames (`ScriptFrame`, `NaviFrame`, `FormFrame`, `ToolFrame`, `LinkFrame`, `MapFrame`, etc).
**`scripts/param1.js` loads independently in both the top-level page and `html/script.php`
(ScriptFrame's own document)** — two separate copies of its globals, not one shared context.
Anything that needs to reach the actual map/navi logic must be set from inside `script.php`,
not from the top-level page — a `document.write()`-injected override script into the top-level
page's `<head>` only ever reaches the *unused* copy. This bit an entire session (two superseded
designs before landing on the current one) — see `[[project_mapper_osrm_revival]]` for the
full history if this trips anyone up again.

`toolbar.js` and `common.js` both load directly into `script.php` (ScriptFrame's own document,
confirmed via grep, not assumed) — they share one plain JS scope, no cross-frame access needed
between them. Files in *other* frames (`map_init.html`, `navi.html`, etc) reach these via
`t = parent.ScriptFrame` and then `t.<name>`.

## `document.write()` elimination — done 2026-07-29
The original codebase built nearly every page in `html/` (9 real frameset pages + 7 `_blank.html`
variants), `theme/noFeature.html`, and `html/script.php` via `document.write()`/`writeln()` —
risking the "unbalanced tree" speculative-parser discard/reparse (confirmed as a real, reproduced
bug on `script.php`, not just theoretical). All converted to `createElement`/`appendChild`/
`insertAdjacentHTML`. One real bug found in the process, worth remembering if `navi.html` is
touched again: its `<form name="navi">` opened *mid-table* rather than wrapping it — invalid
HTML5 that only "worked" before via a legacy backward-compat parsing quirk that
`insertAdjacentHTML` doesn't trigger the same way live `document.write()` did, breaking the
identify tool and layer checkboxes (`document.navi.layer` came back `undefined`). Fixed by
properly wrapping the `<table>` in the `<form>` instead of relying on the quirk.

Three `document.write()` calls outside the original 9 files were triaged and deliberately left
alone: `scripts/wz_jsgraphics.js` (dead IE-only branch), `scripts/query.js`'s `textItemQuery()`
(no live call site), and `scripts/form.js`'s `writeCGIForm()` — the package's hottest code path
(every pan/zoom/click), left as-is because it runs on an already-loaded document (implicit
`document.open()` replacement) rather than mid-network-parse, so it doesn't have the hazard this
cleanup targeted. Don't "fix" `writeCGIForm()` without re-checking that reasoning first.

German-original text (visible strings + internal identifiers in `param1.js` and its referencing
files) was also changed to English this session — `.gif` filenames and the dead `Impressum`
branch were deliberately left untouched.

## Frame load-choreography chain
Every child iframe's *initial* `src` in the Smarty templates (`center_view_map.tpl`,
`mod_overview.tpl`, `mod_tools.tpl`, `mod_navi.tpl`, `mod_links.tpl`, `mod_legend.tpl`) points
at a `_blank.html` variant — a neutral placeholder shown before the server-resolved mapset
config exists. Verified reachable end-to-end by tracing every `document.location`/`src=`
assignment:
1. `map_blank.html`'s `Load1()` sets `ScriptFrame` → `parent.scriptURL` (i.e. `script.php`).
2. `script.php` resolves the mapset, then redirects `FormFrame`→`map_init.html`,
   `ToolFrame`→`tool.html`, `NaviFrame`→`navi.html`, `LinkFrame`→`link.html`.
3. `map_init.html` auto-submits a form to the MapServer CGI. The CGI response body **is**
   `html/form.html` — referenced as `WEB TEMPLATE` in `map/*.map`, not from any JS/tpl, so it
   won't turn up in a plain grep for `form.html`.
4. `form.html`'s `Load3()` (on its own `onload`) redirects `MapFrame`→map image and
   `LegendFrame`→`legend.html`.

None of the 7 `_blank.html` files are dead leftovers — don't remove any without replacing this
whole choreography. Any future consolidation of `html/`+`theme/`+`modules/` into `templates/`
needs to preserve this sequencing, not just move files 1:1.

**Testing gotcha**: the browser's plain reload/refresh button mangles this choreography (likely
re-triggering auto-submitting forms out of order) — symptoms look like a broken/stale render even
with fresh code deployed. Reload via the address bar (click it, Enter) instead when testing here.

## theme/ and modules/ — also fully reachable
- **`theme/` (5 files)** — every file is directly referenced by `map/*.map` via MapServer's own
  `TEMPLATE`/`HEADER`/`FOOTER`/`EMPTY` directives, genuinely can't be ordinary Smarty `.tpl`.
- **`modules/` (5 files)** — follow the *standard* Bitweaver `{bitmodule}` convention (each
  gated by `$modMap`, unconditionally `true` in `display_map.php`).

Of the four locations (`html/`, `theme/`, `modules/`, `templates/`), only `html/`+`theme/` are
genuinely non-standard/MapServer-constrained — the other two are ordinary framework convention.

## Selectable mapsets (script.php / mapsets_inc.php)
`html/script.php` (PHP, replaces the old static `script.html`) resolves which map to load,
server-side, before ScriptFrame's own `param1.js` runs:
1. Reads `includes/mapsets_inc.php` — package-default registry (git-tracked, public; currently
   just `test` → `map/test_rlp.map`, single IOM1880 demo layer).
2. Merges in `/etc/webstack/domains/{$gBitDbName}/mapper_mapsets.php` if present — a
   site-specific, webstack-managed (private) extension. Keyed off `$gBitDbName`, which resolves
   identically under desktop's `switch-site` and real per-site servers.
3. Resolves `?mapset=` against the merged registry, falling back to the resolved default.
4. Emits `mapPfad`/`layerList`/`layerAlias`/`layerVisible`/`layerIsQueryable`/`layerLink`/
   `fullExtent` as inline `json_encode()` `<script>` vars, between `param1.js` and `browser.js`.
   `param1.js` no longer declares any of these itself — `script.php` is the single source of truth.

This is a deliberate stopgap, not the intended end state — `list_maps.php` is still a stub
("list of maps" is dross per the user). The real fix is a DB-backed map catalog
(`LibertyContent` objects), same pattern as every other package's content list — not built yet,
raised twice by the user as a "should be" for later.

## `scriptURL`/`styleURL` — must both come from `html_head_inc.tpl`
`templates/html_head_inc.tpl` emits `scriptURL` and `styleURL` as top-level-page globals from
`MAPPER_PKG_URL`. **Both are required** — `script.php`'s own inline JS reads `parent.styleURL`
at the very top of its own document, before its own `param1.js` (which used to set this) has
had a chance to load. Trimming this template down to just `scriptURL` produces a silent
`href="undefined"` request that 302-loops and cascades into a broken Overview/blank map — easy
to misread as a caching or mapset bug since nothing throws. Check this template first if
Overview/legend rendering looks broken while the map itself loads.

## MAPPER_PKG_PATH / MAPPER_PKG_URL
Auto-defined by `BitSystem::registerPackage()` as `BIT_ROOT_PATH . basename($path)` —
always environment-correct (desktop flat checkout vs per-site symlinked server deployment)
with zero extra plumbing. There is no `env.js`/environment-detection file and none is needed.

## storage/maps vs storage/mapper — different rules
- `storage/mapper/` — source raster/vector data (`SHAPEPATH`). Server-side only, never referenced
  by a browser URL. Nginx `deny all`s it explicitly (`location ^~ /storage/mapper/`).
- `storage/maps/` — mapserv-generated output tiles (`IMAGEPATH`/`IMAGEURL`). **Is** served
  directly by nginx, so it must resolve under the site's docroot. On desktop's flat checkout this
  needs an explicit `storage/maps → {domain}/storage/maps` symlink, handled automatically by
  `switch-site`. Real per-site servers never hit this — their docroot already *is* the
  site-specific tree.
- Both `storage/maps/` (ephemeral, regenerable tiles) and `storage/mapper/` (large source
  library, independently archived/tiered — see below) are excluded from the nightly
  `firebird-backup`/`firebird-restore` DR sync — see the DR-sync data-loss section below for why
  `mapper` needed adding to that exclude list the hard way.

## OS data library — storage tiering policy (decided 2026-07-30)
A large "library" of OS Open Data products has been assembled — see `[[project_mapper_genealogy_goal]]`
in memory for the full dataset list. Three-tier policy:
- **`srv9:/media3/OS-Data/`** — the full library, every dataset, both old mySociety-mirrored and
  current OS Data Hub editions, plus original zips in `OS-Data/source/`. Archive/backup tier, not
  a live serving path.
- **`storage/mapper/<dataset>/`** (real per-site storage) — reserved for datasets actually wired
  into a *kept* live mapset, as a real copy independent of the archive. While a dataset is still
  being evaluated, symlinking `storage/mapper/X` → the archive copy is fine — promote to a real
  copy only once a mapset built from it is actually kept.
- **srv10** gets only whichever datasets end up wired into a mapset that's actually kept
  (explicit user policy) — never the speculative full library. srv9 holds everything.
- **Desktop follows the same pattern** (added 2026-08-01, see DR-sync section below):
  `/home/media1/OS-Data/` is desktop's equivalent of srv9's `/media3/OS-Data/`, with
  `storage/mapper/<name>` symlinked in — keeps the root disk light.

## Mapfile / mapserver.conf — not git-tracked, manual per environment
`map/iom_years.map` (and every other site-specific mapfile) plus `/etc/mapserver.conf` are
manual symlinks into `/etc/webstack/mapserver/` — deliberately outside the public `mapper`
package repo (mapfiles reference private bulk map data). Must be recreated by hand on every new
environment. `mapserver.conf`'s `MS_MAP_PATTERN` is `^/srv/website/[^/]+/mapper/` — generalized
to cover desktop, server-shared (`_bw5`), and any per-site symlink.

## Bulk map data — never git-tracked
`data/` and `dataIOM/` live on disk under the package directory but are `.gitignore`'d — same
treatment as `storage/attachments`. A small curated public subset (readme/license text, index
shapefiles, one already-public IOM1880 raster) stays tracked.

## MapServer CGI semantics (verify empirically, don't assume)
- `imgbox`/`imgxy` (drag-zoom/pan) are **pixel-space** — `mapserv` converts to map coordinates
  itself using `mapsize` also submitted with the form. Don't pre-convert to geographic
  coordinates before sending — produces wildly wrong extents with no error ("nothing moves").
- `mapext` is the *target* extent to render/navigate to; `imgext` is a *different* field (the
  previous frame's extent, used for click-to-geo math) — confirmed the hard way via a wasted
  test render that silently ignored `imgext` and fell back to the mapfile's own `EXTENT`.
- The `<!-- MapServer Template -->` magic first line is required only for `WEB TEMPLATE`,
  `LAYER HEADER`/`FOOTER`/`TEMPLATE`, and `WEB EMPTY` files — **not** for plain browser-loaded
  files, and **not** for `LEGEND TEMPLATE`, which uses a different fragment-substitution path.
- Given a requested extent whose aspect ratio doesn't match the requested `mapsize`, MapServer's
  own default behaviour is `contain`-style: it expands the *looser* axis outward so nothing is
  cropped, never the reverse. Confirmed via direct CGI render — see the cover-fit section below
  for why this is sometimes the wrong default for this package.

## Meridian vector mapset (`meridian_2014`/`_2016`) — wired up 2026-07-30
Built from `data/meridian/` (OS Meridian 2, GB-wide vector: roads by class, rail, settlements,
boundaries, lakes, woodland, coastline, rivers). Included layers: `woodland_region`,
`lake_region`, `county_region` (off by default), `coast_ln_polyline`, `river_polyline` (off),
`rail_ln_polyline` (off), `motorway_polyline`, `a_road_polyline`, `b_road_polyline` (off),
`minor_rd_polyline` (off), `settlemt_point`, `station_point` (off). The 33k-record cartotext
label layer was deferred, not included.

Two MapServer 8.6.5 compatibility gotchas hit while building it, relevant to any future mapfile
work: a `MAP`-level `TRANSPARENT` directive is no longer valid, and `WEB`-level
`MINSCALE`/`MAXSCALE` were removed in favour of per-`LAYER` `MAXSCALEDENOM`/`MINSCALEDENOM`.
`settlemt_point`/`station_point` needed `MAXSCALEDENOM 300000` — without it, rendering all 23,868
settlement points at whole-GB extent produces a solid black smear (confirmed empirically).

Moved to private/lsces ownership same day (mapfile hardcodes `/srv/website/lsces/...` paths, same
reasoning as `iom_years.map` — can't be the public package's demo). Data provenance: no
download-date metadata anywhere in the tree, but all 4,853 files share a tight mtime window
(5 June 2014) — treated as a strong acquisition-date proxy, reflected as "circa 2014" in the
mapset title rather than stated as fact. A 2016 edition was later added as a *second*, separate
mapset (`meridian_2016`) rather than replacing 2014 — see `[[feedback_mapper_historic_no_replace]]`.

**Deploy lesson, hit once and worth remembering**: `mode=map` CGI testing never exercises
`IMAGEPATH`/`WEB TEMPLATE` at all, only `mode=browse` does — a mapfile that renders fine under
`mode=map` on desktop can still break `mode=browse` on a real server (`meridian.map`'s
`IMAGEPATH` was left pointing at desktop's path the first time this was deployed). Always test a
relocated/environment-specific mapfile's full `mode=browse` path on the actual deploy target.

## Bitweaver-module vs. raw-frameset dimension mismatch — found + fixed 2026-07-30
The original mapper HTML assumed one hand-tuned mapset (`iom`, near-square, 5 layers).
`modules/mod_navi.tpl`/`mod_overview.tpl` both hardcoded a fixed iframe `height` sized to fit only
that. Adding `meridian` (tall portrait GB extent, 12 layers) exposed this: NaviFrame's 12-checkbox
layer list got clipped, and FormFrame's Overview thumbnail only ever showed GB's northern half
(reported as "works as long as I only work in Scotland"). Fixed both the same way: JS now sets
`window.frameElement.style.height` to `document.body.scrollHeight` once real content is loaded,
so the iframe always fits whatever the active mapset actually needs.

**Deliberately did NOT make the Overview reference image CSS-responsive** — MapServer's
click-to-recenter feature reads click x/y in the image's *rendered* pixel space and assumes it
matches the mapfile's `REFERENCE SIZE` directive exactly; any CSS rescaling breaks that mapping
silently (clicks pan to the wrong place, no error). A first attempt using `width:100%` had to be
reverted for exactly this reason.

**Still open**: visibly unused horizontal space in the Overview box on wider layouts, since
`meridian.map`'s `REFERENCE SIZE 100 217` is narrower than the sidebar column allows. Proper fix
needs a wider-native-resolution `graphics/meridian_overview.png` and matching `REFERENCE SIZE` —
not CSS scaling (see above). Needs the real column width measured first (no Chrome available on
desktop for automation, see `[[feedback_no_chrome]]`) — not actioned yet.

## Full Extent / per-mapset `extent` config — introduced 2026-07-30, extended 2026-08-01
`scripts/param1.js` originally hardcoded a single IOM-box `fullExtent` constant, shared (wrongly)
across every mapset — harmless with one mapset, broke the instant `meridian` existed (clicking
Full Extent snapped to the tiny IOM box). Fixed by adding a per-mapset `'extent'` key to
`mapsets_inc.php`/`mapper_mapsets.php`, emitted by `script.php` as `fullExtent`. See the
cover-fit section below for the 2026-08-01 follow-up (how that extent is actually *used* to fit
the frame, and an audit of whether each mapset's configured value is genuinely correct).

## OS Data library — mapsets actually built, 2026-07-31
Worked through the archived OS-Data library into real mapsets. All follow the same pattern
(mapfile in `/etc/webstack/mapserver/`, data in `storage/mapper/<dataset>/`, cherry-picked
selectively onto srv10 via `mapper_mapsets.php`'s `dataDir` gate — entries carry a `'dataDir'`
path, `array_filter`'d via `is_dir()` at request time, so one registry file deploys identically
to srv9's full archive and srv10's cherry-picked subset):
- `minisc_2019` / `minisc_2026` — OS MiniScale, 5 mutually-exclusive raster styles
  (`layerExclusive: true`). 2026 confirmed a pure clone of 2019 (identical world-file values,
  only filenames differ).
- `opmplc_2020` / `opmplc_2026`, `vmdvec_2020` / `vmdvec_2026` — OS Open Map Local and OS
  VectorMap District, GeoPackage vector, `CONNECTIONTYPE OGR` straight into the `.gpkg` (no
  tileindex needed). 7 curated "core" layers per mapset (TidalWater/SurfaceWater/Woodland/Road/
  Building/NamedPlace) rather than the full schema — `Building` alone is 14.3M features
  nationwide, so every non-trivial layer carries an empirically-chosen `MAXSCALEDENOM` tier
  (all sub-1.5s render time, even whole-GB). 2020 and 2026 editions use different layer-name
  casing internally (`Building` vs `building`) — confirmed per-edition via `ogrinfo`, not assumed.
  Whole-GB default view showing mostly coastline is expected (by design, not a bug).
- `over_gb` — combines both editions (2014/2026) *and* both styles into **one** mapset as four
  `layerExclusive` layers (matching the `iom` year-layer pattern). Same raster used as
  `meridian`'s `REFERENCE` thumbnail.
- `zoomstack_2026` — OS Open Zoomstack, all 21 layers (purpose-built as a tiered web basemap
  already, so uses its own built-in `roads_local`/`regional`/`national` tiering rather than a
  custom one).

The raster-tile route for OS Open Map Local (`omlras_gtfc_gb`, 10,591 files) was set aside in
favour of the simpler `opmplc` GeoPackage build — a "simpler first" choice, not "raster is
obsolete" (see Known follow-ups).

## display_map.php — mapset resolution bug, found + fixed, 2026-07-31
`display_map.php` resolved a fallback mapset key for the page **title** but passed the **raw,
unvalidated** `$_GET['mapset']` to the `scriptURL`-feeding Smarty variable. Since `script.php` is
the JS *engine* (not just content) that sets `mapPath`/`layerList` and redirects every other
frame, a stale key didn't gracefully fall back — it broke the whole frame choreography. Fixed by
having `display_map.php` resolve+validate once and pass the *resolved* key everywhere downstream;
an unresolvable explicit `?mapset=` now gets a proper `HTTP_NOT_FOUND` before any iframe is even
created, rather than silently loading a substitute. **Lesson**: `script.php` isn't ordinary
content — don't try to intercept/replace it directly to handle an error case; the fix belongs one
layer up, in whatever assigns its inputs.

## mapper permissions — never actually wired up, found + fixed, 2026-07-31
5 permissions were declared in `admin/schema_inc.php` but never `verifyPermission()`-checked
anywhere, and critically never synced into the live database at all — the entire module,
including the public `test` demo, was open to anonymous users with zero access control, on dev
and both real servers. Fixed: `display_map.php` now calls `verifyPermission()` (`test` stays
basic/anonymous-gated, everything else needs `bit_p_view_mapper`/registered), with a bare URL
falling back to the `test` demo rather than a login wall. **The missing DB rows were the real
fix, not the code** — the Bitweaver admin installer's own permission-cleanup stage handles this
automatically; see `[[feedback_installer_permission_cleanup]]` — check for that mechanism before
hand-writing permission SQL.

## test_rlp.map / OS250.map — symlink-vs-real-location gotcha, 2026-07-31
`test_rlp.map` (the public portable demo mapfile) had `SHAPEPATH`/`IMAGEPATH` hardcoded to
desktop's specific path — worked on desktop by coincidence, broke on every server (never caught
before because the permission gap above meant nobody could reach it live).

**Root cause is a real architectural conflict, not just a wrong path**: every site's `mapper/`
(e.g. `lsces/mapper`) is a **symlink** to the shared `_bw5/mapper` checkout. MapServer resolves a
mapfile's *relative* paths against that real, symlink-followed location — never the apparent
per-site path used to reach it. This is exactly why every other mapfile hardcodes an absolute,
site-specific path instead: they're deployed per-site into webstack, one real copy each.
`test_rlp.map` can't do that and stay the single portable public demo. Fixed with `SHAPEPATH` →
relative `"."` (unambiguous regardless of which site's symlink was used) plus `git update-index
--skip-worktree map/test_rlp.map` on each server, so each server can carry a local-only hardcoded
`IMAGEPATH` override that `git pull` never fights.

`OS250.map` had the same two path bugs plus invalid `MINSCALE`/`MAXSCALE` syntax plus tile data
that was never actually downloaded (placeholder `readme.txt`s only) — not worth fixing, removed
entirely, superseded by `minisc_2019`/`minisc_2026`.

## iom_years data — moved into its own subfolder, 2026-07-31
The 5 historic IOM raster pairs used to sit as loose files directly in `storage/mapper/` — the
only dataset not following the "one subfolder per dataset" convention. Moved to
`storage/mapper/iom_years/`, `iom_years.map`'s `SHAPEPATH` updated to match, consistently across
desktop/srv9/srv10.

## MapFrame scrollbar / clipped pan-arrows — found + fixed, 2026-08-01
Once the permission gap above was fixed and mapsets became viewable in a real browser again, a
real display bug surfaced: a vertical scrollbar inside `MapFrame`, clipping the pan-arrow
overlay. Several plausible causes were ruled out empirically before finding the real one (frame
outer-sizing, mapfile `SIZE` vs frame-size mismatch, `common.js` layer-positioning math — all
confirmed correct by construction/live browser inspection). **Actual root cause**: classic CSS
gotcha — `<img>` is inline by default, so injected images get a few pixels of "phantom" baseline
space below them unless given `display:block`, and `scripts/layer.js`'s layer-creation functions
used `overflow:inherit` rather than `overflow:hidden`, so that phantom space bled out past each
layer's box and accumulated into a real page-level scrollbar. Fixed with both
`overflow:hidden` (layer.js) *and* `display:block` on every injected `<img>` in `map.html` —
`overflow:hidden` alone clipped the 8px-tall North/South pan buttons almost entirely, since the
same leaking offset was now consuming most of their height instead of spilling harmlessly past
the page edge. `MapFrame`'s fixed `731px` height was correct all along and needs no dynamic
resize, unlike NaviFrame/FormFrame above.

## "Full Extent" cover-fit + storage/mapper DR-sync data loss, 2026-08-01
Picked back up the "default EXTENT is just whole-GB" follow-up. MapServer's own extent-adjustment
is `contain`-style (expands the *looser* axis, never crops) — correct in general, but wrong here:
a portrait GB extent in a landscape `MapFrame` renders as a thin populated strip surrounded by
huge blank margins.

**Fix**: added `computeCoverExtent(extentStr, frameWidth, frameHeight)` to `scripts/toolbar.js` —
`cover`-style fit instead (`scale = min(scaleX, scaleY)`, crop-and-center the looser axis), same
idea as CSS `object-fit:cover`. Wired into the `fullextent` case in `setTool()` (was a bare
`mapext = fullExtent`) and into `html/map_init.html`'s initial CGI submission (previously sent no
`mapext` at all, falling back to the mapfile's raw `EXTENT`) — so opening the map now behaves as
if Full Extent had already been clicked. Verified visually via direct CGI render before/after.
Deliberately axis-agnostic (not hardcoded to crop height) — whichever axis is tighter for a given
mapset/frame combination becomes the fill axis.

**Per-mapset `extent` config audit**: found `opmplc_2020`/`_2026`, `vmdvec_2020`/`_2026`, and
`zoomstack_2026` all shared meridian's *exact* extent string byte-for-byte — implausible as
independently-derived real bounds across three different OS products, almost certainly
copy-pasted placeholder. Verified the rest via `gdalinfo`/`ogrinfo` against real data — all
genuinely correct: `iom`, `meridian_2014`/`_2016` match their real data to the metre; `minisc`'s
`0 0 700000 1300000` is the raster's real georeferenced bounds (and is the actual "one map shows
a sliver of Europe" case — real corner at 3°38'E, North Sea/Benelux longitude); `over_gb`'s wide
box is also genuine (a deliberately broad reference/locator raster, real corners run 21°55'W to
16°9'E). `opmplc`/`vmdvec`/`zoomstack` verification pending their data recovery (below) — expected
to need correcting.

**Storage/mapper DR-sync data loss, found mid-session**: `/srv/website/lsces/storage/mapper/` on
desktop had silently shrunk to only 5 of 12 wired datasets. Root cause: `firebird-backup`'s
nightly `rsync --delete --exclude=maps` (srv10→desktop) excluded the ephemeral tile-output dir
but never the *source data* dir, so every night it silently trimmed desktop's `storage/mapper`
down to match srv10's deliberately-cherry-picked subset. `firebird-restore` (srv10→srv9) had the
same gap, worse — no excludes at all — but hadn't bitten yet since it's manual-only and most of
srv9's `storage/mapper` entries are symlinks into `/media3/OS-Data/` rather than real copies, so
the archive itself was never actually at risk. Fixed both scripts (`--exclude=maps
--exclude=mapper` on both), deployed to both servers.

**Recovery + relocation, same session**: pulled the 7 missing datasets back from srv9, and used
the opportunity to move the whole OS-Data library on desktop off the root disk (81% full, 173G
free) onto `/home/media1` (1.9TB free) — see the storage tiering policy above.
`lsces/storage/mapper/<name>` is symlinked into `/home/media1/OS-Data/<name>` for every dataset
(every mapfile's `SHAPEPATH` hardcodes the `lsces` path, so these symlinks are functionally
required, not just tidiness). `iom_years` is a real copy in *both* places — `lsces` needs it for
`SHAPEPATH`, `media1` needs its own copy for `bitweaver5/storage/mapper` (below) to show it too.
Symlink target names don't always match the mapfile-facing name (e.g.
`lsces/storage/mapper/meridian_2016` → `/home/media1/OS-Data/merid2_essh_gb_2016`) — always check
each mapfile's own `SHAPEPATH` line for the exact expected subfolder name.

**`bitweaver5/storage/mapper` — corrected same session**: first pass wrongly symlinked this to
`lsces/storage/mapper` (matching the existing `attachments`/`maps` pattern in `switch-site`, which
never actually covers `mapper` at all — its own comment header only mentions the other two).
Corrected per user direction: mapper's OS-Data library has effectively become independent of any
one site, so `bitweaver5/storage/mapper` now symlinks **directly** to `/home/media1/OS-Data`
instead — desktop's equivalent of srv9's `/media3/OS-Data`, going forward. `lsces/storage/mapper`
stays as its own separate set of symlinks (required for `SHAPEPATH` resolution, see above) rather
than being replaced by this new symlink. Also removed `bitweaver5/storage/maps.old-testfiles`
(38MB, a stale pre-`switch-site` backup dir — confirmed via mtime that nothing in it postdated the
live `maps` cache before deleting).

**Known follow-up, not yet actioned**: `storage/maps/` (the generated tile cache) has no cleanup
mechanism at all — 1,287 files / 68MB on `lsces` going back to March 2025, growing unbounded.
Needs an actual periodic-cleanup script (age or size based), not just a one-off manual clear.

## Known follow-ups (not actioned)
- Status icon (`turnLayerVisible("Status")` target in `map.html`) restored zero-sized rather
  than fully removed — some interaction handlers call it unconditionally. Re-enable somewhere
  less obtrusive if wanted.
- `theme/land_header.html` still has original leftover German "Rheinland-Pfalz" demo content.
- OSRM routing link (separate from mapper, referenced from the wiki Mapping Index page) has no
  running instance anywhere — not part of this revival.
- Whether `storage/mapper` source rasters should eventually become real `LibertyMime`
  attachments (once the DB-backed map catalog exists) — raised, not decided.
- Overview box unused horizontal space on wide layouts — see "Bitweaver-module vs. raw-frameset
  dimension mismatch" above. Needs real column width measured first.
- `pancon_gb_2016` (Land-Form PANORAMA Contours, DXF, 812 tiles across GB) — investigated
  2026-07-31, held for later. No embedded CRS (needs explicit `PROJECTION` override and a mixed
  point/line-entity split), and needs a `gdaltindex`-built TILEINDEX across the 812 files, same
  pattern considered (then dropped) for `omlras_gtfc_gb`.
- srv10 cherry-pick list — partially actioned 2026-07-31 (`meridian_2014`, `minisc_2026`,
  `over_gb` copied over directly, real copies not symlinks — no `/media3`-equivalent archive on
  srv10's single-disk hardware, see `[[project_srv10_hardware]]`). Rest of the newer mapsets
  still srv9-only — decision on which (if any) more to promote not made yet, `dataDir` gate means
  nothing breaks either way in the meantime.
- Per-mapset `extent` values for `opmplc_2020`/`_2026`, `vmdvec_2020`/`_2026`, `zoomstack_2026` —
  still pending verification/correction, see the cover-fit section above.
- Raster mapsets are still wanted, not just superseded by vector — `omlras_gtfc_gb`'s TILEINDEX
  approach (10,591 tiles, `gdaltindex` already proven working for it) is still the right path
  whenever it's picked back up, not dead, just not next. Raster renders suit some uses (exact
  visual fidelity to the original cartographic product) better than a styled vector reconstruction
  ever will.

See `[[project_mapper_osrm_revival]]` memory for the full session-by-session history (wrong
turns included) behind the choices above.
