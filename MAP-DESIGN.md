# Map Design Guide

How to write a mapfile and lay out its folder so Mapper loads it cleanly, draws it in both
viewers and shows a proper legend, description and overview thumbnail. For what the package
does with a map once loaded, see `MANUAL.md`.

The Mapper Archive tab (Mapper admin page) checks most of this per map: its **Folder rule** and
**Reference image** columns say what is missing.

## 1. The map folder

One folder per map in the site's *Maps folder*, holding everything the map needs so the folder can
be copied to another machine or site and still work:

    Maps/<name>/
      <name>.map            the mapfile
      data/                 the data it reads (or links inside the folder to shared data)
      tiles/reference.png   the overview thumbnail; for tile maps tiles/ is also the render cache
      source/               the original download, where there is one

Name the folder in lower case; it is the key the tile cache and the `storage/mapper/<folder>` link
are built on, so the same name has to work on every machine.

## 2. Paths: relative to the folder

    SHAPEPATH "data"                       (or "." when the data sits beside the .map)
    CONNECTION "file.gpkg"                 (OGR layers - resolved against SHAPEPATH)
    REFERENCE ... IMAGE "tiles/reference.png"

Package assets keep `../` paths (`SYMBOLSET`, `FONTSET`, layer `TEMPLATE`/`HEADER`/`FOOTER`,
`LEGEND TEMPLATE`); the loader rewrites them to the package. Never write an absolute path to
another site's `storage/` or to `/home/...` — the loader rewrites what it can, but a path it
cannot recognise breaks on the next machine.

**Accepted exceptions** (shown as `OK (...)` in the Folder rule column): tile maps whose GDAL
connector lives in a shared `data` directory (absolute `SHAPEPATH`), and multi-edition maps
(`over_gb`, `omlras_gb`) that read editions from sibling folders.

## 3. Reference image

`REFERENCE` needs `EXTENT`, `SIZE` and `STATUS ON` as well as `IMAGE`, and the image must exist at
`tiles/reference.png`. A missing file makes the *whole* draw fail (`msDrawReferenceMap()`), so
the map shows nothing, not just no thumbnail. Make the image from the map's own overview extent;
the thumbnail's pixel shape follows the map's `EXTENT` aspect ratio, so it needs no fixed height.

## 4. Legend

The classic viewer runs in HTML-legend mode (`hasHTMLLegend` in `scripts/param1.js`). Three things
must all be present or the legend frame shows nothing useful:

1. **A `LEGEND` block** with `STATUS on`. Without one MapServer writes no legend file at all and
   the frame prints the bare `/storage/maps/…leg….png` URL.
2. **`TEMPLATE "../theme/legend.html"`** inside it. Without the template the frame shows an empty
   `<table></table>` followed by the URL text.
3. **A named class and a `LEGTITLE` per layer.** The template prints one heading per layer from
   the layer's `LEGTITLE` metadata and one row per class from its `NAME`. An unnamed class gives an
   empty legend; no `LEGTITLE` prints the literal `[metadata name=LEGTITLE]`.

Copy this block (it is the same in every map):

    LEGEND
      KEYSIZE 25 12
      IMAGECOLOR 255 255 255
      OUTLINECOLOR 255 255 255
      KEYSPACING 5 5
      TRANSPARENT off
      POSITION ul
      TEMPLATE "../theme/legend.html"
      LABEL
        TYPE truetype
        FONT arial
        SIZE 8
        COLOR 0 51 102
        ANTIALIAS true
      END
      STATUS on
    END

and per layer:

    METADATA
      LEGTITLE 'Deleted lines'
    END
    CLASS
      NAME "Deleted line (way)"
      ...

Keep the heading and the class name from repeating each other (heading "Roads", classes
"Motorway", "A road"). A layer with one class can use the layer's own name for both.

## 5. Description

Add `# MAPPER: DESCRIPTION=...` comment lines at the top of the `.map`; each becomes a paragraph in
the viewer's "i" panel and under the legend. They fill a map's description only when it has none,
at load time or on *Refresh*; an edited description is never overwritten.

## 6. Layers

- One `LAYER` per thing a user can toggle; `NAME` is the key, `STATUS` the initial state.
- Queryable layers need a `TEMPLATE` (and usually `HEADER`/`FOOTER`) for the Info popup.
- Loading reads the layers into the map's records, so *Refresh* resets per-layer queryable flags.

## 7. Checklist before loading

- Folder has the `.map`, its data, and `tiles/reference.png`.
- `SHAPEPATH`, `CONNECTION` and `REFERENCE IMAGE` are relative to the folder.
- `LEGEND` block with `TEMPLATE`; every layer has `LEGTITLE`; every class has a `NAME`.
- A `# MAPPER: DESCRIPTION=` comment.
- No stale second copy of the `.map` under the same title anywhere in the maps folder (rename old
  copies to `.stale`).
- After loading, the Archive tab row reads **Reference image OK** and **Folder rule OK**, and the
  classic viewer draws a map, an overview and a legend.
