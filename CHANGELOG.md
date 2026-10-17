# CHANGELOG

## [Unreleased]

### Fixed

- The character map keeps its segments instead of one entry per codepoint, so a
  crafted `cmap` can no longer exhaust memory or CPU while a font loads.
- Unsorted or overlapping `cmap` segments and groups are rejected.
- Exceptions thrown by `Font::fromFile()`, `Font::fromBytes()`, and
  `FontLoader::load()` no longer carry the font bytes in their trace.

## [0.10.1] - 2026-10-17

### Fixed

- `FontFinder` returns the regular face for a query without weight or style.
- `FontFinder` scans large directories within bounded memory.
- Compact subsets keep `GSUB` tables small.

## [0.10.0] - 2026-10-16

### Added

- Convert glyph names to UTF-8 with `GlyphName::toUnicode()`, using the complete
  Adobe Glyph List, Unicode-name rules, and optional Zapf Dingbats mappings.

## [0.9.0] - 2026-10-16

### Added

- Write a loaded face as a standalone TTF, WOFF, or WOFF2 file with
  `SfntWriter`, `WoffWriter`, and `Woff2Writer`, including a single face
  extracted from a collection.
- Create Unicode subsets with `Font::subset()`, `SubsetOptions`, `UnicodeSet`,
  and `UnicodeRange`, with policies for glyph renumbering, layout
  preservation, and hint removal.
- Preserve ligatures, contextual substitutions, mark positioning, kerning, and
  OpenType feature variations in compact subsets, including oversized
  pair-positioning tables and legacy `kern` data.
- Keep variable-font axes, glyph variations, horizontal and vertical metrics,
  and ligature caret adjustments when subsetting; `Font::withoutVariations()`
  drops them on request.
- Choose WOFF2 compression through `BrotliExtensionCompressor`,
  `BrotliProcessCompressor`, and `BrotliCompressionProfile`.
- Load supported containers from memory with `Font::fromBytes()`.
- Read declared font-wide bounds, line metrics, cap and x heights, italic
  angle, fixed-pitch status, and embedding flags with `Font::metrics()`.
- Read the canonical face descriptor with `Font::descriptor()`.
- Inspect skipped font-discovery candidates through diagnostics.
- Distinguish invalid text from missing characters with `InvalidTextException`
  and `GlyphNotFoundException`; report write and compression failures with
  `FontWriteException` and `CompressionException`.

### Changed

- `Font::glyphMetrics()` accepts a glyph ID or one UTF-8 character.
- Standalone fonts reject a nonzero face index for file and bytes loading.

### Deprecated

- `Font::getFace()`, `getDescriptor()`, `getGlyphMetrics()`, and `getMetrics()`:
  use `face()`, `descriptor()`, and `glyphMetrics()`.

## [0.8.0] - 2026-08-10

- Support transformed WOFF2 `glyf`, `loca`, and `hmtx` tables.

## [0.7.0] - 2026-08-10

- Initial release.

[0.10.1]: https://github.com/altophp/font/compare/v0.10.0...v0.10.1
[0.10.0]: https://github.com/altophp/font/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/altophp/font/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/altophp/font/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/altophp/font/releases/tag/v0.7.0
