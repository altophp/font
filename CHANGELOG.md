# CHANGELOG

## [Unreleased]

### Added
- `Font::fromFile()`, `face()`, `getDescriptor()`, `metadata()`,
  `glyphIdForCodepoint()`, `glyphMetrics()`, `glyphOutline()`, `variations()`,
  `withVariations()`, `variationCoordinates()`.
- OpenType/TrueType/WOFF/WOFF2 sfnt parsing (`OpenType/SfntFont` and table
  readers), `glyf` simple and compound glyph outlines.
- Variable font support: `fvar`/`avar`/`gvar`/`HVAR` axis and instance
  resolution.
- Font discovery: `FontFinder` (`system()` / `fromDirectories()` /
  `fromLocator()` / `has()` / `find()` / `get()`), `FontQuery`
  (family/weight/style/stretch), `Locator\{FontLocatorInterface, FontLocator}`
  (the one injectable seam for tests or custom/non-directory sources).
- Descriptor and metadata value objects: `FontDescriptor`, `FontWeight`,
  `FontStyle`, `FontStretch`, `FontMetadata`, `FontFormat`.
- Exception hierarchy under `FontExceptionInterface`.
- TrueType/OpenType collection (`.ttc`/`.otc`) support: `Font::fromFile($path,
  faceIndex: $n)` selects a face; `FontFace::$faceIndex`/`$faceCount` report a
  file's shape. Nested collections and compressed WOFF2 collections still
  reject cleanly.

### Changed
- Split out of the former all-in-one `atelier/font` prototype: shaping,
  kerning, and SVG output moved to a separate package. This package now only
  reads font files and reports facts. See `ARCHITECTURE.md`.
