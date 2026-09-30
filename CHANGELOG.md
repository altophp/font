# CHANGELOG

## [Unreleased]

- Reserve `Font::metrics()` for font-wide metrics. Migrate development calls
  from `metrics($glyph)` to `glyphMetrics($glyph)`; the latter now accepts a
  glyph ID or one UTF-8 character. The released `getMetrics($glyph)` and
  `getGlyphMetrics($id)` aliases retain their glyph-metric behavior.

- Load supported font containers directly from memory with `Font::fromBytes()`.
- Expose declared font bounds, line metrics, cap/x heights, italic angle,
  fixed-pitch status and raw embedding flags through `Font::metrics()`.
  Selected variable-font views are explicitly unsupported by this new API.
- Reject nonzero face indexes for standalone fonts, consistently for file and
  bytes loading. Previously these indexes were silently accepted.

- Reduce and split oversized GPOS PairPos class matrices, with a glyph-pair
  fallback for rows that cannot fit internal 16-bit offsets.
- Compact legacy `kern` format 0 pairs and vertical `vhea`, `vmtx`, and VVAR
  mappings when glyph IDs are renumbered.
- Compact every standard GSUB and GPOS lookup type, including contextual,
  chained, mark, cursive, alternate, reverse-chaining, and extension lookups.
- Preserve OpenType Layout 1.1 feature variations and relocate Device and
  VariationIndex data referenced by positioning records.
- Add documentation.
- Write supported faces as standalone SFNT, WOFF, and WOFF2 files.
- Create conservative Unicode subsets with optional glyph compaction, layout
  preservation, and hint removal.
- Preserve variable-font axes and per-glyph `gvar` and HVAR data while
  subsetting.
- Add native and process-backed Brotli compression adapters for WOFF2 output.
- Add canonical `descriptor()` and `glyphMetrics()` APIs, font-discovery diagnostics,
  and dedicated text and missing-glyph exceptions.

## [0.8.0]

- Support transformed WOFF2 `glyf`, `loca`, and `hmtx` tables.

## [0.7.0]

- Initial release.
