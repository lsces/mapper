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

## `document.write()` / "unbalanced tree" — the real modernisation target
**Corrected 2026-07-29** — an earlier session misread the "unbalanced tree cleanup" work-thread
name as being about the `html/`+`theme/`+`modules/`+`templates/` directory split, and spent a
pass auditing file reachability across all four instead (kept below as a legitimate but
secondary side-finding). The actual term is the browser-parsing one: `document.write()`/
`document.writeln()` calls that don't resolve to a tree matching what the browser's speculative
preload-scanner predicted force it to discard that work and reparse — see MDN's
[Speculative Parsing](https://developer.mozilla.org/en-US/docs/Glossary/speculative_parsing)
glossary page. This is not a couple of stray calls — nearly every file in `html/` (all 9 real
frameset pages plus all 7 `_blank.html` variants), `theme/noFeature.html`, and `html/script.php`
opens its `<body>` and builds its content this way. It's the wholesale pattern this pre-PHP7
codebase was written in, not an isolated fix.

Two risk tiers once this gets scoped for real:
- **Trivial — done 2026-07-29:** the 7 `_blank.html` files each wrote a single hardcoded static
  string with no dynamic value (e.g. `tool_blank.html:10` was
  `document.writeln('<body bgcolor="#FFFFFF">')`) — converted to plain static `<body>` markup,
  no JS involved, zero behaviour change. `map_blank.html` kept its real `Load1()`/frame-size-
  detection logic, just with a static `<body>` ahead of it instead of a document.write()'d one.
- **`script.php` — done 2026-07-29, first of the "real work" tier, confirmed live bug:** user
  reproduced a sticky broken load on hard-reload (shift+reload) that a plain reload recovered
  from cleanly — a real-world hit of the speculative-parser discard/reparse this whole thread is
  about, not just a hygiene nit. Two document.write() calls fixed:
  - The `<link rel="stylesheet">` injection (was line 38) → `document.createElement('link')` +
    `document.head.appendChild()`, same position/timing in the parse, no parser-stream injection.
  - The `<body onload=... bgcolor=... onunload=...>` open (was line 90) → static `<body>` tag
    (attributes minus the JS-dependent ones) with `document.body.setAttribute('bgcolor', ...)`,
    `window.onload = Load2`, `window.onunload = closeWindows` in a script right after — same
    pattern as `map_blank.html`. `BereichColor1` (from `param1.js`, loaded earlier in the same
    document via `<script src>`) is already available at that point either way, so timing is
    unaffected.
- **Rest of `html/`+`theme/noFeature.html` — done 2026-07-29.** `form.html`, `legend.html`,
  `link.html`, `tool.html`, `navi.html`, `map.html`, `map_init.html`, `help.html`,
  `theme/noFeature.html` all converted: stylesheet `<link>` injections →
  `createElement`/`appendChild`; conditional `<body ...>` opens → static `<body>` +
  `document.body.setAttribute(...)`; loop-built table/form content → accumulate into a string,
  single `document.body.insertAdjacentHTML('beforeend', ...)` at the end. Where an `onload="X()"`
  attribute referenced a function not actually defined in that file (dead else-branches — every
  `*FrameColor`/`BereichColor1` var is always set in `param1.js`, so the "color is null" branches
  never execute in practice) the replacement is `window.onload = function(){ X(); };`, not a bare
  `window.onload = X;` — preserves the original's deferred-at-invocation-time failure semantics
  instead of throwing immediately at assignment time (bare reference would ReferenceError right
  away since `X` isn't declared in that file's scope at all).
  - `map.html` needed more than itself: its actual layer-drawing `document.write()` calls lived in
    `scripts/layer.js`'s `createBackLayer1`/`createBackLayer2`/`createMapLayer`/`createElseLayer`
    (called cross-frame as `t.createBackLayer1(...)` etc, `t = parent.ScriptFrame`, writing into
    `m.document` where `m = parent.MapFrame` — i.e. back into `map.html`'s own document while it's
    still parsing). Converted to `m.document.createElement('div')` + `style.cssText` +
    `appendChild`; the now-unused `addRest()` helper (only existed to write the closing `</div>`
    after these) was removed.
  - **Real bug found via this pass, not a timing artifact:** `navi.html`'s `<form name="navi">`
    opened *mid-table*, between two `<tr>`s, not wrapping it — invalid HTML5 (a `<form>` directly
    inside `<table>` gets immediately popped off the stack per spec) that only "worked" before via
    the legacy "form element pointer" backward-compat mechanism, which turned out to be sensitive
    to *how* the markup is parsed. Building the whole block as one string + a single
    `insertAdjacentHTML` doesn't trigger that mechanism the same way live incremental
    `document.write()` did, so `document.navi.layer` came back `undefined` — broke both the
    identify tool (`scripts/query.js` reading `parent.NaviFrame.document.navi.layer`) and navi.html's
    own `updateLayer()`/`refreshVisibility()` (`this.document.navi.layer`). Fix: don't rely on the
    quirk at all — moved `<form name="navi">` to properly wrap the entire `<table>...</table>`
    instead of opening mid-table, so the inputs are genuine DOM descendants. Confirmed fixed.
  - Pre-existing cosmetic bugs in the original write-string content (e.g. `navi.html`'s
    `<table width="100% border="0" ...>` missing a closing quote, `link.html`'s missing
    `<table>`/`</table>` structure) were carried over unchanged — harmless, out of scope for a
    document.write elimination pass, not touched.
- **Three more `document.write()` calls found outside the original 9-file list, in `scripts/`,
  triaged 2026-07-29, none actioned:**
  - `scripts/wz_jsgraphics.js:59` (third-party vendored library) — gated behind
    `jg_ie && document.all && !window.opera`, dead in any browser this site needs to support.
    Left alone, matches the "third-party libs, read-only unless asked" convention even though
    this file isn't physically under `externals/`.
  - `scripts/query.js:83` (`textItemQuery()`, writes into a `parent.ResultFrame` that doesn't
    exist in any current template) — genuinely dead, `textItemQuery` has no call site anywhere in
    the package. Would throw immediately regardless of document.write. Left alone.
  - `scripts/form.js:33` (`writeCGIForm()`) — **live**, called on every pan/zoom/click-to-zoom
    (from `common.js`, `nav.js`, `toolbar.js`), so highest-frequency document.write call site in
    the package by far. **Deliberately left as-is (user decision, 2026-07-29)**: this call happens
    on an already-loaded `FormFrame` document (not mid-network-parse), so `document.write()` here
    triggers the browser's *implicit `document.open()`* behavior (in-memory full-document
    replacement, no network stream involved) rather than the speculative-preload-scanner hazard
    this whole thread is about — it's a different anti-pattern than the 9 files above, doesn't
    reproduce the "unbalanced tree...reparsed from network" warning, and isn't worth the
    regression risk of touching the package's hottest code path for a hazard it doesn't actually
    have.
- **German original text changed to English — done 2026-07-29** (browser was offering to
  translate the page). Covered both visible text (page titles, `theme/land_header.html`'s demo
  heading) and internal identifiers/comments (`scripts/param1.js` and the files that reference
  its variables). Left alone: the `.gif` asset filenames (still German — binary rename is a
  different risk class, wasn't asked for) and `toolbar.js`'s `Impressum` branch (dead code, no
  call site, `impress.html` doesn't exist).

## Frame load-choreography chain (file-reachability side-finding, not the "unbalanced tree" fix)
Every child iframe's *initial* `src` in the Smarty templates (`center_view_map.tpl`,
`mod_overview.tpl`, `mod_tools.tpl`, `mod_navi.tpl`, `mod_links.tpl`, `mod_legend.tpl`) points
at a `_blank.html` variant (`form_blank`, `legend_blank`, `link_blank`, `map_blank`,
`navi_blank`, `script_blank`, `tool_blank`) — a neutral placeholder shown before the
server-resolved mapset config exists to redirect to real content. Verified reachable by tracing
every `document.location`/`src=` assignment:
1. `map_blank.html`'s `Load1()` sets `ScriptFrame` → `parent.scriptURL` (i.e. `script.php`).
2. `script.php` resolves the mapset, then redirects `FormFrame`→`map_init.html`,
   `ToolFrame`→`tool.html`, `NaviFrame`→`navi.html`, `LinkFrame`→`link.html`.
3. `map_init.html` auto-submits a form to the MapServer CGI. The CGI response body **is**
   `html/form.html` — referenced as `WEB TEMPLATE` in `map/*.map`, not from any JS/tpl, so it
   won't turn up in a plain grep for `form.html`.
4. `form.html`'s `Load3()` (on its own `onload`) redirects `MapFrame`→map image and
   `LegendFrame`→`legend.html`.

None of the 7 are dead leftovers — don't remove any without replacing this whole choreography.
Any future consolidation of `html/`+`theme/`+`modules/` into `templates/` needs to preserve this
sequencing, not just move files 1:1.

## theme/ and modules/ — also fully reachable (file-location side-finding)
Audited both (2026-07-29), same reachability-tracing approach as the `_blank.html` pass above:
- **`theme/` (5 files)** — every file is directly referenced by `map/*.map` via MapServer's own
  `TEMPLATE`/`HEADER`/`FOOTER`/`EMPTY` directives: `land.html` (LAYER TEMPLATE, all 3 mapfiles),
  `land_header.html`/`land_footer.html` (LAYER HEADER/FOOTER), `legend.html` (LEGEND TEMPLATE),
  `noFeature.html` (WEB EMPTY). All reachable, all required — this is MapServer's CGI template
  substitution mechanism, genuinely can't be ordinary Smarty `.tpl`.
- **`modules/` (5 files)** — these actually follow the *standard* Bitweaver `{bitmodule}`
  convention used by every other package for placeable sidebar/content blocks (`mod_overview`→
  FormFrame, `mod_tools`→ToolFrame, `mod_navi`→NaviFrame, `mod_links`→LinkFrame, `mod_legend`→
  LegendFrame), each gated by `$modMap` (set unconditionally `true` in `display_map.php`). So of
  the four locations, only `html/`+`theme/` are genuinely non-standard (MapServer-constrained,
  can't be ordinary Smarty) — `modules/` and `templates/`'s companion-php pair are both standard
  framework conventions. Doesn't affect the real "unbalanced tree" (`document.write()`) work
  above — this was a separate side-finding about directory layout, not about parsing.

## Selectable mapsets (script.php / mapsets_inc.php)
`html/script.php` (PHP, replaces the old static `script.html`) resolves which map to load,
server-side, before ScriptFrame's own `param1.js` runs:
1. Reads `includes/mapsets_inc.php` — package-default registry (git-tracked, public; currently
   just `test` → `map/test_rlp.map`, single IOM1880 demo layer).
2. Merges in `/etc/webstack/domains/{$gBitDbName}/mapper_mapsets.php` if present — a
   site-specific, webstack-managed (private) extension. Keyed off `$gBitDbName`, which resolves
   identically under desktop's `switch-site` and real per-site servers.
3. Resolves `?mapset=` against the merged registry, falling back to the resolved default.
4. Emits `mapPfad`/`layerList`/`layerAlias`/`layerVisible`/`layerIsQueryable`/`layerLink` as
   inline `json_encode()` `<script>` vars, between `param1.js` and `browser.js`.

`param1.js` no longer declares any of these itself (dead vars removed, replaced with a comment
pointing here) — `script.php` is the single source of truth.

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
to misread as a caching or mapset bug since nothing throws. Not a caching artifact (confirmed
via a fresh-profile browser test) — check this template first if Overview/legend rendering
looks broken while the map itself loads.

## MAPPER_PKG_PATH / MAPPER_PKG_URL
Auto-defined by `BitSystem::registerPackage()` as `BIT_ROOT_PATH . basename($path)` —
always environment-correct (desktop flat checkout vs per-site symlinked server deployment)
with zero extra plumbing. There is no `env.js`/environment-detection file and none is needed —
an earlier design assumed one would be, don't reintroduce it.

## storage/maps vs storage/mapper — different rules
- `storage/mapper/` — source raster data (`SHAPEPATH`). Server-side only, never referenced by a
  browser URL. Nginx `deny all`s it explicitly (`location ^~ /storage/mapper/`). Real, no
  symlink games needed regardless of environment.
- `storage/maps/` — mapserv-generated output tiles (`IMAGEPATH`/`IMAGEURL`). **Is** served
  directly by nginx, so it must actually resolve under the site's docroot. `iom_years.map`'s
  `IMAGEPATH` is intentionally site-specific (`lsces/storage/maps/`, matching a real per-site
  deployment) — on desktop's flat `bitweaver5` checkout this needed an explicit
  `storage/maps → {domain}/storage/maps` symlink, now handled automatically by
  `switch-site` (`/srv/website/bitweaver5/switch-site`) alongside the existing
  `storage/attachments` symlink. Real per-site servers (srv9/srv10) never hit this — their
  docroot already *is* the site-specific tree.
- `storage/maps/` is excluded from the nightly `firebird-backup` DR rsync
  (`--exclude=maps`, `/etc/webstack/cron.daily/firebird-backup`) — purely regenerable output,
  no reason to push it through the srv10→desktop mirror.

## OS data library — storage tiering policy (decided 2026-07-30)
A large "library" of OS Open Data products has been assembled (see the Meridian/OS-data-library
sections above, and [[project_mapper_genealogy_goal]] in memory for the full 16-dataset list) —
most of it experimental, not yet wired into any mapset. Three-tier policy agreed with the user
for where this data actually lives:
- **`srv9:/media3/Archive/OS-Data/`** (and, once done, a `media4` mirror for the same redundancy
  the old mapper backups had) — the full library, every dataset, both old mySociety-mirrored
  editions and current OS Data Hub ones, plus original zips in `OS-Data/source/`. This is the
  archive/backup tier — not a live serving path.
- **`storage/mapper/<dataset>/`** (real per-site storage, e.g. `lsces/storage/mapper/meridian/`)
  — reserved for datasets actually wired into a *kept* live mapset, as a real copy independent
  of the archive (matches how `meridian`'s data already lives there, not symlinked back to
  `OS-Data/`). While a dataset is still being evaluated/tested, symlinking `storage/mapper/X` →
  the `OS-Data/` archive copy is fine (avoids duplicating data before it's proven worth keeping)
  — promote to a real copy only once a mapset built from it is actually being kept.
- **srv10** gets only whichever datasets end up wired into a mapset that's actually kept
  (explicit user policy: "srv10 will only have it's used data sets in storage/mapper") — never
  the speculative full library. srv9 is the one that holds everything, matching the project's
  usual test-on-srv9-first convention.

## Mapfile / mapserver.conf — not git-tracked, manual per environment
`map/iom_years.map` (site-specific mapfile) and `/etc/mapserver.conf` are both manual symlinks
into `/etc/webstack/mapserver/` — deliberately outside the public `mapper` package repo (mapfile
references private bulk map data). Must be recreated by hand on every new environment; nothing
automates this yet. `mapserver.conf`'s `MS_MAP_PATTERN` is `^/srv/website/[^/]+/mapper/` —
generalized to cover desktop (`bitweaver5`), server-shared (`_bw5`), and any per-site symlink
(`lsces/mapper`), since `MAPPER_PKG_PATH` legitimately resolves differently per environment.

## Bulk map data — never git-tracked
`data/` (OS MiniScale/os10k/os50k/meridian, ~1.1GB) and `dataIOM/` (historic IOM rasters,
private) live on disk under the package directory but are `.gitignore`'d — same treatment as
`storage/attachments`, not a deploy path. A small curated public subset (readme/license text,
index shapefiles, one already-public IOM1880 raster) stays tracked; this only blocks *new* bulk
additions from landing in the public `github.com/lsces/mapper` repo by accident.

## MapServer CGI semantics (verify empirically, don't assume)
- `imgbox`/`imgxy` (used by drag-zoom/pan in `scripts/zoombox.js`/`nav.js`) are **pixel-space**
  — `mapserv` converts to map coordinates itself, server-side, using `imgext`/`mapsize` also
  submitted with the form. Confirmed via direct `curl --data-urlencode imgbox=...` testing.
  Don't pre-convert to geographic coordinates before sending — that produces wildly wrong
  extents with no error at all ("no crash, but nothing moves").
- The `<!-- MapServer Template -->` magic first line is required only for `WEB TEMPLATE`,
  `LAYER HEADER`/`FOOTER`/`TEMPLATE`, and `WEB EMPTY` files (`theme/land_header.html`,
  `land_footer.html`, `land.html`, `noFeature.html`) — **not** for plain browser-loaded files
  (`navi.html`, the old `script.html`) and **not** for `LEGEND TEMPLATE`
  (`theme/legend.html`), which uses a different, unvalidated fragment-substitution path.

## Meridian vector mapset — wired up 2026-07-30
Investigated the "what to do with ~1.1GB in `data/`" pending thread. Turned up two findings:
`map/OS250.map` (pre-existing, never registered in any mapset) is dead scaffolding — its 5
raster `LAYER`s (OS250k, 4× miniscale, OS10k) point at `TILEINDEX` shapefiles whose target
`.tif` tiles were never actually downloaded; `data/os250k/`, `data/os10k/`, `data/MiniScale/`
contain only placeholder `readme.txt`s linking a now-dead OS product page. The real ~1.1GB is
almost entirely `data/meridian/` (OS Meridian 2, GB-wide vector: roads by class, rail,
settlements, county/district/dlua boundaries, lakes, woodland, coastline, rivers, ~33k cartotext
labels) — untouched by any mapfile until now.

Built `map/meridian.map` with real vector `LAYER`s (`LINE`/`POLYGON`/`POINT` straight off
`data/meridian/data/*.shp`, no tileindex needed — small enough to load whole). Included: `woodland_region`, `lake_region`,
`county_region` (off by default), `coast_ln_polyline`, `river_polyline` (off),
`rail_ln_polyline` (off), `motorway_polyline`, `a_road_polyline`, `b_road_polyline` (off),
`minor_rd_polyline` (off), `settlemt_point`, `station_point` (off). Deferred, not included:
`admin_ln_polyline`/`dlua_region`/`junction_font_point`/`rndabout_point` (minor/redundant with
what's already there), the `text` cartotext label layer (33k records, own pass needed).

Hit two MapServer-version compatibility errors while building it (confirmed via direct `mapserv`
CGI test, `mapserv -v` reports 8.6.5 installed): a `MAP`-level `TRANSPARENT` directive is no
longer valid, and `WEB`-level `MINSCALE`/`MAXSCALE` were removed in favour of `LAYER`-level
`MAXSCALEDENOM`/`MINSCALEDENOM`. `OS250.map` has both of the same directives and would throw the
identical parse errors if it were ever actually loaded — not fixed, since that file is still
unregistered dead scaffolding with no real data behind it either.

`settlemt_point`/`station_point` needed `MAXSCALEDENOM 300000` — without it, rendering all
23,868 settlement points+labels at whole-GB extent produces a solid black smear over England
(confirmed empirically, not guessed). Fine once zoomed to city level.

Verified via direct `mapserv` CGI invocation (`REQUEST_METHOD=GET QUERY_STRING=...`), not through
the browser frameset: a whole-GB overview render (clean, no clutter) and a zoomed ~70km box
around London (M25 ring, Thames, motorway/A/B/minor road colour tiers all correctly visible) —
both produced valid, legible PNGs.

**Moved to private/lsces ownership, same day, once the browser-tested viewer looked right.**
`meridian.map` hardcodes `/srv/website/lsces/...` paths (see below), so - same reasoning as
`iom_years.map` - it can't be the public package's demo mapset. Data moved from
`mapper/data/meridian/data/` to `storage/mapper/meridian/data/` (real per-site storage, matching
where the IOM historic rasters already live); `meridian.map` moved from a tracked package file to
`/etc/webstack/mapserver/meridian.map` with `mapper/map/meridian.map` now a symlink to it (exact
`iom_years.map` pattern); the `'meridian'` mapset entry moved out of the public
`includes/mapsets_inc.php` into `/etc/webstack/domains/lsces/mapper_mapsets.php`, alongside
`'iom'`. Re-verified post-move via direct `mapserv` CGI call through the symlink path (must use
the `mapper/map/...` symlink, not the real `/etc/webstack/...` path directly - `mapserver.conf`'s
`MS_MAP_PATTERN` only allows paths matching `^/srv/website/[^/]+/mapper/`) - identical render to
pre-move. `mapper/includes/mapsets_inc.php` now only has `'test'` again.

## Bitweaver-module vs. raw-frameset dimension mismatch — found + partially fixed 2026-07-30
The original mapper HTML (`html/*.html`, `modules/mod_*.tpl`) was never designed to sit inside
Bitweaver's module/sidebar system — it's a bolted-on wrapper around a standalone MapServer JS
frameset that assumed one hand-tuned mapset (`iom`, near-square extent, 5 layers). Adding
`meridian` (tall portrait GB extent, 12 layers) exposed this directly: `modules/mod_navi.tpl`
and `modules/mod_overview.tpl` both hardcode a fixed pixel `height` on their iframe, sized to fit
`iom`'s content only.
- **NaviFrame** (`modules/mod_navi.tpl`, `html/navi.html`): fixed `height:180px` clipped
  meridian's 12-checkbox list with no scrollbar (`scrolling="no"`). Fixed in two parts: the
  per-layer toggle changed from `<input type="radio">` to `type="checkbox"` (was mutually-
  exclusive, wrong for independently-combinable layers - see `layerExclusive` mapset flag below)
  and `navi.html` now sets `window.frameElement.style.height` to `document.body.scrollHeight`
  right after building the layer list, so the iframe always fits whatever the active mapset's
  layer count actually needs. The template's own `height:180px` is now just the pre-JS fallback;
  `scrolling="auto"` stays as a defensive no-op in case the resize script doesn't run.
- **FormFrame/Overview** (`modules/mod_overview.tpl`, `html/form.html`): fixed `height:120px`
  clipped the reference-map thumbnail to its top ~55%, which for meridian's tall GB extent meant
  only Scotland/northern England was ever visible or clickable - reported by the user as "works
  as long as I only work in Scotland". Same resize-to-content fix: `form.html`'s `Load3()` (plus
  the `<input name="ref">`'s own `onload`) now sets `window.frameElement.style.height` to
  `document.body.scrollHeight` once the reference image has actually loaded.
  - **Deliberately did NOT make the reference image itself CSS-responsive** (no
    `width:100%`/`max-width` on the `<input name="ref">`). MapServer's click-to-recenter feature
    (clicking the reference thumbnail to pan the main map) reads the click's x/y in the
    *rendered* pixel space of that `<input type="image">` and assumes it matches the mapfile's
    own `REFERENCE SIZE` directive exactly. Any CSS that rescales the displayed image away from
    its native `SIZE` breaks that coordinate mapping silently (clicks pan to the wrong place,
    no error). First pass of this fix used `width:100%; height:auto` and had to be reverted for
    exactly this reason - caught before it shipped, not from a live bug report.
  - Still open: there's visibly unused horizontal space in the Overview box on wider layouts
    (user's own observation - "room for a much longer frame on the right"), since
    `meridian.map`'s `REFERENCE SIZE 100 217` is narrower than the sidebar column allows. Fixing
    this properly (without the coordinate bug above) means regenerating
    `graphics/meridian_overview.png` at a wider native resolution and widening `REFERENCE SIZE`
    in `meridian.map` to match exactly - not CSS scaling. Needs the real column width (not yet
    measured - no Chrome available on desktop for automation, see
    `[[feedback_no_chrome]]`) before picking a size. Not actioned yet.

## Moved to lsces private storage + deployed to srv9 — 2026-07-30
Data relocated from `mapper/data/meridian/data/` to `storage/mapper/meridian/data/` (real
per-site storage, matching where the IOM historic rasters already live - see the "Moved to
private/lsces ownership" note above). Committed and pushed: public `mapper` GitHub repo (all the
code from this session), and `/etc/webstack` (`mapserver/meridian.map`,
`domains/lsces/mapper_mapsets.php`) - `meridian.map` itself is **not** in the public repo, same
as `iom_years.map`; the `mapper/map/meridian.map` symlink is manual, per-environment (nothing
automates it, matches `iom_years.map`).

Deployed to **srv9 only so far** (srv10 held back pending confirmation, per the project's
test-srv9-first convention): webstack pulled, `server-pull-all.sh mapper` run, `map/meridian.map`
symlink created manually, and the 1.1GB data tree rsynced across (verified byte-identical after -
4,853 files, exact size match on both ends).

**Caught a real deploy bug in the process**: `meridian.map`'s `WEB IMAGEPATH` was still
`/srv/website/bitweaver5/storage/maps/` - copied from the public `test_rlp.map` (which is
correctly desktop-only), not updated to the site-specific path when the file was moved to
private/lsces ownership. Broke `mode=browse` (the WEB TEMPLATE flow real page loads use) with
`msSaveImage(): Unable to access file` on srv9, since no `/srv/website/bitweaver5` exists there -
raw `mode=map` testing (used earlier in this file) doesn't exercise `IMAGEPATH` at all, so it
passed clean and this only surfaced once tested through the real deploy target. Fixed to
`/srv/website/lsces/storage/maps/`, matching `iom_years.map`'s convention exactly; re-verified on
srv9 as the `nginx` user (not just as `lester`) that `mode=browse` now produces real, correctly-
sized `[ref]` and `[img]` files. **Lesson: always test a relocated/environment-specific mapfile's
full `mode=browse` path on the actual deploy target, not just `mode=map` on desktop** - the two
modes exercise different directives (`mode=map` never touches `IMAGEPATH`/`WEB TEMPLATE` at all).

srv10 since deployed too (same sequence, no repeat of the IMAGEPATH bug since it pulled the
already-fixed version) - both servers verified byte-identical data (4,853 files, 1,154,618,378
bytes, matching desktop) and a working `mode=browse` render as the `nginx` user. `meridian` is
now live on all three environments (desktop, srv9, srv10).

**Data provenance** — no download date on record, and no metadata/README/license file anywhere
in the `data/meridian/` tree to check (searched, including the ARC/INFO `text.e00` header -
nothing). All 4,853 files carry an mtime in the same 78-second window: **5 June 2014, 13:59:19–
14:00:37 BST** - too tight to be anything but a single bulk extraction, not per-file survey
dates. Treated as a strong proxy for acquisition date, not a certainty (extraction usually
follows shortly after download, but isn't the same event). Reflected in the mapset title
(`mapper_mapsets.php`) as "Great Britain (OS Meridian 2, circa 2014)" rather than stated as fact.

## Full Extent hardcoded to the IOM box — found + fixed 2026-07-30
`scripts/param1.js` hardcoded `var fullExtent = "213000 464300 250900 505524"` (the IOM box) as
a single constant used by every mapset - toolbar.js's "Full Extent" button
(`case "fullextent"` in `scripts/toolbar.js`) always reset to it regardless of which mapfile was
actually loaded. Harmless with only one mapset ever existing; broke visibly the moment a second
one did - clicking Full Extent on `meridian` snapped to the tiny IOM box, effectively showing
nothing (real GB coordinates are nowhere near it). Fixed the same way `mapPath`/`layerList`/etc
already work: added `'extent'` to each mapset's config (must match that mapfile's own top-level
`EXTENT` exactly - `mapsets_inc.php` and `mapper_mapsets.php`), `html/script.php` emits it as
`fullExtent`, and the hardcoded line in `param1.js` is gone (replaced with a comment, matching
the existing note about the other per-mapset vars).

## OS Data library — mapsets actually built, 2026-07-31
Following on from the "storage tiering policy" above: worked through the archived OS-Data
library and wired up real mapsets, rather than leaving it all speculative. `meridian` renamed to
`meridian_2014` (its true vintage, confirmed via file mtimes) once a 2016 edition was pulled from
the archive — **both kept as separate mapsets** (`meridian_2014`, `meridian_2016`), not a
newer-replaces-older swap; see `[[feedback_mapper_historic_no_replace]]` in memory - editions are
historic snapshots, coexist like the `iom` year-layers do. Same non-replacement principle applied
throughout the session.

New mapsets, all following the same pattern (mapfile in `/etc/webstack/mapserver/`, data
symlinked from `storage/mapper/<dataset>/` on srv9, cherry-picked selectively onto srv10):
- `minisc_2019` / `minisc_2026` — OS MiniScale, 5 mutually-exclusive raster styles each
  (standard/std_with_grid/mono/relief1/relief2), `layerExclusive: true`. Straight raster `DATA`
  reference, no tileindex needed (single whole-GB image per style). 2026 confirmed a pure clone
  of 2019 (identical world-file values and pixel dimensions, only filenames differ).
- `opmplc_2020` / `opmplc_2026`, `vmdvec_2020` / `vmdvec_2026` — OS Open Map Local and OS
  VectorMap District, both GeoPackage vector, `CONNECTIONTYPE OGR` straight into the `.gpkg` (no
  tileindex, no per-tile files - much simpler than the raster-tile route originally considered
  for `omlras_gtfc_gb` (OS Open Map Local *raster* tiles, 10,591 files across GB) - that dataset
  was set aside as the quicker path to a working mapset that session, **not because raster is
  obsolete** - see "Known follow-ups" below, raster still has real value for some uses and this
  is meant to be picked back up, not abandoned. 7 curated "core" layers per mapset (TidalWater/SurfaceWater/Woodland/Road/
  Building/NamedPlace) rather than the full raw schema - `Building` alone is 14.3M features
  nationwide in `opmplc`, so every non-trivial layer carries a `MAXSCALEDENOM` tier, values chosen
  from feature counts and confirmed empirically (zoomed-in/mid-zoom/whole-GB timing checks, all
  sub-1.5s even for the busiest mapset). 2020 (mySociety mirror) and 2026 (OS Data Hub) editions
  use different layer-name casing internally (`Building` vs `building`, `distinctiveName` vs
  `distinctive_name`) - confirmed per-edition via `ogrinfo`, not assumed from the other year.
  Whole-GB default view showing mostly just coastline is expected (data too dense for anything
  else to pass `MAXSCALEDENOM` at that scale), not a bug - confirmed with the user live.
- `over_gb` — combines both editions (2014/2026) *and* both styles (Overview/OverviewPlus) into
  **one** mapset as four `layerExclusive` layers, matching the `iom` year-layer pattern rather
  than separate mapsets per edition. This is the same raster already used as `meridian`'s
  `REFERENCE` thumbnail (`graphics/gb_overview.png`) - confirmed via matching embedded GeoTIFF
  extent, no external world file needed.
- `zoomstack_2026` — OS Open Zoomstack, all 21 layers (not a curated subset like opmplc/vmdvec
  needed) - this product is purpose-built as a tiered web basemap
  (`roads_local`/`regional`/`national`, `local_buildings`/`district_buildings` already split by
  the product itself), so used its own built-in tiering for `MAXSCALEDENOM` instead of inventing
  one.

**`mapper_mapsets.php` gained a `dataDir` gate**: entries can carry a `'dataDir'` path, filtered
via `is_dir()` at request time (`array_filter` over the full mapset list before returning). Lets
this one file deploy identically to srv9 (holds the full archive, symlinked from
`/media3/OS-Data/`) and srv10 (only ever gets a deliberately cherry-picked subset actually copied
into `storage/mapper/`) without maintaining two separate registries. Entries with no `dataDir`
(`iom`, `test`) are assumed always-present and never filtered.

## display_map.php — mapset resolution bug, found + fixed, 2026-07-31
`display_map.php` computed a properly resolved (with fallback) mapset key for the page **title**,
but passed the **raw, unvalidated** `$_GET['mapset']` straight through to the `mapset` Smarty
variable that feeds `html_head_inc.tpl`'s `scriptURL`. A stale/renamed key (e.g. the old
`meridian` link after the rename to `meridian_2014` above) reached `html/script.php` raw - since
`script.php` is not just a content page but the JS *engine* that sets `mapPath`/`layerList` and
redirects every other frame, this didn't gracefully fall back, it broke the whole frame
choreography (empty map, title said one thing, nothing actually loaded). Fixed by having
`display_map.php` resolve+validate once and pass the *resolved* key everywhere downstream;
`script.php`'s own internal fallback stayed untouched (still needed as a safety net, but should
now only ever see a valid key in practice).

An earlier attempt at fixing the symptom - intercepting `script.php` directly with a "not found"
error page when the mapset didn't resolve - was reverted. Same root cause: `script.php` isn't
ordinary content, replacing its document with anything other than the real engine breaks every
other frame's redirect. The actual fix belongs in `display_map.php`, one layer up, before
`scriptURL` is ever built. When an explicitly-requested mapset doesn't resolve, `display_map.php`
now calls the standard `$gBitSystem->fatalError(..., HttpStatusCodes::HTTP_NOT_FOUND)` (matching
the pattern used across other packages, e.g. `wiki/backlinks.php`) *before* assigning `modMap`/
`mapset`/calling `$gBitSystem->display()` at all - no iframes are even created, nothing silently
loads a substitute map. A bare URL with no `?mapset=` still falls through to the site default
silently, as before.

## mapper permissions — never actually wired up, found + fixed, 2026-07-31
`mapper/admin/schema_inc.php` has always declared 5 permissions (`bit_p_v_map_mapper` "Can view
MAP files", basic/anonymous; `bit_p_view_mapper` "Can view map archives", registered; plus
create/edit/admin variants) - but nothing in the package ever called `verifyPermission()` with
any of them, and critically the permissions had never actually been synced into the live
database's `users_permissions`/`users_role_permissions` tables at all (confirmed via direct
query - zero rows for `package = 'mapper'`). Net effect: the entire mapper module, including the
public `test` demo, was fully open to anonymous users with zero access control, on both
localhost/dev and the real servers.

Fixed in two parts:
1. `display_map.php` now calls `$gBitSystem->verifyPermission(...)` - `test` (the public package
   demo) stays gated on `bit_p_v_map_mapper` (basic), everything else (`iom`, `meridian_*`,
   `minisc_*`, `opmplc_*`, `vmdvec_*`, `over_gb`, `zoomstack_2026`) requires `bit_p_view_mapper`
   (registered), since it's now real OS-licensed data or private family genealogy data rather
   than a demo. A bare URL with a resolved default the current user can't see falls back to the
   `test` demo instead of a login wall - only an *explicit* `?mapset=` for something private
   still prompts to log in.
2. `script.php` now emits `mapsetKey`, and `html/link.html` shows *"This is a demonstration map.
   Log in for the full map catalogue."* when viewing `test` - browser-cached, needs a hard
   refresh to see after deploy.

**The missing DB rows were the real fix, not the code** - first attempt hand-wrote the matching
`INSERT`s directly via `isql` (reverse-engineered from how other packages' working permissions
are structured: `users_permissions` row + `users_role_permissions` row per granted role,
`role_id -1`=anonymous/`3`=registered/`2`=editors). This worked but was the wrong tool - the
Bitweaver admin installer has its own cleanup stage that detects and installs exactly this kind
of gap automatically; the user ran that on srv9 and srv10 instead once flagged. See
`[[feedback_installer_permission_cleanup]]` in memory - check for that mechanism first next time
before hand-writing permission SQL.

## test_rlp.map / OS250.map — hardcoded paths, symlink-vs-real-location gotcha, 2026-07-31
Both `test_rlp.map` and `OS250.map` are genuine files living directly in `mapper/map/` (unlike
`iom_years.map`/`meridian_*.map`/etc, which are manual symlinks into `/etc/webstack/mapserver/` -
see the "not git-tracked" section below). `test_rlp.map` had `SHAPEPATH
"/srv/website/bitweaver5/mapper/data"` and `IMAGEPATH "/srv/website/bitweaver5/storage/maps/"` -
both hardcoded to desktop's specific flat-checkout path. Worked fine on desktop by coincidence,
broke on every server. Never caught before because the permission gap above meant nobody could
actually reach the `test` mapset live to trigger it.

**Root cause is deeper than a wrong path** - it's a real architectural conflict for this one
specific file. Every site's `mapper/` (e.g. `lsces/mapper`) is a **symlink** to the shared
`_bw5/mapper` package checkout (`realpath /srv/website/lsces/mapper/map` resolves to
`/srv/website/_bw5/mapper/map`). MapServer resolves a mapfile's relative paths against that *real*
(symlink-followed) location, not the apparent per-site path used to reach it - so a relative
`SHAPEPATH`/`IMAGEPATH` computed from `mapper/map/` always lands under the shared `_bw5/` tree,
never under any specific site's `storage/`. This is exactly why every *other* mapfile hardcodes an
absolute, site-specific path instead of a relative one - they're deployed per-site into webstack,
one real copy each. `test_rlp.map` can't do that and stay the single portable public demo.

Fix, in two parts:
- `SHAPEPATH` → relative `"."` (works fine - doesn't need to reach outside the shared package,
  and "the mapfile's own real directory" is unambiguous regardless of which site's symlink was
  used to get there).
- `IMAGEPATH` → **cannot** be relative (needs a genuinely site-specific writable target) and
  **cannot** be hardcoded in the git-tracked file either (would defeat the "clean clone, works
  out of the box" point of keeping it public/portable). Resolved with
  `git update-index --skip-worktree map/test_rlp.map` on each server: `git pull` deploys the
  clean relative-path version everywhere, then each server gets a **local-only** edit hardcoding
  its own real `IMAGEPATH` (matching every other mapfile's convention), and skip-worktree tells
  git to leave that local edit alone on all future pulls instead of fighting it. GitHub/desktop
  keep the portable version; srv9/srv10 each quietly diverge in their own worktree.

`OS250.map` had the same two hardcoded-path bugs, *plus* invalid `WEB`-level
`MINSCALE`/`MAXSCALE` (removed in current MapServer, replaced by per-layer `MAXSCALEDENOM`/
`MINSCALEDENOM` - same issue already documented above for a different reason), *plus* its
`TILEINDEX`-referenced raster tiles (OS250k/MiniScale/OS10k) were never actually included, only
placeholder text. Not worth fixing paths on a file that could never render regardless - removed
entirely, along with its exclusive tileindex shapefiles and placeholder folders. Properly
superseded by `minisc_2019`/`minisc_2026` and the (not yet wired into a mapset) `ras250_gb_2026`
archive data.

Along the way, found and removed a pile of untracked, desktop-local-only leftover data in
`mapper/data/` that predated this session's organised OS-Data library and had been fully
superseded: a `MiniScale_*_R14.*` tileindex set (pointing at tiles that were never downloaded,
same dead-scaffolding pattern as `OS250.map`), a 56-tile `ras250_gb` index (ditto - real tiles now
exist properly in `storage/mapper/ras250_gb_2026/`), and a completely empty `os50k_vec/`
directory skeleton (zero files, unreferenced by any mapfile). None of this was git-tracked, so no
package-repo action was needed for it.

## iom_years data — moved into its own subfolder, 2026-07-31
The 5 historic IOM raster pairs (`IOM1880bw`, `IOM1906`, `IOM1940`, `IOM1947`, `IOM25000a`, each
`.tif`+`.tfw`) used to sit as loose files directly in `storage/mapper/` - the only dataset not
following the "one subfolder per dataset" convention everything else in this session's OS-Data
work uses. Moved to `storage/mapper/iom_years/`, `iom_years.map`'s `SHAPEPATH` updated to match.
Desktop, srv9, and srv10 all done consistently.

## Known follow-ups (not actioned)
- Status icon (`turnLayerVisible("Status")` target in `map.html`) restored zero-sized rather
  than fully removed — some interaction handlers call it unconditionally. Re-enable somewhere
  less obtrusive if wanted.
- `theme/land_header.html` still has original leftover German "Rheinland-Pfalz" demo content.
- OSRM routing link (separate from mapper, referenced from the wiki Mapping Index page) has no
  running instance anywhere — not part of this revival.
- Whether `storage/mapper` source rasters should eventually become real `LibertyMime`
  attachments (once the DB-backed map catalog exists) — raised, not decided.
- `pancon_gb_2016` (Land-Form PANORAMA Contours, DXF, 812 tiles across GB) — investigated
  2026-07-31, held for later. Usable but more involved than the recent builds: no embedded CRS
  (DXF entities layer reports `Geometry: Unknown (any)`, needs explicit `PROJECTION` override and
  likely a mixed point/line-entity split by the `Layer` attribute), and needs a `gdaltindex`-built
  TILEINDEX across the 812 files, same pattern originally considered (then dropped) for
  `omlras_gtfc_gb`.
- srv10 cherry-pick list — partially actioned 2026-07-31 (`meridian_2014`, `minisc_2026`,
  `over_gb` copied over directly, no `/media3` archive on srv10 so real copies not symlinks -
  single-disk hardware, see `[[project_srv10_hardware]]`). Rest of the newer mapsets
  (`meridian_2016`, `minisc_2019`, `opmplc_*`, `vmdvec_*`, `zoomstack_2026`) still srv9-only,
  decision on which (if any) more to promote not made yet - `dataDir` gate means nothing breaks
  either way in the meantime.
- **`MapFrame` iframe height is a fixed `731px`, not dynamic** (`templates/center_view_map.tpl`)
  — found 2026-07-31, once the newer mapsets became actually reachable. `NaviFrame`/`FormFrame`
  already got resize-to-content JS in the 2026-07-30 pass (see the "Bitweaver-module vs.
  raw-frameset dimension mismatch" section above), `MapFrame` never did. Most of today's mapsets
  use `SIZE 500 1083` (vs. `iom`'s `SIZE 600 600`, which the 731px was presumably sized around) -
  the taller image forces the iframe to scroll, which then clips/misaligns the pan-arrow overlay
  controls (positioned by `nav.js`/`toolbar.js` relative to the iframe's own viewport, not the
  full image). Proper fix needs each mapset's real `SIZE` added to the registry shape and emitted
  via `script.php` (matching how `fullExtent` already works), then `MapFrame` resized the same
  way `NaviFrame`/`FormFrame` are. Not done - logged only.
- **Raster mapsets are still wanted, not just superseded by vector** - `omlras_gtfc_gb`
  (OS Open Map Local, raster tiles) was set aside this session in favour of the `opmplc_2020`/
  `_2026` GeoPackage vector build, but that's a "simpler to build first" choice, not a "raster is
  obsolete" one - raster renders are better suited to some applications (e.g. exact visual fidelity
  to the original cartographic product, no per-layer styling to get wrong) than a styled vector
  reconstruction ever will be. `omlras_gtfc_gb`'s TILEINDEX approach (10,591 tiles, `gdaltindex`
  already installed and proven working for it earlier this session) is still the right path
  whenever it's picked back up - not dead, just not next.

See `[[project_mapper_osrm_revival]]` memory for the full session-by-session history (wrong
turns included) behind the choices above.
