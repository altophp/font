# Font writing and subsetting

Branch: `sa/font-writing-subsetting`

## Goal

Add immutable font transformations that always produce a new file. The source
font is never modified or replaced.

The first useful path is:

```text
TTF/OTF/WOFF/WOFF2 with glyf outlines
    -> immutable SFNT table document
    -> Unicode subset
    -> WOFF or WOFF2
```

## API rules

- Keep `Font` immutable. A transformation returns a new in-memory document or
  writes a new destination; it never changes the loaded font.
- Refuse an existing destination by default. Do not add an `overwrite` boolean
  to the first public API.
- Use focused final classes and readonly value objects.
- Add an interface only at a real interchangeable boundary. The expected first
  one is `BrotliCompressorInterface`; an SFNT writer with one implementation does
  not need an interface.
- Accept Unicode codepoints, ranges, or text. Do not call bytes or glyph IDs
  "characters".
- Throw package exceptions implementing `FontExceptionInterface`.
- Preserve unknown SFNT tables byte-for-byte only while their references remain
  valid. A glyph-changing transformation must reject every unmodeled table that
  can contain glyph IDs.
- Preserve an unchanged standalone SFNT byte-for-byte, including `DSIG`.
  Remove `DSIG` whenever data is rebuilt or transformed.
- Keep optional native or process-backed accelerators behind explicit adapters.
  No public API may depend on shell commands.

## Support boundaries

| Input | Read now | First writing target | Subsetting target |
| --- | --- | --- | --- |
| Static TrueType `glyf` | Yes | Yes | First |
| OpenType with `glyf` | Yes | Yes | First |
| WOFF 1 | Yes | Decode to SFNT, then encode | First |
| WOFF2, single font | Yes | Decode to SFNT, then encode | First |
| TTC/OTC | Yes, selected face | Extract selected face | After SFNT writer |
| Variable `glyf` | `fvar`/`avar`/`gvar`/`HVAR` | Preserve | Yes, with stable glyph IDs and axes |
| CFF/CFF2 | No | No | Later project |
| Color glyph tables | No | No | Later project |
| WOFF2 collections | No | No | Later project |

## Milestones

### 1. Lossless SFNT output and controlled reconstruction

- [x] Share one SFNT builder between WOFF2 reconstruction and normal writing.
- [x] Rebuild the table directory, table checksums, and `head.checkSumAdjustment`.
- [x] Keep a byte-for-byte fast path for an unchanged standalone SFNT.
- [x] Add an internal immutable `SfntDocument` table set for transformations.
- [x] Extract one selected TTC/OTC face as a standalone SFNT.
- [x] Add `SfntWriter`, writing only to a destination that does not exist.
- [x] Reload every generated font with `alto/font` in tests.
- [x] Validate generated SFNT, WOFF, and WOFF2 fonts with OpenType Sanitizer in
  CI.

### 2. WOFF 1 output

- [x] Add a WOFF encoder using `ext-zlib`.
- [x] Compress each table only when the compressed representation is smaller.
- [x] Use Zlib level 6 by default; level 9 doubled CPU in the measured large
  variable font for a 244-byte reduction.
- [x] Remove `DSIG` before reconstructing a different container.
- [x] Reject inconsistent lengths, ranges, ordering, overlaps, and decompression
  bombs while decoding WOFF.
- [x] Validate every declared WOFF table checksum.
- [ ] Preserve metadata/private blocks only through explicit options.
- [x] Round-trip TTF, WOFF, WOFF2, and selected collection faces.

### 3. Conservative static `glyf` subsetting

- [x] Add immutable `UnicodeSet` and `UnicodeRange` value objects with text,
  codepoint, CSS range, and range
  factories.
- [x] Add immutable `union()`, `intersect()`, and `without()` operations without
  expanding Unicode intervals into individual codepoints.
- [x] Intersect requested intervals with parsed `cmap` mappings without expanding
  the entire Unicode range.
- [x] Always retain glyph 0 and close recursively over compound components.
- [x] Start with retained glyph IDs and empty unused glyph slots.
- [x] Rebuild `cmap` format 4/12, `glyf`, `loca`, and `head` loca format.
- [x] Preserve `hmtx` and stable glyph IDs; recalculate `head` bbox, `hhea`
  advance maximum across all slots, and bearing extrema from retained contours.
- [x] Recalculate `maxp` geometry maxima and `OS/2` coverage. Rewrite `post`
  format 2 only when glyph renumbering is introduced.
- [x] Add opt-in fail-closed glyph renumbering for core static TrueType tables,
  including compound references, horizontal metrics, cmap, maxp, and post.
- [ ] Extend glyph renumbering beyond the supported GSUB, GPOS, GDEF, gvar,
  HVAR, and MERG formats to vertical, kerning, and remaining glyph-indexed
  tables.
- [x] Compute conservative GSUB closure for lookup types 1 through 8, including
  contextual and chained-context formats 1 through 3. Preserve GSUB, GPOS, and
  GDEF with stable GIDs and explicit non-compaction warnings.
- [x] Reject nested contextual lookup dependencies and unmodeled
  glyph-introducing tables rather than producing incomplete output.
- [ ] Classify and rewrite or reject `kern`, BASE, vertical metrics, VORG, hdmx,
  LTSH, color, bitmap, and remaining glyph-indexed tables.
- [x] Offer explicit hint removal, including glyph instructions and dependent
  hint/device tables; keep hinting by default.

### 4. WOFF2 output

- [x] Add `BrotliCompressorInterface` and a typed process adapter using quality 11.
- [x] Emit a valid WOFF2 container using null transforms first.
- [x] Remove `DSIG` and set bit 11 in `head.flags` as required by WOFF2.
- [x] Avoid materializing an intermediate SFNT; a 25.9 MiB SF Pro input stays
  below a 128 MiB PHP memory limit.
- [x] Pad the final compressed block to the four-byte boundary expected by
  browser WOFF2 decoders while excluding padding from `totalCompressedSize`.
- [x] Add `glyf`/`loca` and `hmtx` transforms only after null-transform output is
  independently validated.
- [x] Validate null-transform output with CoreGraphics in addition to Alto's
  own decoder.
- [x] Add a native ext-brotli adapter that requests `BROTLI_FONT` mode.
- [x] Add explicit `Fast` (quality 5) and `Maximum` (quality 11) profiles and
  validate both outputs in Chromium and CoreGraphics.
- [x] Stream process-backed `write()` calls through temporary input/output
  streams in 1 MiB chunks. Keep `dump()` as a convenient string-returning API
  without a bounded-memory promise.
- [ ] Add a seekable font-source abstraction so parsing and table access no
  longer require the complete source font in one PHP string.
- [ ] Measure total process-tree RSS in addition to PHP's memory limit because
  the external Brotli process is accounted separately.
- [ ] Benchmark a pure-PHP Brotli implementation before promising it as a
  supported production path; keep compression behind the interface.

### 5. OpenType Layout and variable fonts

- [x] Model conservative GSUB references and compute layout glyph closure.
- [x] Offer an explicit `LayoutPolicy::Drop` that removes GSUB, GPOS, and GDEF
  before compact renumbering; preserve layout by default.
- [x] Compact GSUB 1.0 single, multiple, ligature, and chained-context formats
  1/2/3, including extension lookups, while retaining lookup indexes.
- [x] Compact GPOS single and pair positioning, mark-to-base format 1, and
  chained-context format 1. Relocate ValueRecord device and VariationIndex
  offsets and preserve extension lookup reachability.
- [x] Compact GDEF 1.0/1.2/1.3 class definitions, attachment points, ligature
  carets formats 1/2, mark glyph sets, and a final ItemVariationStore.
- [ ] Add the remaining GSUB/GPOS formats and anchor device or variation
  offsets.
- [x] Subset `gvar` glyph data while preserving GIDs and all axes.
- [x] Remap `gvar` blocks and HVAR mappings while preserving fvar, avar, STAT,
  MVAR, and cvar with unchanged axes.
- [ ] Compact the HVAR ItemVariationStore itself and add VVAR support.
- [ ] Then support complete axis pinning, including phantom/component deltas,
  IUP, Variation Stores, FeatureVariations, metrics, bboxes, and metadata.
- [ ] Treat axis-range reduction as a separate final feature: it must remap all
  normalized regions and every Variation Store.

## Verification gate

Every milestone must pass:

```bash
composer check
```

Generated fonts must also be reopened by `alto/font`. Green parser tests alone
do not prove that an output font is accepted by browsers or platform font
stacks.

Current real-font proof uses SF Pro ASCII (`U+0020-007E`): 675 of 35,026
glyphs, 2,850,764-byte SFNT, 1,115,800-byte WOFF, 1,008,340-byte fast WOFF2,
and 826,700-byte maximum WOFF2. The run stays below a 128 MiB PHP limit; source
and subset shape identically at default coordinates and `wght=700` in
HarfBuzz. SFNT, WOFF, and both WOFF2 profiles load in CoreGraphics; both WOFF2
profiles also load in Chromium.

Writing the complete 25,872,268-byte SF Pro source as fast WOFF2 now peaks at
97,124,352 bytes of PHP memory through the streamed `write()` path, versus
119,226,368 bytes through `dump()`. The streamed output reloads in Alto and
CoreGraphics.

The Lato digits-and-math proof requests 20 Unicode characters. Compact mode
with layout dropped produces 21 glyphs, a 12,816-byte TTF, and a 6,068-byte
WOFF2. Dropping hinting reduces them to 8,504 and 3,912 bytes. Alto reloads all
four files, HarfBuzz reports exactly 20 Unicode mappings in both TTF files, and
CoreGraphics accepts every output.

Keeping compacted GSUB substitutions for the same Lato selection retains 113
glyphs and produces a 19,936-byte TTF or 8,108-byte WOFF2 without hinting. The
compacted closure reaches 112 referenced glyphs within the new 113-glyph font;
Alto, HarfBuzz, and CoreGraphics accept the result. GPOS is explicitly removed
in this intermediate policy.

Preserving both compacted GSUB and GPOS for that selection keeps the same 113
glyphs and produces a 33,464-byte TTF or 11,396-byte WOFF2 without hinting.
HarfBuzz reports the same glyph advances as the source for the numeric proof
string; Alto reloads both files and CoreGraphics accepts both containers.

Compact SF Pro ASCII keeps 675 glyphs and all three axes. The 25,872,268-byte
source becomes a 1,317,364-byte TTF, a 653,504-byte fast WOFF2, or a 573,508-byte
maximum WOFF2. The streamed run peaks at 122,290,176 bytes under a 128 MiB PHP
limit. HarfBuzz shaping matches the source at default coordinates and
`wght=700`; Alto reloads every output and CoreGraphics accepts all containers.
