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
- **Trivial:** the 7 `_blank.html` files each write a single hardcoded static string with no
  dynamic value (e.g. `tool_blank.html:10` — `document.writeln('<body bgcolor="#FFFFFF">')`) —
  can become plain static `<body>` markup, no JS involved, zero behaviour change.
- **Real work:** `form.html`, `help.html`, `navi.html`, `tool.html`, `legend.html`, `link.html`,
  `map.html`, `map_init.html`, `script.php`, `theme/noFeature.html` build genuinely dynamic
  content this way (colors/attrs pulled from `t.*`/`o.*` JS globals set by `param1.js`/
  `script.php`, loops over layer lists, table structure for the toolbar) — converting these
  needs real restructuring to static HTML + post-parse DOM manipulation (or server-side
  rendering where the values are already known at request time, the same move `script.php`
  already made for the old static `script.html`). Not scoped yet — do this file by file, same
  "one step at a time" discipline as everything else in this package, since it's a real
  refactor risk against a now-working, live frameset.

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

## Known follow-ups (not actioned)
- Status icon (`turnLayerVisible("Status")` target in `map.html`) restored zero-sized rather
  than fully removed — some interaction handlers call it unconditionally. Re-enable somewhere
  less obtrusive if wanted.
- `theme/land_header.html` still has original leftover German "Rheinland-Pfalz" demo content.
- OSRM routing link (separate from mapper, referenced from the wiki Mapping Index page) has no
  running instance anywhere — not part of this revival.
- Whether `storage/mapper` source rasters should eventually become real `LibertyMime`
  attachments (once the DB-backed map catalog exists) — raised, not decided.

See `[[project_mapper_osrm_revival]]` memory for the full session-by-session history (wrong
turns included) behind the choices above.
