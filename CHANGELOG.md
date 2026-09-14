# CHANGELOG

## [Unreleased]

- Compact legacy `kern` format 0 pairs and vertical `vhea`, `vmtx`, and VVAR
  mappings when glyph IDs are renumbered.
- Add documentation.
- Write supported faces as standalone SFNT, WOFF, and WOFF2 files.
- Create conservative Unicode subsets with optional glyph compaction, layout
  preservation, and hint removal.
- Preserve variable-font axes and per-glyph `gvar` and HVAR data while
  subsetting.
- Add native and process-backed Brotli compression adapters for WOFF2 output.
- Add canonical `descriptor()` and `metrics()` APIs, font-discovery diagnostics,
  and dedicated text and missing-glyph exceptions.

## [0.8.0]

- Support transformed WOFF2 `glyf`, `loca`, and `hmtx` tables.

## [0.7.0]

- Initial release.
