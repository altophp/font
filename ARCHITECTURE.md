# alto/font ↔ atelier/svg-font split -- architecture plan

> **Update (post-migration):** this document is kept as the historical record of the
> original split analysis. The migration happened, then went through a further
> simplification pass modeled on Symfony's own components (one facade class, one
> injectable seam, no redundant wrapper types). Current shape, differing from what's
> described below:
>
> - `Provider/{SystemFontProvider, DirectoryFontProvider, ChainFontProvider,
>   CachedFontProvider, FontFileScanner}` collapsed into a single
>   `Locator\FontLocator` (`::in()`, `::system()`, `::standardSystemDirectories()`) plus
>   `Locator\FontLocatorInterface`. System vs. custom directories were never a real type
>   distinction (same shape, different source list); chaining is just array merging;
>   caching moved inline into the finder below instead of a decorator class; the
>   directory-scanner had only one caller once system/custom merged, so it's inlined too.
> - `FontRegistry` (`Registry\`) was renamed `FontFinder` and moved to the top-level
>   `Alto\Font\` namespace, alongside `FontQuery` (also promoted from `Registry\`) --
>   matching how Symfony's `Yaml`/`Finder` are flat top-level classes, not nested. Methods
>   shortened to `has()`/`find()`/`get()`. No separate interface for it (a `final` facade
>   class, like Symfony's own components) -- `FontLocatorInterface` remains the one
>   injectable/testable seam, for the data source, not the finder itself.
> - `FontCandidate` and `Metadata\{FontSource, FontSourceType}` were deleted entirely,
>   not just simplified. Tracing actual usage showed `FontCandidate::source` was written
>   by every locator but never read downstream, and `FontMetadata::source` independently
>   *hardcoded* `FontSource::localFile()` regardless of real origin -- both ends were
>   disconnected from each other and from reality. `Locator\FontLocatorInterface::fonts()`
>   now yields plain path strings.
> - `Text/UnicodeString.php` also moved to `alto/font` rather than being excluded --
>   `Font::getMetrics(string)` needs its UTF-8 decoder and it has no shaping/SVG dependency.
> - A third pass regrouped files by role instead of by "which OpenType table this came
>   from": `GlyphId`/`GlyphMetrics`/`GlyphOutline`/`Contour`/`PathCommand` (previously at
>   the package root) and `Geometry/GlyphPoint.php` all moved into `Glyph/` -- everything
>   that describes one glyph's identity, spacing, and shape, together. `Variation/`'s 14
>   flat files split into `Variation/Table/` (the binary parsers: `FvarTable`, `GvarTable`,
>   `HvarTable`, and `AvarMap` renamed `AvarTable` for consistency with the other three)
>   and `Variation/ItemStore/` (the shared low-level delta machinery reused by gvar/HVAR:
>   `ItemVariationStore`, `TupleRegion`, `TupleVariation`, `DeltaSetIndexMap`), leaving
>   `FontVariations`/`VariationAxis`/`VariationInstance`/`VariationCoordinates`/
>   `NormalizedCoordinates`/`VariationDeltas` at the `Variation/` top level.
> - `FontCollectionInfo` (§ "future `.ttc`/`.otc`" below) was implemented, not just
>   described: `SfntFont::parse()` reads the `TTCHeader` and dispatches to the requested
>   face's own sfnt table directory (table offsets in a collection are already absolute
>   from file start, so no rewriting is needed, only an offset parameter threaded through
>   table-directory parsing). No new type was added -- `FontFace` gained `$faceIndex`/
>   `$faceCount` (default `0`/`1` for non-collection files) alongside the existing facts,
>   and `Font::fromFile()`/`FontLoaderInterface::load()` gained an `int $faceIndex = 0`
>   parameter. Nested collections (a face directory itself declaring `ttcf`) and
>   compressed WOFF2 collections remain explicitly rejected.
>
> See `README.md`/`CHANGELOG.md` for the current state; treat file paths and class names
> below as "true when written," not necessarily current.

Planning document only. Nothing under `/Users/simonandre/Code/Atelier/font` has been
touched; nothing has been moved, published, or git-initialized. `atelier/svg-font` does
not exist yet as a directory.

Source analyzed: `/Users/simonandre/Code/Atelier/font/src/` (62 PHP files across 13
namespaces, ~57 test files, QA green as of 2026-07-14: PHPStan max clean, 258 tests,
~97% coverage).

## Guiding principle

`alto/font` answers "what is this font, and what are the facts about this glyph"
(including, for variable fonts, "what are the facts about this glyph **at this
instance**"). `atelier/svg-font` answers "how do I lay out a string of text using those
facts, and how do I turn that into SVG". The boundary is not "declarative vs computed" --
it's "facts about a font/glyph" vs "shaping a text run and rendering it". Variable-font
delta math (gvar/HVAR) stays on the facts side, because `SfntFont::glyphOutline()` and
`SfntFont::glyphMetrics()` already take an optional `VariationCoordinates` argument and
resolve deltas internally -- that's fact-computation for a specific instance, not text
shaping. This refines the split as discussed in conversation: it isn't "fvar is metadata,
gvar/HVAR is drawing" -- it's "all variation math lives with the fact-computation it
serves", full stop.

## Deliverable 1 -- file-by-file classification

### Moves to `alto/font` as-is (no changes needed)

File reading / binary parsing:
- `Binary/BinaryReader.php`
- `OpenType/SfntFont.php` (1071 lines -- the core parser: sfnt/WOFF/WOFF2 container
  handling, `glyf`/compound glyph decoding, `face()`, `glyphIdForCodepoint()`,
  `glyphMetrics()`, `glyphOutline()`, `variations()` -- all fact-producing methods)
- `OpenType/Table/CmapTable.php`, `OpenType/Table/NameTable.php`,
  `OpenType/Table/TableRecord.php`
- `Loader/FontLoader.php`, `Loader/FontLoaderInterface.php`

Discovery:
- `Provider/FontProviderInterface.php`, `Provider/FontCandidate.php`,
  `Provider/SystemFontProvider.php`, `Provider/DirectoryFontProvider.php`,
  `Provider/ChainFontProvider.php`, `Provider/CachedFontProvider.php`
- `Registry/FontRegistryInterface.php`, `Registry/FontRegistry.php`,
  `Registry/FontQuery.php`
- `FontFinder.php`

Metadata / descriptors:
- `FontFace.php` (unitsPerEm, ascender, descender, glyphCount, tables, names -- pure facts)
- `Descriptor/FontDescriptor.php`, `Descriptor/FontWeight.php`,
  `Descriptor/FontStyle.php`, `Descriptor/FontStretch.php`
- `Metadata/FontMetadata.php`, `Metadata/FontFormat.php`, `Metadata/FontSource.php`,
  `Metadata/FontSourceType.php`

Per-glyph facts (not drawing):
- `GlyphId.php`, `GlyphMetrics.php`

Variable-font fact computation (all of it -- see guiding principle above):
- `Variation/FvarTable.php`, `Variation/GvarTable.php`, `Variation/HvarTable.php`,
  `Variation/AvarMap.php`, `Variation/FontVariations.php`, `Variation/VariationAxis.php`,
  `Variation/VariationInstance.php`, `Variation/VariationCoordinates.php`,
  `Variation/NormalizedCoordinates.php`, `Variation/TupleVariation.php`,
  `Variation/TupleRegion.php`, `Variation/ItemVariationStore.php`,
  `Variation/DeltaSetIndexMap.php`, `Variation/VariationDeltas.php`

Glyph outline geometry -- **partial move, see split below**:
- `Geometry/GlyphPoint.php` -- moves as-is (pure `{x, y, onCurve}`, used while decoding
  `glyf` on/off-curve points into contours; a parsing-time concern, not a drawing one).

Shared:
- `Exception/FontExceptionInterface.php`, `Exception/FontNotFoundException.php`,
  `Exception/InvalidFontException.php`, `Exception/UnsupportedFontException.php` -- move
  to `alto/font` as the base hierarchy. `atelier/svg-font` either depends on these
  directly (simplest) or defines its own thin exceptions that wrap/extend them -- no need
  to duplicate the hierarchy.

### Needs splitting (not a clean single-file move)

- **`PathCommand.php`, `Contour.php`, `GlyphOutline.php`** -- this is the one place the
  current code already fuses "generic geometry" and "SVG rendering" in the same class,
  more tightly than the rest of the conversation assumed:
  - `PathCommand` carries `moveTo()`/`lineTo()`/`quadraticTo()`/`closePath()` (generic:
    command type + float coordinates) **and** an affine `transform()` (generic) -- these
    stay in `alto/font`.
  - But it *also* carries `scaledForSvg(float $scale, float $x, float $baselineY)` (does
    the SVG Y-axis flip + baseline placement) and `toPathData()` (renders directly to
    SVG path-data syntax with SVG-specific number formatting) -- these two methods do not
    belong in `alto/font` and must move to `atelier/svg-font`.
  - Same split applies one level up: `Contour::scaledForSvg()`/`toPathData()` move;
    `Contour::transform()` and the `list<PathCommand>` container stay.
  - `GlyphOutline` itself (glyphId + `list<Contour>`, generic `transform()`) stays in
    `alto/font` as the fact `glyphOutline()` returns.
  - Concretely: `alto/font`'s `GlyphOutline`/`Contour`/`PathCommand` keep only
    constructors + `transform()`. `atelier/svg-font` gets a parallel/decorator concept
    (e.g. an `SvgGlyphRenderer` that takes an `alto/font` `GlyphOutline` and produces SVG
    path-data) rather than the exporter logic living on the geometry classes themselves.
    This is a cleaner boundary than today's code has -- today's `PathCommand` already
    knows about SVG, which is exactly the coupling this split is meant to remove.

- **`Font.php`** -- also needs trimming, not a verbatim move. Today's `Font` mixes fact
  methods (`fromFile()`, `face()`, `getDescriptor()`, `metadata()`,
  `glyphIdForCodepoint()`, `glyphMetrics()`, `glyphOutline()`, `variations()`,
  `withVariations()`, `variationCoordinates()`) with shaping/output convenience methods
  (`text()`, `outline()`, `path()`). `alto/font`'s `Font` keeps only the fact methods.
  `atelier/svg-font` reimplements `text()`/`outline()`/`path()`-equivalent convenience on
  top of `alto/font`'s `Font`, using its own shaping loop.

### Gets reimplemented in `atelier/svg-font` (not moved verbatim)

These are exactly the classes already marked `@internal` in today's code -- confirming
the conversation's instinct that they shouldn't be stabilized as `alto/font` public API:

- `TextToOutlines.php` (`@internal prefer Font::path()...`) -- the shaping-loop
  orchestrator. Reimplemented using `alto/font`'s `glyphIdForCodepoint()`/
  `glyphMetrics()`/`glyphOutline()` as the fact source.
- `GlyphOutlineExporter.php` (`@internal`) -- trivial (20 lines), calls
  `Contour::scaledForSvg()->toPathData()` per contour. Reimplemented against whatever
  `alto/font`'s trimmed `GlyphOutline`/`Contour` look like once `scaledForSvg`/
  `toPathData` move out (see split above).
- `Shaping/SimpleTextShaper.php`, `Shaping/TextShaperInterface.php`,
  `Shaping/PositionedGlyph.php`, `Shaping/GlyphRun.php`, `Shaping/TextDirection.php` --
  the cmap-walking, codepoint-to-glyph, cursor-advancing logic. This is the natural home
  for future kerning-application and a future HarfBuzz adapter (behind
  `TextShaperInterface`), since applying shaping data during a text walk is exactly
  `atelier/svg-font`'s job.
- `Outline/OutlinerInterface.php`, `Outline/SimpleOutliner.php`, `Outline/TextPath.php`
  -- `TextPath::d()` is a raw SVG path string; this whole namespace is SVG output.
- `Text/FontText.php`, `Text/UnicodeString.php` -- `FontText` couples a `Font` + text +
  size and calls into outlining; `UnicodeString::codepoints()` is a tiny, generic
  UTF-8-splitter but has no other callers today, so it travels with the shaping code
  rather than staying stranded alone in `alto/font`.
- `TextPathGenerator.php`, `OutlinedGlyph.php` -- same reasoning; `OutlinedGlyph` is the
  per-glyph positioned-and-rendered record (`pathData`, `x`, `baselineY`) that only makes
  sense once you're producing SVG output for a specific text run.
- `PathCommand::scaledForSvg()`/`toPathData()`, `Contour::scaledForSvg()`/`toPathData()`
  -- see split above.

## Deliverable 2 -- new `alto/font` descriptive types

All verified against the real OpenType/sfnt spec, cross-checked against what today's
code already reads (to avoid proposing something that already exists under another
name).

### `OutlineFormat` enum

Which table(s) hold the actual contour data -- distinct from the container format:

| Case | Indicated by |
| --- | --- |
| `Glyf` | `glyf` + `loca` tables present |
| `Cff` | `CFF ` table present, no `CFF2` |
| `Cff2` | `CFF2` table present (implies variable CFF) |
| `Sbix` | `sbix` table present (Apple bitmap color glyphs, e.g. some emoji fonts) |
| `SvgInOt` | `SVG ` table present (SVG-in-OpenType color glyphs) |
| `CbdtCblc` | `CBDT` + `CBLC` tables present (Google/Android bitmap color glyphs) |

A font can technically expose more than one (e.g. `glyf` + `sbix` for a color font with
an outline fallback) -- model as a `list<OutlineFormat>` or pick a `primary()` accessor
that prefers outline formats over bitmap/color ones for text-to-path purposes.

### `ContainerFormat` enum

Today's `Metadata\FontFormat` already models this (`TrueType`, `OpenType`, `Woff`,
`Woff2`, `TrueTypeCollection`, `Unknown`, keyed off file extension via `fromPath()`).
Recommendation: **don't introduce a second enum** -- extend `FontFormat` itself (or rename
it `ContainerFormat` for clarity if a breaking rename is acceptable pre-v1) to also
distinguish sniffing by magic bytes, not just extension: `wOFF`/`wOF2`/`OTTO` (CFF
OpenType)/`0x00010000` or `true`/`ttcf` (collection) signature -- extension-based
detection alone will mislabel a renamed file.

### Table-presence directory

A `FontCapabilities`-style value object (or a method on `FontFace`/`FontMetadata`) that
surfaces which of the following 4-byte tags are present, so a caller can ask "does this
font have kerning / ligatures / color / variation data" without attempting to parse any
of them:

`kern` (legacy kerning), `GSUB` (glyph substitution -- ligatures, alternates), `GPOS`
(glyph positioning -- modern kerning, mark attachment), `COLR`+`CPAL` (color layers +
palette), `fvar` (variable font axes), `gvar` (glyph variation deltas), `HVAR` (metric
variation deltas), `avar` (axis variation remapping), `CFF `/`CFF2` (CFF outlines),
`sbix`/`CBDT`+`CBLC`/`SVG ` (bitmap/color alternatives), `morx`/`kerx` (Apple Advanced
Typography -- the AAT equivalent of GSUB/GPOS, present in many macOS system fonts as the
*only* shaping data, e.g. `.SF Pro`/`.SF Compact` -- worth flagging explicitly since a
naive "supports kerning?" check via `kern`/`GPOS` alone will wrongly say "no" for these).

Today's `SfntFont` already has an internal `WOFF2_KNOWN_TAGS` list (used only for WOFF2
decompression) that could seed this, but it currently serves a narrower purpose and
isn't exposed publicly.

### `FontInfo` -- complete `name` table (IDs 0-25)

Today's code already reads a good subset, split across two places:
- `Descriptor\FontDescriptor::fromFace()` reads IDs **16** (typographic family, falls
  back to **1**), **17** (typographic subfamily, falls back to **2**), **4** (full name),
  **6** (PostScript name).
- `Metadata\FontMetadata::fromFace()` reads IDs **0** (copyright → `copyright`), **13**
  (license description → confusingly named `license` field today), **14** (license URL →
  `licenseUrl`), **8** (manufacturer), **9** (designer), **12** (designer URL --
  `designerUrl`), **11** (vendor URL -- `vendorUrl`), **10** (description), **5**
  (version).

Full standard ID table for reference, with gaps marked:

| ID | Meaning | Read today? |
| -- | --- | --- |
| 0 | Copyright notice | ✅ `FontMetadata::copyright` |
| 1 | Font Family (legacy, 4-style grouping) | ✅ fallback for family |
| 2 | Font Subfamily (legacy) | ✅ fallback for subfamily |
| 3 | Unique font identifier | ❌ missing |
| 4 | Full font name | ✅ `FontDescriptor::fullName` |
| 5 | Version string | ✅ `FontMetadata::version` |
| 6 | PostScript name | ✅ `FontDescriptor::postScriptName` |
| 7 | Trademark | ❌ missing |
| 8 | Manufacturer | ✅ `FontMetadata::manufacturer` |
| 9 | Designer | ✅ `FontMetadata::designer` |
| 10 | Description | ✅ `FontMetadata::description` |
| 11 | Vendor URL | ✅ `FontMetadata::vendorUrl` |
| 12 | Designer URL | ✅ `FontMetadata::designerUrl` |
| 13 | License Description | ✅ `FontMetadata::license` (rename to `licenseDescription` for clarity) |
| 14 | License Info URL | ✅ `FontMetadata::licenseUrl` |
| 15 | Reserved | n/a |
| 16 | Typographic Family | ✅ preferred family source |
| 17 | Typographic Subfamily | ✅ preferred subfamily source |
| 18 | Compatible Full (Macintosh only) | ❌ missing, low value |
| 19 | Sample text | ❌ missing -- genuinely useful, some fonts embed a real sample string |
| 20 | PostScript CID findfont name | ❌ missing -- only relevant once CFF/CID support lands |
| 21 | WWS Family Name | ❌ missing -- alternate naming model for large weight/width families |
| 22 | WWS Subfamily Name | ❌ missing |
| 23 | Light Background Palette | ❌ missing -- COLR-specific |
| 24 | Dark Background Palette | ❌ missing -- COLR-specific |
| 25 | Variations PostScript Name Prefix | ❌ missing -- variable-font PS naming |

Recommendation: extend `FontMetadata` in place with IDs 3, 7, 19 (clear value, no
prerequisite work) rather than introducing a whole separate `FontInfo` class competing
with it. IDs 20-25 can wait for CFF/CID and COLR support respectively, since they're
meaningless without those features.

### `EmbeddingPermissions` -- OS/2 `fsType` bitfield

**Not read anywhere today** -- `OS/2` currently only appears in `SfntFont`'s
`WOFF2_KNOWN_TAGS` list for decompression bookkeeping; none of its fields are parsed.
Real bit layout (`fsType` is a `uint16` at a fixed offset in the `OS/2` table):

| Bits | Meaning |
| --- | --- |
| 0 | Reserved, must be 0 |
| 1-2 (value, not independent bits) | Embedding level: `0` = Installable Embedding (no restriction), `2` = Restricted License Embedding (must not be embedded), `4` = Preview & Print Embedding, `8` = Editable Embedding -- **these four values are mutually exclusive**, encoded in bits 1-3 as a small integer, not independent flags (a common source of bugs -- reading it as independent bits will misclassify) |
| 3 | Reserved |
| 8 | No subsetting (bit, independent) -- if set, only fully embed, don't subset |
| 9 | Bitmap embedding only (bit, independent) -- if set, only bitmap glyphs may be embedded, not outlines |
| 4-7, 10-15 | Reserved |

Model as a value object exposing `installable(): bool`, `restricted(): bool`,
`previewAndPrint(): bool`, `editable(): bool`, `noSubsetting(): bool`,
`bitmapEmbeddingOnly(): bool` rather than exposing the raw int, given the
mutually-exclusive-value gotcha above.

### `UnicodeCoverage` / `CmapFormat`

Today's `OpenType/Table/CmapTable.php` only implements subtable **format 4** (segment
mapping, BMP-only) and **format 12** (segmented coverage, full Unicode incl.
supplementary planes) -- confirmed by reading the file; anything else throws
"No supported cmap format 4 or 12 subtable found." Full format reference for the new
type's scope:

| Format | Purpose | Supported today |
| --- | --- | --- |
| 0 | Byte encoding table (256 single-byte codes, legacy Mac) | ❌ |
| 2 | High-byte mapping (legacy CJK, mixed 1/2-byte) | ❌ |
| 4 | Segment mapping to delta values (BMP, most common historically) | ✅ |
| 6 | Trimmed table mapping (contiguous small range) | ❌ |
| 12 | Segmented coverage (full Unicode, supplementary planes) | ✅ |
| 13 | Many-to-one range mapping (used for last-resort/fallback fonts) | ❌ |
| 14 | Unicode Variation Sequences (emoji presentation, CJK variants) | ❌ -- notable gap for correct emoji/variant-selector handling |

Separately, `OS/2.ulUnicodeRange1`-`ulUnicodeRange4` (four `uint32`s = 128 bits) are
self-declared block-level coverage claims (bit N ⇒ "this font claims to cover Unicode
block N" per the OS/2 spec's fixed bit-to-block table, e.g. bit 9 = Cyrillic, bit 60 =
CJK Unified Ideographs) -- a `UnicodeCoverage` type reading these can answer "does this
font claim Cyrillic support" in O(1) without walking cmap at all, complementary to (not
a replacement for) actual cmap-based lookup.

### `FontVariationsInfo`

Today's `Variation\FontVariations` + `VariationAxis` + `VariationInstance` already model
exactly this (axis tag/min/default/max/name, named instances) -- per the guiding
principle above, this stays exactly where it is; no new type needed, just confirming
it's already correctly scoped as a fact-bearing (not drawing) concern.

### `FontCollectionInfo` (future `.ttc`/`.otc`, describe only)

Not implemented at all today (`FontFormat::TrueTypeCollection` case exists but nothing
parses the `ttcf` header). A `.ttc`/`.otc` file starts with a `TTCHeader`: tag `ttcf`,
major/minor version, `numFonts` (`uint32`), then `numFonts` × `uint32` offsets, each
pointing to a normal sfnt table directory (fonts in a collection commonly share `glyf`/
`loca`/`CFF ` tables across faces, only `name`/`hmtx`/`cmap`/`post` typically differ per
face). A `FontCollectionInfo` describing this (`faceCount: int`,
`faceOffsets: list<int>`) is enough to describe the structure; actually loading a
specific face (`FontLoader::load($path, faceIndex: int)`) is real parsing work, listed
in today's `TODO_NEXT.md` as item 4 and correctly out of scope for this planning doc.

## Summary of what changes vs. what conversation assumed

1. The fvar/gvar split framing from conversation was slightly off -- all variable-font
   math (declarative *and* delta-application) stays in `alto/font`, because
   `SfntFont::glyphOutline()`/`glyphMetrics()` already resolve instances internally as
   part of fact-computation, not shaping.
2. `PathCommand`/`Contour` need actual surgery, not a clean move -- they currently carry
   SVG-specific rendering methods (`scaledForSvg`, `toPathData`) alongside generic
   geometry (`transform`), more tightly coupled than the conversation's file-level
   framing assumed.
3. `Font.php` itself needs trimming (drop `text()`/`outline()`/`path()`), not a verbatim
   move -- it currently straddles both sides.
4. `ContainerFormat` and part of `FontInfo` already exist today under different names
   (`Metadata\FontFormat`, `Metadata\FontMetadata`) -- extend those in place rather than
   introducing parallel/competing types.
5. New finding not previously discussed: many macOS system fonts (the `.SF Pro`/
   `.SF Compact` family) use Apple Advanced Typography (`morx`/`kerx`) instead of
   `GSUB`/`GPOS` for their shaping data -- a future "does this font have kerning/ligature
   data" capability check needs to account for both table families, not just the
   OpenType-standard ones.
